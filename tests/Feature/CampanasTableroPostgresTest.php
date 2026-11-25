<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\ElectoralCampaign;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Opt-in checks of the Tablero (Fase 5) on a LOCAL PostgreSQL with the real territory and polygons:
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral php artisan test --filter=CampanasTableroPostgresTest
// Weeks in Bogotá time with the real timestamp column, the comunas of Bucaramanga, the polygons of the map and the time
// of a departamento board with 122.000 synthetic fichas, without and with cache. Everything is rolled back.
class CampanasTableroPostgresTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private string $santander;

    private string $bucaramanga;

    private string $giron;

    private array $municipios;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }
        $this->santander = (string) DB::table('geographic_units')->where('type', 'department')->where('official_code', '68')->value('id');
        $this->bucaramanga = (string) DB::table('geographic_units')->where('type', 'municipality')->where('official_code', '68001')->value('id');
        $this->giron = (string) DB::table('geographic_units')->where('type', 'municipality')->where('official_code', '68307')->value('id');
        if (! $this->santander || ! $this->bucaramanga || ! $this->giron
            || ! DB::table('geographic_units')->where('type', 'comuna')->where('parent_id', $this->bucaramanga)->exists()) {
            $this->markTestSkipped('The local database has no Santander territory with the comunas of Bucaramanga.');
        }
        $this->municipios = DB::table('geographic_units as g')->join('geographic_units as p', 'p.id', '=', 'g.parent_id')
            ->where('g.type', 'municipality')->where(fn ($q) => $q->where('p.id', $this->santander)->orWhere('p.parent_id', $this->santander))
            ->pluck('g.id')->all();

        config(['features.campanas_electorales' => ['enabled' => true, 'beta_users' => []]]);
        $this->user = User::create([
            'name' => 'Tablero PG', 'email' => 'tablero-pg-'.Str::random(6).'@test.local', 'password' => 'secret123',
            'role_id' => Role::where('code', 'R02_ADMIN_FUNCIONAL')->value('id'),
            'permissions' => ['view.mapa', 'view.votantes', 'write.data', 'view.campanas', 'manage.campanas'],
        ]);
        $this->user->forceFill(['campaign_id' => Campaign::DEFAULT_ID])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function crear(array $payload): ElectoralCampaign
    {
        $id = $this->actingAs($this->user)->postJson('/campanas-electorales', $payload + ['election_year' => 2027])->assertCreated()->json('data.campana.id');

        return ElectoralCampaign::findOrFail($id);
    }

    private function tablero(ElectoralCampaign $c, string $query = ''): array
    {
        return $this->actingAs($this->user)->getJson("/campanas-electorales/{$c->id}/tablero{$query}")->assertOk()->json('data');
    }

    private function fichas(int $n, string $geo, string $createdAt, ?string $comuna = null): void
    {
        DB::statement("INSERT INTO lideres (id, nombre, municipio, tipo, campaign_id, geographic_unit_id, comuna_id, comuna_source, is_active, fallecido, es_lider, created_at, updated_at)
            SELECT uuid_generate_v4(), 'TABLERO PG '||g, '', 'votante', ?, ?, ?, ?, true, false, false, ?::timestamp, ?::timestamp FROM generate_series(1, ?) AS g",
            [Campaign::DEFAULT_ID, $geo, $comuna, $comuna ? 'manual' : null, $createdAt, $createdAt, $n]);
    }

    public function test_weeks_use_bogota_time_on_the_real_timestamp_column(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-25 15:00:00', 'UTC'));
        $c = $this->crear(['name' => 'PG Tablero semanas', 'cargo' => 'alcaldia', 'scope_level' => 'municipality', 'territorio_ids' => [$this->giron]]);
        $antes = $this->actingAs($this->user)->getJson("/campanas-electorales/{$c->id}/tablero/territorio/{$this->giron}")->json('data.semanas');
        $base = array_column($antes, 'nuevos', 'inicio');

        $this->fichas(6, $this->giron, '2026-11-16 03:00:00');   // Sunday 15 in Bogotá
        $this->fichas(7, $this->giron, '2026-11-17 03:00:00');   // Monday 16 in Bogotá
        $this->fichas(301, $this->giron, '2026-10-27 15:00:00');
        $d = $this->actingAs($this->user)->getJson("/campanas-electorales/{$c->id}/tablero/territorio/{$this->giron}")->assertOk()->json('data');
        $semanas = array_column($d['semanas'], null, 'inicio');

        $this->assertSame(($base['2026-11-09'] ?? 0) + 6, $semanas['2026-11-09']['nuevos']);
        $this->assertSame(($base['2026-11-16'] ?? 0) + 7, $semanas['2026-11-16']['nuevos']);
        $this->assertTrue($semanas['2026-10-26']['masiva']);
        $this->assertSame('2026-11-23', end($d['semanas'])['inicio']);
    }

    public function test_bucaramanga_comunas_layer_coverage_and_polygons(): void
    {
        $c = $this->crear(['name' => 'PG Tablero Bga', 'cargo' => 'alcaldia', 'scope_level' => 'municipality', 'territorio_ids' => [$this->bucaramanga]]);
        $comuna = (string) DB::table('geographic_units')->where('type', 'comuna')->where('parent_id', $this->bucaramanga)->orderBy('official_code')->value('id');
        $this->actingAs($this->user)->putJson("/campanas-electorales/{$c->id}/metas", ['metas' => [['geographic_unit_id' => $comuna, 'votantes_meta' => 500]]])->assertOk();
        $d = $this->tablero($c);

        $total = DB::table('lideres')->where('campaign_id', Campaign::DEFAULT_ID)->where('geographic_unit_id', $this->bucaramanga)
            ->where('is_active', true)->where(fn ($q) => $q->where('fallecido', false)->orWhereNull('fallecido'))->count();
        $con = DB::table('lideres')->where('campaign_id', Campaign::DEFAULT_ID)->where('geographic_unit_id', $this->bucaramanga)
            ->where('is_active', true)->where(fn ($q) => $q->where('fallecido', false)->orWhereNull('fallecido'))->whereNotNull('comuna_id')->count();
        $bga = collect($d['territorios'])->firstWhere('id', $this->bucaramanga);
        $this->assertSame(['con' => $con, 'total' => $total], $bga['cobertura_comuna']);
        $this->assertSame('comunas', $d['mapa']['capa']);
        $this->assertSame(500, $bga['votantes']['meta']);
        $estado = collect($d['territorios'])->firstWhere('id', $comuna)['estado'];
        $this->assertSame($total > 0 && $con / $total < 0.6 ? 'insuficiente' : 'ok', $estado);
        $sinPoligono = DB::table('geographic_units')->where('parent_id', $this->bucaramanga)->whereIn('type', ['comuna', 'corregimiento'])
            ->whereNull('geom')->orderBy('canonical_name')->pluck('canonical_name')->all();
        $this->assertEqualsCanonicalizing($sinPoligono, $d['mapa']['sin_poligono']);
        $this->assertStringNotContainsString('sugerencia', json_encode($d));
    }

    public function test_a_departamento_board_with_122000_fichas_is_fast_without_and_with_cache(): void
    {
        config(['cache.default' => 'database']);
        $cd = (string) DB::table('political_organizations')->where('canonical_name', 'Centro Democrático')->value('id');
        $comunas = DB::table('geographic_units')->where('type', 'comuna')->where('parent_id', $this->bucaramanga)->pluck('id')->all();
        // 122.000 fichas over every municipio and 430 days, 5 % leaders; a third of Bucaramanga's with a comuna.
        DB::statement("INSERT INTO lideres (id, nombre, municipio, tipo, campaign_id, geographic_unit_id, comuna_id, is_active, fallecido, es_lider, created_at, updated_at)
            SELECT uuid_generate_v4(), 'PERF TABLERO '||g, '', 'votante', ?, (?::uuid[])[1 + (g % ?)], NULL, true, false, g % 20 = 0,
                   timestamp '2025-09-18 12:00:00' + (g % 430) * interval '1 day', now()
            FROM generate_series(1, 122000) AS g", [Campaign::DEFAULT_ID, '{'.implode(',', $this->municipios).'}', count($this->municipios)]);
        DB::statement("UPDATE lideres SET comuna_id = (?::uuid[])[1 + (abs(hashtext(id::text)) % ?)] WHERE nombre LIKE 'PERF TABLERO %' AND geographic_unit_id = ? AND abs(hashtext(id::text)) % 3 = 0",
            ['{'.implode(',', $comunas).'}', count($comunas), $this->bucaramanga]);
        DB::statement('ANALYZE lideres');
        $c = $this->crear(['name' => 'PG Tablero rendimiento', 'cargo' => 'gobernacion', 'political_organization_id' => $cd ?: null, 'election_date' => '2027-10-25']);
        $metas = [];
        foreach (array_slice($this->municipios, 0, 40) as $i => $m) {
            $metas[] = ['geographic_unit_id' => $m, 'votos_meta' => 1000 + $i, 'lideres_meta' => $i % 2 ? 30 : null];
        }
        $this->actingAs($this->user)->putJson("/campanas-electorales/{$c->id}/metas", ['metas' => $metas])->assertOk();

        $this->tablero($c, '?actualizar=1'); // warm up the PHP side (autoload, config) once
        $start = microtime(true);
        $sin = $this->tablero($c, '?actualizar=1');
        $sinMs = (microtime(true) - $start) * 1000;
        $start = microtime(true);
        $con = $this->tablero($c);
        $conMs = (microtime(true) - $start) * 1000;
        fwrite(STDERR, sprintf("\nTablero 122.000 fichas: sin caché %.0f ms, con caché %.0f ms, %d territorios, %d semanas\n",
            $sinMs, $conMs, count($sin['territorios']), count($sin['evolucion']['semanas'])));

        $this->assertLessThan(1500, $sinMs, "Sin caché: {$sinMs} ms");
        $this->assertLessThan(200, $conMs, "Con caché: {$conMs} ms");
        $this->assertSame($sin['totales'], $con['totales']);
        $this->assertGreaterThanOrEqual(122000, $sin['totales']['votantes']['registrados']);
        $this->assertSame(40, $sin['totales']['territorios']['con_meta']);
        $this->assertSame('municipios', $sin['mapa']['capa']);
        $semanas = array_sum(array_filter(array_column($sin['evolucion']['semanas'], 'nuevos')));
        $this->assertGreaterThan(100000, $semanas + (int) $sin['evolucion']['antes']);

        $this->actingAs($this->user);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson("/campanas-electorales/{$c->id}/tablero?actualizar=1")->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        fwrite(STDERR, "Consultas sin caché: {$queries}\n");
        $this->assertLessThan(25, $queries);
    }
}
