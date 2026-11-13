<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use App\Services\Geo\BarrioMatcher;
use App\Services\Geo\ComunasGeoJson;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Opt-in checks of geo:import-comunas (with the real files of database/data/geo), pg_trgm matching, geo:asignar-comunas
// and GET /api/geo/comunas.geojson on a LOCAL PostGIS database that already has the Bucaramanga polygon
// (geo:import-municipios):
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral php artisan test --filter=GeoComunasPostgresTest
// Everything runs inside a transaction that is rolled back; the comunas and barrios already loaded are removed inside it
// so each test starts from a first load.
class GeoComunasPostgresTest extends TestCase
{
    use DatabaseTransactions;

    private string $bga;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }

        $bga = DB::table('geographic_units')->where('type', 'municipality')->where('official_code', '68001')->whereNotNull('geom')->value('id');
        if (! $bga) {
            $this->markTestSkipped('The local database has no Bucaramanga polygon: run geo:import-municipios first.');
        }
        $this->bga = $bga;

        foreach (['lideres', 'event_attendees', 'organizaciones'] as $table) {
            DB::table($table)->whereNotNull('comuna_id')->update(['comuna_id' => null, 'comuna_source' => null]);
        }
        DB::table('barrio_aliases')->delete();
        DB::table('barrios')->delete();
        DB::table('geographic_units')->whereIn('type', ['comuna', 'corregimiento'])->delete();
        ComunasGeoJson::flush('68001');
    }

    private function snapshot(): string
    {
        return (string) DB::scalar(
            "SELECT md5(
                (SELECT coalesce(string_agg(id::text || coalesce(official_code, '') || coalesce(metadata::text, '')
                    || coalesce(md5(ST_AsBinary(geom)), '') || coalesce(md5(ST_AsBinary(geom_simplified)), ''), ',' ORDER BY id), '') FROM geographic_units)
                || (SELECT coalesce(string_agg(nombre_normalizado || comuna_unit_id::text || fuente || coalesce(poblacion::text, '')
                    || coalesce(md5(ST_AsBinary(geom)), ''), ',' ORDER BY nombre_normalizado), '') FROM barrios))"
        );
    }

    private function comuna(string $code): string
    {
        return DB::table('geographic_units')->where('official_code', "68001-{$code}")->value('id');
    }

    private function barrioComuna(string $norm): ?string
    {
        return DB::table('barrios as b')->join('geographic_units as c', 'c.id', '=', 'b.comuna_unit_id')
            ->where('b.municipio_id', $this->bga)->where('b.nombre_normalizado', $norm)->value('c.official_code');
    }

    private function lider(string $barrio): string
    {
        $id = (string) Str::uuid();
        DB::table('lideres')->insert([
            'id' => $id, 'campaign_id' => Campaign::DEFAULT_ID, 'nombre' => 'F3C TEST '.Str::random(5), 'municipio' => 'Bucaramanga',
            'provincia' => '', 'geographic_unit_id' => $this->bga, 'barrio' => $barrio, 'cargo' => '', 'tipo' => 'directorio',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_dry_run_reports_and_writes_nothing(): void
    {
        $before = $this->snapshot();
        $audits = DB::table('audit_logs')->count();

        $this->artisan('geo:import-comunas', ['--dry-run' => true])
            ->expectsOutputToContain('Municipio: Bucaramanga (68001).')
            ->expectsOutputToContain('QUEBRADA LA IGLESIA I: AMB COD_COMUNA C17, geometría C11')
            ->expectsOutputToContain('DRY-RUN: no se guardó nada.')
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame($audits, DB::table('audit_logs')->count());
        $this->assertSame(0, DB::table('barrios')->count());
    }

    public function test_it_loads_17_comunas_and_3_corregimientos_valid_and_inside_bucaramanga(): void
    {
        $this->artisan('geo:import-comunas')
            ->expectsOutputToContain('Cargados en Bucaramanga: 17 comunas, 3 corregimientos')
            ->assertSuccessful();

        $rows = DB::select(
            "SELECT c.type, count(*) AS n,
                    bool_and(ST_IsValid(c.geom) AND ST_IsValid(c.geom_simplified)) AS valid,
                    bool_and(ST_Contains(m.geom, ST_PointOnSurface(c.geom))) AS inside,
                    bool_and(c.level = 4 AND c.is_active) AS flags
             FROM geographic_units c JOIN geographic_units m ON m.id = c.parent_id
             WHERE m.id = ? AND c.type IN ('comuna', 'corregimiento') GROUP BY c.type ORDER BY c.type",
            [$this->bga]
        );
        $this->assertSame([['comuna', 17], ['corregimiento', 3]], array_map(fn ($r) => [$r->type, (int) $r->n], $rows));
        foreach ($rows as $row) {
            $this->assertTrue($row->valid && $row->inside && $row->flags, $row->type);
        }

        $c01 = DB::table('geographic_units')->where('official_code', '68001-C01')->first();
        $this->assertSame('Comuna 1 - Norte', $c01->canonical_name);
        $this->assertSame(66710, json_decode($c01->metadata, true)['poblacion']);
        $this->assertNotEmpty(json_decode(DB::table('geographic_units')->where('official_code', '68001-R1')->value('metadata'), true)['veredas']);

        $this->assertSame(311, DB::table('barrios')->where('municipio_id', $this->bga)->count());
        $this->assertSame(0, DB::table('barrios')->whereNotNull('geom')->whereRaw('NOT ST_IsValid(geom)')->count());
        $this->assertSame('68001-C08', $this->barrioComuna('CORDONCILLO 1'));
        // The polygon wins over the layer's COD_COMUNA and over the lists...
        $this->assertSame('68001-C11', $this->barrioComuna('QUEBRADA LA IGLESIA 1'));
        // ...unless it lies outside every comuna: then the Planeación list decides.
        $this->assertSame('68001-C02', $this->barrioComuna('BOSCONIA'));
        $this->assertSame('68001-R1', $this->barrioComuna('ANGELINOS'));

        $audit = DB::table('audit_logs')->where('action', 'geo.import_comunas')->latest('created_at')->first();
        $this->assertSame($this->bga, $audit->entity_id);
    }

    public function test_running_it_again_leaves_the_same_data_and_never_drops_a_geometry(): void
    {
        $this->artisan('geo:import-comunas')->assertSuccessful();
        $after = $this->snapshot();
        $withGeom = DB::table('barrios')->whereNotNull('geom')->count();

        $this->artisan('geo:import-comunas')->expectsOutputToContain('nuevos: 0, ya existentes: 311')->assertSuccessful();
        $this->assertSame($after, $this->snapshot());

        // Without the AMB polygons the lists still load, and the polygons already stored are kept.
        $this->artisan('geo:import-comunas', ['--barrios' => base_path('tests/Fixtures/geo/barrios_vacio.geojson')])->assertSuccessful();
        $this->assertSame($withGeom, DB::table('barrios')->whereNotNull('geom')->count());
    }

    public function test_a_comuna_outside_the_municipio_aborts_without_writing(): void
    {
        $before = $this->snapshot();

        $this->artisan('geo:import-comunas', ['--comunas' => base_path('tests/Fixtures/geo/comunas_fuera.geojson')])
            ->expectsOutputToContain('La comuna C01 queda fuera del polígono del municipio.')
            ->expectsOutputToContain('Las comunas no son válidas: no se guardó nada.')
            ->assertFailed();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_similarity_assigns_a_clear_winner_and_flags_a_tie_between_comunas(): void
    {
        $this->artisan('geo:import-comunas')->assertSuccessful();
        $matcher = app(BarrioMatcher::class);

        $galan = $matcher->match($this->bga, Campaign::DEFAULT_ID, 'JOSE ANTONIO GALAN');
        $this->assertSame([BarrioMatcher::ASIGNADO, 'similitud', $this->comuna('C05')], [$galan['status'], $galan['metodo'], $galan['comuna_id']]);
        $this->assertGreaterThanOrEqual(BarrioMatcher::MIN_SIMILARITY, $galan['score']);

        foreach ([['VILLA PRUEBA NORTE', 'C01'], ['VILLA PRUEBA SUR', 'C11']] as [$name, $code]) {
            DB::table('barrios')->insert(['id' => (string) Str::uuid(), 'municipio_id' => $this->bga, 'comuna_unit_id' => $this->comuna($code),
                'nombre' => $name, 'nombre_normalizado' => $name, 'fuente' => 'planeacion', 'created_at' => now(), 'updated_at' => now()]);
        }
        $matcher->forget();
        $tie = $matcher->match($this->bga, Campaign::DEFAULT_ID, 'VILLA PRUEBA');
        $this->assertSame(BarrioMatcher::AMBIGUO, $tie['status']);
        $this->assertCount(2, $tie['candidatos']);
    }

    public function test_the_command_assigns_the_real_barrio_texts(): void
    {
        $this->artisan('geo:import-comunas')->assertSuccessful();
        $ids = [];
        foreach (['CORDONCILLO I', 'CORDONCILLO 1', 'LA PAZ, FLORIDABLANCA', 'CONJUNTO RESIDENCIAL PINARES CAMPESTRE, PIEDECUESTA', '05 DE ENERO', 'MIRADOR DE FATIMA CASA 33'] as $text) {
            $ids[$text] = $this->lider($text);
        }

        $this->artisan('geo:asignar-comunas', ['--tabla' => ['lideres']])->assertSuccessful();

        $comunas = DB::table('lideres')->whereIn('id', $ids)->pluck('comuna_id', 'id');
        $this->assertSame($this->comuna('C08'), $comunas[$ids['CORDONCILLO I']]);
        $this->assertSame($this->comuna('C08'), $comunas[$ids['CORDONCILLO 1']]);
        $this->assertSame($this->comuna('C04'), $comunas[$ids['05 DE ENERO']]);
        $this->assertNull($comunas[$ids['LA PAZ, FLORIDABLANCA']]);
        $this->assertNull($comunas[$ids['CONJUNTO RESIDENCIAL PINARES CAMPESTRE, PIEDECUESTA']]);
        $this->assertNull($comunas[$ids['MIRADOR DE FATIMA CASA 33']]);
    }

    public function test_the_endpoint_returns_the_comunas_with_etag(): void
    {
        $this->artisan('geo:import-comunas')->assertSuccessful();
        $user = User::create([
            'name' => 'Comunas PG', 'email' => 'comunas-pg-'.Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => Role::where('code', 'R09_CAMPO')->value('id'), 'permissions' => ['view.mapa'],
        ]);
        $user->forceFill(['campaign_id' => Campaign::DEFAULT_ID])->save();

        $res = $this->actingAs($user)->get('/api/geo/comunas.geojson?municipio=68001')->assertOk()
            ->assertHeader('Content-Type', 'application/geo+json');
        $body = json_decode($res->getContent(), true);
        $this->assertSame('FeatureCollection', $body['type']);
        $this->assertCount(20, $body['features']);
        $first = $body['features'][0]['properties'];
        $this->assertSame(['68001-C01', 'comuna', 1, 66710, 'Bucaramanga'], [$first['codigo'], $first['tipo'], $first['numero'], $first['poblacion'], $first['municipio']]);
        $this->assertContains($body['features'][0]['geometry']['type'], ['MultiPolygon']);

        $this->actingAs($user)->get('/api/geo/comunas.geojson?municipio=68001', ['If-None-Match' => $res->headers->get('ETag')])->assertStatus(304);
        $empty = json_decode($this->actingAs($user)->get('/api/geo/comunas.geojson?municipio=68276')->assertOk()->getContent(), true);
        $this->assertSame([], $empty['features']);
    }
}
