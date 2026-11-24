<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\Geo\BarrioMatcher;
use App\Services\Geo\ComunaAssigner;
use App\Services\Geo\ComunaCatalog;
use App\Services\Geo\PuestoMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Fills comuna_id / comuna_source of the fichas, event attendees and organizations of a municipio with comunas with
// the cascade of ComunaAssigner (barrio > "COMUNA N" written > polling station). 'manual' is never overwritten; a
// comuna of this municipio left on a row of another municipio is cleared. Every change is kept in comuna_cambios under
// one batch (lote) that geo:revertir-comunas undoes; a run without changes creates no batch. Rows are read in pages of
// 500. With --dry-run it only reads (read-only transaction) and reports what it would do.
class AsignarComunas extends Command
{
    protected $signature = 'geo:asignar-comunas
        {--municipio=68001 : Código DANE del municipio}
        {--tabla=* : lideres, event_attendees u organizaciones (por defecto las tres)}
        {--dry-run : Solo muestra el resultado, no guarda nada}
        {--detalle=40 : Cuántos textos mostrar por estado}';

    protected $description = 'Assign the comuna of fichas, attendees and organizations (barrio, written comuna, polling station)';

    public const PAGE = 500;

    // comuna_source => counter of the summary; "direccion" would be masked as personal data in the audit log.
    private const ORIGINS = ['auto' => 'auto', 'texto' => 'texto', 'direccion' => 'por_direccion', 'puesto' => 'puesto'];

    private ?string $lote = null;

    private int $skipped = 0;

    public function handle(ComunaCatalog $catalog, ComunaAssigner $assigner): int
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
        $this->lote = null;
        $this->skipped = 0;

