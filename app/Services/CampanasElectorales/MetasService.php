<?php

namespace App\Services\CampanasElectorales;

use App\Models\AuditLog;
use App\Models\ElectoralCampaign;
use App\Models\ElectoralCampaignGoal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// Saves a batch of goals all or nothing (PUT /metas and the Excel import). Per item, a missing key keeps the value and
// null clears it; a unit whose three values end up empty loses its goal row. A municipio carries its own goal or goals
// in its comunas, never both. Every changed cell is audited with its before and after.
class MetasService
{
    public const NUMERO = 'Escribe un número entero, 0 o más';

    // "Escribe un número entero entre 0 y 10.000.000."
    public static function tope(): string
    {
        return 'Escribe un número entero entre 0 y '.TerritorioCampana::miles((int) config('campanas_electorales.meta_max')).'.';
    }

    public function __construct(private readonly ElectoralCampaignAccess $access) {}

    /**
     * @param  array<int, array<string, mixed>>  $items  [{geographic_unit_id, votos_meta?, lideres_meta?, votantes_meta?}]
     * @return int the units whose goals changed
     *
     * @throws MetasInvalidas
     */
    public function guardar(ElectoralCampaign $c, array $items, User $user, string $accion, array $metadata = []): int
    {
        return DB::transaction(function () use ($c, $items, $user, $accion, $metadata) {
            // Serializes two batches on the same campaign and re-reads its status under the lock.
            $locked = ElectoralCampaign::query()->whereKey($c->id)->lockForUpdate()->first(['id', 'status']);
            if (! $locked || $locked->status !== ElectoralCampaign::ACTIVE) {
                throw new MetasInvalidas([['indice' => null, 'campo' => null, 'mensaje' => ElectoralCampaignAccess::ARCHIVADA]]);
            }

            $filas = $this->access->territorio()->filas($c->scope_level, $this->access->territoryIds($c));
            $porId = array_column($filas, null, 'id');
            $existentes = ElectoralCampaignGoal::query()->where('electoral_campaign_id', $c->id)->get()->keyBy('geographic_unit_id');

            $finales = [];
            foreach ($existentes as $unidad => $g) {
                $finales[$unidad] = ['votos_meta' => $g->votos_meta, 'lideres_meta' => $g->lideres_meta, 'votantes_meta' => $g->votantes_meta];
            }
            $errores = [];
            $indicePorUnidad = [];
            foreach ($items as $i => $item) {
                $unidad = $item['geographic_unit_id'] ?? null;
                if (! is_string($unidad) || ! isset($porId[$unidad])) {
                    $errores[] = ['indice' => $i, 'campo' => 'geographic_unit_id', 'mensaje' => 'Ese territorio no es de esta campaña.'];

                    continue;
                }
                $indicePorUnidad[$unidad] = $i;
                $finales[$unidad] ??= ['votos_meta' => null, 'lideres_meta' => null, 'votantes_meta' => null];
                foreach (ElectoralCampaignGoal::CAMPOS as $campo) {
                    if (array_key_exists($campo, $item)) {
                        $finales[$unidad][$campo] = $item[$campo] === null ? null : (int) $item[$campo];
                    }
                }
            }

            // A municipio and one of its comunas both with goals.
            $conValor = fn (?array $m) => $m !== null && ($m['votos_meta'] !== null || $m['lideres_meta'] !== null || $m['votantes_meta'] !== null);
            foreach ($filas as $f) {
                if ($f['tipo'] !== 'municipality' || ! $conValor($finales[$f['id']] ?? null)) {
                    continue;
                }
                foreach ($filas as $h) {
                    if ($h['padre_id'] === $f['id'] && $conValor($finales[$h['id']] ?? null)) {
                        $errores[] = ['indice' => $indicePorUnidad[$f['id']] ?? $indicePorUnidad[$h['id']] ?? null, 'campo' => null,
                            'mensaje' => "{$f['nombre']} tiene metas por comuna: deja vacía la meta del municipio o borra las de sus comunas."];
                        break;
                    }
                }
            }
            if ($errores) {
                throw new MetasInvalidas($errores);
            }

            $antes = [];
            $despues = [];
            $cambios = [];
            foreach (array_keys($indicePorUnidad) as $unidad) {
                $previo = $existentes->get($unidad);
                $nuevo = $finales[$unidad];
                foreach (ElectoralCampaignGoal::CAMPOS as $campo) {
                    $v0 = $previo?->{$campo};
                    if ($v0 !== $nuevo[$campo]) {
                        $antes[$unidad][$campo] = $v0;
                        $despues[$unidad][$campo] = $nuevo[$campo];
                        $cambios[] = ['unidad' => $unidad, 'territorio' => $porId[$unidad]['nombre'], 'campo' => $campo, 'antes' => $v0, 'despues' => $nuevo[$campo]];
                    }
                }
                if (! isset($despues[$unidad])) {
                    continue;
                }
                if (! $conValor($nuevo)) {
                    $previo?->delete();
                } elseif ($previo) {
                    $previo->forceFill($nuevo + ['updated_by' => $user->id])->save();
                } else {
                    (new ElectoralCampaignGoal(['electoral_campaign_id' => $c->id, 'geographic_unit_id' => $unidad] + $nuevo))
                        ->forceFill(['updated_by' => $user->id])->save();
                }
            }

            if ($cambios) {
                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => $accion,
                    'entity_type' => 'electoral_campaign',
                    'entity_id' => $c->id,
                    'metadata' => $metadata + ['unidades' => count($despues), 'cambios' => $cambios],
                    'before_state' => $antes,
                    'after_state' => $despues,
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]);
            }

            return count($despues);
        });
    }
}
