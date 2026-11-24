<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\Geo\BarrioNormalizer;
use App\Services\Geo\ComunaCatalog;
use App\Services\Geo\PuestoNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Loads the polling stations of a municipio with comunas (puestos_votacion) from the Registraduría's open list
// (docs/geografia.md, datos.gov.co mv2e-prx5, CC BY-SA 4.0). The comuna is the dataset's; when it is empty, the comuna
// polygon that holds the point; a dataset comuna that the polygon contradicts is kept and reported. Aliases (column
// alias, separated by |) are curated by hand. Upserts by normalized name, never deletes; idempotent. With --dry-run it
// only reads (read-only transaction).
class ImportPuestosVotacion extends Command
{
    protected $signature = 'geo:import-puestos
        {--municipio=68001 : Código DANE del municipio}
        {--archivo= : CSV (departamento, municipio, puesto, comuna, direccion, latitud, longitud, alias)}
        {--dry-run : Solo muestra lo que haría, no guarda nada}';

    protected $description = 'Load the polling stations of a municipio and their comuna';

    public const DEFAULT_FILES = ['68001' => 'data/geo/bucaramanga_puestos_2023.csv'];

    public const FUENTE = 'datos.gov.co mv2e-prx5';

    public const ANIO = 2023;

    private const COLUMNS = ['municipio', 'puesto', 'comuna', 'direccion', 'latitud', 'longitud', 'alias'];

