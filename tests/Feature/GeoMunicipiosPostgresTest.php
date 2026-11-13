<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

// Opt-in checks of geo:import-municipios and GET /api/geo/municipios.geojson on a LOCAL PostGIS database:
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral php artisan test --filter=GeoMunicipiosPostgresTest
// Everything runs inside a transaction that is rolled back. The real Santander rows are set aside (code and name
// changed inside the transaction) so the fixture builds its own department.
class GeoMunicipiosPostgresTest extends TestCase
{
    use DatabaseTransactions;

    private const FIXTURE = 'tests/Fixtures/geo/municipios_minimo.geojson';

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }

        DB::table('geographic_units')->where('official_code', 'like', '68%')->update(['official_code' => null]);
        DB::table('geographic_units')->where('type', 'department')->whereRaw("lower(unaccent(canonical_name)) = 'santander'")
            ->update(['canonical_name' => 'Santander real (apartado en el test)']);

        $country = $this->unit('country', 'País test', null);
        $dept = $this->unit('department', 'Santander', $country, '68');
        $prov = $this->unit('province', 'Provincia test', $dept);
        $otherDept = $this->unit('department', 'Cundinamarca test', $country);
        $this->ids = [
            'dept' => $dept,
            'prov' => $prov,
            'bucaramanga' => $this->unit('municipality', 'Bucaramanga', $prov),
            'carmen' => $this->unit('municipality', 'El Carmen', $prov),
            'miranda' => $this->unit('municipality', 'San Jose Miranda', $prov),
            'gambita' => $this->unit('municipality', 'Gambita', $prov),
            'sin_poligono' => $this->unit('municipality', 'Zqx Sin Poligono', $dept),
            'bogota' => $this->unit('municipality', 'Bogota', $this->unit('province', 'Distrito capital test', $otherDept)),
        ];
    }

    private function unit(string $type, string $name, ?string $parent, ?string $code = null): string
    {
        $id = (string) Str::uuid();
        DB::table('geographic_units')->insert([
            'id' => $id, 'type' => $type, 'canonical_name' => $name, 'parent_id' => $parent, 'official_code' => $code,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function snapshot(): string
    {
        return (string) DB::scalar(
            "SELECT md5(string_agg(id::text || coalesce(official_code, '') || coalesce(md5(ST_AsBinary(geom)), '')
                || coalesce(md5(ST_AsBinary(geom_simplified)), ''), ',' ORDER BY id)) FROM geographic_units"
        );
    }

    private function import(array $options = []): PendingCommand
    {
        return $this->artisan('geo:import-municipios', ['archivo' => base_path(self::FIXTURE)] + $options);
    }

    private function user(array $permissions): User
    {
        $user = User::create([
            'name' => 'Geo PG', 'email' => 'geo-pg-'.Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => Role::where('code', 'R09_CAMPO')->value('id'), 'permissions' => $permissions,
        ]);
        $user->forceFill(['campaign_id' => Campaign::DEFAULT_ID])->save();

        return $user;
    }

    public function test_dry_run_reports_the_matching_and_writes_nothing(): void
    {
        $before = $this->snapshot();
        $audits = DB::table('audit_logs')->count();

        $this->import(['--dry-run' => true])
            ->expectsOutputToContain('Emparejados: 4 (nombre: 2, excepción: 1, similitud: 1)')
            ->expectsOutputToContain('Features del archivo sin municipio: 68999 INVENTADO')
            ->expectsOutputToContain('Municipios de Santander sin polígono: Zqx Sin Poligono')
            ->expectsOutputToContain("Registro extra (no se toca): Bogota ({$this->ids['bogota']}) está bajo Distrito capital test / Cundinamarca test")
            ->expectsOutputToContain('El departamento no se recalcula: faltan polígonos de 1 municipios. Use --parcial para forzarlo.')
            ->expectsOutputToContain('DRY-RUN: no se guardó nada.')
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame($audits, DB::table('audit_logs')->count());
    }

    public function test_it_loads_codes_and_valid_polygons_and_the_unions(): void
    {
        $this->import(['--parcial' => true])
            ->expectsOutputToContain('--parcial: se recalculan provincias y departamento aunque faltan polígonos de: Zqx Sin Poligono.')
            ->expectsOutputToContain('Polígonos cargados: 4 municipios, 1 provincias y el departamento.')
            ->assertSuccessful();

        $codes = DB::table('geographic_units')->whereIn('id', array_values($this->ids))->pluck('official_code', 'id');
        $this->assertSame('68001', $codes[$this->ids['bucaramanga']]);
        $this->assertSame('68235', $codes[$this->ids['carmen']]);
        $this->assertSame('68684', $codes[$this->ids['miranda']]);
        $this->assertSame('68298', $codes[$this->ids['gambita']]);
        $this->assertNull($codes[$this->ids['sin_poligono']]);
        $this->assertNull($codes[$this->ids['bogota']]);

        $geo = DB::selectOne(
            "SELECT count(*) FILTER (WHERE m.geom IS NOT NULL) AS with_geom,
                    count(*) FILTER (WHERE m.geom IS NOT NULL AND (NOT ST_IsValid(m.geom) OR NOT ST_IsValid(m.geom_simplified)
                        OR GeometryType(m.geom) <> 'MULTIPOLYGON' OR ST_SRID(m.geom) <> 4326)) AS bad,
                    count(*) FILTER (WHERE m.geom IS NOT NULL AND NOT ST_Contains(d.geom, ST_PointOnSurface(m.geom))) AS outside
             FROM geographic_units m, geographic_units d
             WHERE d.id = ? AND m.type = 'municipality' AND m.id = ANY(?::uuid[])",
            [$this->ids['dept'], '{'.implode(',', $this->ids).'}']
        );
        $this->assertSame(4, (int) $geo->with_geom);
        $this->assertSame(0, (int) $geo->bad);
        $this->assertSame(0, (int) $geo->outside);

        // The self-intersecting Gámbita bow tie is repaired into two triangles.
        $this->assertSame(2, (int) DB::scalar('SELECT ST_NumGeometries(geom) FROM geographic_units WHERE id = ?', [$this->ids['gambita']]));
        $this->assertTrue((bool) DB::scalar(
            'SELECT ST_Equals(ST_Union(m.geom), p.geom) FROM geographic_units m, geographic_units p WHERE p.id = ? AND m.parent_id = p.id GROUP BY p.geom',
            [$this->ids['prov']]
        ));
        $this->assertNotNull(DB::scalar('SELECT geom FROM geographic_units WHERE id = ?', [$this->ids['dept']]));
        $this->assertNotNull(DB::scalar('SELECT geom_simplified FROM geographic_units WHERE id = ?', [$this->ids['dept']]));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'geo.import_municipios')->where('entity_id', $this->ids['dept'])->count());
    }

    public function test_without_parcial_a_department_missing_polygons_keeps_its_geometry(): void
    {
        $old = 'SRID=4326;MULTIPOLYGON(((-74 6,-72 6,-72 8,-74 8,-74 6)))';
        DB::update('UPDATE geographic_units SET geom = ST_GeomFromEWKT(?), geom_simplified = ST_GeomFromEWKT(?) WHERE id = ?', [$old, $old, $this->ids['dept']]);

        $this->import()
            ->expectsOutputToContain('El departamento no se recalcula')
            ->expectsOutputToContain('Polígonos cargados: 4 municipios, 1 provincias; el departamento no se recalculó.')
            ->assertSuccessful();

        $this->assertTrue((bool) DB::scalar('SELECT ST_Equals(geom, ST_GeomFromEWKT(?)) FROM geographic_units WHERE id = ?', [$old, $this->ids['dept']]));
        $this->assertNotNull(DB::scalar('SELECT geom FROM geographic_units WHERE id = ?', [$this->ids['prov']]));
    }

    public function test_a_province_missing_polygons_is_not_recalculated_without_parcial(): void
    {
        $this->unit('municipality', 'Zqx Otro Sin Poligono', $this->ids['prov']);

        $this->import()
            ->expectsOutputToContain('Provincia sin recalcular (le faltan municipios con polígono): Provincia test (Zqx Otro Sin Poligono).')
            ->expectsOutputToContain('Polígonos cargados: 4 municipios, 0 provincias; el departamento no se recalculó.')
            ->assertSuccessful();

        $this->assertNull(DB::scalar('SELECT geom FROM geographic_units WHERE id = ?', [$this->ids['prov']]));
        $this->assertNull(DB::scalar('SELECT geom FROM geographic_units WHERE id = ?', [$this->ids['dept']]));
        $this->assertSame(4, DB::table('geographic_units')->where('parent_id', $this->ids['prov'])->whereNotNull('geom')->count());
    }

    public function test_a_file_that_matches_nothing_aborts_without_writing(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'geo');
        file_put_contents($path, json_encode(['type' => 'FeatureCollection', 'features' => [[
            'type' => 'Feature', 'properties' => ['MPIO_CDPMP' => '68999', 'MPIO_CNMBRE' => 'INVENTADO'],
            'geometry' => ['type' => 'Polygon', 'coordinates' => [[[-73.1, 7.1], [-73.0, 7.1], [-73.0, 7.2], [-73.1, 7.1]]]],
        ]]]));
        $before = $this->snapshot();

        try {
            $this->artisan('geo:import-municipios', ['archivo' => $path, '--parcial' => true])
                ->expectsOutputToContain('Ningún feature del archivo cruzó con un municipio de Santander: no se guardó nada.')
                ->assertFailed();
        } finally {
            unlink($path);
        }

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'geo.import_municipios')->where('entity_id', $this->ids['dept'])->count());
    }

    public function test_a_department_without_code_gets_68_and_one_with_another_code_aborts(): void
    {
        DB::table('geographic_units')->where('id', $this->ids['dept'])->update(['official_code' => null]);

        $this->import()->expectsOutputToContain('no tiene código: se le fija 68')->assertSuccessful();
        $this->assertSame('68', DB::table('geographic_units')->where('id', $this->ids['dept'])->value('official_code'));

        DB::table('geographic_units')->where('id', $this->ids['dept'])->update(['official_code' => '99']);
        $before = $this->snapshot();

        $this->import()->expectsOutputToContain('tiene el código 99, no 68: no se guardó nada.')->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_running_it_again_leaves_the_same_data(): void
    {
        $this->import(['--parcial' => true])->assertSuccessful();
        $first = $this->snapshot();

        $this->import(['--parcial' => true])->expectsOutputToContain('Emparejados: 4 (código: 4)')->assertSuccessful();

        $this->assertSame($first, $this->snapshot());
    }

    public function test_a_code_already_held_by_another_municipio_aborts_everything(): void
    {
        DB::table('geographic_units')->where('id', $this->ids['bogota'])->update(['official_code' => '68001']);
        $before = $this->snapshot();

        $this->import()->expectsOutputToContain('Conflicto de código: 68001')->assertFailed();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_the_endpoint_returns_the_loaded_municipios(): void
    {
        $this->import()->assertSuccessful();

        $response = $this->actingAs($this->user(['view.mapa']))->get('/api/geo/municipios.geojson?simplified=1');
        $response->assertOk()->assertHeader('Content-Type', 'application/geo+json');
        $this->assertNotEmpty($response->headers->get('ETag'));

        $data = json_decode($response->getContent(), true);
        $this->assertSame('FeatureCollection', $data['type']);
        $this->assertCount(4, $data['features']);
        $bucaramanga = collect($data['features'])->firstWhere('properties.official_code', '68001');
        $this->assertSame($this->ids['bucaramanga'], $bucaramanga['id']);
        $this->assertSame(['id' => $this->ids['bucaramanga'], 'nombre' => 'Bucaramanga', 'official_code' => '68001',
            'provincia' => 'Provincia test', 'provincia_id' => $this->ids['prov']], $bucaramanga['properties']);
        $this->assertSame('MultiPolygon', $bucaramanga['geometry']['type']);

        $this->actingAs($this->user(['view.votantes']))->getJson('/api/geo/municipios.geojson')->assertForbidden();
    }
}
