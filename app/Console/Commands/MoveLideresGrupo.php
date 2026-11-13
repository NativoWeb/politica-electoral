<?php

namespace App\Console\Commands;

use App\Http\Controllers\EventoCompromisoController;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\CachedQueries;
use App\Support\CurrentCampaign;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Moves fichas (lideres) to another grupo político of the same tree, e.g. the ones a user created while still in the
// parent group. Moves with the ficha what is the ficha's own and carries campaign_id (its nexos); what hangs from it
// without a group column follows it by lider_id; what belongs to the origin group (events, actas, organizations,
// WhatsApp sends) or is history (consents, merges, audit, favorites) stays and is only reported.
// A cédula already present in the destination aborts everything (unique per group). --dry-run reads only.
class MoveLideresGrupo extends Command
{
    protected $signature = 'data:move-lideres-grupo
        {grupo_destino : Slug o id del grupo político que recibe las fichas}
        {--ids= : Ids de las fichas, separados por coma}
        {--creados-por= : Id del usuario: las fichas que creó (POST mapa-politico.lider.store en la auditoría)}
        {--dry-run : Muestra el reporte sin cambiar nada}
        {--reason= : Motivo (obligatorio sin --dry-run), queda en la auditoría}';

    protected $description = 'Move lideres to another campaign of the same tree with their own nexos, audited';

    private const STORE_ACTION = 'POST mapa-politico.lider.store';

    private const AUDIT_WINDOW_SECONDS = 5;

    // Hang from the ficha by lider_id and have no group column: they follow it without being touched.
    private const FOLLOW = [
        'lider_contactos' => 'lider_id',
        'lider_info_politica' => 'lider_id',
        'lider_municipios_influencia' => 'lider_id',
        'lider_seguimientos' => 'lider_id',
    ];

    // Belong to the origin group or are history: they stay as they are.
    private const STAY = [
        'event_attendees' => ['lider_id'],
        'event_organizers' => ['lider_id'],
        'event_acta_participantes' => ['lider_id'],
        'event_compromiso_responsables' => ['lider_id'],
        'event_import_rows' => ['match_lider_id', 'suggested_lider_id'],
        'data_consents' => ['lider_id'],
        'organizacion_miembros' => ['lider_id'],
        'organizaciones' => ['representante_lider_id'],
        'whatsapp_send_recipients' => ['person_id'],
        'favorites' => ['favoritable_id'],
        'recent_views' => ['viewable_id'],
        'taggables' => ['taggable_id'],
        'merge_history' => ['kept_id', 'absorbed_id'],
        'audit_logs' => ['entity_id'],
    ];

    private const STAY_REASON = [
        'event_attendees' => 'asistencia a eventos del grupo de origen',
        'event_organizers' => 'organizadores de eventos del grupo de origen',
        'event_acta_participantes' => 'actas del grupo de origen',
        'event_compromiso_responsables' => 'compromisos del grupo de origen',
        'event_import_rows' => 'filas de importación del grupo de origen',
        'data_consents' => 'append-only (Ley 1581): no se toca',
        'organizacion_miembros' => 'organizaciones del grupo de origen',
        'organizaciones' => 'representante de organización del grupo de origen',
        'whatsapp_send_recipients' => 'historial de envíos del grupo de origen',
        'favorites' => 'preferencia de cada usuario',
        'recent_views' => 'historial de cada usuario',
        'taggables' => 'etiquetas compartidas (sin grupo)',
        'merge_history' => 'historial de unificaciones',
        'audit_logs' => 'auditoría (append-only)',
    ];

    private array $parents = [];