        DB::beginTransaction();
        try {
            if ($dryRun && DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION READ ONLY');
            }

            foreach ($tables as $table) {
                $summary[$table] = $this->assignTable($table, $municipio->id, $catalog, $assigner, $dryRun);
            }

            if ($dryRun) {
                DB::rollBack();
            } else {
                AuditLog::create([
                    'user_id' => null,
                    'action' => 'geo.asignar_comunas',
                    'entity_type' => 'geographic_unit',
                    'entity_id' => $municipio->id,
                    'metadata' => ['command' => 'geo:asignar-comunas', 'municipio' => $code, 'lote' => $this->lote, 'omitidos' => $this->skipped,
                        'resumen' => array_map(fn ($s) => $s['counts'], $summary)],
                ]);
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->printSummary($municipio->canonical_name, $summary, (int) $this->option('detalle'));
        if ($this->skipped > 0) {
            $this->warn("{$this->skipped} registros cambiaron mientras se leían: no se tocaron (la próxima corrida los revisa).");
        }
        if ($dryRun) {
            $this->warn('DRY-RUN: no se guardó nada.');
        } elseif ($this->lote) {
            $this->info("Comunas asignadas. Lote: {$this->lote} (geo:revertir-comunas --lote={$this->lote} lo deshace).");
        } else {
            $this->info('Sin cambios: no se creó lote.');
        }

        return self::SUCCESS;
    }

    private function assignTable(string $table, string $municipioId, ComunaCatalog $catalog, ComunaAssigner $assigner, bool $dryRun): array
    {
        $municipioSql = ComunaAssigner::TABLES[$table];
        $comunaIds = array_column($catalog->comunasOf($municipioId), 'id');
        $signals = $assigner->signalColumns($table);
        $counts = array_fill_keys(['registros', 'manual', ...array_values(self::ORIGINS), 'sin_senal', 'cambian', 'sin_cambio',
            'puesto_ambiguo', 'puesto_sin_match', 'puesto_otro_municipio', 'limpiadas_otro_municipio'], 0);
        $transitions = [];
        $texts = [];

        DB::table($table)->whereRaw("{$municipioSql} = ?", [$municipioId])
            ->select(['id', 'campaign_id', 'barrio', 'comuna_id', 'comuna_source', ...$signals])
            ->chunkById(self::PAGE, function ($rows) use ($table, $municipioId, $comunaIds, $signals, $assigner, $dryRun, &$counts, &$transitions, &$texts) {
                $changes = [];
                foreach ($rows as $row) {
                    $counts['registros']++;
                    if ($row->comuna_source === 'manual' && ($row->comuna_id === null || in_array($row->comuna_id, $comunaIds, true))) {
                        $counts['manual']++;

                        continue;
                    }

                    $best = $assigner->resolve($municipioId, $row->campaign_id, $row->barrio, ComunaAssigner::signalsOf($row, $signals));
                    $counts[self::ORIGINS[$best['comuna_source'] ?? ''] ?? 'sin_senal']++;
                    $this->collectTexts($best, $counts, $texts);

                    if ($best['comuna_id'] === $row->comuna_id && $best['comuna_source'] === $row->comuna_source) {
                        $counts['sin_cambio']++;

                        continue;
                    }
                    $counts['cambian']++;
                    $key = ($row->comuna_source ?? 'sin comuna').' → '.($best['comuna_source'] ?? 'sin comuna');
                    $transitions[$key] = ($transitions[$key] ?? 0) + 1;
                    $changes[] = [$row, $best['comuna_id'], $best['comuna_source']];
                }
                if (! $dryRun) {
                    $this->write($table, $changes);
                }
            }, 'id');

        // A comuna of this municipio on a row that is now somewhere else.
        $stale = DB::table($table)->whereIn('comuna_id', $comunaIds)
            ->whereRaw("({$municipioSql} IS DISTINCT FROM ?)", [$municipioId])
            ->orderBy('id')->get(['id', 'comuna_id', 'comuna_source']);
        $counts['limpiadas_otro_municipio'] = $stale->count();
        if (! $dryRun) {
            foreach ($stale->chunk(self::PAGE) as $chunk) {
                $this->write($table, $chunk->map(fn ($row) => [$row, null, null])->all());
            }
        }

        return ['counts' => $counts, 'texts' => $texts, 'transitions' => $transitions];
    }

    // Applies the changes of one page, each only if the row still has the comuna and origin that were read (a ficha
    // changed meanwhile, by hand or otherwise, is left alone), and records in comuna_cambios only the rows that really
    // changed, under this run's batch.
    private function write(string $table, array $changes): void
    {
        $applied = [];
        foreach ($changes as [$row, $comunaId, $source]) {
            $n = DB::table($table)->where('id', $row->id)
                ->where(fn ($q) => $row->comuna_id === null ? $q->whereNull('comuna_id') : $q->where('comuna_id', $row->comuna_id))
                ->where(fn ($q) => $row->comuna_source === null ? $q->whereNull('comuna_source') : $q->where('comuna_source', $row->comuna_source))
                ->update(['comuna_id' => $comunaId, 'comuna_source' => $source]);
            if ($n === 1) {
                $applied[] = [$row, $comunaId, $source];
            } else {
                $this->skipped++;
            }
        }
        if ($applied === []) {
            return;
        }
        $this->lote ??= (string) Str::uuid();
        $now = now();

        DB::table('comuna_cambios')->insert(array_map(fn ($c) => [
            'lote' => $this->lote, 'tabla' => $table, 'registro_id' => $c[0]->id,
            'antes_comuna_id' => $c[0]->comuna_id, 'antes_source' => $c[0]->comuna_source,
            'despues_comuna_id' => $c[1], 'despues_source' => $c[2], 'created_at' => $now,
        ], $applied));
    }

    private function collectTexts(array $best, array &$counts, array &$texts): void
    {
        $barrio = $best['barrio'];
        // Only what no signal settled: a barrio that did not match but whose comuna came from text or puesto is not pending.
        if ($best['comuna_source'] === null && ! in_array($barrio['status'], [BarrioMatcher::ASIGNADO, BarrioMatcher::VACIO], true)) {
            $texts['barrio_'.$barrio['status']][$barrio['texto']] = ($texts['barrio_'.$barrio['status']][$barrio['texto']] ?? 0) + 1;
        }
        if ($best['comuna_source'] !== null && $best['comuna_source'] !== 'puesto') {
            return;
        }
        if ($best['puesto_motivo'] === 'vota_en_otro_municipio') {
            $counts['puesto_otro_municipio']++;
        }
        $p = $best['puesto'];
        if ($p && in_array($p['status'], [PuestoMatcher::AMBIGUO, PuestoMatcher::SIN_MATCH], true)) {
            $counts['puesto_'.$p['status']]++;
            $texts['puesto_'.$p['status']][$p['texto']] = ($texts['puesto_'.$p['status']][$p['texto']] ?? 0) + 1;
        }
    }

    private function printSummary(string $municipio, array $summary, int $detail): void
    {
        $this->info("Municipio: {$municipio}.");
        $this->table(
            ['Tabla', 'Registros', 'Manual (respetada)', 'Por barrio', 'Por texto', 'Por puesto', 'Sin señal', 'Cambian', 'Sin cambio', 'Limpiadas (otro mpio)'],
            array_map(fn ($table, $s) => [
                $table, $s['counts']['registros'], $s['counts']['manual'], $s['counts']['auto'], $s['counts']['texto'],
                $s['counts']['puesto'], $s['counts']['sin_senal'], $s['counts']['cambian'], $s['counts']['sin_cambio'],
                $s['counts']['limpiadas_otro_municipio'],
            ], array_keys($summary), $summary)
        );
        $lideres = $summary['lideres']['counts'] ?? null;
        if ($lideres) {
            $this->line("Puesto de votación sin usar (fichas): ambiguo {$lideres['puesto_ambiguo']}, sin coincidencia {$lideres['puesto_sin_match']}, "
                ."vota en otro municipio o sin municipio de votación {$lideres['puesto_otro_municipio']}.");
        }

        $transitions = [];
        foreach ($summary as $s) {
            foreach ($s['transitions'] as $key => $n) {
                $transitions[$key] = ($transitions[$key] ?? 0) + $n;
            }
        }
        if ($transitions !== []) {
            arsort($transitions);
            $this->line('Cambios por origen (antes → después):');
            foreach ($transitions as $key => $n) {
                $this->line("  - {$key}: {$n}");
            }
        }

        $labels = [
            'barrio_'.BarrioMatcher::AMBIGUO => 'Ambiguos', 'barrio_'.BarrioMatcher::SIN_MATCH => 'Sin match',
            'barrio_'.BarrioMatcher::OTRO_MUNICIPIO => 'Otro municipio',
            'puesto_'.PuestoMatcher::AMBIGUO => 'Puesto ambiguo', 'puesto_'.PuestoMatcher::SIN_MATCH => 'Puesto sin match',
        ];
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
