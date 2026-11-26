<?php

namespace Tests\Feature;

use App\Models\ElectoralCampaign;
use App\Services\Reportes\Fuentes\PersonasFuente;
use App\Support\EsLider;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsCampanasScenario;
use Tests\TestCase;

// Who counts as a leader (App\Support\EsLider, decision of 2026-11-26): the word «líder» in the cargo, tipo lider /
// nuevo_lider or the box; never a deceased ficha. The SQL and the PHP say the same, and the board, the Votantes card and
// Reportes count the same fichas. The same cases on PostgreSQL: EsLiderPostgresTest.
class EsLiderReglaTest extends TestCase
{
    use BuildsCampanasScenario;

    // [cargo, extra columns, counts]
    public const CASOS = [
        'LIDER COLONIAL' => ['LIDER COLONIAL', [], true],
        'Líder con otros cargos' => ['Líder, Candidato Alcaldía 2027', [], true],
        'Lider sin tilde' => ['Lider', [], true],
        'Líder entre comillas latinas' => ['«Líder» barrial', [], true],
        'LÍDER en mayúsculas con tilde' => ['LÍDER', [], true],
        'líder con punto' => ['líder.', [], true],
        'después de una barra' => ['Coordinador/Líder', [], true],
        'en minúsculas al inicio' => ['lider abogado joven', [], true],
        'Directorio Municipal' => ['Directorio Municipal', [], false],
        'Concejal' => ['Concejal', [], false],
        'Juventudes' => ['Juventudes', [], false],
        'Liderazgo juvenil' => ['Liderazgo juvenil', [], false],
        'Lideresa' => ['Lideresa', [], false],
        'Líderes' => ['Líderes comunitarios', [], false],
        'COLIDER' => ['COLIDER', [], false],
        'sin cargo' => [null, [], false],
        'solo espacios' => ['   ', [], false],
        'tipo nuevo_lider' => [null, ['tipo' => 'nuevo_lider'], true],
        'tipo lider' => [null, ['tipo' => 'lider'], true],
        'tipo directorio sin cargo' => [null, ['tipo' => 'directorio'], false],
        'casilla sin cargo' => [null, ['es_lider' => true], true],
        'Líder fallecido' => ['Líder', ['fallecido' => true], false],
        'casilla fallecido' => [null, ['es_lider' => true, 'fallecido' => true], false],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCampanas();
        $this->flag(true);
    }

    public function test_sql_and_php_agree_on_every_case(): void
    {
        $ids = [];
        foreach (self::CASOS as $nombre => [$cargo, $extra]) {
            $ids[$nombre] = $this->ficha($this->cd, $this->giron, ['cargo' => $cargo, 'nombre' => $nombre] + $extra);
        }
        $porSql = EsLider::where(DB::table('lideres'))->whereIn('id', $ids)->pluck('nombre')->sort()->values()->all();
        $esperados = collect(self::CASOS)->filter(fn ($c) => $c[2])->keys()->sort()->values()->all();
        $this->assertSame($esperados, $porSql);

        foreach (DB::table('lideres')->whereIn('id', $ids)->get() as $row) {
            $this->assertSame(self::CASOS[$row->nombre][2], EsLider::de($row), "PHP: {$row->nombre}");
        }
    }

