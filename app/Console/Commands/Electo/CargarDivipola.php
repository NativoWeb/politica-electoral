<?php

namespace App\Console\Commands\Electo;

use App\Console\Commands\ImportGeoMunicipios;
use App\Models\AuditLog;
use App\Services\Electo\CsvReader;
use App\Services\Electo\Normalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Loads the DANE DIVIPOLA departamentos and municipios of the whole country into geographic_units (no geometry), so
// fichas from outside Santander get a real municipio. Source: datos.gov.co dataset gdxc-w37w (DIVIPOLA, DANE), copy in
// database/data/geo/divipola_municipios.csv. Santander (68) is never touched: its municipios, provincias and polygons
// come from geo:import-municipios. Rows the users created by hand (e.g. Cundinamarca, Bogota without a code) are not
// moved or renamed: they only receive their DANE code when the name matches. A municipio whose name repeats in another
// departamento is stored as "Nombre (Departamento)" so the lookups by name of the rest of the platform never confuse it
// with a Santander one. Running it again changes nothing; with --dry-run it only reads.
class CargarDivipola extends Command
{
    protected $signature = 'geo:cargar-divipola
        {archivo? : CSV DIVIPOLA (cod_dpto, dpto, cod_mpio, nom_mpio, tipo_municipio); por defecto database/data/geo/divipola_municipios.csv}
        {--dry-run : Solo muestra lo que haría, no guarda nada}';

    protected $description = 'Carga los departamentos y municipios DIVIPOLA (DANE) de todo el país en geographic_units';

    public const DEFAULT_FILE = 'data/geo/divipola_municipios.csv';

    public const SOURCE = 'DIVIPOLA DANE (datos.gov.co gdxc-w37w)';

    private const SANTANDER = '68';

    private const SMALL_WORDS = ['de', 'del', 'la', 'las', 'los', 'el', 'y', 'e', 'en'];

    // DANE code => other names an existing hand-made municipio may have anywhere in the tree.
    private const ALIASES = ['11001' => ['bogota', 'bogota d.c.', 'bogota dc', 'bogota, d.c.']];

