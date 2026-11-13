<?php

namespace App\Console\Commands;

use App\Http\Controllers\EventoCompromisoController;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\User;
use App\Services\CampaignService;
use App\Support\CurrentCampaign;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// Makes a grupo político a subgrupo of another one (campaigns.parent_id) with the rules of the Grupos políticos screen
// (CampaignService::parentError). The groups above the new parent start reading the subgrupo's data (read only);
// those above the old parent stop. --dry-run only reports that.
class SetCampaignParent extends Command
{
    protected $signature = 'data:campaign-parent
        {hijo : Slug o id del grupo político que pasa a ser subgrupo}
        {padre : Slug o id de su nuevo grupo principal}
        {--dry-run : Muestra el impacto sin cambiar nada}';

    protected $description = 'Move a campaign under another one (parent_id), audited';

    public function handle(CampaignService $campaigns): int
    {
        $childId = $this->resolve($this->argument('hijo'));
        $parentId = $this->resolve($this->argument('padre'));
        if (! $childId || ! $parentId) {
            $this->error('Grupo político no encontrado: '.(! $childId ? $this->argument('hijo') : $this->argument('padre')));

            return self::FAILURE;
        }

        $child = DB::table('campaigns')->where('id', $childId)->first(['id', 'name', 'parent_id']);
        $parentName = DB::table('campaigns')->where('id', $parentId)->value('name');
        if ($child->parent_id === $parentId) {
            $this->info("{$child->name} ya es subgrupo de {$parentName}: no hay nada que cambiar.");

            return self::SUCCESS;
        }
        if ($error = $campaigns->parentError($childId, $parentId)) {
            $this->error($error);

            return self::FAILURE;
        }

        $impact = $this->impact($child, $parentId);
        $this->report($child->name, $parentName, $impact);

        if ($this->option('dry-run')) {
            $this->warn('--dry-run: no se cambió nada.');

            return self::SUCCESS;
        }

        $error = DB::transaction(function () use ($campaigns, $child, $parentId, $impact) {
            DB::table('campaigns')->whereIn('id', [$child->id, $parentId])->lockForUpdate()->get(['id']);
            // Checked again inside the lock: another change may have created a cycle in between.
            if ($error = $campaigns->parentError($child->id, $parentId)) {
                return $error;
            }
            DB::table('campaigns')->where('id', $child->id)->update(['parent_id' => $parentId, 'updated_at' => now()]);
            AuditLog::create([
                'user_id' => null,
                'action' => 'CAMPANA_PADRE_CAMBIADO',
                'entity_type' => 'campana',
                'entity_id' => $child->id,
                'before_state' => ['parent_id' => $child->parent_id],
                'after_state' => ['parent_id' => $parentId],
                'metadata' => [
                    'command' => 'data:campaign-parent',
                    'ganan_lectura' => $impact['gain'],
                    'pierden_lectura' => $impact['lose'],
                    'usuarios_afectados' => count($impact['users']),
                ],
            ]);

            return null;
        });
        if ($error) {
            $this->error($error);

            return self::FAILURE;
        }

        $this->forgetCaches($impact['users']);
        $this->info("Listo: {$child->name} es subgrupo de {$parentName}. Cachés limpiadas de ".count($impact['users']).' usuarios.');

        return self::SUCCESS;
    }

    private function resolve(string $value): ?string
    {
        return trim($value) === '' ? null : CurrentCampaign::resolveForCommand(trim($value));
    }

    // Groups whose visible set changes: the chain above the new parent gains the child's subtree, the chain above
    // the old one loses it (a common ancestor keeps it). The subtree's own users only become "subgrupo".
    private function impact(object $child, string $parentId): array
    {
        $before = $child->parent_id ? $this->chain($child->parent_id) : [];
        $after = $this->chain($parentId);
        $gain = array_values(array_diff($after, $before));
        $lose = array_values(array_diff($before, $after));
        $subtree = (new CurrentCampaign)->withDescendants($child->id);

        $users = DB::table('users')->whereIn('campaign_id', [...$gain, ...$lose, ...$subtree])->pluck('id')->all();

        return [
            'gain' => $gain,
            'lose' => $lose,
            'subtree' => $subtree,
            'users' => $users,
            'lideres' => DB::table('lideres')->whereIn('campaign_id', $subtree)->where('is_active', true)->count(),
            'nexos' => DB::table('nexos_familiares')->whereIn('campaign_id', $subtree)->count(),
        ];
    }

    private function chain(string $campaignId): array
    {
        $ids = [];
        for ($current = $campaignId; $current !== null && ! in_array($current, $ids, true); $current = DB::table('campaigns')->where('id', $current)->value('parent_id')) {
            $ids[] = $current;
        }

        return $ids;
    }

    private function report(string $childName, string $parentName, array $impact): void
    {
        $names = DB::table('campaigns')->pluck('name', 'id');
        $usersOf = fn (array $ids) => DB::table('users')->whereIn('campaign_id', $ids)->where('is_active', true)->count();
        $list = fn (array $ids) => $ids ? implode(', ', array_map(fn ($id) => $names[$id] ?? $id, $ids)) : 'ninguno';

        $this->line("{$childName} pasará a ser subgrupo de {$parentName}.");
        $this->table(['', 'Valor'], [
            ['Grupos que empiezan a ver sus datos (solo lectura)', $list($impact['gain'])],
            ['  usuarios activos de esos grupos', $usersOf($impact['gain'])],
            ['Grupos que dejan de verlos', $list($impact['lose'])],
            ['  usuarios activos de esos grupos', $usersOf($impact['lose'])],
            ['Líderes activos del subgrupo (y sus subgrupos)', $impact['lideres']],
            ['Nexos familiares del subgrupo', $impact['nexos']],
            ['Usuarios del subgrupo (siguen viendo solo lo suyo)', $usersOf($impact['subtree'])],
        ]);
        if (in_array(Campaign::DEFAULT_ID, $impact['gain'], true)) {
            $this->warn('El grupo político principal verá esos datos también en exportaciones, offline, auditoría y el agente de WhatsApp.');
        }
    }

    // Per-user caches that depend on the visible groups (compromiso alerts, 5 min).
    private function forgetCaches(array $userIds): void
    {
        foreach (User::whereIn('id', $userIds)->get() as $user) {
            $request = Request::create('/');
            $request->setUserResolver(fn () => $user);
            Cache::forget(EventoCompromisoController::alertsKey($request));
        }
    }
}