    public function test_board_votantes_card_and_reportes_count_the_same(): void
    {
        $this->ficha($this->cd, $this->giron, ['cargo' => 'Líder']);
        $this->ficha($this->cd, $this->giron, ['cargo' => 'LIDER COLONIAL']);
        $this->ficha($this->cd, $this->giron, ['tipo' => 'nuevo_lider']);
        $this->ficha($this->cd, $this->giron, ['es_lider' => true]);
        $this->ficha($this->cd, $this->giron, ['cargo' => 'Directorio Municipal']);
        $this->ficha($this->cd, $this->giron, ['cargo' => 'Liderazgo juvenil']);
        $this->ficha($this->cd, $this->giron, ['cargo' => 'Líder', 'is_active' => false]);
        $this->ficha($this->cd, $this->giron, ['cargo' => 'Líder', 'fallecido' => true]);
        $this->ficha($this->campX, $this->giron, ['cargo' => 'Líder']);
        $c = $this->campana(['name' => 'Alcaldía Girón', 'cargo' => 'alcaldia', 'scope_level' => ElectoralCampaign::SCOPE_MUNICIPALITY], [$this->giron]);
        $user = $this->manager();

        $tablero = $this->actingAs($user)->getJson("/campanas-electorales/{$c->id}/tablero")->assertOk()->json('data');
        $this->assertSame(4, $tablero['totales']['lideres']['registrados']);
        $this->assertNull(collect($tablero['avisos'])->firstWhere('tipo', 'sin_lideres'));
        $this->assertSame(['votantes' => 6, 'lideres' => 4], collect($this->actingAs($user)->getJson("/campanas-electorales/{$c->id}")->json('data.filas'))
            ->firstWhere('id', $this->giron)['avance']);

        // Votantes (with the fallecida, 8) and Reportes also show the subgroup's fichas: filtered to the owner group, as the board counts.
        $this->actingAs($user)->get('/votantes')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('counters.total', 8)->where('counters.es_lider', 5));
        $this->actingAs($user)->get("/votantes?campana={$this->cd}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('counters.total', 7)->where('counters.es_lider', 4));
        $this->actingAs($user)->get("/votantes?campana={$this->cd}&es_lider=1")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('votantes.total', 4)->where('counters.es_lider', 4));

        PersonasFuente::olvidarEsquema();
        $reportes = $this->eventUser($this->cd, ['view.reportes', 'view.votantes']);
        $spec = ['v' => 1, 'fuente' => 'personas', 'filtros' => ['marcas' => ['es_lider'], 'grupo' => [$this->cd]], 'columnas' => ['nombre', 'marcas'],
            'orden' => ['campo' => 'nombre', 'dir' => 'asc'], 'busqueda' => ''];
        $this->actingAs($reportes)->postJson('/reportes/personas/conteo', ['spec' => $spec])->assertOk()->assertJsonPath('total', 4);
        $filas = $this->actingAs($reportes)->postJson('/reportes/personas/lista', ['spec' => $spec, 'pagina' => 1])->assertOk()->json('filas');
        $this->assertSame(array_fill(0, 4, 'Es líder'), array_column(array_column($filas, 'celdas'), 'marcas'));
    }

    public function test_the_ficha_says_when_it_counts_by_its_cargo(): void
    {
        $user = $this->manager();
        $porCargo = $this->ficha($this->cd, $this->giron, ['cargo' => 'Líder, Directorio Municipal']);
        $porTipo = $this->ficha($this->cd, $this->giron, ['tipo' => 'nuevo_lider']);
        $casilla = $this->ficha($this->cd, $this->giron, ['cargo' => 'Concejal', 'es_lider' => true]);

        $this->actingAs($user)->getJson("/votantes/{$porCargo}")->assertOk()
            ->assertJsonPath('data.es_lider_por_cargo', true)->assertJsonPath('data.es_lider', false);
        $this->actingAs($user)->getJson("/votantes/{$porTipo}")->assertOk()->assertJsonPath('data.es_lider_por_cargo', true);
        $this->actingAs($user)->getJson("/votantes/{$casilla}")->assertOk()
            ->assertJsonPath('data.es_lider_por_cargo', false)->assertJsonPath('data.es_lider', true);
        $this->actingAs($user)->getJson("/mapa-politico/persona/{$porCargo}")->assertOk()->assertJsonPath('es_lider_por_cargo', true);
        $this->actingAs($user)->getJson("/mapa-politico/persona/{$casilla}")->assertOk()->assertJsonPath('es_lider_por_cargo', false);

        $this->flag(false);
        $this->assertArrayNotHasKey('es_lider_por_cargo', $this->actingAs($user)->getJson("/votantes/{$porCargo}")->assertOk()->json('data'));
        $this->assertArrayNotHasKey('es_lider_por_cargo', $this->actingAs($user)->getJson("/mapa-politico/persona/{$porCargo}")->assertOk()->json());
        $this->actingAs($user)->get('/votantes')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->missing('counters.es_lider'));
    }

    public function test_an_unsafe_table_name_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EsLider::sql('lideres; DROP TABLE lideres');
    }
}
