<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Tests\Concerns\BuildsReportesB3Scenario;
use Tests\TestCase;

// Reportes B-3, fuente asistencias (docs/reportes.md): who sees it, grupo político and territory, parity with the
// event's screen, the «fechas» filter, personal columns, the row's ficha and the files. SQLite.
class ReportesAsistenciasTest extends TestCase
{
    use BuildsReportesB3Scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReportesB3();
    }

    public function test_asistencias_only_with_view_eventos(): void
    {
        $soloVotantes = $this->eventUser(Campaign::DEFAULT_ID, ['view.reportes', 'view.votantes', 'export.datos_personales']);
        $this->assertNotContains('asistencias', $this->fuentes($soloVotantes));
        foreach (['conteo', 'lista', 'excel', 'pdf'] as $accion) {
            $this->actingAs($soloVotantes)->postJson("/reportes/asistencias/{$accion}", ['spec' => $this->spec('asistencias')])
                ->assertForbidden()->assertJson(['success' => false]);
        }
        $this->actingAs($soloVotantes)->getJson('/reportes/opciones/eventos?fuente=asistencias')->assertForbidden();

        // register.asistentes (the operator) does not see who attended.
        $operador = $this->eventUser(Campaign::DEFAULT_ID, ['view.reportes', 'register.asistentes']);
        $this->assertSame([], $this->fuentes($operador));
        $this->actingAs($operador)->getJson('/reportes/opciones/eventos')->assertForbidden();
        $this->assertContains('asistencias', $this->fuentes($this->eventUser(Campaign::DEFAULT_ID, ['view.reportes', 'manage.eventos'])));

        $sinReportes = $this->eventUser(Campaign::DEFAULT_ID, ['view.eventos']);
        $this->actingAs($sinReportes)->postJson('/reportes/asistencias/conteo', ['spec' => $this->spec('asistencias')])->assertForbidden();
        $this->assertSame(0, AuditLog::count());
    }

    public function test_options_answer_for_the_fuente_asked_and_fall_back_to_the_first_that_knows_the_filter(): void
    {
        $soloEventos = $this->eventUser(Campaign::DEFAULT_ID, ['view.reportes', 'view.eventos']);
        $eventos = $this->actingAs($soloEventos)->getJson('/reportes/opciones/eventos?fuente=asistencias')->assertOk()->json('opciones');
        $this->assertEqualsCanonicalizing([$this->evCd->id, $this->evViejo->id, $this->evX->id], array_column($eventos, 'valor'));
        // Without ?fuente the first fuente the user has answers (here asistencias: no Personas).
        $this->actingAs($soloEventos)->getJson('/reportes/opciones/barrios')->assertOk()
            ->assertJsonPath('opciones', [['valor' => 'Centro', 'etiqueta' => 'Centro'], ['valor' => 'El Poblado', 'etiqueta' => 'El Poblado']]);
        $this->actingAs($soloEventos)->getJson('/reportes/opciones/partidos?fuente=asistencias')->assertNotFound();
        $this->actingAs($soloEventos)->getJson('/reportes/opciones/eventos?fuente=nada')->assertStatus(422);
        $this->actingAs($soloEventos)->getJson('/reportes/opciones/eventos?fuente=personas')->assertForbidden();
    }

    public function test_asistencias_respect_the_grupo_the_subgrupo_and_the_territory_and_never_a_deleted_event(): void
    {
        $this->assertSame(5, $this->conteo($this->cd, 'asistencias'), 'CD: its 3 + 1 old + the subgrupo’s');
        $this->assertSame(1, $this->conteo($this->eventUser($this->campX, ['view.reportes', 'view.eventos']), 'asistencias'));
        $this->assertSame(1, $this->conteo($this->eventUser($this->campY, ['view.reportes', 'view.eventos']), 'asistencias'));
        $lebrija = $this->eventUser(Campaign::DEFAULT_ID, ['view.reportes', 'view.eventos'], 'R02_ADMIN_FUNCIONAL', [$this->lebrija]);
        $this->assertSame(['Dora Prueba'], array_column(array_column($this->lista($lebrija, 'asistencias')['filas'], 'celdas'), 'nombre'));

        $nombres = array_column(array_column($this->lista($this->cd, 'asistencias')['filas'], 'celdas'), 'nombre');
        $this->assertNotContains('Borrado Prueba', $nombres);
        $this->assertNotContains('Ye Prueba', $nombres);
        // A filter on an event the user cannot see is dropped, never widens.
        $this->assertSame(5, $this->conteo($this->cd, 'asistencias', ['evento' => [$this->evY->id, $this->evBorrado->id]]));
    }

    public function test_the_attendees_of_an_event_are_the_ones_its_screen_shows(): void
    {
        $screen = $this->actingAs($this->cd)->get("/eventos/{$this->evCd->id}")->assertOk()->viewData('page')['props']['asistentes'];
        $lista = $this->lista($this->cd, 'asistencias', ['evento' => [$this->evCd->id]]);

        $this->assertSame($screen['total'], $lista['total']);
        $this->assertEqualsCanonicalizing(array_column($screen['data'], 'id'), array_column($lista['filas'], 'id'));
    }

    public function test_asistencias_filters_and_relative_dates(): void
    {
        $this->assertSame(4, $this->conteo($this->cd, 'asistencias', ['fecha' => ['relativo' => 'ultimos_30']]));
        $hoy = Carbon::now('America/Bogota');
        $this->assertSame(1, $this->conteo($this->cd, 'asistencias', ['fecha' => ['desde' => $hoy->copy()->subDays(45)->toDateString(), 'hasta' => $hoy->copy()->subDays(35)->toDateString()]]));
        // Reversed ends are swapped; an impossible date is dropped.
        $this->assertSame(1, $this->conteo($this->cd, 'asistencias', ['fecha' => ['desde' => $hoy->copy()->subDays(35)->toDateString(), 'hasta' => $hoy->copy()->subDays(45)->toDateString()]]));
        $this->assertSame(5, $this->conteo($this->cd, 'asistencias', ['fecha' => ['desde' => '2026-13-45']]));
        $this->assertSame(5, $this->conteo($this->cd, 'asistencias', ['fecha' => ['relativo' => 'siempre']]));

        $this->assertSame(3, $this->conteo($this->cd, 'asistencias', ['sin_ficha' => true]));
        $this->assertSame(1, $this->conteo($this->cd, 'asistencias', ['municipio' => [$this->lebrija]]));
        $this->assertSame(1, $this->conteo($this->cd, 'asistencias', ['barrio' => ['El Poblado']]));
        $this->assertSame(5, $this->conteo($this->cd, 'asistencias', ['tipo_evento' => [DB::table('event_types')->where('nombre', 'Gira')->value('id')]]));
        $this->assertSame(1, $this->conteo($this->cd, 'asistencias', ['grupo' => [$this->campX]]));
        $this->assertSame(5, $this->conteo($this->cd, 'asistencias', ['municipio' => [$this->velez], 'sql' => '1=1', 'sin_ficha' => 'si']));
    }

    public function test_asistencias_personal_columns_and_search_by_cedula_need_the_permission(): void
    {
        foreach (['cedula', 'celular'] as $col) {
            $this->actingAs($this->cd)->postJson('/reportes/asistencias/lista', ['spec' => $this->spec('asistencias', [], ['nombre', $col])])->assertForbidden();
            $this->actingAs($this->cd)->postJson('/reportes/asistencias/excel', ['spec' => $this->spec('asistencias', [], ['nombre', $col])])->assertForbidden();
        }
        $this->assertSame(0, $this->conteo($this->cd, 'asistencias', [], ['busqueda' => '3333']));
        $this->assertSame(1, $this->conteo($this->cdDatos, 'asistencias', [], ['busqueda' => '3333']));
        $this->assertSame(1, $this->conteo($this->cd, 'asistencias', [], ['busqueda' => 'beto prueba']));

        $fila = collect($this->lista($this->cdDatos, 'asistencias', ['evento' => [$this->evCd->id]], ['nombre', 'cedula', 'celular', 'origen', 'vinculado'])['filas'])
            ->firstWhere('celdas.nombre', 'Beto Prueba');
        $this->assertSame(['nombre' => 'Beto Prueba', 'cedula' => '3333', 'celular' => '3003333', 'origen' => 'Operador', 'vinculado' => 'No'], $fila['celdas']);
        $this->assertSame(0, AuditLog::count(), 'Counting and listing are not audited');
    }

    public function test_the_row_opens_the_linked_ficha_only_when_the_user_can_open_it(): void
    {
        $accion = fn (User $user) => collect($this->lista($user, 'asistencias', ['evento' => [$this->evCd->id]])['filas'])
            ->mapWithKeys(fn ($f) => [$f['celdas']['nombre'] => $f['accion']])->all();

        $cd = $accion($this->cd);
        $this->assertSame(['tipo' => 'ficha', 'id' => $this->fichaGiron, 'editable' => false], $cd['Ana Prueba']);
        $this->assertNull($cd['Beto Prueba']);
        $this->assertSame($this->fichaLebrija, $cd['Ceci Prueba']['id']);

        $escribe = $this->eventUser(Campaign::DEFAULT_ID, [...self::PERMISOS, 'write.data']);
        $this->assertTrue($accion($escribe)['Ana Prueba']['editable']);

        // The ficha in Lebrija is outside a Girón user's territory: the row opens nothing.
        $giron = $accion($this->deGiron);
        $this->assertSame($this->fichaGiron, $giron['Ana Prueba']['id']);
        $this->assertNull($giron['Ceci Prueba']);
        $this->assertSame('Sí', collect($this->lista($this->deGiron, 'asistencias', [], ['nombre', 'vinculado'])['filas'])->firstWhere('celdas.nombre', 'Ceci Prueba')['celdas']['vinculado']);

        // Without view.votantes or view.mapa no ficha opens.
        $sinFichas = $this->eventUser(Campaign::DEFAULT_ID, ['view.reportes', 'view.eventos']);
        $this->assertSame([null], array_values(array_unique(array_map(fn ($a) => $a === null ? null : 'x', $accion($sinFichas)))));
    }

    public function test_asistencias_catalog_shortcuts_and_the_grupo_column(): void
    {
        $f = $this->fuente($this->cd, 'asistencias');
        $this->assertSame(['singular' => 'asistente', 'plural' => 'asistentes', 'todos' => 'todos los asistentes de los eventos', 'femenino' => false], $f['unidad']);
        $this->assertSame(5, $f['total']);
        $this->assertSame(['ultimo_mes', 'sin_ficha'], array_column($f['atajos'], 'clave'));
        $this->assertSame(['relativo' => 'ultimos_30'], $f['atajos'][0]['spec']['filtros']['fecha']);
        $this->assertSame(['clave' => 'fecha', 'dir' => 'desc', 'etiqueta' => 'Eventos más recientes'], $f['ordenes'][0]);
        $filtros = collect($f['filtros'])->pluck('filtros')->flatten(1)->keyBy('clave');
        $this->assertSame('fechas', $filtros['fecha']['tipo']);
        $this->assertSame([['valor' => ['relativo' => 'ultimos_7'], 'etiqueta' => 'Última semana'], ['valor' => ['relativo' => 'ultimos_30'], 'etiqueta' => 'Último mes']],
            $filtros['fecha']['opciones']);
        $this->assertSame('eventos', $filtros['evento']['opciones_de']);
        $columnas = collect($f['columnas'])->keyBy('clave');
        $this->assertTrue($columnas['cedula']['sensible']);
        $this->assertTrue($columnas['nombre']['siempre']);
        $this->assertArrayHasKey('grupo', $columnas->all(), 'CD sees itself and its subgrupo');
        $this->assertFalse(in_array('cedula', collect($f['presets'])->firstWhere('por_defecto', true)['columnas'], true));

        $x = $this->fuente($this->eventUser($this->campX, ['view.reportes', 'view.eventos']), 'asistencias');
        $this->assertNotContains('grupo', array_column($x['columnas'], 'clave'));
        $this->actingAs($this->eventUser($this->campX, ['view.reportes', 'view.eventos']))
            ->postJson('/reportes/asistencias/lista', ['spec' => $this->spec('asistencias', [], ['nombre', 'grupo'])])->assertStatus(422);
    }

    public function test_asistencias_excel_and_pdf_are_audited_and_speak_of_attendees(): void
    {
        $rows = $this->excel($this->cdDatos, 'asistencias', $this->spec('asistencias', ['evento' => [$this->evCd->id]], ['nombre', 'cedula', 'evento', 'fecha', 'municipio'],
            ['orden' => ['campo' => 'nombre', 'dir' => 'asc']]));
        $this->assertSame('Reporte de eventos y asistencia — Evento Gira Girón', $rows[0][0]);
        $this->assertSame(['Persona', 'Cédula', 'Evento', 'Fecha del evento', 'Municipio del evento'], $rows[3]);
        $this->assertSame(['Ana Prueba', '1111', 'Gira Girón'], array_slice($rows[4], 0, 3));
        $this->assertMatchesRegularExpression('#^\d{2}/\d{2}/\d{4} \d{1,2}:\d{2} [ap]\. m\.$#', $rows[4][3]);
        $this->assertCount(7, $rows);

        $log = AuditLog::where('action', 'EXPORT reportes.asistencias.excel')->sole();
        $this->assertSame('asistencias', $log->metadata['fuente']);
        $this->assertSame(3, $log->metadata['filas']);
        $this->assertTrue($log->metadata['con_datos_personales']);

        $vista = null;
        View::composer('reportes.lista', function ($view) use (&$vista) {
            $vista = $view->getData();
        });
        $this->actingAs($this->cd)->postJson('/reportes/asistencias/pdf', ['spec' => $this->spec('asistencias')])->assertOk()
            ->assertHeader('X-Reporte-Total', '5');
        $this->assertSame('asistentes', $vista['unidad']['plural']);
        $this->assertSame([['nombre' => 'Girón', 'total' => 4], ['nombre' => 'Lebrija', 'total' => 1]], $vista['desglose']['municipios']);
        $this->assertSame(1, AuditLog::where('action', 'EXPORT reportes.asistencias.pdf')->count());
    }
}
