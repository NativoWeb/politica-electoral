<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\Geo\BarrioMatcher;
use App\Services\Geo\BarrioNormalizer;
use App\Services\Geo\ComunaAssigner;
use App\Services\Geo\ComunaCatalog;
use App\Services\Geo\ComunasGeoJson;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Loads the comunas and corregimientos of a municipio (geographic_units below it, official_code '68001-C01'...
// '68001-R1') and its barrios/veredas (docs/geografia.md). Barrio sources, by priority: the AMB polygons (their comuna
// is the one that holds most of the polygon, whatever the layer's COD_COMUNA says), then the Planeación list, then
// datos.gov.co (which also gives the 2024 population); a later source never changes the comuna of a barrio an earlier
// one placed, it is reported as a discrepancy. Upserts by code / normalized name, never deletes and never replaces a
// geometry with NULL; with --dry-run it only reads (read-only transaction).
class ImportGeoComunas extends Command
{
    protected $signature = 'geo:import-comunas
        {--municipio=68001 : Código DANE del municipio}
        {--comunas= : GeoJSON de comunas y corregimientos (EPSG:4326)}
        {--barrios= : GeoJSON de barrios del AMB (EPSG:4326)}
        {--planeacion= : CSV de Planeación (comuna_o_corregimiento, barrio_o_vereda)}
        {--datosgov= : CSV de datos.gov.co (comuna, barrio, categoria, personas_2024)}
        {--dry-run : Solo muestra lo que haría, no guarda nada}';

    protected $description = 'Load the comunas, corregimientos and barrios of a municipio';

    // ~11 m: comunas are small, the map draws this copy.
    public const SIMPLIFY_TOLERANCE = 0.0001;

    // Below this share of its area inside one comuna, a barrio polygon is reported as split between comunas.
    private const SPLIT_WARNING = 0.6;

    // Below this share the polygon does not decide the comuna (it lies mostly outside every comuna): the layer's
    // COD_COMUNA does, or else the next source.
    private const MIN_SHARE = 0.2;

    public const DEFAULT_FILES = [
        '68001' => [
            'comunas' => 'data/geo/bucaramanga_comunas.geojson',
            'barrios' => 'data/geo/bucaramanga_barrios_amb.geojson',
            'planeacion' => 'data/geo/bucaramanga_barrios_planeacion.csv',
            'datosgov' => 'data/geo/bucaramanga_barrios_datosgov.csv',
        ],
    ];

    private array $report = ['discrepancias' => [], 'divididos' => [], 'sin_comuna' => [], 'codigos_desconocidos' => []];

    public function handle(): int
    {
        $code = (string) $this->option('municipio');
        if (! preg_match('/^\d{5}$/', $code)) {
            $this->error('--municipio debe ser un código DANE de 5 dígitos.');

            return self::FAILURE;
        }

        try {
            $files = $this->files($code);
            $comunas = $this->readComunas($files['comunas']);
            $amb = $files['barrios'] ? $this->readAmbBarrios($files['barrios'], $code) : [];
            $planeacion = $files['planeacion'] ? $this->readPlaneacion($files['planeacion']) : [];
            $datosgov = $files['datosgov'] ? $this->readDatosGov($files['datosgov']) : [];
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->error('geo:import-comunas necesita PostgreSQL con PostGIS.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        DB::beginTransaction();
        try {
            if ($dryRun) {
                DB::statement('SET TRANSACTION READ ONLY');
            }

            $municipio = DB::selectOne(
                "SELECT id, canonical_name, (geom IS NOT NULL) AS has_geom FROM geographic_units WHERE type = 'municipality' AND official_code = ?",
                [$code]
            );
            if (! $municipio || ! $municipio->has_geom) {
                DB::rollBack();
                $this->error($municipio
                    ? "{$municipio->canonical_name} ({$code}) no tiene polígono: cargue antes geo:import-municipios."
                    : "No existe el municipio {$code} en geographic_units.");

                return self::FAILURE;
            }

            $problems = $this->checkComunas($comunas, $municipio->id, $code);
            if ($problems !== []) {
                DB::rollBack();
                foreach ($problems as $problem) {
                    $this->error($problem);
                }
                $this->error('Las comunas no son válidas: no se guardó nada.');

                return self::FAILURE;
            }

            $barrios = $this->planBarrios($comunas, $amb, $planeacion, $datosgov);
            $existing = $this->existing($municipio->id, $code);
            $this->printPlan($municipio, $code, $comunas, $barrios, $existing);

            if ($dryRun) {
                DB::rollBack();
                $this->warn('DRY-RUN: no se guardó nada.');

                return self::SUCCESS;
            }

            $ids = $this->applyComunas($comunas, $municipio->id, $code, $datosgov);
            $this->applyBarrios($barrios, $municipio->id, $ids);

            $problems = $this->verify($municipio->id);
            if ($problems !== []) {
                DB::rollBack();
                foreach ($problems as $problem) {
                    $this->error($problem);
                }
                $this->error('La verificación falló: no se guardó nada.');

                return self::FAILURE;
            }

            AuditLog::create([
                'user_id' => null,
                'action' => 'geo.import_comunas',
                'entity_type' => 'geographic_unit',
                'entity_id' => $municipio->id,
                'metadata' => [
                    'command' => 'geo:import-comunas',
                    'municipio' => $code,
                    'files' => array_map(fn ($f) => $f ? ['file' => basename($f), 'sha256' => hash_file('sha256', $f)] : null, $files),
                    'comunas' => count(array_filter($comunas, fn ($c) => $c['tipo'] === 'comuna')),
                    'corregimientos' => count(array_filter($comunas, fn ($c) => $c['tipo'] === 'corregimiento')),
                    'barrios' => count($barrios),
                    'discrepancias' => count($this->report['discrepancias']),
                ],
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        ComunasGeoJson::flush($code);
        foreach ([ComunaCatalog::class, BarrioMatcher::class, ComunaAssigner::class] as $service) {
            app()->forgetInstance($service);
        }
        $this->info(sprintf('Cargados en %s: %d comunas, %d corregimientos y %d barrios/veredas.',
            $municipio->canonical_name,
            count(array_filter($comunas, fn ($c) => $c['tipo'] === 'comuna')),
            count(array_filter($comunas, fn ($c) => $c['tipo'] === 'corregimiento')),
            count($barrios)));
        $this->line('Siguiente paso: php artisan geo:asignar-comunas --municipio='.$code.' --dry-run');

        return self::SUCCESS;
    }

    private function files(string $code): array
    {
        $defaults = self::DEFAULT_FILES[$code] ?? null;
        $files = [];
        foreach (['comunas', 'barrios', 'planeacion', 'datosgov'] as $key) {
            $given = $this->option($key);
            $files[$key] = $given ?: ($defaults ? database_path($defaults[$key]) : null);
            if ($files[$key] !== null && (! is_file($files[$key]) || ! is_readable($files[$key]))) {
                throw new \InvalidArgumentException("No se puede leer el archivo {$files[$key]}.");
            }
        }
        if (! $files['comunas']) {
            throw new \InvalidArgumentException("No hay archivos por defecto para {$code}: indique --comunas (y --barrios, --planeacion, --datosgov).");
        }

        return $files;
    }

    private function readGeoJson(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ($data['type'] ?? null) !== 'FeatureCollection' || ! is_array($data['features'] ?? null)) {
            throw new \InvalidArgumentException(basename($path).' no es un GeoJSON FeatureCollection.');
        }

        return $data['features'];
    }

    // [code => ['code' => 'C01', 'tipo', 'numero', 'nombre', 'poblacion', 'veredas', 'fuente', 'geometry']]
    private function readComunas(string $path): array
    {
        $comunas = [];
        foreach ($this->readGeoJson($path) as $i => $feature) {
            $p = $feature['properties'] ?? [];
            $tipo = $p['tipo'] ?? null;
            $code = strtoupper(trim((string) ($p['codigo'] ?? '')));
            $type = $feature['geometry']['type'] ?? null;
            $valid = in_array($tipo, ComunaCatalog::TYPES, true)
                && preg_match($tipo === 'comuna' ? '/^C\d{2}$/' : '/^R\d{1,2}$/', $code)
                && trim((string) ($p['nombre'] ?? '')) !== ''
                && in_array($type, ['Polygon', 'MultiPolygon'], true);
            if (! $valid) {
                throw new \InvalidArgumentException('El feature #'.($i + 1).' de '.basename($path).' no tiene tipo comuna/corregimiento, código (C01, R1...), nombre o polígono.');
            }
            if (isset($comunas[$code])) {
                throw new \InvalidArgumentException("El código {$code} está repetido en ".basename($path).'.');
            }
            $comunas[$code] = [
                'code' => $code,
                'tipo' => $tipo,
                'numero' => (int) ($p['numero'] ?? substr($code, 1)),
                'nombre' => trim($p['nombre']),
                'poblacion' => isset($p['poblacion_fuente']) ? (int) $p['poblacion_fuente'] : null,
                'veredas' => isset($p['veredas']) ? array_values(array_filter(array_map('trim', explode(',', (string) $p['veredas'])))) : null,
                'fuente' => $p['fuente'] ?? null,
                'geometry' => json_encode($feature['geometry']),
            ];
        }
        if ($comunas === []) {
            throw new \InvalidArgumentException(basename($path).' no trae comunas.');
        }
        ksort($comunas);

        return $comunas;
    }

    // [norm => ['nombre', 'attr' => 'C01'|null, 'geometries' => [json...]]] of the features of the municipio.
    private function readAmbBarrios(string $path, string $code): array
    {
        $barrios = [];
        foreach ($this->readGeoJson($path) as $i => $feature) {
            $p = $feature['properties'] ?? [];
            if (($p['COD_DEPART'] ?? null).($p['COD_MUNICI'] ?? null) !== $code) {
                continue;
            }
            $name = trim((string) ($p['NOMBRE_BAR'] ?? ''));
            $type = $feature['geometry']['type'] ?? null;
            if ($name === '' || ! in_array($type, ['Polygon', 'MultiPolygon'], true)) {
                throw new \InvalidArgumentException('El barrio #'.($i + 1).' de '.basename($path).' no tiene nombre o polígono.');
            }
            $norm = BarrioNormalizer::normalize($name);
            $attr = trim((string) ($p['COD_COMUNA'] ?? ''));
            $barrios[$norm] ??= ['nombre' => $name, 'attr' => ctype_digit($attr) ? sprintf('C%02d', (int) $attr) : null, 'geometries' => []];
            $barrios[$norm]['geometries'][] = json_encode($feature['geometry']);
        }

        return $barrios;
    }

    private function readCsv(string $path, array $required): array
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, escape: '\\');
        $header = array_map(fn ($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)), $header ?: []);
        if (array_diff($required, $header) !== []) {
            fclose($handle);
            throw new \InvalidArgumentException(basename($path).' debe tener las columnas '.implode(', ', $required).'.');
        }
        $rows = [];
        while (($line = fgetcsv($handle, escape: '\\')) !== false) {
            if ($line === [null]) {
                continue;
            }
            $rows[] = array_combine($header, array_pad($line, count($header), null));
        }
        fclose($handle);

        return $rows;
    }

    // [['nombre', 'code']]: "1".."17" are comunas, "Corregimiento N" corregimientos.
    private function readPlaneacion(string $path): array
    {
        return array_map(function ($row) {
            $where = trim((string) $row['comuna_o_corregimiento']);

            return [
                'nombre' => trim(preg_replace('/\s*\(.*?\)\s*/', ' ', (string) $row['barrio_o_vereda'])),
                'code' => ctype_digit($where) ? sprintf('C%02d', (int) $where)
                    : (preg_match('/^corregimiento\s+(\d+)$/i', $where, $m) ? 'R'.(int) $m[1] : null),
                'origen' => $where,
            ];
        }, $this->readCsv($path, ['comuna_o_corregimiento', 'barrio_o_vereda']));
    }

    // [['nombre', 'code', 'poblacion']]: "01. NORTE" is comuna 1, "CORREG. 1" corregimiento 1.
    private function readDatosGov(string $path): array
    {
        return array_map(function ($row) {
            $where = trim((string) $row['comuna']);

            return [
                'nombre' => trim((string) $row['barrio']),
                'code' => preg_match('/^(\d{1,2})\./', $where, $m) ? sprintf('C%02d', (int) $m[1])
                    : (preg_match('/^CORREG\.?\s*(\d+)/i', $where, $m) ? 'R'.(int) $m[1] : null),
                'poblacion' => is_numeric($row['personas_2024'] ?? null) ? (int) $row['personas_2024'] : null,
                'origen' => $where,
            ];
        }, $this->readCsv($path, ['comuna', 'barrio', 'personas_2024']));
    }

    // Repaired, polygons only, MultiPolygon, SRID 4326.
    private static function geomSql(string $expr): string
    {
        return "ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_SetSRID({$expr}, 4326)), 3))";
    }

    // Each comuna: valid and non-empty after repair, inside the municipio (point on surface), code free.
    private function checkComunas(array $comunas, string $municipioId, string $code): array
    {
        $rows = DB::select(
            'WITH c AS (
                SELECT f.code, '.self::geomSql('ST_GeomFromGeoJSON(f.geometry)').' AS geom
                FROM json_to_recordset(?::json) AS f(code text, geometry text)
            )
            SELECT c.code, ST_IsValid(c.geom) AS valid, ST_IsEmpty(c.geom) AS empty,
                   ST_Contains(m.geom, ST_PointOnSurface(c.geom)) AS inside
            FROM c, geographic_units m WHERE m.id = ?',
            [json_encode(array_values(array_map(fn ($c) => ['code' => $c['code'], 'geometry' => $c['geometry']], $comunas))), $municipioId]
        );

        $problems = [];
        foreach ($rows as $row) {
            if (! $row->valid || $row->empty) {
                $problems[] = "Geometría vacía o inválida tras ST_MakeValid: {$row->code}";
            } elseif (! $row->inside) {
                $problems[] = "La comuna {$row->code} queda fuera del polígono del municipio.";
            }
        }

        $taken = DB::select(
            "SELECT official_code, type, parent_id FROM geographic_units
             WHERE official_code = ANY(?::text[]) AND (parent_id IS DISTINCT FROM ? OR type NOT IN ('comuna', 'corregimiento'))",
            ['{'.implode(',', array_map(fn ($c) => "{$code}-{$c['code']}", $comunas)).'}', $municipioId]
        );
        foreach ($taken as $row) {
            $problems[] = "El código {$row->official_code} ya lo tiene otra unidad ({$row->type}) que no es de este municipio.";
        }

        return $problems;
    }

    // [norm => ['nombre', 'code', 'fuente', 'geometry' => json|null, 'poblacion' => ?int]]
    private function planBarrios(array $comunas, array $amb, array $planeacion, array $datosgov): array
    {
        $barrios = [];

        if ($amb !== []) {
            $geometric = $this->geometricComunas($comunas, $amb);
            foreach ($amb as $norm => $b) {
                $geo = $geometric[$norm] ?? null;
                if ($geo && $geo['pct'] < self::MIN_SHARE * 100) {
                    $this->report['divididos'][] = "{$b['nombre']}: solo {$geo['pct']}% dentro de una comuna ({$geo['code']}), la geometría no decide";
                    $geo = null;
                } elseif ($geo && $geo['pct'] < self::SPLIT_WARNING * 100) {
                    $this->report['divididos'][] = "{$b['nombre']}: {$geo['pct']}% en {$geo['code']}";
                }
                $code = $geo['code'] ?? $b['attr'];
                if ($code !== null && ! isset($comunas[$code])) {
                    $this->report['codigos_desconocidos'][] = "{$b['nombre']} (amb): COD_COMUNA {$code} no está en el archivo de comunas";
                    $code = null;
                }
                if ($geo && $b['attr'] !== null && $b['attr'] !== $geo['code']) {
                    $this->report['discrepancias'][] = "{$b['nombre']}: AMB COD_COMUNA {$b['attr']}, geometría {$geo['code']} ({$geo['pct']}% del polígono) → {$geo['code']}";
                } elseif (! $geo && $code !== null) {
                    $this->report['discrepancias'][] = "{$b['nombre']}: el polígono no decide, se usa el COD_COMUNA {$code}";
                }
                // Without a comuna yet: the polygon is kept and the comuna comes from the next source.
                $barrios[$norm] = ['nombre' => $b['nombre'], 'code' => $code, 'fuente' => $code ? 'amb' : null, 'geometry' => $b['geometries'], 'poblacion' => null];
            }
        }

        foreach ([['planeacion', $planeacion], ['datosgov', $datosgov]] as [$fuente, $rows]) {
            foreach ($rows as $row) {
                $norm = BarrioNormalizer::normalize($row['nombre']);
                if ($norm === '') {
                    continue;
                }
                if ($row['code'] === null || ! isset($comunas[$row['code']])) {
                    $this->report['codigos_desconocidos'][] = "{$row['nombre']} ({$fuente}): comuna «{$row['origen']}» no está en el archivo de comunas";

                    continue;
                }
                if (isset($barrios[$norm]) && $barrios[$norm]['code'] === null) {
                    $this->report['discrepancias'][] = "{$row['nombre']}: el polígono AMB no decide la comuna, {$fuente} dice {$row['code']} → {$row['code']}";
                    $barrios[$norm]['code'] = $row['code'];
                    $barrios[$norm]['fuente'] = $fuente;
                } elseif (isset($barrios[$norm])) {
                    if ($barrios[$norm]['code'] !== $row['code']) {
                        $this->report['discrepancias'][] = "{$row['nombre']}: {$fuente} dice {$row['code']}, {$barrios[$norm]['fuente']} dice {$barrios[$norm]['code']} → {$barrios[$norm]['code']}";
                    }
                } else {
                    $barrios[$norm] = ['nombre' => $row['nombre'], 'code' => $row['code'], 'fuente' => $fuente, 'geometry' => null, 'poblacion' => null];
                }
                if (isset($row['poblacion']) && $barrios[$norm]['code'] === $row['code']) {
                    $barrios[$norm]['poblacion'] = ($barrios[$norm]['poblacion'] ?? 0) + $row['poblacion'];
                }
            }
        }
        foreach ($barrios as $norm => $b) {
            if ($b['code'] === null) {
                $this->report['sin_comuna'][] = "{$b['nombre']} (AMB): sin comuna por geometría, atributo ni otra fuente";
                unset($barrios[$norm]);
            }
        }
        ksort($barrios);

        return $barrios;
    }

    // [norm => ['code', 'pct']]: the comuna holding the largest share of each AMB polygon.
    private function geometricComunas(array $comunas, array $amb): array
    {
        $barrioRows = [];
        foreach ($amb as $norm => $b) {
            foreach ($b['geometries'] as $g) {
                $barrioRows[] = ['norm' => (string) $norm, 'geometry' => $g];
            }
        }

        $rows = DB::select(
            'WITH b AS (
                SELECT f.norm, ST_Union('.self::geomSql('ST_GeomFromGeoJSON(f.geometry)').') AS geom
                FROM json_to_recordset(?::json) AS f(norm text, geometry text) GROUP BY f.norm
            ), c AS (
                SELECT f.code, '.self::geomSql('ST_GeomFromGeoJSON(f.geometry)').' AS geom
                FROM json_to_recordset(?::json) AS f(code text, geometry text)
            ), shares AS (
                SELECT b.norm, c.code, ST_Area(ST_Intersection(b.geom, c.geom)) / NULLIF(ST_Area(b.geom), 0) AS share,
                       row_number() OVER (PARTITION BY b.norm ORDER BY ST_Area(ST_Intersection(b.geom, c.geom)) DESC) AS rank
                FROM b JOIN c ON ST_Intersects(b.geom, c.geom)
            )
            SELECT norm, code, round((share * 100)::numeric, 1)::float AS pct FROM shares WHERE rank = 1 AND share > 0',
            [json_encode($barrioRows), json_encode(array_values(array_map(fn ($c) => ['code' => $c['code'], 'geometry' => $c['geometry']], $comunas)))]
        );

        $out = [];
        foreach ($rows as $row) {
            $out[$row->norm] = ['code' => $row->code, 'pct' => $row->pct];
        }

        return $out;
    }

    private function existing(string $municipioId, string $code): array
    {
        return [
            'comunas' => DB::table('geographic_units')->where('parent_id', $municipioId)->whereIn('type', ComunaCatalog::TYPES)
                ->pluck('official_code')->map(fn ($c) => substr((string) $c, strlen($code) + 1))->flip()->all(),
            'barrios' => DB::table('barrios')->where('municipio_id', $municipioId)->pluck('nombre_normalizado')->flip()->all(),
        ];
    }

    // code => geographic_units.id
    private function applyComunas(array $comunas, string $municipioId, string $code, array $datosgov): array
    {
        $poblacion2024 = [];
        foreach ($datosgov as $row) {
            if ($row['code'] !== null && $row['poblacion'] !== null) {
                $poblacion2024[$row['code']] = ($poblacion2024[$row['code']] ?? 0) + $row['poblacion'];
            }
        }

        $ids = [];
        foreach ($comunas as $c) {
            $official = "{$code}-{$c['code']}";
            $metadata = json_encode(array_filter([
                'numero' => $c['numero'],
                'poblacion' => $c['poblacion'],
                'poblacion_2024' => $poblacion2024[$c['code']] ?? null,
                'veredas' => $c['veredas'],
                'fuente' => $c['fuente'],
            ], fn ($v) => $v !== null));
            $id = DB::table('geographic_units')->where('official_code', $official)->where('type', $c['tipo'])->value('id');
            if ($id) {
                DB::update(
                    'UPDATE geographic_units SET canonical_name = ?, parent_id = ?, level = 4, is_active = true, metadata = ?::jsonb,
                        updated_at = now() WHERE id = ?',
                    [$c['nombre'], $municipioId, $metadata, $id]
                );
                // Only a geometry that changed is rewritten (and simplified again): the simplification is not
                // byte-for-byte repeatable, and an unchanged comuna must stay the same.
                DB::update(
                    'UPDATE geographic_units g SET geom = n.geom, geom_simplified = NULL
                     FROM (SELECT '.self::geomSql('ST_GeomFromGeoJSON(?)').' AS geom) n
                     WHERE g.id = ? AND (g.geom IS NULL OR md5(ST_AsBinary(g.geom)) <> md5(ST_AsBinary(n.geom)))',
                    [$c['geometry'], $id]
                );
            } else {
                $id = (string) Str::uuid();
                DB::insert(
                    'INSERT INTO geographic_units (id, parent_id, type, canonical_name, official_code, level, is_active, metadata, geom, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, 4, true, ?::jsonb, '.self::geomSql('ST_GeomFromGeoJSON(?)').', now(), now())',
                    [$id, $municipioId, $c['tipo'], $c['nombre'], $official, $metadata, $c['geometry']]
                );
            }
            $ids[$c['code']] = $id;
        }

        DB::update(
            'UPDATE geographic_units SET geom_simplified = '.self::geomSql('ST_SimplifyPreserveTopology(geom, '.self::SIMPLIFY_TOLERANCE.')').'
             WHERE id = ANY(?::uuid[]) AND geom IS NOT NULL AND geom_simplified IS NULL',
            ['{'.implode(',', $ids).'}']
        );

        return $ids;
    }

    private function applyBarrios(array $barrios, string $municipioId, array $comunaIds): void
    {
        $rows = [];
        foreach ($barrios as $norm => $b) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'nombre' => Str::limit($b['nombre'], 150, ''),
                'norm' => (string) $norm,
                'comuna' => $comunaIds[$b['code']],
                'fuente' => $b['fuente'],
                'poblacion' => $b['poblacion'],
                'geometry' => $b['geometry'] ? json_encode(['type' => 'GeometryCollection', 'geometries' => array_map(fn ($g) => json_decode($g, true), $b['geometry'])]) : null,
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::statement(
                'INSERT INTO barrios (id, municipio_id, comuna_unit_id, nombre, nombre_normalizado, fuente, poblacion, geom, created_at, updated_at)
                 SELECT r.id, ?, r.comuna, r.nombre, r.norm, r.fuente, r.poblacion,
                        CASE WHEN r.geometry IS NULL THEN NULL
                             ELSE '.self::geomSql('ST_UnaryUnion(ST_CollectionExtract(ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(r.geometry), 4326)), 3))').' END, now(), now()
                 FROM json_to_recordset(?::json) AS r(id uuid, nombre text, norm text, comuna uuid, fuente text, poblacion int, geometry text)
                 ON CONFLICT (municipio_id, nombre_normalizado) DO UPDATE SET
                     nombre = EXCLUDED.nombre, comuna_unit_id = EXCLUDED.comuna_unit_id, fuente = EXCLUDED.fuente,
                     poblacion = COALESCE(EXCLUDED.poblacion, barrios.poblacion), geom = COALESCE(EXCLUDED.geom, barrios.geom),
                     updated_at = now()',
                [$municipioId, json_encode($chunk)]
            );
        }
    }

    private function verify(string $municipioId): array
    {
        $problems = [];
        $rows = DB::select(
            "SELECT c.official_code,
                    (c.geom IS NULL OR NOT ST_IsValid(c.geom) OR c.geom_simplified IS NULL OR NOT ST_IsValid(c.geom_simplified)) AS invalid,
                    NOT ST_Contains(m.geom, ST_PointOnSurface(c.geom)) AS outside
             FROM geographic_units c JOIN geographic_units m ON m.id = c.parent_id
             WHERE c.parent_id = ? AND c.type IN ('comuna', 'corregimiento')",
            [$municipioId]
        );
        foreach ($rows as $row) {
            if ($row->invalid) {
                $problems[] = "Geometría inválida: {$row->official_code}";
            } elseif ($row->outside) {
                $problems[] = "{$row->official_code} queda fuera del polígono del municipio.";
            }
        }
        $invalidBarrios = DB::table('barrios')->where('municipio_id', $municipioId)->whereNotNull('geom')
            ->whereRaw('NOT ST_IsValid(geom)')->pluck('nombre');
        foreach ($invalidBarrios as $name) {
            $problems[] = "Geometría inválida del barrio {$name}";
        }

        return $problems;
    }

    private function printPlan(object $municipio, string $code, array $comunas, array $barrios, array $existing): void
    {
        $this->info("Municipio: {$municipio->canonical_name} ({$code}).");
        $this->table(['Código', 'Tipo', 'Nombre', 'Población', 'Barrios/veredas', 'Acción'], array_map(fn ($c) => [
            "{$code}-{$c['code']}", $c['tipo'], $c['nombre'], $c['poblacion'] ?? '-',
            count(array_filter($barrios, fn ($b) => $b['code'] === $c['code'])),
            isset($existing['comunas'][$c['code']]) ? 'actualiza' : 'crea',
        ], array_values($comunas)));

        $bySource = collect($barrios)->countBy('fuente')->map(fn ($n, $k) => "{$k}: {$n}")->implode(', ');
        $new = count(array_diff_key($barrios, $existing['barrios']));
        $this->info(sprintf('Barrios/veredas: %d (%s); nuevos: %d, ya existentes: %d; con polígono: %d.',
            count($barrios), $bySource, $new, count($barrios) - $new, count(array_filter($barrios, fn ($b) => $b['geometry'] !== null))));

        $onlyInDb = array_diff_key($existing['barrios'], $barrios);
        if ($onlyInDb !== []) {
            $this->warn('Barrios en BD que no vienen en los archivos (no se tocan): '.implode(', ', array_keys($onlyInDb)));
        }

        foreach ([
            'discrepancias' => 'Discrepancias (gana la geometría / la fuente de mayor prioridad)',
            'divididos' => 'Barrios con el polígono repartido entre comunas',
            'sin_comuna' => 'Barrios sin comuna (no se cargan)',
            'codigos_desconocidos' => 'Filas con comuna desconocida (no se cargan)',
        ] as $key => $title) {
            $items = $this->report[$key];
            $this->line("{$title}: ".($items === [] ? 'ninguna' : count($items)));
            foreach ($items as $item) {
                $this->line("  - {$item}");
            }
        }
    }
}
