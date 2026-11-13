<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\Geo\MunicipiosGeoJson;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// Loads the DANE MGN polygons and DIVIPOLA codes of the Santander municipios (docs/geografia.md) into geographic_units:
// official_code, geom and geom_simplified of each municipio, and the ST_Union of its provincias and the departamento.
// Features are matched to the municipios below the departamento by code (once loaded), an explicit alias, the
// unaccented lowercase name or, last, pg_trgm similarity. Nothing is created or deleted; with --dry-run it only reads
// (read-only transaction). Running it again leaves the same data.
class ImportGeoMunicipios extends Command
{
    protected $signature = 'geo:import-municipios
        {archivo? : GeoJSON de municipios en EPSG:4326 (por defecto database/data/geo/santander_municipios.geojson)}
        {--dry-run : Solo muestra el cruce, no guarda nada}
        {--parcial : Recalcula también las provincias y el departamento aunque les falten municipios con polígono}';

    protected $description = 'Load the DANE polygons and codes of the Santander municipios, provincias and departamento';

    public const DEFAULT_FILE = 'data/geo/santander_municipios.geojson';

    public const DEPARTMENT_CODE = '68';

    // ~55 m: the simplified copy the map draws; the full geometry stays in geom.
    public const SIMPLIFY_TOLERANCE = 0.0005;

    private const MIN_SIMILARITY = 0.55;

    // DANE code => other spellings (unaccented, lowercase) a municipio has had in the imported Excel files.
    public const ALIASES = [
        '68081' => ['barrancabermeja distrito', 'distrito de barrancabermeja', 'barranca'],
        '68235' => ['el carmen', 'carmen de chucuri', 'el carmen de chucury'],
        '68255' => ['playon'],
        '68298' => ['gambita'],
        '68368' => ['jesus maria'],
        '68370' => ['jordan sube', 'jordan'],
        '68524' => ['palmas de socorro', 'palmas socorro'],
        '68689' => ['san vicente', 'san vicente de chucury'],
        '68720' => ['santa helena', 'santa helena de opon'],
        '68855' => ['valle san jose'],
    ];

