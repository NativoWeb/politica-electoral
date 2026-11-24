<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Services\Geo\PuestoMatcher;
use App\Services\Geo\Trigram;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Opt-in checks of the comuna cascade on a LOCAL PostGIS database that already has the Bucaramanga comunas
// (geo:import-comunas): geo:import-puestos with the real file of database/data/geo, the comuna_source constraint, the
// read-only dry-run and a real run + revert with synthetic fichas, and the PHP trigram against pg_trgm.
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral php artisan test --filter=ComunaCascadaPostgresTest
// Everything runs inside a transaction that is rolled back; the puestos already loaded are removed inside it.
class ComunaCascadaPostgresTest extends TestCase
{
    use DatabaseTransactions;

    private string $bga;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }
        $bga = DB::table('geographic_units')->where('type', 'municipality')->where('official_code', '68001')->value('id');
        if (! $bga || ! DB::table('geographic_units')->where('parent_id', $bga)->where('type', 'comuna')->exists()) {
            $this->markTestSkipped('The local database has no Bucaramanga comunas: run geo:import-comunas first.');
        }
        $this->bga = $bga;
        DB::table('puestos_votacion')->delete();
        app(PuestoMatcher::class)->forget();
    }

    private function comuna(string $code): string
    {
        return DB::table('geographic_units')->where('official_code', "68001-{$code}")->value('id');
    }

    private function puestosSnapshot(): string
    {
        return (string) DB::scalar("SELECT md5(coalesce(string_agg(nombre_normalizado || coalesce(comuna_unit_id::text, '') || coalesce(comuna_origen, '')
            || coalesce(alias::text, '') || coalesce(ST_AsText(geom), ''), ',' ORDER BY nombre_normalizado), '')) FROM puestos_votacion");
    }

    // A synthetic ficha (no hooks, like a bulk load) in Bucaramanga.
    private function ficha(array $values): string
    {
        $id = (string) Str::uuid();
        DB::table('lideres')->insert($values + [
            'id' => $id, 'campaign_id' => Campaign::DEFAULT_ID, 'nombre' => 'CASCADA TEST '.Str::random(5), 'municipio' => 'Bucaramanga',
            'provincia' => '', 'geographic_unit_id' => $this->bga, 'cargo' => '', 'tipo' => 'votante', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function comunaOf(string $id): array
    {
        $r = DB::table('lideres')->where('id', $id)->first(['comuna_id', 'comuna_source']);

        return [$r->comuna_id, $r->comuna_source];
    }

    public function test_import_puestos_loads_88_with_their_comuna_and_is_idempotent(): void
    {
        $this->artisan('geo:import-puestos', ['--dry-run' => true])
            ->expectsOutputToContain('Puestos en el archivo: 88.')
            ->expectsOutputToContain('IE RURAL BOSCONIA: dataset 68001-C02, polígono 68001-R2')
            ->expectsOutputToContain('DRY-RUN: no se guardó nada.')
            ->assertSuccessful();
        $this->assertSame(0, DB::table('puestos_votacion')->count());
        $audits = DB::table('audit_logs')->where('action', 'geo.import_puestos')->count();

        $this->artisan('geo:import-puestos')
            ->expectsTable(['Creados', 'Actualizados', 'Sin cambio', 'Comuna por polígono', 'Sin comuna', 'Discrepancias', 'En la BD y no en el archivo'],
                [[88, 0, 0, 2, 0, 6, 0]])
            ->assertSuccessful();
        $this->assertSame(88, DB::table('puestos_votacion')->where('municipio_id', $this->bga)->whereNotNull('geom')->whereNotNull('comuna_unit_id')->count());
        $this->assertSame($this->comuna('C07'), DB::table('puestos_votacion')->where('nombre', 'UNIDADES TECNOLOGICAS DE SANT')->value('comuna_unit_id'));
        $this->assertSame($this->comuna('R1'), DB::table('puestos_votacion')->where('nombre', 'EL PABLON')->value('comuna_unit_id'));
        // The two prisons have no comuna in the dataset: the polygon gives it.
        $this->assertSame(2, DB::table('puestos_votacion')->where('comuna_origen', 'poligono')->count());
        $this->assertSame($audits + 1, DB::table('audit_logs')->where('action', 'geo.import_puestos')->count());

        $before = $this->puestosSnapshot();
        $this->artisan('geo:import-puestos')
            ->expectsTable(['Creados', 'Actualizados', 'Sin cambio', 'Comuna por polígono', 'Sin comuna', 'Discrepancias', 'En la BD y no en el archivo'],
                [[0, 0, 88, 2, 0, 6, 0]])
            ->assertSuccessful();
        $this->assertSame($before, $this->puestosSnapshot());
    }

    public function test_the_constraint_takes_the_new_origins_and_nothing_else(): void
    {
        $id = $this->ficha(['comuna_id' => $this->comuna('C12'), 'comuna_source' => 'puesto']);
        foreach (['auto', 'texto', 'direccion', 'manual'] as $source) {
            DB::table('lideres')->where('id', $id)->update(['comuna_source' => $source]);
        }

        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('lideres')->where('id', $id)->update(['comuna_source' => 'adivinada']));
    }

    public function test_dry_run_is_read_only_and_a_real_run_can_be_reverted(): void
    {
        $this->artisan('geo:import-puestos')->assertSuccessful();
        $puesto = $this->ficha(['puesto_votacion' => 'UNIDADES TECNOLOGICAS DE SANTANDER', 'municipio_votacion' => 'BUCARAMANGA']);
        $texto = $this->ficha(['barrio' => 'COMUNA 14']);
        $ambiguo = $this->ficha(['puesto_votacion' => 'COLEGIO AURELIO MARTINEZ MUTIS', 'municipio_votacion' => 'BUCARAMANGA']);
        $florida = $this->ficha(['puesto_votacion' => 'UTS', 'municipio_votacion' => 'FLORIDABLANCA']);
        $cambios = DB::table('comuna_cambios')->count();

        $this->artisan('geo:asignar-comunas', ['--tabla' => ['lideres'], '--dry-run' => true])->expectsOutputToContain('DRY-RUN')->assertSuccessful();
        $this->assertSame([null, null], $this->comunaOf($puesto));
        $this->assertSame($cambios, DB::table('comuna_cambios')->count());

        $this->artisan('geo:asignar-comunas', ['--tabla' => ['lideres']])->assertSuccessful();
        $this->assertSame([$this->comuna('C07'), 'puesto'], $this->comunaOf($puesto));
        $this->assertSame([$this->comuna('C14'), 'texto'], $this->comunaOf($texto));
        $this->assertSame([null, null], $this->comunaOf($ambiguo));
        $this->assertSame([null, null], $this->comunaOf($florida));
        $lote = DB::table('comuna_cambios')->where('registro_id', $puesto)->value('lote');
        $this->assertNotNull($lote);

        $this->artisan('geo:revertir-comunas', ['--lote' => $lote])->assertSuccessful();
        $this->assertSame([null, null], $this->comunaOf($puesto));
        $this->assertSame([null, null], $this->comunaOf($texto));
    }

    public function test_the_php_trigram_matches_pg_trgm(): void
    {
        $pairs = [['IE INEM CUSTODIO GARCIA ROV SEDE A', 'INEM SEDE A'], ['COL AURELIO MARTINEZ MUTIS', 'COL AURELIO MARTINEZ MUTIS SEDE B'],
            ['UNIV INDUSTRIAL DE SANTANDER', 'UNIV INDUSTRIAL'], ['ESC NORMAL SUPERIOR SEDE A', 'ESC NORMAL SUPERIOR'], ['word', 'two words']];
        foreach ($pairs as [$a, $b]) {
            $this->assertEqualsWithDelta((float) DB::scalar('SELECT similarity(?, ?)', [$a, $b]), Trigram::similarity($a, $b), 1e-6, "{$a} / {$b}");
        }
    }
}