    public function handle(ComunaCatalog $catalog): int
    {
        $code = (string) $this->option('municipio');
        if (! preg_match('/^\d{5}$/', $code)) {
            $this->error('--municipio debe ser un código DANE de 5 dígitos.');

            return self::FAILURE;
        }
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->error('geo:import-puestos necesita PostgreSQL con PostGIS.');

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

        $path = $this->option('archivo') ?: (isset(self::DEFAULT_FILES[$code]) ? database_path(self::DEFAULT_FILES[$code]) : null);
        if (! $path || ! is_file($path)) {
            $this->error('No hay archivo de puestos para '.$code.'. Use --archivo=.');

            return self::FAILURE;
        }

        try {
            $rows = $this->read($path, $municipio->canonical_name);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $comunas = [];
        foreach ($catalog->comunasOf($municipio->id) as $c) {
            $comunas[substr((string) $c['codigo'], strlen($code) + 1)] = $c;
        }

        $dryRun = (bool) $this->option('dry-run');
        $report = ['creados' => 0, 'actualizados' => 0, 'sin_cambio' => 0, 'por_poligono' => 0, 'sin_comuna' => [], 'discrepancias' => [], 'no_estan' => []];

        DB::beginTransaction();
        try {
            if ($dryRun) {
                DB::statement('SET TRANSACTION READ ONLY');
            }
            $existing = DB::table('puestos_votacion')->where('municipio_id', $municipio->id)
                ->get(['id', 'nombre', 'nombre_normalizado', 'comuna_unit_id', 'comuna_origen', 'direccion', 'fuente', 'anio', 'alias',
                    DB::raw('round(ST_X(geom)::numeric, 7)::float AS lon'), DB::raw('round(ST_Y(geom)::numeric, 7)::float AS lat')])
                ->keyBy('nombre_normalizado');

            foreach ($rows as $row) {
                $this->place($row, $municipio->id, $comunas, $report);
                $this->upsert($row, $municipio->id, $existing->get($row['norm']), $dryRun, $report);
            }
            $report['no_estan'] = $existing->keys()->diff(array_column($rows, 'norm'))->values()->all();

            if ($dryRun) {
                DB::rollBack();
            } else {
                AuditLog::create([
                    'user_id' => null,
                    'action' => 'geo.import_puestos',
                    'entity_type' => 'geographic_unit',
                    'entity_id' => $municipio->id,
                    'metadata' => ['command' => 'geo:import-puestos', 'municipio' => $code, 'archivo' => basename($path),
                        'sha256' => hash_file('sha256', $path), 'resumen' => array_map(fn ($v) => is_array($v) ? count($v) : $v, $report)],
                ]);
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->printReport($municipio->canonical_name, $code, count($rows), $report);
        $this->{$dryRun ? 'warn' : 'info'}($dryRun ? 'DRY-RUN: no se guardó nada.' : 'Puestos cargados.');

        return self::SUCCESS;
    }

    // [{nombre, norm, comuna_texto, direccion, lat, lon, alias[]}]; aborts on a bad file before touching the database.
    private function read(string $path, string $municipioName): array
    {
        $handle = fopen($path, 'r');
        $header = array_map(fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\xEF\xBB\xBF")), fgetcsv($handle, null, ',', '"', '') ?: []);
        if ($missing = array_diff(self::COLUMNS, $header)) {
            fclose($handle);
            throw new \InvalidArgumentException('Al CSV le faltan columnas: '.implode(', ', $missing).'.');
        }

        $own = BarrioNormalizer::plain($municipioName);
        $rows = [];
        $names = [];
        $line = 1;
        while (($data = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $line++;
            if ($data === [null]) {
                continue;
            }
            $r = array_combine($header, array_pad($data, count($header), ''));
            if (BarrioNormalizer::plain($r['municipio']) !== $own) {
                fclose($handle);
                throw new \InvalidArgumentException("Línea {$line}: el puesto es de {$r['municipio']}, no de {$municipioName}.");
            }
            $nombre = trim(preg_replace('/\s+/', ' ', (string) $r['puesto']));
            $norm = PuestoNormalizer::normalize($nombre);
            if ($norm === '') {
                fclose($handle);
                throw new \InvalidArgumentException("Línea {$line}: puesto sin nombre.");
            }
            if (isset($names[$norm])) {
                fclose($handle);
                throw new \InvalidArgumentException("Línea {$line}: «{$nombre}» se escribe igual que otro puesto ({$norm}).");
            }
            $lat = is_numeric($r['latitud']) ? (float) $r['latitud'] : null;
            $lon = is_numeric($r['longitud']) ? (float) $r['longitud'] : null;
            if ($lat !== null && $lon !== null && (abs($lat) > 90 || abs($lon) > 180)) {
                $lat = $lon = null;
            }
            $alias = [];
            foreach (array_filter(array_map('trim', explode('|', (string) $r['alias']))) as $a) {
                $an = PuestoNormalizer::normalize($a);
                if ($an !== '' && $an !== $norm && ! in_array($an, array_column($alias, 'norm'), true)) {
                    $alias[] = ['texto' => Str::upper($a), 'norm' => $an];
                }
            }
            $names[$norm] = true;
            $rows[] = ['nombre' => $nombre, 'norm' => $norm, 'comuna_texto' => trim((string) $r['comuna']),
                'direccion' => Str::limit(trim(preg_replace('/\s+/', ' ', (string) $r['direccion'])), 300, '') ?: null,
                'lat' => $lat, 'lon' => $lon, 'alias' => $alias];
        }
        fclose($handle);
        if ($rows === []) {
            throw new \InvalidArgumentException('El CSV no tiene puestos.');
        }

        // An alias is one puesto's only: never another's name or alias.
        $owner = [];
        foreach ($rows as $r) {
            foreach ($r['alias'] as $a) {
                if (isset($names[$a['norm']]) || isset($owner[$a['norm']])) {
                    throw new \InvalidArgumentException("El alias «{$a['texto']}» de «{$r['nombre']}» ya es el nombre o alias de otro puesto.");
                }
                $owner[$a['norm']] = $r['nombre'];
            }
        }

        return $rows;
    }

    // The dataset's comuna ("03COMUNA 3 SAN FRANCISCO" -> C03, "18CORREGIMIENTO 1" -> R1), checked against the polygon.
    private function place(array &$row, string $municipioId, array $comunas, array &$report): void
    {
        $dataset = null;
        if (preg_match('/^\d*\s*(COMUNA|CORREGIMIENTO)\s+(\d{1,2})\b/', BarrioNormalizer::plain($row['comuna_texto']), $m)) {
            $key = $m[1] === 'COMUNA' ? sprintf('C%02d', (int) $m[2]) : 'R'.(int) $m[2];
            $dataset = $comunas[$key] ?? null;
        }

        $polygon = null;
        if ($row['lat'] !== null && $row['lon'] !== null) {
            $id = DB::table('geographic_units')->where('parent_id', $municipioId)->whereIn('type', ComunaCatalog::TYPES)
                ->whereRaw('ST_Contains(geom, ST_SetSRID(ST_MakePoint(?, ?), 4326))', [$row['lon'], $row['lat']])->value('id');
            $polygon = $id ? collect($comunas)->firstWhere('id', $id) : null;
        }

        if ($dataset) {
            $row['comuna_id'] = $dataset['id'];
            $row['comuna_origen'] = 'dataset';
            if ($polygon && $polygon['id'] !== $dataset['id']) {
                $report['discrepancias'][] = "{$row['nombre']}: dataset {$dataset['codigo']}, polígono {$polygon['codigo']}";
            } elseif (! $polygon) {
                $report['discrepancias'][] = "{$row['nombre']}: dataset {$dataset['codigo']}, el punto no cae en ninguna comuna";
            }
        } elseif ($polygon) {
            $row['comuna_id'] = $polygon['id'];
            $row['comuna_origen'] = 'poligono';
            $report['por_poligono']++;
        } else {
            $row['comuna_id'] = null;
            $row['comuna_origen'] = null;
            $report['sin_comuna'][] = $row['nombre'].($row['comuna_texto'] !== '' ? " (dataset: {$row['comuna_texto']})" : '');
        }
    }

    private function upsert(array $row, string $municipioId, ?object $current, bool $dryRun, array &$report): void
    {
        $alias = array_column($row['alias'], 'texto');
        sort($alias);
        $values = [
            'nombre' => $row['nombre'], 'comuna_unit_id' => $row['comuna_id'], 'comuna_origen' => $row['comuna_origen'],
            'direccion' => $row['direccion'], 'fuente' => self::FUENTE, 'anio' => self::ANIO,
            'alias' => $alias === [] ? null : json_encode($alias, JSON_UNESCAPED_UNICODE),
        ];
        $lat = $row['lat'] !== null ? round($row['lat'], 7) : null;
        $lon = $row['lon'] !== null ? round($row['lon'], 7) : null;

        if ($current) {
            $currentAlias = json_decode((string) $current->alias, true) ?: [];
            sort($currentAlias);
            $same = $current->nombre === $values['nombre'] && $current->comuna_unit_id === $values['comuna_unit_id']
                && $current->comuna_origen === $values['comuna_origen'] && $current->direccion === $values['direccion']
                && $current->fuente === $values['fuente'] && (int) $current->anio === $values['anio'] && $currentAlias === $alias
                && ($current->lat === null ? null : (float) $current->lat) === $lat
                && ($current->lon === null ? null : (float) $current->lon) === $lon;
            if ($same) {
                $report['sin_cambio']++;

                return;
            }
            $report['actualizados']++;
            if (! $dryRun) {
                DB::table('puestos_votacion')->where('id', $current->id)->update($values + ['updated_at' => now()]);
                $this->writeGeom($current->id, $lat, $lon);
            }

            return;
        }

        $report['creados']++;
        if (! $dryRun) {
            $id = (string) Str::uuid();
            DB::table('puestos_votacion')->insert($values + ['id' => $id, 'municipio_id' => $municipioId, 'nombre_normalizado' => $row['norm'],
                'created_at' => now(), 'updated_at' => now()]);
            $this->writeGeom($id, $lat, $lon);
        }
    }

    private function writeGeom(string $id, ?float $lat, ?float $lon): void
    {
        if ($lat === null || $lon === null) {
            DB::update('UPDATE puestos_votacion SET geom = NULL WHERE id = ?', [$id]);

            return;
        }
        DB::update('UPDATE puestos_votacion SET geom = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?', [$lon, $lat, $id]);
    }

    private function printReport(string $municipio, string $code, int $total, array $report): void
    {
        $this->info("Municipio: {$municipio} ({$code}). Puestos en el archivo: {$total}.");
        $this->table(['Creados', 'Actualizados', 'Sin cambio', 'Comuna por polígono', 'Sin comuna', 'Discrepancias', 'En la BD y no en el archivo'], [[
            $report['creados'], $report['actualizados'], $report['sin_cambio'], $report['por_poligono'], count($report['sin_comuna']),
            count($report['discrepancias']), count($report['no_estan']),
        ]]);
        foreach (['discrepancias' => 'Discrepancias dataset / polígono (gana el dataset)', 'sin_comuna' => 'Sin comuna',
            'no_estan' => 'En la BD y no en el archivo (no se borran)'] as $key => $label) {
            if ($report[$key] !== []) {
                $this->line("{$label}:");
                foreach ($report[$key] as $text) {
                    $this->line("  - {$text}");
                }
            }
        }
    }
}