    public function handle(): int
    {
        $path = $this->argument('archivo') ?: database_path(self::DEFAULT_FILE);

        try {
            $features = $this->readFeatures($path);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->error('geo:import-municipios necesita PostgreSQL con PostGIS.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        DB::beginTransaction();
        try {
            if ($dryRun) {
                DB::statement('SET TRANSACTION READ ONLY');
            }

            $department = $this->department();
            if (! $department) {
                DB::rollBack();
                $this->error('No existe el departamento '.self::DEPARTMENT_CODE.' (Santander) en geographic_units.');

                return self::FAILURE;
            }

            if ($department->official_code !== null && $department->official_code !== self::DEPARTMENT_CODE) {
                DB::rollBack();
                $this->error("El departamento {$department->canonical_name} tiene el código {$department->official_code}, no ".self::DEPARTMENT_CODE.': no se guardó nada.');

                return self::FAILURE;
            }

            $municipios = $this->municipios($department->id);
            $plan = $this->match($features, $municipios);
            $plan['unions'] = $this->unionScope($plan, $department->id, (bool) $this->option('parcial'));
            $this->printPlan($plan, $municipios, $features, $department->id);
            $this->printUnions($plan['unions'], $department);

            if ($plan['matches'] === []) {
                DB::rollBack();
                $this->error('Ningún feature del archivo cruzó con un municipio de Santander: no se guardó nada.');

                return self::FAILURE;
            }

            if ($plan['conflicts'] !== [] || $plan['invalid'] !== []) {
                DB::rollBack();
                $this->error('Hay códigos en conflicto o geometrías inválidas: no se guardó nada.');

                return self::FAILURE;
            }

            if ($dryRun) {
                DB::rollBack();
                $this->warn('DRY-RUN: no se guardó nada.');

                return self::SUCCESS;
            }

            if ($department->official_code === null) {
                DB::update('UPDATE geographic_units SET official_code = ?, updated_at = now() WHERE id = ?', [self::DEPARTMENT_CODE, $department->id]);
            }
            $this->apply($plan, $department->id);
            $problems = $this->verify($department->id);
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
                'action' => 'geo.import_municipios',
                'entity_type' => 'geographic_unit',
                'entity_id' => $department->id,
                'metadata' => [
                    'command' => 'geo:import-municipios',
                    'file' => basename($path),
                    'sha256' => hash_file('sha256', $path),
                    'matched' => count($plan['matches']),
                    'unmatched_features' => array_column($plan['unmatchedFeatures'], 'code'),
                ],
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        MunicipiosGeoJson::flush();
        $this->info(sprintf(
            'Polígonos cargados: %d municipios, %d provincias%s.',
            count($plan['matches']),
            count($plan['unions']['provinces']),
            $plan['unions']['department'] ? ' y el departamento' : '; el departamento no se recalculó'
        ));

        return self::SUCCESS;
    }

    // [['idx' => int, 'code' => '68001', 'name' => 'BUCARAMANGA', 'geometry' => json string], ...]
    private function readFeatures(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new \InvalidArgumentException("No se puede leer el archivo {$path}.");
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ($data['type'] ?? null) !== 'FeatureCollection' || ! is_array($data['features'] ?? null)) {
            throw new \InvalidArgumentException('El archivo no es un GeoJSON FeatureCollection.');
        }

        $features = [];
        $seen = [];
        foreach ($data['features'] as $i => $feature) {
            $props = $feature['properties'] ?? [];
            $code = (string) ($props['MPIO_CDPMP'] ?? (isset($props['DPTO_CCDGO'], $props['MPIO_CCDGO'])
                ? $props['DPTO_CCDGO'].$props['MPIO_CCDGO']
                : (isset($props['CCDE'], $props['CCMU']) ? $props['CCDE'].$props['CCMU'] : '')));
            $name = trim((string) ($props['MPIO_CNMBRE'] ?? $props['NMG'] ?? ''));
            $type = $feature['geometry']['type'] ?? null;

            if (! preg_match('/^\d{5}$/', $code) || $name === '' || ! in_array($type, ['Polygon', 'MultiPolygon'], true)) {
                throw new \InvalidArgumentException('El feature #'.($i + 1).' no tiene código DANE de 5 dígitos, nombre o polígono.');
            }
            if (! str_starts_with($code, self::DEPARTMENT_CODE)) {
                continue;
            }
            if (isset($seen[$code])) {
                throw new \InvalidArgumentException("El código {$code} está repetido en el archivo.");
            }
            $seen[$code] = true;

            $features[] = ['idx' => count($features), 'code' => $code, 'name' => $name, 'geometry' => json_encode($feature['geometry'])];
        }

        if ($features === []) {
            throw new \InvalidArgumentException('El archivo no trae municipios del departamento '.self::DEPARTMENT_CODE.'.');
        }

        return $features;
    }

    private function department(): ?object
    {
        return DB::selectOne(
            "SELECT id, canonical_name, official_code FROM geographic_units
             WHERE type = 'department' AND (official_code = ? OR lower(unaccent(canonical_name)) = 'santander')
             ORDER BY (official_code = ?) DESC NULLS LAST LIMIT 1",
            [self::DEPARTMENT_CODE, self::DEPARTMENT_CODE]
        );
    }

    // Every municipio below the departamento (directly or through a provincia), with its normalized name.
    private function municipios(string $departmentId): array
    {
        return DB::select(
            "WITH RECURSIVE tree AS (
                SELECT id FROM geographic_units WHERE id = ?
                UNION
                SELECT g.id FROM geographic_units g JOIN tree t ON g.parent_id = t.id
            )
            SELECT m.id, m.canonical_name, m.official_code, m.is_active, lower(unaccent(m.canonical_name)) AS norm,
                   m.parent_id, (m.geom IS NOT NULL) AS has_geom, p.canonical_name AS provincia
            FROM geographic_units m
            JOIN tree t ON t.id = m.id
            LEFT JOIN geographic_units p ON p.id = m.parent_id
            WHERE m.type = 'municipality'
            ORDER BY m.canonical_name",
            [$departmentId]
        );
    }

    private function match(array $features, array $municipios): array
    {
        $norms = DB::select(
            'SELECT f.idx, lower(unaccent(f.name)) AS norm FROM json_to_recordset(?::json) AS f(idx int, name text)',
            [json_encode(array_map(fn ($f) => ['idx' => $f['idx'], 'name' => $f['name']], $features))]
        );
        foreach ($norms as $row) {
            $features[$row->idx]['norm'] = $row->norm;
        }

        $matches = [];
        $usedUnits = [];
        $byMethod = function (callable $find, string $method) use (&$features, &$matches, &$usedUnits, $municipios) {
            foreach ($features as $f) {
                if (isset($matches[$f['idx']])) {
                    continue;
                }
                $candidates = array_values(array_filter($municipios, fn ($m) => ! isset($usedUnits[$m->id]) && $find($f, $m)));
                if (count($candidates) === 1) {
                    $matches[$f['idx']] = ['feature' => $f, 'unit' => $candidates[0], 'method' => $method, 'score' => null];
                    $usedUnits[$candidates[0]->id] = true;
                }
            }
        };

        $byMethod(fn ($f, $m) => $m->official_code === $f['code'], 'código');
        $byMethod(fn ($f, $m) => $m->norm === $f['norm'], 'nombre');
        $byMethod(fn ($f, $m) => in_array($m->norm, self::ALIASES[$f['code']] ?? [], true), 'excepción');
        $this->matchBySimilarity($features, $municipios, $matches, $usedUnits);

        ksort($matches);
        $matches = array_values($matches);
        $matchedIdx = array_column(array_column($matches, 'feature'), 'idx');

        return [
            'matches' => $matches,
            'unmatchedFeatures' => array_values(array_filter($features, fn ($f) => ! in_array($f['idx'], $matchedIdx, true))),
            'unmatchedUnits' => array_values(array_filter($municipios, fn ($m) => ! isset($usedUnits[$m->id]))),
            'allUnits' => $municipios,
            'conflicts' => $this->conflicts($matches),
            'invalid' => $this->invalidGeometries($matches),
        ];
    }

    // pg_trgm for what is left: the best pair wins only when it is clearly better than the runner-up.
    private function matchBySimilarity(array $features, array $municipios, array &$matches, array &$usedUnits): void
    {
        $leftFeatures = array_values(array_filter($features, fn ($f) => ! isset($matches[$f['idx']])));
        $leftUnits = array_values(array_filter($municipios, fn ($m) => ! isset($usedUnits[$m->id])));
        if ($leftFeatures === [] || $leftUnits === []) {
            return;
        }

        $pairs = DB::select(
            'SELECT f.idx, u.id, similarity(f.norm, u.norm) AS score
             FROM json_to_recordset(?::json) AS f(idx int, norm text)
             CROSS JOIN json_to_recordset(?::json) AS u(id uuid, norm text)
             WHERE similarity(f.norm, u.norm) >= ?
             ORDER BY score DESC',
            [
                json_encode(array_map(fn ($f) => ['idx' => $f['idx'], 'norm' => $f['norm']], $leftFeatures)),
                json_encode(array_map(fn ($m) => ['id' => $m->id, 'norm' => $m->norm], $leftUnits)),
                self::MIN_SIMILARITY,
            ]
        );

        $units = collect($leftUnits)->keyBy('id');
        foreach ($pairs as $pair) {
            if (isset($matches[$pair->idx]) || isset($usedUnits[$pair->id])) {
                continue;
            }
            $rivals = collect($pairs)->filter(fn ($p) => ($p->idx === $pair->idx xor $p->id === $pair->id)
                && ! isset($matches[$p->idx]) && ! isset($usedUnits[$p->id]));
            if ($rivals->contains(fn ($p) => $pair->score - $p->score < 0.1)) {
                continue;
            }
            $matches[$pair->idx] = ['feature' => $features[$pair->idx], 'unit' => $units[$pair->id], 'method' => 'similitud', 'score' => round((float) $pair->score, 2)];
            $usedUnits[$pair->id] = true;
        }
    }

    // A code that another municipio row already holds would break the (official_code, type) unique index.
    private function conflicts(array $matches): array
    {
        $conflicts = [];
        foreach ($matches as $m) {
            $other = DB::selectOne(
                "SELECT id, canonical_name FROM geographic_units WHERE type = 'municipality' AND official_code = ? AND id <> ?",
                [$m['feature']['code'], $m['unit']->id]
            );
            if ($other) {
                $conflicts[] = "{$m['feature']['code']}: ya lo tiene {$other->canonical_name} ({$other->id}), no {$m['unit']->canonical_name}";
            }
        }

        return $conflicts;
    }

    private function invalidGeometries(array $matches): array
    {
        if ($matches === []) {
            return [];
        }

        $rows = DB::select(
            'SELECT f.code FROM json_to_recordset(?::json) AS f(code text, geometry text)
             WHERE NOT ST_IsValid('.self::geomSql('ST_GeomFromGeoJSON(f.geometry)').') OR ST_IsEmpty('.self::geomSql('ST_GeomFromGeoJSON(f.geometry)').')',
            [json_encode(array_map(fn ($m) => ['code' => $m['feature']['code'], 'geometry' => $m['feature']['geometry']], $matches))]
        );

        return array_column($rows, 'code');
    }

    // Repaired, polygons only, MultiPolygon, SRID 4326.
    private static function geomSql(string $expr): string
    {
        return "ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_SetSRID({$expr}, 4326)), 3))";
    }

    private function apply(array $plan, string $departmentId): void
    {
        $simplified = self::geomSql('ST_SimplifyPreserveTopology(geom, '.self::SIMPLIFY_TOLERANCE.')');

        foreach ($plan['matches'] as $m) {
            DB::update(
                'UPDATE geographic_units SET official_code = ?, geom = '.self::geomSql('ST_GeomFromGeoJSON(?)').', updated_at = now() WHERE id = ?',
                [$m['feature']['code'], $m['feature']['geometry'], $m['unit']->id]
            );
        }
        $ids = array_map(fn ($m) => $m['unit']->id, $plan['matches']);
        DB::update(
            "UPDATE geographic_units SET geom_simplified = {$simplified} WHERE id = ANY(?::uuid[])",
            ['{'.implode(',', $ids).'}']
        );

        // Provincias and departamento (only those unionScope() allows): union of the municipios below them. A union
        // that comes out NULL keeps the geometry the unit already had.
        $provinces = $plan['unions']['provinces'];
        if ($provinces !== []) {
            DB::update(
                'UPDATE geographic_units p SET geom = COALESCE(u.geom, p.geom), updated_at = now()
                 FROM (
                     SELECT m.parent_id, '.self::geomSql('ST_Union(m.geom)').' AS geom
                     FROM geographic_units m
                     WHERE m.type = ? AND m.geom IS NOT NULL
                     GROUP BY m.parent_id
                 ) u
                 WHERE p.id = u.parent_id AND p.id = ANY(?::uuid[])',
                ['municipality', '{'.implode(',', $provinces).'}']
            );
        }
        if ($plan['unions']['department']) {
            DB::update(
                'UPDATE geographic_units SET geom = COALESCE((
                     SELECT '.self::geomSql('ST_Union(m.geom)').'
                     FROM geographic_units m
                     WHERE m.type = ? AND m.geom IS NOT NULL
                       AND (m.parent_id = ? OR m.parent_id IN (SELECT id FROM geographic_units WHERE parent_id = ?))
                 ), geom), updated_at = now() WHERE id = ?',
                ['municipality', $departmentId, $departmentId, $departmentId]
            );
        }
        $recomputed = [...$provinces, ...($plan['unions']['department'] ? [$departmentId] : [])];
        if ($recomputed !== []) {
            DB::update(
                "UPDATE geographic_units SET geom_simplified = {$simplified} WHERE geom IS NOT NULL AND id = ANY(?::uuid[])",
                ['{'.implode(',', $recomputed).'}']
            );
        }
    }

    // Which provincias of the departamento, and whether the departamento, get their union recomputed: only when every
    // municipio below them will have a polygon after this run, unless --parcial.
    private function unionScope(array $plan, string $departmentId, bool $partial): array
    {
        $matched = array_flip(array_map(fn ($m) => $m['unit']->id, $plan['matches']));
        $missing = array_values(array_filter($plan['allUnits'], fn ($m) => ! $m->has_geom && ! isset($matched[$m->id])));
        $missingByParent = collect($missing)->groupBy('parent_id');
        $withMunicipios = collect($plan['allUnits'])->pluck('parent_id')->unique()->flip();

        $provinces = DB::table('geographic_units')->where('type', 'province')->where('parent_id', $departmentId)
            ->orderBy('canonical_name')->get(['id', 'canonical_name']);

        $included = [];
        $skipped = [];
        foreach ($provinces as $province) {
            if (! isset($withMunicipios[$province->id])) {
                continue;
            }
            if ($partial || ! $missingByParent->has($province->id)) {
                $included[] = $province->id;
            } else {
                $skipped[] = $province->canonical_name.' ('.$missingByParent[$province->id]->pluck('canonical_name')->implode(', ').')';
            }
        }

        return [
            'provinces' => $included,
            'skippedProvinces' => $skipped,
            'department' => $partial || $missing === [],
            'missing' => array_map(fn ($m) => $m->canonical_name, $missing),
            'partial' => $partial,
        ];
    }

    private function printUnions(array $unions, object $department): void
    {
        if ($department->official_code === null) {
            $this->warn("El departamento {$department->canonical_name} no tiene código: se le fija ".self::DEPARTMENT_CODE.'.');
        }
        if ($unions['missing'] === []) {
            return;
        }
        if ($unions['partial']) {
            $this->warn('--parcial: se recalculan provincias y departamento aunque faltan polígonos de: '.implode(', ', $unions['missing']).'.');

            return;
        }
        foreach ($unions['skippedProvinces'] as $line) {
            $this->warn("Provincia sin recalcular (le faltan municipios con polígono): {$line}.");
        }
        $this->warn('El departamento no se recalcula: faltan polígonos de '.count($unions['missing']).' municipios. Use --parcial para forzarlo.');
    }

    private function verify(string $departmentId): array
    {
        $problems = [];
        $invalid = DB::select(
            'SELECT canonical_name FROM geographic_units
             WHERE geom IS NOT NULL AND (NOT ST_IsValid(geom) OR NOT ST_IsValid(geom_simplified) OR geom_simplified IS NULL)
               AND (id = ? OR parent_id = ? OR parent_id IN (SELECT id FROM geographic_units WHERE parent_id = ?))',
            [$departmentId, $departmentId, $departmentId]
        );
        foreach ($invalid as $row) {
            $problems[] = "Geometría inválida: {$row->canonical_name}";
        }

        $outside = DB::select(
            "SELECT m.canonical_name FROM geographic_units m, geographic_units d
             WHERE d.id = ? AND m.type = 'municipality' AND m.geom IS NOT NULL
               AND (m.parent_id = d.id OR m.parent_id IN (SELECT id FROM geographic_units WHERE parent_id = d.id))
               AND NOT ST_Contains(d.geom, ST_PointOnSurface(m.geom))",
            [$departmentId]
        );
        foreach ($outside as $row) {
            $problems[] = "El municipio {$row->canonical_name} queda fuera del polígono del departamento.";
        }

        return $problems;
    }

    private function printPlan(array $plan, array $municipios, array $features, string $departmentId): void
    {
        $this->info(sprintf('Archivo: %d municipios del departamento %s. BD: %d municipios bajo Santander.', count($features), self::DEPARTMENT_CODE, count($municipios)));

        $this->table(['Código', 'Nombre DANE', 'Municipio en BD', 'Provincia', 'Cruce'], array_map(fn ($m) => [
            $m['feature']['code'], $m['feature']['name'], $m['unit']->canonical_name, $m['unit']->provincia ?? '-',
            $m['method'].($m['score'] !== null ? " ({$m['score']})" : '').(($m['unit']->official_code && $m['unit']->official_code !== $m['feature']['code']) ? " [cambia código {$m['unit']->official_code}]" : ''),
        ], $plan['matches']));

        $methods = collect($plan['matches'])->countBy('method')->map(fn ($n, $k) => "{$k}: {$n}")->implode(', ');
        $this->info('Emparejados: '.count($plan['matches']).($methods ? " ({$methods})" : ''));

        $this->line('Features del archivo sin municipio: '.($plan['unmatchedFeatures'] === []
            ? 'ninguno'
            : implode(', ', array_map(fn ($f) => "{$f['code']} {$f['name']}", $plan['unmatchedFeatures']))));
        $this->line('Municipios de Santander sin polígono: '.($plan['unmatchedUnits'] === []
            ? 'ninguno'
            : implode(', ', array_map(fn ($m) => "{$m->canonical_name} ({$m->id})", $plan['unmatchedUnits']))));

        foreach ($this->extras($departmentId) as $line) {
            $this->warn($line);
        }
        foreach ($plan['conflicts'] as $conflict) {
            $this->error("Conflicto de código: {$conflict}");
        }
        foreach ($plan['invalid'] as $code) {
            $this->error("Geometría vacía o inválida tras ST_MakeValid: {$code}");
        }
    }

    // Municipio rows that are not part of Santander, or that repeat a Santander name: reported, never deleted.
    private function extras(string $departmentId): array
    {
        $rows = DB::select(
            "WITH RECURSIVE tree AS (
                SELECT id FROM geographic_units WHERE id = ?
                UNION
                SELECT g.id FROM geographic_units g JOIN tree t ON g.parent_id = t.id
            )
            SELECT m.id, m.canonical_name, p.canonical_name AS parent, d.canonical_name AS grandparent,
                   (m.id IN (SELECT id FROM tree)) AS in_department,
                   count(*) OVER (PARTITION BY lower(unaccent(m.canonical_name))) AS same_name,
                   (SELECT count(*) FROM lideres l WHERE l.geographic_unit_id = m.id) AS lideres,
                   (SELECT count(*) FROM electoral_results r WHERE r.geographic_unit_id = m.id) AS resultados
            FROM geographic_units m
            LEFT JOIN geographic_units p ON p.id = m.parent_id
            LEFT JOIN geographic_units d ON d.id = p.parent_id
            WHERE m.type = 'municipality'",
            [$departmentId]
        );

        $lines = [];
        foreach ($rows as $row) {
            $where = trim(($row->parent ?? 'sin padre').($row->grandparent ? " / {$row->grandparent}" : ''));
            $usage = "{$row->lideres} líderes, {$row->resultados} resultados";
            if (! $row->in_department) {
                $lines[] = "Registro extra (no se toca): {$row->canonical_name} ({$row->id}) está bajo {$where}, fuera de Santander; {$usage}.";
            } elseif ($row->same_name > 1) {
                $lines[] = "Registro repetido (no se toca): {$row->canonical_name} ({$row->id}) bajo {$where}; {$usage}.";
            }
        }

        return $lines;
    }
}
