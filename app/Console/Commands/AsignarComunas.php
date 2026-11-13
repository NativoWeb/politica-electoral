<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\Geo\BarrioMatcher;
use App\Services\Geo\ComunaAssigner;
use App\Services\Geo\ComunaCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// Fills comuna_id (source 'auto') of the fichas, event attendees and organizations of a municipio with comunas from
// their barrio text (BarrioMatcher). 'manual' is never overwritten; a comuna of this municipio left on a row of another
// municipio is cleared. With --dry-run it only reads (read-only transaction) and reports what it would do.
class AsignarComunas extends Command
{
    protected $signature = 'geo:asignar-comunas
        {--municipio=68001 : Código DANE del municipio}
        {--tabla=* : lideres, event_attendees u organizaciones (por defecto las tres)}
        {--dry-run : Solo muestra el resultado, no guarda nada}
        {--detalle=40 : Cuántos textos mostrar por estado}';

    protected $description = 'Assign the comuna of fichas, attendees and organizations from their barrio';

    public function handle(ComunaCatalog $catalog, BarrioMatcher $matcher, ComunaAssigner $assigner): int
    {
        $code = (string) $this->option('municipio');
        $tables = $this->option('tabla') ?: array_keys(ComunaAssigner::TABLES);
        if (array_diff($tables, array_keys(ComunaAssigner::TABLES)) !== []) {
            $this->error('--tabla debe ser: '.implode(', ', array_keys(ComunaAssigner::TABLES)).'.');

            return self::FAILURE;
        }

        $municipio = DB::table('geographic_units')->where('type', 'municipality')->where('official_code', $code)->first(['id', 'canonical_name']);
        if (! $municipio) {
            $this->error("No existe el municipio {$code}.");

            return self::FAILURE;
        }
        if (! $catalog->hasComunas($municipio->id)) {
            $this->error("{$municipio->canonical_name} no tiene comunas cargadas: corra antes geo:import-comunas --municipio={$code}.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $summary = [];

        DB::beginTransaction();
        try {
            if ($dryRun && DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION READ ONLY');
            }

            foreach ($tables as $table) {
                $summary[$table] = $this->assignTable($table, $municipio->id, $catalog, $matcher, $assigner, $dryRun);
            }

            if ($dryRun) {
                DB::rollBack();
            } else {
                AuditLog::create([
                    'user_id' => null,
                    'action' => 'geo.asignar_comunas',
                    'entity_type' => 'geographic_unit',
                    'entity_id' => $municipio->id,
                    'metadata' => ['command' => 'geo:asignar-comunas', 'municipio' => $code,
                        'resumen' => array_map(fn ($s) => $s['counts'], $summary)],
                ]);
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->printSummary($municipio->canonical_name, $summary, (int) $this->option('detalle'));
        $this->{$dryRun ? 'warn' : 'info'}($dryRun ? 'DRY-RUN: no se guardó nada.' : 'Comunas asignadas.');

        return self::SUCCESS;
    }

    private function assignTable(string $table, string $municipioId, ComunaCatalog $catalog, BarrioMatcher $matcher, ComunaAssigner $assigner, bool $dryRun): array
    {
        $municipioSql = ComunaAssigner::TABLES[$table];
        $comunaIds = array_column($catalog->comunasOf($municipioId), 'id');
        $counts = array_fill_keys(['registros', 'asignadas', 'cambian', 'sin_cambio', 'manual', BarrioMatcher::AMBIGUO,
            BarrioMatcher::SIN_MATCH, BarrioMatcher::OTRO_MUNICIPIO, BarrioMatcher::VACIO, 'limpiadas_otro_municipio'], 0);
        $texts = [];
        $updates = [];

        $rows = DB::table($table)->whereRaw("{$municipioSql} = ?", [$municipioId])
            ->orderBy('id')
            ->get(['id', 'campaign_id', 'barrio', 'comuna_id', 'comuna_source']);

        foreach ($rows as $row) {
            $counts['registros']++;
            if ($row->comuna_source === 'manual' && ($row->comuna_id === null || in_array($row->comuna_id, $comunaIds, true))) {
                $counts['manual']++;

                continue;
            }

            $match = $matcher->match($municipioId, $row->campaign_id, $row->barrio);
            if ($match['status'] === BarrioMatcher::ASIGNADO) {
                $counts['asignadas']++;
            } else {
                $counts[$match['status']]++;
                if ($match['status'] !== BarrioMatcher::VACIO) {
                    $texts[$match['status']][$match['texto']] = ($texts[$match['status']][$match['texto']] ?? 0) + 1;
                }
            }

            $changes = $assigner->changesFor($municipioId, $row->campaign_id, $row->barrio, $row->comuna_id, $row->comuna_source);
            if ($changes === null) {
                $counts['sin_cambio']++;

                continue;
            }
            $counts['cambian']++;
            $updates[($changes['comuna_id'] ?? '').'|'.($changes['comuna_source'] ?? '')][] = $row->id;
        }

        // A comuna of this municipio on a row that is now somewhere else.
        $stale = DB::table($table)->whereIn('comuna_id', $comunaIds)
            ->whereRaw("({$municipioSql} IS DISTINCT FROM ?)", [$municipioId])->pluck('id')->all();
        $counts['limpiadas_otro_municipio'] = count($stale);

        if (! $dryRun) {
            foreach ($updates as $key => $ids) {
                [$comunaId, $source] = explode('|', $key);
                foreach (array_chunk($ids, 500) as $chunk) {
                    DB::table($table)->whereIn('id', $chunk)->update(['comuna_id' => $comunaId ?: null, 'comuna_source' => $source ?: null]);
                }
            }
            foreach (array_chunk($stale, 500) as $chunk) {
                DB::table($table)->whereIn('id', $chunk)->update(['comuna_id' => null, 'comuna_source' => null]);
            }
        }

        return ['counts' => $counts, 'texts' => $texts];
    }

    private function printSummary(string $municipio, array $summary, int $detail): void
    {
        $this->info("Municipio: {$municipio}.");
        $this->table(
            ['Tabla', 'Registros', 'Con comuna', 'Cambian', 'Sin cambio', 'Manual (respetada)', 'Ambiguos', 'Sin match', 'Otro municipio', 'Sin barrio', 'Limpiadas (otro mpio)'],
            array_map(fn ($table, $s) => [
                $table, $s['counts']['registros'], $s['counts']['asignadas'], $s['counts']['cambian'], $s['counts']['sin_cambio'],
                $s['counts']['manual'], $s['counts'][BarrioMatcher::AMBIGUO], $s['counts'][BarrioMatcher::SIN_MATCH],
                $s['counts'][BarrioMatcher::OTRO_MUNICIPIO], $s['counts'][BarrioMatcher::VACIO], $s['counts']['limpiadas_otro_municipio'],
            ], array_keys($summary), $summary)
        );

        $labels = [BarrioMatcher::AMBIGUO => 'Ambiguos', BarrioMatcher::SIN_MATCH => 'Sin match', BarrioMatcher::OTRO_MUNICIPIO => 'Otro municipio'];
        foreach ($labels as $status => $label) {
            $merged = [];
            foreach ($summary as $s) {
                foreach ($s['texts'][$status] ?? [] as $text => $n) {
                    $merged[$text] = ($merged[$text] ?? 0) + $n;
                }
            }
            if ($merged === []) {
                continue;
            }
            arsort($merged);
            $this->line("{$label} (texto normalizado: registros):");
            foreach (array_slice($merged, 0, $detail, true) as $text => $n) {
                $this->line("  - {$text}: {$n}");
            }
        }
    }
}
