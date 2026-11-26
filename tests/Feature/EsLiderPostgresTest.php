<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use App\Services\Reportes\Fuentes\PersonasFuente;
use App\Support\EsLider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Opt-in: who counts as a leader (App\Support\EsLider) on a LOCAL PostgreSQL, with real accents and capitals:
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral php artisan test --filter=EsLiderPostgresTest
// The cases of EsLiderReglaTest give the same answer in SQL and PHP, and the board, the Votantes card and Reportes count
// the same fichas of a municipio (the local data plus the synthetic ones). Everything is rolled back.
class EsLiderPostgresTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private string $giron;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }
        $this->giron = (string) DB::table('geographic_units')->where('type', 'municipality')->where('official_code', '68307')->value('id');
        if (! $this->giron) {
            $this->markTestSkipped('The local database has no Girón.');
        }
        config(['features.campanas_electorales' => ['enabled' => true, 'beta_users' => []]]);
        $this->user = User::create([
            'name' => 'Líder PG', 'email' => 'lider-pg-'.Str::random(6).'@test.local', 'password' => 'secret123',
            'role_id' => Role::where('code', 'R02_ADMIN_FUNCIONAL')->value('id'),
            'permissions' => ['view.mapa', 'view.votantes', 'write.data', 'view.campanas', 'manage.campanas', 'view.reportes'],
        ]);
        $this->user->forceFill(['campaign_id' => Campaign::DEFAULT_ID])->save();
        PersonasFuente::olvidarEsquema();
    }

    private function ficha(string $nombre, ?string $cargo, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('lideres')->insert(array_merge([
            'id' => $id, 'campaign_id' => Campaign::DEFAULT_ID, 'geographic_unit_id' => $this->giron, 'municipio' => 'Girón',
            'nombre' => $nombre, 'cargo' => $cargo, 'tipo' => 'votante', 'is_active' => true, 'fallecido' => false, 'es_lider' => false,
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));

        return $id;
    }

    public function test_sql_and_php_agree_with_real_accents_and_capitals(): void
    {
        $ids = [];
        foreach (EsLiderReglaTest::CASOS as $nombre => [$cargo, $extra]) {
            $ids[$nombre] = $this->ficha("ZZLIDERPG {$nombre}", $cargo, $extra);
        }
        $porSql = EsLider::where(DB::table('lideres'))->whereIn('id', $ids)->pluck('nombre')->sort()->values()->all();
        $esperados = collect(EsLiderReglaTest::CASOS)->filter(fn ($c) => $c[2])->keys()->map(fn ($n) => "ZZLIDERPG {$n}")->sort()->values()->all();
        $this->assertSame($esperados, $porSql);
        foreach (DB::table('lideres')->whereIn('id', $ids)->get() as $row) {
            $this->assertSame(EsLiderReglaTest::CASOS[substr($row->nombre, 10)][2], EsLider::de($row), "PHP: {$row->nombre}");
        }

        // The rule over the whole local table: PHP row by row says the same as the SQL.
        $local = DB::table('lideres')->whereNotNull('cargo')->where('cargo', '!=', '')->limit(5000)->get(['id', 'cargo', 'tipo', 'es_lider', 'fallecido']);
        $sqlIds = EsLider::where(DB::table('lideres'))->whereIn('id', $local->pluck('id'))->pluck('id')->sort()->values()->all();
        $phpIds = $local->filter(fn ($r) => EsLider::de($r))->pluck('id')->sort()->values()->all();
        $this->assertSame($phpIds, $sqlIds);
    }

    public function test_board_votantes_and_reportes_count_the_same_in_a_municipio(): void
    {
        $id = $this->actingAs($this->user)->postJson('/campanas-electorales', [
            'name' => 'PG Líderes Girón', 'cargo' => 'alcaldia', 'scope_level' => 'municipality', 'territorio_ids' => [$this->giron], 'election_year' => 2027,
        ])->assertCreated()->json('data.campana.id');
        $tablero = fn () => $this->actingAs($this->user)->getJson("/campanas-electorales/{$id}/tablero?actualizar=1")->assertOk()->json('data.totales.lideres.registrados');
        $antes = $tablero();

        $this->ficha('ZZLIDERPG uno', 'LÍDER');
        $this->ficha('ZZLIDERPG dos', 'líder, Candidato Alcaldía 2027');
        $this->ficha('ZZLIDERPG tres', null, ['tipo' => 'nuevo_lider']);
        $this->ficha('ZZLIDERPG cuatro', 'Concejal', ['es_lider' => true]);
        $this->ficha('ZZLIDERPG no', 'Directorio Municipal');
        $this->ficha('ZZLIDERPG muerto', 'Líder', ['fallecido' => true]);
        $this->ficha('ZZLIDERPG inactivo', 'Líder', ['is_active' => false]);
        $total = $tablero();
        $this->assertSame($antes + 4, $total);

        $municipio = rawurlencode((string) DB::table('geographic_units')->where('id', $this->giron)->value('canonical_name'));
        $props = $this->actingAs($this->user)->get('/votantes?campana='.Campaign::DEFAULT_ID."&municipio={$municipio}")->assertOk()->viewData('page')['props'];
        $this->assertSame($total, $props['counters']['es_lider']);
        $filtrado = $this->actingAs($this->user)->get('/votantes?campana='.Campaign::DEFAULT_ID."&municipio={$municipio}&es_lider=1")->assertOk()->viewData('page')['props'];
        $this->assertSame($total, $filtrado['votantes']['total']);

        $spec = ['v' => 1, 'fuente' => 'personas', 'filtros' => ['marcas' => ['es_lider'], 'municipio' => [$this->giron]] + $this->grupo(),
            'columnas' => ['nombre', 'marcas'], 'orden' => ['campo' => 'nombre', 'dir' => 'asc'], 'busqueda' => 'ZZLIDERPG'];
        $this->actingAs($this->user)->postJson('/reportes/personas/conteo', ['spec' => $spec])->assertOk()->assertJsonPath('total', 4);
        $spec['busqueda'] = '';
        $this->actingAs($this->user)->postJson('/reportes/personas/conteo', ['spec' => $spec])->assertOk()->assertJsonPath('total', $total);
    }

    // The grupo filter exists only when the user sees more than one group.
    private function grupo(): array
    {
        return DB::table('campaigns')->where('parent_id', Campaign::DEFAULT_ID)->exists() ? ['grupo' => [Campaign::DEFAULT_ID]] : [];
    }
}
