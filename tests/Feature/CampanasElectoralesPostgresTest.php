<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\ElectoralCampaign;
use App\Models\Role;
use App\Models\User;
use App\Services\CampanasElectorales\DetalleCampana;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

// Opt-in checks of Campañas electorales on a LOCAL PostgreSQL with the real electoral results (2023 and 2026):
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral php artisan test --filter=CampanasElectoralesPostgresTest
// The suggestion is compared with what /asamblea and /concejo/{id} show, the progress with the fichas in the database,
// and the detail of a departamento campaign is timed with 130.000 synthetic fichas. Everything is rolled back.
class CampanasElectoralesPostgresTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private string $cd;

    private string $santander;

    private string $bucaramanga;

    private string $giron;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }
        $this->cd = (string) DB::table('political_organizations')->where('canonical_name', 'Centro Democrático')->value('id');
        $this->santander = (string) DB::table('geographic_units')->where('type', 'department')->where('official_code', '68')->value('id');
        $this->bucaramanga = (string) DB::table('geographic_units')->where('type', 'municipality')->where('official_code', '68001')->value('id');
        $this->giron = (string) DB::table('geographic_units')->where('type', 'municipality')->where('official_code', '68307')->value('id');
        if (! $this->cd || ! $this->santander || ! $this->bucaramanga || ! $this->giron
            || ! DB::table('contests')->join('corporations', 'corporations.id', '=', 'contests.corporation_id')->where('corporations.name', 'Asamblea')->exists()) {
            $this->markTestSkipped('The local database has no electoral results of Santander loaded.');
        }

        config(['features.campanas_electorales' => ['enabled' => true, 'beta_users' => []]]);
        $this->user = User::create([
            'name' => 'Campañas PG', 'email' => 'campanas-pg-'.Str::random(6).'@test.local', 'password' => 'secret123',
            'role_id' => Role::where('code', 'R02_ADMIN_FUNCIONAL')->value('id'),
            'permissions' => ['view.mapa', 'view.votantes', 'write.data', 'view.campanas', 'manage.campanas'],
        ]);
        $this->user->forceFill(['campaign_id' => Campaign::DEFAULT_ID])->save();
    }

    private function crear(array $payload): ElectoralCampaign
    {
        $id = $this->actingAs($this->user)->postJson('/campanas-electorales', $payload + ['election_year' => 2027])->assertCreated()->json('data.campana.id');

        return ElectoralCampaign::findOrFail($id);
    }

    private function detalle(ElectoralCampaign $c): array
    {
        return $this->actingAs($this->user)->getJson("/campanas-electorales/{$c->id}")->assertOk()->json('data');
    }

    public function test_asamblea_total_equals_the_list_votes_of_asamblea_plus_its_candidates(): void
    {
        $c = $this->crear(['name' => 'PG Asamblea', 'cargo' => 'asamblea', 'political_organization_id' => $this->cd]);
        $d = $this->detalle($c);

        $lista = null;
        $this->actingAs($this->user)->get('/asamblea')->assertOk()->assertInertia(function (AssertableInertia $p) use (&$lista) {
            $lista = collect($p->toArray()['props']['votosLista'])->where('id', $this->cd)->sum('total');
        });
        $nominal = (int) DB::scalar("SELECT COALESCE(SUM(er.value), 0) FROM electoral_results er
            JOIN contests con ON con.id = er.contest_id JOIN corporations co ON co.id = con.corporation_id
            WHERE co.name = 'Asamblea' AND er.organization_id IS NULL AND er.metric_type IN ('votes', 'nominal_votes')
              AND er.candidacy_id IN (SELECT candidacy_id FROM candidacy_endorsements WHERE is_primary AND organization_id = ?)", [$this->cd]);

        $this->assertGreaterThan(0, $lista);
        $this->assertSame($lista + $nominal, $d['totales']['sugerencia']['base']);
        $this->assertSame('Asamblea 2023', $d['sugerencias']['comparable']);
        $this->assertSame(intdiv(($lista + $nominal) * 110 + 50, 100), $d['totales']['sugerencia']['votos']);

        // Per municipio: the list votes /asamblea/{id} shows plus every candidate of the party there. The screen only
        // lists the 30 candidates with most votes, so its own sum can be lower than the party's (Girón: 5.548 of 6.769).
        $giron = collect($d['filas'])->firstWhere('id', $this->giron)['sugerencia']['base'];
        $listaGiron = null;
        $this->actingAs($this->user)->get("/asamblea/{$this->giron}")->assertOk()->assertInertia(function (AssertableInertia $p) use (&$listaGiron) {
            $listaGiron = collect($p->toArray()['props']['votosLista'])->where('id', $this->cd)->sum('total');
        });
        $nominalGiron = (int) DB::scalar("SELECT COALESCE(SUM(er.value), 0) FROM electoral_results er
            JOIN contests con ON con.id = er.contest_id JOIN corporations co ON co.id = con.corporation_id
            WHERE co.name = 'Asamblea' AND er.organization_id IS NULL AND er.geographic_unit_id = ?
              AND er.candidacy_id IN (SELECT candidacy_id FROM candidacy_endorsements WHERE is_primary AND organization_id = ?)", [$this->giron, $this->cd]);
        $this->assertSame($listaGiron + $nominalGiron, $giron);
    }

    public function test_concejo_of_bucaramanga_equals_the_concejo_screen(): void
    {
        $c = $this->crear(['name' => 'PG Concejo', 'cargo' => 'concejo', 'scope_level' => 'municipality', 'territorio_ids' => [$this->bucaramanga],
            'political_organization_id' => $this->cd]);
        $base = collect($this->detalle($c)['filas'])->firstWhere('id', $this->bucaramanga)['sugerencia']['base'];

        $pantalla = null;
        $this->actingAs($this->user)->get("/concejo/{$this->bucaramanga}")->assertOk()->assertInertia(function (AssertableInertia $p) use (&$pantalla) {
            $pantalla = collect($p->toArray()['props']['concejales'])->where('partido', 'Centro Democrático')->sum('votos');
        });
        $this->assertGreaterThan(0, $pantalla);
        $this->assertSame($pantalla, $base);
    }

    public function test_gobernacion_progress_and_constraints_on_postgres(): void
    {
        $c = $this->crear(['name' => 'PG Gobernación', 'cargo' => 'gobernacion', 'political_organization_id' => $this->cd]);
        $d = $this->detalle($c);

        $this->assertCount(DB::table('geographic_units as g')->join('geographic_units as p', 'p.id', '=', 'g.parent_id')
            ->where('g.type', 'municipality')->where(fn ($q) => $q->where('p.id', $this->santander)->orWhere('p.parent_id', $this->santander))->count(),
            array_filter($d['filas'], fn ($f) => $f['tipo'] === 'municipality'));
        $this->assertSame('Gobernación 2023', $d['sugerencias']['comparable']);
        $this->assertStringContainsString('(no hay Gobernación por municipio)', collect($d['filas'])->firstWhere('id', $this->giron)['sugerencia']['texto'] ?? '');

        $esperado = DB::table('lideres as l')->join('geographic_units as g', 'g.id', '=', 'l.geographic_unit_id')
            ->join('geographic_units as p', 'p.id', '=', 'g.parent_id')
            ->where(fn ($q) => $q->where('p.id', $this->santander)->orWhere('p.parent_id', $this->santander))
            ->where('l.campaign_id', Campaign::DEFAULT_ID)->where('l.is_active', true)->where('l.fallecido', false)->count();
        $this->assertSame($esperado, $d['totales']['avance']['votantes']);
        $sinComuna = DB::table('lideres')->where('campaign_id', Campaign::DEFAULT_ID)->where('geographic_unit_id', $this->bucaramanga)
            ->where('is_active', true)->where('fallecido', false)->whereNull('comuna_id')->count();
        $this->assertSame($sinComuna, collect($d['filas'])->firstWhere('id', $this->bucaramanga)['sin_comuna']['votantes']);

        $this->actingAs($this->user)->putJson("/campanas-electorales/{$c->id}/metas", ['metas' => [['geographic_unit_id' => $this->giron, 'votos_meta' => 1200]]])
            ->assertOk()->assertJsonPath('data.totales.votos_meta', 1200);

        // The database refuses what the screens never send.
        $this->expectException(QueryException::class);
        DB::table('electoral_campaign_goals')->where('electoral_campaign_id', $c->id)->update(['votos_meta' => -1]);
    }

    public function test_the_active_name_is_unique_per_group_ignoring_case_in_the_database(): void
    {
        $c = $this->crear(['name' => 'PG Única', 'cargo' => 'gobernacion']);
        $this->expectException(QueryException::class);
        DB::table('electoral_campaigns')->insert([
            'id' => (string) Str::uuid(), 'campaign_id' => $c->campaign_id, 'name' => 'pg ÚNICA', 'cargo' => 'gobernacion',
            'election_year' => 2027, 'scope_level' => 'department', 'status' => 'active',
        ]);
    }

    public function test_the_detail_of_a_departamento_campaign_with_130000_fichas_is_fast(): void
    {
        $municipios = DB::table('geographic_units as g')->join('geographic_units as p', 'p.id', '=', 'g.parent_id')
            ->where('g.type', 'municipality')->where(fn ($q) => $q->where('p.id', $this->santander)->orWhere('p.parent_id', $this->santander))
            ->pluck('g.id')->all();
        DB::statement("INSERT INTO lideres (id, nombre, municipio, tipo, campaign_id, geographic_unit_id, is_active, es_lider, created_at, updated_at)
            SELECT uuid_generate_v4(), 'PERF CAMPANAS '||g, '', 'votante', ?, (?::uuid[])[1 + (g % ?)], true, g % 20 = 0, now(), now()
            FROM generate_series(1, 130000) AS g", [Campaign::DEFAULT_ID, '{'.implode(',', $municipios).'}', count($municipios)]);
        DB::statement('ANALYZE lideres');
        $c = $this->crear(['name' => 'PG Rendimiento', 'cargo' => 'gobernacion', 'political_organization_id' => $this->cd]);

        $this->detalle($c);
        $start = microtime(true);
        $d = $this->detalle($c);
        $ms = (microtime(true) - $start) * 1000;
        $this->assertLessThan(800, $ms, "Detalle: {$ms} ms");
        $this->assertGreaterThanOrEqual(130000, $d['totales']['avance']['votantes']);
        $this->assertGreaterThanOrEqual(6500, $d['totales']['avance']['lideres']);

        $this->actingAs($this->user);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $modulo = app(DetalleCampana::class);
        $modulo->detalle($modulo->access()->find($c->id));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThan(15, $queries);
    }
}