    public function handle(): int
    {
        $path = $this->argument('archivo') ?: database_path(self::DEFAULT_FILE);
        if (! is_file($path)) {
            $this->error("No existe el archivo {$path}.");

            return self::FAILURE;
        }

        try {
            $rows = $this->readRows($path);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        DB::beginTransaction();
        try {
            if ($dryRun && DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION READ ONLY');
            }
            $country = DB::table('geographic_units')->where('type', 'country')->orderBy('created_at')->first(['id']);
            if (! $country) {
                DB::rollBack();
                $this->error('No existe el país (type country) en geographic_units.');

                return self::FAILURE;
            }

            $plan = $this->plan($rows, $country->id);
            $this->table(['Acción', 'Departamentos', 'Municipios'], [
                ['Ya existían con código', $plan['dept_existing'], $plan['mun_existing']],
                ['Existían sin código (se les pone el código)', count($plan['dept_code']), count($plan['mun_code'])],
                ['Nuevos', count($plan['dept_new']), count($plan['mun_new'])],
                ['Nombre con departamento (repetido en otro)', '', $plan['suffixed']],
            ]);

            if ($dryRun) {
                DB::rollBack();
                $this->warn('DRY-RUN: no se guardó nada.');

                return self::SUCCESS;
            }

            $this->apply($plan, $country->id);
            AuditLog::create([
                'user_id' => null,
                'action' => 'geo.cargar_divipola',
                'entity_type' => 'geographic_unit',
                'entity_id' => $country->id,
                'metadata' => [
                    'command' => 'geo:cargar-divipola',
                    'file' => basename($path),
                    'sha256' => hash_file('sha256', $path),
                    'departamentos_nuevos' => count($plan['dept_new']),
                    'municipios_nuevos' => count($plan['mun_new']),
                    'codigos_asignados' => array_merge(array_keys($plan['dept_code']), array_keys($plan['mun_code'])),
                ],
            ]);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->info('DIVIPOLA cargada.');

        return self::SUCCESS;
    }

    private function readRows(string $path): array
    {
        $rows = [];
        foreach (CsvReader::rows($path) as $row) {
            foreach (['cod_dpto', 'dpto', 'cod_mpio', 'nom_mpio'] as $col) {
                if (! isset($row[$col])) {
                    throw new \InvalidArgumentException("El archivo no tiene la columna {$col}.");
                }
            }
            $dept = str_pad(trim($row['cod_dpto']), 2, '0', STR_PAD_LEFT);
            $mun = str_pad(trim($row['cod_mpio']), 5, '0', STR_PAD_LEFT);
            if (! preg_match('/^\d{2}$/', $dept) || ! preg_match('/^\d{5}$/', $mun) || ! str_starts_with($mun, $dept)) {
                throw new \InvalidArgumentException("Código DIVIPOLA inválido: {$dept}/{$mun}.");
            }
            $rows[] = ['dept' => $dept, 'dept_name' => trim($row['dpto']), 'code' => $mun, 'name' => trim($row['nom_mpio']),
                'tipo' => trim($row['tipo_municipio'] ?? '')];
        }
        if (count($rows) < 10) {
            throw new \InvalidArgumentException('El archivo DIVIPOLA está vacío o incompleto.');
        }

        return $rows;
    }

    private function plan(array $rows, string $countryId): array
    {
        $units = DB::table('geographic_units')->whereIn('type', ['department', 'province', 'municipality'])
            ->get(['id', 'type', 'canonical_name', 'official_code', 'parent_id'])->keyBy('id');
        $deptByCode = [];
        $uncodedDepts = [];
        $munByCode = [];
        foreach ($units as $u) {
            if ($u->type === 'department') {
                $u->official_code !== null ? $deptByCode[$u->official_code] = $u->id : $uncodedDepts[Normalizer::key($u->canonical_name)][] = $u->id;
            } elseif ($u->type === 'municipality' && $u->official_code !== null) {
                $munByCode[$u->official_code] = $u->id;
            }
        }

        $nameCount = [];
        foreach ($rows as $r) {
            $key = Normalizer::key(self::pretty($r['name']));
            $nameCount[$key] = ($nameCount[$key] ?? 0) + 1;
        }
        $santander = $this->santanderNames();

        $plan = ['dept_existing' => 0, 'mun_existing' => 0, 'dept_code' => [], 'dept_new' => [], 'mun_code' => [], 'mun_new' => [], 'suffixed' => 0];
        $deptIds = [];
        $taken = [];
        foreach ($rows as $r) {
            if ($r['dept'] === self::SANTANDER) {
                continue;
            }
            $dept = $r['dept'];
            if (! isset($deptIds[$dept])) {
                if (isset($deptByCode[$dept])) {
                    $deptIds[$dept] = $deptByCode[$dept];
                    $plan['dept_existing']++;
                } elseif (count($uncodedDepts[Normalizer::key(self::pretty($r['dept_name']))] ?? []) === 1) {
                    $deptIds[$dept] = $uncodedDepts[Normalizer::key(self::pretty($r['dept_name']))][0];
                    $plan['dept_code'][$dept] = $deptIds[$dept];
                } else {
                    $deptIds[$dept] = (string) Str::uuid();
                    $plan['dept_new'][$dept] = ['id' => $deptIds[$dept], 'name' => self::pretty($r['dept_name'])];
                }
            }

            if (isset($munByCode[$r['code']])) {
                $plan['mun_existing']++;

                continue;
            }
            $existing = $this->uncodedMunicipio($units, $r, $deptIds[$dept], $taken);
            if ($existing) {
                $taken[$existing] = true;
                $plan['mun_code'][$r['code']] = $existing;

                continue;
            }
            $name = self::pretty($r['name']);
            if (($nameCount[Normalizer::key($name)] ?? 0) > 1 || isset($santander[Normalizer::key($name)])) {
                $name .= ' ('.self::pretty($r['dept_name']).')';
                $plan['suffixed']++;
            }
            $plan['mun_new'][$r['code']] = ['name' => mb_substr($name, 0, 150), 'parent' => $deptIds[$dept], 'tipo' => $r['tipo']];
        }

        return $plan;
    }

    // Every way a Santander municipio is written in the platform (name, the aliases of geo:import-municipios and of
    // geographic_aliases, and the municipio text of the fichas): a municipio of another departamento with one of these
    // names always carries its departamento, so no lookup by name can take it for the Santander one.
    private function santanderNames(): array
    {
        $ids = DB::table('geographic_units')->where('type', 'municipality')->where('official_code', 'like', self::SANTANDER.'%')->pluck('id')->all();
        $names = DB::table('geographic_units')->whereIn('id', $ids)->pluck('canonical_name')->all();
        foreach (ImportGeoMunicipios::ALIASES as $aliases) {
            array_push($names, ...$aliases);
        }
        if (DB::getSchemaBuilder()->hasTable('geographic_aliases')) {
            array_push($names, ...DB::table('geographic_aliases')->whereIn('geographic_unit_id', $ids)->pluck('raw_alias')->all());
        }
        foreach (array_chunk($ids, 500) as $chunk) {
            array_push($names, ...DB::table('lideres')->whereIn('geographic_unit_id', $chunk)->distinct()->pluck('municipio')->all());
        }

        return array_flip(array_filter(array_map(fn ($n) => Normalizer::key(self::pretty((string) $n)), $names)));
    }

    // A municipio created by hand without a DANE code: same name below the same departamento, or a known alias.
    private function uncodedMunicipio($units, array $r, string $deptId, array $taken): ?string
    {
        $names = [Normalizer::key(self::pretty($r['name'])), ...array_map([Normalizer::class, 'key'], self::ALIASES[$r['code']] ?? [])];
        $byAlias = isset(self::ALIASES[$r['code']]);
        $found = [];
        foreach ($units as $u) {
            if ($u->type !== 'municipality' || $u->official_code !== null || isset($taken[$u->id])
                || ! in_array(Normalizer::key($u->canonical_name), $names, true)) {
                continue;
            }
            if ($byAlias || $this->below($units, $u, $deptId)) {
                $found[] = $u->id;
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }

    private function below($units, object $unit, string $deptId): bool
    {
        for ($i = 0, $parent = $unit->parent_id; $parent !== null && $i < 4; $i++) {
            if ($parent === $deptId) {
                return true;
            }
            $parent = $units[$parent]->parent_id ?? null;
        }

        return false;
    }

    private function apply(array $plan, string $countryId): void
    {
        $now = now();
        $meta = fn (array $extra) => json_encode(['fuente' => self::SOURCE] + $extra, JSON_UNESCAPED_UNICODE);
        foreach ($plan['dept_new'] as $code => $d) {
            DB::table('geographic_units')->insert([
                'id' => $d['id'], 'parent_id' => $countryId, 'type' => 'department', 'canonical_name' => $d['name'],
                'official_code' => str_pad((string) $code, 2, '0', STR_PAD_LEFT), 'level' => 1, 'is_active' => true, 'metadata' => $meta([]),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach ([$plan['dept_code'], $plan['mun_code']] as $codes) {
            foreach ($codes as $code => $id) {
                DB::table('geographic_units')->where('id', $id)->whereNull('official_code')->update(['official_code' => (string) $code, 'updated_at' => $now]);
            }
        }
        foreach (array_chunk($plan['mun_new'], 500, true) as $chunk) {
            $insert = [];
            foreach ($chunk as $code => $m) {
                $insert[] = [
                    'id' => (string) Str::uuid(), 'parent_id' => $m['parent'], 'type' => 'municipality', 'canonical_name' => $m['name'],
                    'official_code' => (string) $code, 'level' => 3, 'is_active' => true, 'metadata' => $meta(['tipo_municipio' => $m['tipo']]),
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
            DB::table('geographic_units')->insert($insert);
        }
    }

    // "BOGOTÁ, D.C." -> "Bogotá, D.C."; "SAN JOSÉ DE CÚCUTA" -> "San José de Cúcuta".
    public static function pretty(string $name): string
    {
        $words = explode(' ', preg_replace('/\s+/u', ' ', trim(mb_convert_case($name, MB_CASE_LOWER, 'UTF-8'))));
        foreach ($words as $i => $w) {
            if ($i > 0 && in_array($w, self::SMALL_WORDS, true)) {
                continue;
            }
            $words[$i] = preg_match('/^(\p{L}\.)+$/u', $w) ? mb_strtoupper($w) : mb_convert_case($w, MB_CASE_TITLE, 'UTF-8');
        }

        return implode(' ', $words);
    }
}