    public function handle(): int
    {
        $destId = trim((string) $this->argument('grupo_destino')) === '' ? null
            : CurrentCampaign::resolveForCommand(trim((string) $this->argument('grupo_destino')));
        if (! $destId) {
            $this->error('Grupo político destino no encontrado: '.$this->argument('grupo_destino'));

            return self::FAILURE;
        }

        $ids = $this->option('ids');
        $creator = $this->option('creados-por');
        if (($ids === null || $ids === '') === ($creator === null || $creator === '')) {
            $this->error('Indique --ids o --creados-por (uno de los dos).');

            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');
        $reason = trim((string) $this->option('reason'));
        if (! $dryRun && $reason === '') {
            $this->error('--reason es obligatorio para mover (o use --dry-run).');

            return self::FAILURE;
        }

        $creatorUser = null;
        $unmatched = [];
        if ($creator) {
            if (! Str::isUuid($creator) || ! ($creatorUser = DB::table('users')->where('id', $creator)->first(['id', 'territory_scope', 'campaign_id']))) {
                $this->error("Usuario no encontrado: {$creator}");

                return self::FAILURE;
            }
            if ($creatorUser->campaign_id !== $destId) {
                $this->warn('El usuario de --creados-por no pertenece al grupo destino: revise que sea el grupo correcto.');
            }
            [$liderIds, $unmatched] = $this->fromAudit($creator);
        } else {
            $liderIds = array_values(array_unique(array_filter(array_map('trim', explode(',', $ids)))));
            $invalid = array_filter($liderIds, fn ($id) => ! Str::isUuid($id));
            if ($invalid) {
                $this->error('Ids inválidos: '.implode(', ', $invalid));

                return self::FAILURE;
            }
        }

        DB::beginTransaction();
        try {
            if ($dryRun && DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION READ ONLY');
            }
            if (! $dryRun && $liderIds) {
                DB::table('lideres')->whereIn('id', $liderIds)->lockForUpdate()->get(['id']);
            }

            $plan = $this->plan($liderIds, $destId, $creatorUser);
            $this->report($plan, $unmatched, $destId);

            if ($dryRun || $plan['conflicts'] || ! $plan['move']) {
                DB::rollBack();
            } else {
                $this->apply($plan, $destId, $reason, $creator, count($unmatched));
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        if ($plan['conflicts']) {
            $this->error('Abortado: '.count($plan['conflicts']).' cédula(s) ya existen en el grupo destino o se repiten entre las fichas. No se movió nada.');

            return self::FAILURE;
        }
        if ($dryRun) {
            $this->warn('--dry-run: no se cambió nada.');

            return self::SUCCESS;
        }
        if (! $plan['move']) {
            $this->info('No hay fichas para mover.');

            return self::SUCCESS;
        }

        $users = $this->forgetCaches($plan);
        $this->info('Listo: '.count($plan['move']).' ficha(s) movidas. Cachés limpiadas ('.$users.' usuarios).');

        return self::SUCCESS;
    }

    // The fichas a user created, from the audit log: by entity_id or the offline client_id (the ficha's id) when the log
    // has it, otherwise by exact name and
    // created_at within a few seconds (narrowed by municipio when there are homonyms). Anything not unique is reported.
    private function fromAudit(string $userId): array
    {
        $ids = [];
        $unmatched = [];
        $logs = DB::table('audit_logs')->where('user_id', $userId)->where('action', self::STORE_ACTION)
            ->orderBy('created_at')->get(['id', 'entity_id', 'metadata', 'created_at']);

        foreach ($logs as $log) {
            if ($log->entity_id && DB::table('lideres')->where('id', $log->entity_id)->exists()) {
                $ids[] = $log->entity_id;

                continue;
            }
            $meta = is_string($log->metadata) ? json_decode($log->metadata, true) : (array) $log->metadata;
            $clientId = $meta['client_id'] ?? null;
            if (is_string($clientId) && Str::isUuid($clientId) && DB::table('lideres')->where('id', $clientId)->exists()) {
                $ids[] = $clientId;

                continue;
            }
            $nombre = $meta['nombre'] ?? null;
            if (! is_string($nombre) || $nombre === '') {
                $unmatched[] = [$log->id, 'el registro no trae nombre'];

                continue;
            }
            $at = Carbon::parse($log->created_at);
            $candidates = DB::table('lideres')->where('nombre', $nombre)
                ->whereBetween('created_at', [
                    $at->copy()->subSeconds(self::AUDIT_WINDOW_SECONDS)->format('Y-m-d H:i:s'),
                    $at->copy()->addSeconds(self::AUDIT_WINDOW_SECONDS)->format('Y-m-d H:i:s'),
                ])->get(['id', 'geographic_unit_id']);
            if ($candidates->count() > 1 && ! empty($meta['municipio_id'])) {
                $candidates = $candidates->where('geographic_unit_id', $meta['municipio_id'])->values();
            }
            if ($candidates->count() === 1) {
                $ids[] = $candidates->first()->id;
            } else {
                $unmatched[] = [$log->id, $candidates->isEmpty() ? 'ninguna ficha con ese nombre y hora' : 'varias fichas posibles'];
            }
        }

        return [array_values(array_unique($ids)), $unmatched];
    }

    private function plan(array $liderIds, string $destId, ?object $creatorUser): array
    {
        $destRoot = $this->root($destId);
        $destAncestors = $this->chain($destId);
        $destTerritory = $this->groupTerritory($destId);
        $creatorTerritory = $creatorUser && $creatorUser->territory_scope
            ? (is_string($creatorUser->territory_scope) ? json_decode($creatorUser->territory_scope, true) : (array) $creatorUser->territory_scope)
            : null;

        $rows = $liderIds ? DB::table('lideres')->whereIn('id', $liderIds)
            ->get(['id', 'campaign_id', 'is_active', 'cedula', 'referente_documento', 'geographic_unit_id', 'comuna_id', 'comuna_source'])
            ->keyBy('id') : collect();

        $move = [];
        $skipped = [];
        foreach ($liderIds as $id) {
            $row = $rows->get($id);
            $why = match (true) {
                $row === null => 'no existe',
                $row->campaign_id === $destId => 'ya está en el grupo destino',
                ! $row->is_active => 'inactiva',
                $this->root($row->campaign_id) !== $destRoot => 'otro árbol de grupos',
                ! in_array($row->campaign_id, $destAncestors, true) => 'el origen no es un grupo superior del destino',
                $destTerritory !== null && ! in_array($row->geographic_unit_id, $destTerritory, true) => 'fuera del territorio del grupo destino',
                default => null,
            };
            if ($why) {
                $skipped[] = [$id, $why];
            } else {
                $move[$id] = $row;
            }
        }

        $moveIds = array_keys($move);
        $origins = array_values(array_unique(array_map(fn ($r) => $r->campaign_id, $move)));

        return [
            'move' => $moveIds,
            'rows' => $move,
            'skipped' => $skipped,
            'origins' => $origins,
            'conflicts' => $this->cedulaConflicts($move, $destId),
            'outside_creator' => $creatorTerritory === null ? [] : array_values(array_filter($moveIds, fn ($id) => ! in_array($move[$id]->geographic_unit_id, $creatorTerritory, true))),
            'nexos' => $moveIds ? $this->count('nexos_familiares', fn ($q) => $this->ownNexos($q, $move)) : 0,
            'follow' => $this->dependencyCounts(self::FOLLOW, $moveIds),
            'stay' => $this->dependencyCounts(self::STAY, $moveIds),
            'links' => $this->brokenLinks($move, $destId),
            'comuna_auto' => count(array_filter($move, fn ($r) => $r->comuna_id !== null && $r->comuna_source !== 'manual')),
        ];
    }

    // Cédula is unique per group: one already in the destination, or two fichas being moved with the same one.
    private function cedulaConflicts(array $move, string $destId): array
    {
        $cedulas = array_filter(array_map(fn ($r) => $r->cedula, $move), fn ($c) => $c !== null && $c !== '');
        $conflicts = [];
        foreach (array_count_values($cedulas) as $count) {
            if ($count > 1) {
                $conflicts[] = 'repetida entre las fichas';
            }
        }
        if ($cedulas) {
            $taken = DB::table('lideres')->where('campaign_id', $destId)->whereIn('cedula', array_values(array_unique($cedulas)))->pluck('cedula')->all();
            foreach ($move as $id => $row) {
                if (in_array($row->cedula, $taken, true)) {
                    $conflicts[] = "ficha {$id}: su cédula ya existe en el destino";
                }
            }
        }

        return $conflicts;
    }

    // Links that only hold inside one group (referente/referidos by cédula; a nexo is shown with its owner's group).
    private function brokenLinks(array $move, string $destId): array
    {
        $moveIds = array_keys($move);
        $links = ['referente_queda' => 0, 'referidos_quedan' => 0, 'referente_nuevo_en_destino' => 0, 'nexos_ajenos_con_cedula_de_movida' => 0];
        $staying = fn ($q) => $q->whereNotIn('id', $moveIds);
        foreach ($move as $row) {
            if ($row->referente_documento) {
                $links['referente_queda'] += DB::table('lideres')->where('campaign_id', $row->campaign_id)
                    ->where('cedula', $row->referente_documento)->where($staying)->count();
                $links['referente_nuevo_en_destino'] += DB::table('lideres')->where('campaign_id', $destId)
                    ->where('cedula', $row->referente_documento)->count();
            }
            if ($row->cedula) {
                $links['referidos_quedan'] += DB::table('lideres')->where('campaign_id', $row->campaign_id)
                    ->where('referente_documento', $row->cedula)->where($staying)->count();
                $links['nexos_ajenos_con_cedula_de_movida'] += $this->count('nexos_familiares',
                    fn ($q) => $q->where('cedula', $row->cedula)->whereNotIn('person_id', $moveIds));
            }
        }

        return $links;
    }

    private function dependencyCounts(array $tables, array $moveIds): array
    {
        $counts = [];
        foreach ($tables as $table => $columns) {
            $counts[$table] = $moveIds ? $this->count($table, function ($q) use ($columns, $moveIds) {
                $q->where(function ($w) use ($columns, $moveIds) {
                    foreach ((array) $columns as $column) {
                        $w->orWhereIn($column, $moveIds);
                    }
                });
            }) : 0;
        }

        return $counts;
    }

    // The nexos of the fichas that live in each ficha's own (origin) group; a nexo of another group is not the ficha's.
    private function ownNexos($query, array $move)
    {
        $byOrigin = [];
        foreach ($move as $id => $row) {
            $byOrigin[$row->campaign_id][] = $id;
        }

        return $query->where(function ($w) use ($byOrigin) {
            foreach ($byOrigin as $origin => $ids) {
                $w->orWhere(fn ($o) => $o->where('campaign_id', $origin)->whereIn('person_id', $ids));
            }
        });
    }

    // null when the table does not exist on this database.
    private function count(string $table, callable $where): ?int
    {
        if (! Schema::hasTable($table)) {
            return null;
        }
        $query = DB::table($table);
        $where($query);

        return $query->count();
    }

    // The municipios the destination's active users can work in; null = unrestricted (some user has no territory,
    // or the group has no active users yet).
    private function groupTerritory(string $campaignId): ?array
    {
        $scopes = DB::table('users')->where('campaign_id', $campaignId)->where('is_active', true)->pluck('territory_scope');
        if ($scopes->isEmpty()) {
            return null;
        }
        $ids = [];
        foreach ($scopes as $scope) {
            $list = is_string($scope) ? json_decode($scope, true) : $scope;
            if (empty($list)) {
                return null;
            }
            $ids = [...$ids, ...$list];
        }

        return array_values(array_unique($ids));
    }

    private function apply(array $plan, string $destId, string $reason, ?string $creator, int $unmatched): void
    {
        $now = now();
        foreach (array_chunk($plan['move'], 500) as $chunk) {
            DB::table('lideres')->whereIn('id', $chunk)->update(['campaign_id' => $destId, 'updated_at' => $now]);
        }
        if ($plan['nexos'] && Schema::hasTable('nexos_familiares')) {
            $this->ownNexos(DB::table('nexos_familiares'), $plan['rows'])->update(['campaign_id' => $destId, 'updated_at' => $now]);
        }

        AuditLog::create([
            'user_id' => null,
            'action' => 'LIDERES_GRUPO_MOVIDOS',
            'entity_type' => 'campana',
            'entity_id' => $destId,
            'before_state' => ['campaign_ids' => $plan['origins']],
            'after_state' => ['campaign_id' => $destId],
            'metadata' => [
                'command' => 'data:move-lideres-grupo',
                'reason' => $reason,
                'creados_por' => $creator,
                'lider_ids' => $plan['move'],
                'movidas' => count($plan['move']),
                'nexos_movidos' => $plan['nexos'],
                'omitidas' => count($plan['skipped']),
                'sin_cruzar_auditoria' => $unmatched,
                'siguen_a_la_ficha' => $plan['follow'],
                'se_quedan' => $plan['stay'],
                'vinculos' => $plan['links'],
            ],
        ]);
    }

    private function report(array $plan, array $unmatched, string $destId): void
    {
        $names = DB::table('campaigns')->pluck('name', 'id');
        $name = fn ($id) => $names[$id] ?? $id;
        $show = fn ($n) => $n === null ? 'tabla no existe' : $n;

        $this->line('Destino: '.$name($destId).'. Origen: '.($plan['origins'] ? implode(', ', array_map($name, $plan['origins'])) : 'ninguno').'.');
        $this->table(['Se mueve con la ficha (campaign_id)', 'Filas'], [
            ['lideres', count($plan['move'])],
            ['nexos_familiares (los de la ficha)', $plan['nexos'] ?? 'tabla no existe'],
        ]);
        $this->table(['Siguen a la ficha por lider_id (sin columna de grupo)', 'Filas'],
            array_map(fn ($t) => [$t, $show($plan['follow'][$t])], array_keys($plan['follow'])));
        $this->table(['Se quedan como están', 'Filas', 'Motivo'],
            array_map(fn ($t) => [$t, $show($plan['stay'][$t]), self::STAY_REASON[$t]], array_keys($plan['stay'])));
        $this->table(['Vínculos solo dentro del mismo grupo', 'Casos'], [
            ['Referente que se queda en el origen (la ficha pierde su referente)', $plan['links']['referente_queda']],
            ['Referidos que se quedan en el origen (pierden a su referente)', $plan['links']['referidos_quedan']],
            ['Referente que ya existe en el destino (se vincula a otro)', $plan['links']['referente_nuevo_en_destino']],
            ['Nexos de otras fichas con la cédula de una ficha movida (informativo)', $plan['links']['nexos_ajenos_con_cedula_de_movida']],
            ['Fichas con comuna automática (el cron 02:30 la recalcula con los alias del destino)', $plan['comuna_auto']],
        ]);

        foreach ($plan['skipped'] as [$id, $why]) {
            $this->warn("Omitida {$id}: {$why}.");
        }
        foreach ($unmatched as [$auditId, $why]) {
            $this->warn("Sin cruzar (audit_logs {$auditId}): {$why}.");
        }
        if ($plan['outside_creator']) {
            $this->warn(count($plan['outside_creator']).' ficha(s) quedan fuera del territorio del usuario: '.implode(', ', $plan['outside_creator']));
        }
        foreach ($plan['conflicts'] as $conflict) {
            $this->error("Cédula: {$conflict}.");
        }
    }

    // compromiso alerts (per user, 5 min) of every user of the tree, and the shared listings' caches.
    private function forgetCaches(array $plan): int
    {
        $tree = [];
        foreach ($plan['origins'] as $origin) {
            $tree = [...$tree, ...(new CurrentCampaign)->withDescendants($this->root($origin))];
        }
        $count = 0;
        foreach (User::whereIn('id', DB::table('users')->whereIn('campaign_id', array_unique($tree))->pluck('id'))->get() as $user) {
            $request = Request::create('/');
            $request->setUserResolver(fn () => $user);
            Cache::forget(EventoCompromisoController::alertsKey($request));
            $count++;
        }
        CachedQueries::flush();

        return $count;
    }

    private function root(?string $campaignId): ?string
    {
        $chain = $campaignId ? $this->chain($campaignId) : [];

        return $chain ? end($chain) : null;
    }

    private function chain(string $campaignId): array
    {
        $ids = [];
        for ($current = $campaignId; $current !== null && ! in_array($current, $ids, true); $current = $this->parentOf($current)) {
            $ids[] = $current;
        }

        return $ids;
    }

    private function parentOf(string $campaignId): ?string
    {
        if (! array_key_exists($campaignId, $this->parents)) {
            $this->parents[$campaignId] = DB::table('campaigns')->where('id', $campaignId)->value('parent_id');
        }

        return $this->parents[$campaignId];
    }
}
