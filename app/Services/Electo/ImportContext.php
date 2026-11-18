<?php

namespace App\Services\Electo;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// State shared by the parts of an Electo import: the folder, the target campaign, the DIVIPOLA lookup, the Electo
// catalogs and the lineage table (electo_import_rows) that maps every Electo id to the local row it became.
class ImportContext
{
    public const ORIGEN = 'electo';

    private ?array $municipios = null;

    private ?array $departamentos = null;

    private array $catalogs = [];

    public function __construct(
        public readonly string $dir,
        public readonly string $campaignId,
        public readonly int $batchSize,
        public readonly bool $dryRun,
        public readonly ImportReport $report,
        public readonly CarbonImmutable $today,
        private readonly ?\Closure $output = null,
    ) {}

    public function info(string $message): void
    {
        if ($this->output) {
            ($this->output)($message);
        }
    }

    public function file(string $table, bool $required = true): ?string
    {
        $path = CsvReader::find($this->dir, $table);
        if ($path === null && $required) {
            throw new \InvalidArgumentException("No está el archivo {$table}_*.csv en la carpeta.");
        }

        return $path;
    }

    // Streams a CSV in arrays of $batchSize rows.
    public function batches(string $table): \Generator
    {
        $batch = [];
        foreach (CsvReader::rows($this->file($table)) as $row) {
            $batch[] = $row;
            if (count($batch) >= $this->batchSize) {
                yield $batch;
                $batch = [];
            }
        }
        if ($batch !== []) {
            yield $batch;
        }
    }

    // A real run commits every batch on its own (resumable); a dry run is one outer transaction rolled back at the end.
    public function transaction(\Closure $work): mixed
    {
        return $this->dryRun ? $work() : DB::transaction($work);
    }

    // Fresh planner statistics after a bulk load. Inside the dry run's transaction autovacuum cannot see the new rows
    // and set-based UPDATEs would be planned for a table of a few rows; ANALYZE does count the rows of its own
    // transaction. Only tables the app user owns (data_consents is not).
    public function analyze(array $tables): void
    {
        foreach ($tables as $table) {
            DB::statement("ANALYZE {$table}");
        }
    }

    // DIVIPOLA municipio code => {id, name, provincia, departamento}.
    public function municipio(?string $code): ?array
    {
        if ($code === null) {
            return null;
        }
        if ($this->municipios === null) {
            $this->municipios = [];
            $rows = DB::select("SELECT m.id, m.official_code, m.canonical_name,
                    CASE WHEN p.type = 'province' THEN p.canonical_name END AS provincia,
                    CASE WHEN p.type = 'department' THEN p.canonical_name ELSE gp.canonical_name END AS departamento
                FROM geographic_units m
                LEFT JOIN geographic_units p ON p.id = m.parent_id
                LEFT JOIN geographic_units gp ON gp.id = p.parent_id
                WHERE m.type = 'municipality' AND m.official_code IS NOT NULL");
            foreach ($rows as $r) {
                $this->municipios[$r->official_code] = ['id' => $r->id, 'name' => $r->canonical_name, 'provincia' => $r->provincia, 'departamento' => $r->departamento];
            }
        }

        return $this->municipios[$code] ?? null;
    }

    public function departamento(?string $code): ?string
    {
        $code = trim((string) $code);
        if (! preg_match('/^\d{1,2}$/', $code)) {
            return null;
        }
        $this->departamentos ??= DB::table('geographic_units')->where('type', 'department')->whereNotNull('official_code')
            ->pluck('canonical_name', 'official_code')->all();

        return $this->departamentos[str_pad($code, 2, '0', STR_PAD_LEFT)] ?? null;
    }

    public function municipiosLoaded(): int
    {
        return DB::table('geographic_units')->where('type', 'municipality')->whereNotNull('official_code')->count();
    }

    // Electo catalog id => nombre (profesiones, escolaridad, estadocivil, partidos_politicos).
    public function catalog(string $table): array
    {
        if (! isset($this->catalogs[$table])) {
            $this->catalogs[$table] = [];
            $path = $this->file($table, false);
            if ($path === null) {
                $this->report->aviso("Falta el catálogo {$table}: esos campos quedan vacíos.");
            } else {
                foreach (CsvReader::rows($path) as $row) {
                    $this->catalogs[$table][trim($row['id'])] = $row['nombre'];
                }
            }
        }

        return $this->catalogs[$table];
    }

    // [electo id => local id] of the records already created or merged by a previous (or this) run.
    public function lineage(string $entidad, array $ids): array
    {
        $map = [];
        foreach (array_chunk(array_values(array_unique(array_filter($ids, fn ($id) => $id !== null))), 5000) as $chunk) {
            foreach (DB::table('electo_import_rows')->where('entidad', $entidad)->whereIn('origen_id', $chunk)
                ->whereIn('accion', ['creado', 'fusionado'])->get(['origen_id', 'local_id']) as $r) {
                $map[(int) $r->origen_id] = $r->local_id;
            }
        }

        return $map;
    }

    // $rows: [[origen_id, local_id|null, accion, motivo|null], ...]. Re-running replaces an earlier "omitido".
    public function record(string $entidad, array $rows): void
    {
        $now = now();
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('electo_import_rows')->upsert(array_map(fn ($r) => [
                'entidad' => $entidad, 'origen_id' => $r[0], 'local_id' => $r[1], 'accion' => $r[2], 'motivo' => $r[3],
                'run_id' => $this->report->runId, 'created_at' => $now, 'updated_at' => $now,
            ], $chunk), ['entidad', 'origen_id'], ['local_id', 'accion', 'motivo', 'run_id', 'updated_at']);
        }
        foreach ($rows as $r) {
            $this->report->count($entidad, $r[2], $r[3]);
        }
    }

    public static function json(array $value): ?string
    {
        $value = array_filter($value, fn ($v) => $v !== null && $v !== [] && $v !== '');

        return $value === [] ? null : json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    public static function cut(?string $value, int $max): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $max);
    }
}
