<?php

namespace Tests\Feature;

use App\Actions\Votantes\MergeLideres;
use App\Services\Reportes\Fuentes\PersonasFuente;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsCampanasScenario;
use Tests\TestCase;

// "Es líder (cuenta para las metas)" (lideres.es_lider, docs/campanas-electorales.md): saved only with the module on
// and only when the key comes; editing anything else never unmarks it; merging keeps it if either was a líder; the
// Votantes filter counts what the database has; hidden everywhere while the module is off.
class EsLiderTest extends TestCase
{
    use BuildsCampanasScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCampanas();
        $this->flag(true);
    }

    private function esLider(string $id): bool
    {
        return (bool) DB::table('lideres')->where('id', $id)->value('es_lider');
    }

    public function test_votantes_create_and_update_change_it_only_when_the_key_comes(): void
    {
        $user = $this->manager();
        $id = $this->actingAs($user)->postJson('/votantes', ['nombre' => 'Ana Líder', 'municipio_id' => $this->giron, 'es_lider' => true])
            ->assertCreated()->json('id');
        $this->assertTrue($this->esLider($id));
        $sinClave = $this->actingAs($user)->postJson('/votantes', ['nombre' => 'Sin clave', 'municipio_id' => $this->giron])->assertCreated()->json('id');
        $this->assertFalse($this->esLider($sinClave));

        $this->actingAs($user)->putJson("/votantes/{$id}", ['barrio' => 'Otro', 'gran_elector' => false, 'militante' => null])->assertOk();
        $this->assertTrue($this->esLider($id), 'Editing other fields keeps the mark');
        $this->actingAs($user)->putJson("/votantes/{$id}", ['es_lider' => null])->assertOk();
        $this->assertTrue($this->esLider($id), 'null means not sent');
        $this->actingAs($user)->putJson("/votantes/{$id}", ['es_lider' => 'abc'])->assertStatus(422)
            ->assertJsonPath('errors.es_lider.0', 'El campo Es líder debe ser verdadero o falso.');
        $this->actingAs($user)->putJson("/votantes/{$id}", ['es_lider' => false])->assertOk();
        $this->assertFalse($this->esLider($id));

        $this->actingAs($user)->getJson("/votantes/{$id}")->assertOk()->assertJsonPath('data.es_lider', false);
    }

    public function test_the_mapa_create_and_edit_keep_it_unless_sent(): void
    {
        $user = $this->manager();
        $this->actingAs($user)->postJson('/mapa-politico/crear-lider', ['nombre' => 'Líder Mapa', 'municipio_id' => $this->giron, 'es_lider' => true])->assertOk();
        $id = DB::table('lideres')->where('nombre', 'Líder Mapa')->value('id');
        $this->assertTrue($this->esLider($id));

        // The Mapa's panel (and the offline queue) send the whole form without the key.
        $this->actingAs($user)->putJson("/mapa-politico/persona/{$id}", ['telefono' => '3001112233', 'gran_elector' => true, 'destacado' => null])->assertOk();
        $this->assertTrue($this->esLider($id));
        $this->assertSame('3001112233', DB::table('lideres')->where('id', $id)->value('telefono'));
        $this->actingAs($user)->getJson("/mapa-politico/persona/{$id}")->assertOk()->assertJsonPath('es_lider', true);

        $this->actingAs($user)->putJson("/mapa-politico/persona/{$id}", ['es_lider' => false])->assertOk();
        $this->assertFalse($this->esLider($id));
    }

    public function test_with_the_module_off_it_is_ignored_and_hidden(): void
    {
        $user = $this->manager();
        $marcado = $this->ficha($this->cd, $this->giron, ['es_lider' => true, 'nombre' => 'Marcado']);
        $this->flag(false);

        $this->actingAs($user)->putJson("/votantes/{$marcado}", ['es_lider' => false, 'barrio' => 'Nuevo'])->assertOk();
        $this->actingAs($user)->putJson("/mapa-politico/persona/{$marcado}", ['es_lider' => false])->assertOk();
        $this->assertTrue($this->esLider($marcado));
        $nuevo = $this->actingAs($user)->postJson('/votantes', ['nombre' => 'Nuevo', 'municipio_id' => $this->giron, 'es_lider' => true])->assertCreated()->json('id');
        $this->assertFalse($this->esLider($nuevo));

        $this->assertArrayNotHasKey('es_lider', $this->actingAs($user)->getJson("/votantes/{$marcado}")->assertOk()->json('data'));
        $this->assertArrayNotHasKey('es_lider', $this->actingAs($user)->getJson("/mapa-politico/persona/{$marcado}")->assertOk()->json());
        $this->actingAs($user)->get('/votantes?es_lider=1')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('campanasActivas', false)->missing('counters.es_lider')->missing('filters.es_lider')
            ->where('counters.total', 2)
            ->where('votantes.data', fn ($rows) => collect($rows)->every(fn ($r) => ! array_key_exists('es_lider', (array) $r))));
    }

    public function test_the_votantes_filter_counts_what_the_database_has(): void
    {
        $user = $this->manager();
        $this->ficha($this->cd, $this->giron, ['es_lider' => true]);
        $this->ficha($this->cd, $this->lebrija, ['es_lider' => true]);
        $this->ficha($this->cd, $this->giron);
        $this->ficha($this->cd, $this->giron, ['es_lider' => true, 'is_active' => false]);
        $this->ficha($this->campY, $this->giron, ['es_lider' => true]);

        $esperado = DB::table('lideres')->where('campaign_id', $this->cd)->where('is_active', true)->where('es_lider', true)->count();
        $this->actingAs($user)->get('/votantes?es_lider=1')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('campanasActivas', true)->where('filters.es_lider', '1')
            ->where('counters.es_lider', $esperado)->where('votantes.total', $esperado));
        $this->assertSame(2, $esperado);

        $this->actingAs($user)->get('/votantes')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('counters.total', 3)->where('counters.es_lider', 2));
    }

    public function test_merging_two_fichas_keeps_the_mark_if_either_had_it(): void
    {
        $user = $this->manager();
        $this->actingAs($user);
        $a = $this->ficha($this->cd, $this->giron, ['nombre' => 'Pedro Pérez']);
        $b = $this->ficha($this->cd, $this->giron, ['nombre' => 'Pedro Perez Gómez', 'es_lider' => true]);
        foreach (['lider_seguimientos', 'whatsapp_send_recipients', 'favorites', 'recent_views', 'taggables', 'event_organizers'] as $table) {
            if (! Schema::hasTable($table)) {
                Schema::create($table, function ($t) use ($table) {
                    $t->uuid('id')->nullable();
                    $t->uuid(match ($table) {
                        'whatsapp_send_recipients' => 'person_id', 'favorites' => 'favoritable_id', 'recent_views' => 'viewable_id',
                        'taggables' => 'taggable_id', default => 'lider_id',
                    })->nullable();
                });
            }
        }

        $merged = app(MergeLideres::class)->handle($a, $b);

        $this->assertTrue((bool) DB::table('lideres')->where('id', $merged->id)->value('es_lider'));
        $this->assertSame(1, DB::table('lideres')->whereIn('id', [$a, $b])->count());

        $c = $this->ficha($this->cd, $this->giron, ['nombre' => 'Luz Díaz']);
        $d = $this->ficha($this->cd, $this->giron, ['nombre' => 'Luz Diaz Ruiz']);
        $this->assertFalse((bool) DB::table('lideres')->where('id', app(MergeLideres::class)->handle($c, $d)->id)->value('es_lider'));
    }

    public function test_the_reports_offer_and_filter_the_mark_only_with_the_module_on(): void
    {
        PersonasFuente::olvidarEsquema();
        $user = $this->eventUser($this->cd, ['view.reportes', 'view.votantes']);
        $this->ficha($this->cd, $this->giron, ['es_lider' => true]);
        $this->ficha($this->cd, $this->giron);
        $spec = ['v' => 1, 'fuente' => 'personas', 'filtros' => ['marcas' => ['es_lider']], 'columnas' => ['nombre', 'marcas'],
            'orden' => ['campo' => 'nombre', 'dir' => 'asc'], 'busqueda' => ''];
        $marcas = function () use ($user) {
            $opciones = null;
            $this->actingAs($user)->get('/reportes')->assertOk()->assertInertia(function (AssertableInertia $page) use (&$opciones) {
                $filtro = collect($page->toArray()['props']['fuentes'][0]['filtros'])->pluck('filtros')->flatten(1)->firstWhere('clave', 'marcas');
                $opciones = array_column($filtro['opciones'], 'etiqueta', 'valor');
            });

            return $opciones;
        };

        $this->assertSame('Líderes', $marcas()['es_lider'] ?? null);
        $this->actingAs($user)->postJson('/reportes/personas/conteo', ['spec' => $spec])->assertOk()->assertExactJson(['success' => true, 'total' => 1]);
        $filas = $this->actingAs($user)->postJson('/reportes/personas/lista', ['spec' => $spec, 'pagina' => 1])->assertOk()->json('filas');
        $this->assertSame('Es líder', $filas[0]['celdas']['marcas'] ?? null, 'One person reads "Es líder", the filter "Líderes"');

        $this->flag(false);
        $this->assertArrayNotHasKey('es_lider', $marcas());
        $this->assertSame('Gran elector', $marcas()['gran_elector']);
        $this->actingAs($user)->postJson('/reportes/personas/conteo', ['spec' => $spec])->assertOk()->assertJsonPath('total', 2);
    }

    public function test_the_shared_prop_tells_the_forms_to_show_the_box_without_a_campaigns_permission(): void
    {
        $fichas = $this->eventUser($this->cd, ['view.mapa', 'view.votantes', 'write.data']);
        $prop = function () use ($fichas) {
            $value = null;
            $this->actingAs($fichas)->get('/votantes')->assertOk()->assertInertia(function (AssertableInertia $p) use (&$value) {
                $value = $p->toArray()['props']['features'];
            });

            return $value;
        };

        $this->assertSame(['campanas' => false, 'esLider' => true], $prop());
        $this->flag(true, ['otro-usuario']);
        $this->assertFalse($prop()['esLider'], 'Outside the beta list');
        $this->flag(false);
        $this->assertFalse($prop()['esLider']);
    }

    public function test_the_migration_marks_nobody_and_rolls_back(): void
    {
        $migration = require database_path('migrations/2026_11_23_000002_add_es_lider_to_lideres.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('lideres', 'es_lider'));
        $this->lider($this->cd, 'Existente', '111', ['cargo' => 'Líder', 'tipo' => 'directorio']);

        $migration->up();

        $this->assertTrue(Schema::hasColumn('lideres', 'es_lider'));
        $this->assertSame(0, DB::table('lideres')->where('es_lider', true)->count());
        $this->assertSame(1, DB::table('lideres')->where('es_lider', false)->count());
    }
}
