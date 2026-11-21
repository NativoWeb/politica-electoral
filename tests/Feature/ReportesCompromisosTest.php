<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsReportesB3Scenario;
use Tests\TestCase;

// Reportes B-3, fuente compromisos (docs/reportes.md): each origin with its own permission, never a borrador nor a
// deleted event or organization, vencidos/por vencer equal to the bell (CompromisoAlertas), cells and accion. SQLite.
class ReportesCompromisosTest extends TestCase
{
    use BuildsReportesB3Scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReportesB3();
    }

    public function test_compromisos_need_view_eventos_or_view_empresarios(): void
    {
        $soloVotantes = $this->eventUser(Campaign::DEFAULT_ID, ['view.reportes', 'view.votantes', 'export.datos_personales']);
        $this->assertNotContains('compromisos', $this->fuentes($soloVotantes));
        foreach (['conteo', 'lista', 'excel', 'pdf'] as $accion) {
            $this->actingAs($soloVotantes)->postJson("/reportes/compromisos/{$accion}", ['spec' => $this->spec('compromisos', [], ['descripcion'])])
                ->assertForbidden()->assertJson(['success' => false]);
        }
        $this->actingAs($soloVotantes)->getJson('/reportes/opciones/responsables?fuente=compromisos')->assertForbidden();
        $this->assertContains('compromisos', $this->fuentes($this->eventUser(Campaign::DEFAULT_ID, ['view.reportes', 'view.eventos'])));
        $this->assertContains('compromisos', $this->fuentes($this->eventUser(Campaign::DEFAULT_ID, ['view.reportes', 'view.empresarios'])));
        $this->assertSame(0, AuditLog::count());
    }

    private function compromisos(): array
    {
        $dia = fn (int $d) => Carbon::now('America/Bogota')->addDays($d)->toDateString();
        $org = $this->org(Campaign::DEFAULT_ID, 'Empresa CD');
        $borrada = $this->org(Campaign::DEFAULT_ID, 'Empresa borrada', ['deleted_at' => now()]);

        return [
            ...$this->acta($this->evCd, null, Campaign::DEFAULT_ID, [
                ['Vencido evento', $dia(-1), 'pendiente', 'Sandra Pachón'],
                ['Por vencer evento', $dia(1), 'en_curso', 'Brayan Aparicio'],
                ['Cumplido tarde', $dia(-3), 'cumplido', 'Sandra Pachón'],
                ['Sin fecha', null, 'pendiente', 'Sandra Pachón'],
                ['Lejano', $dia(20), 'pendiente', 'Sandra Pachón'],
            ]),
            ...$this->acta($this->evCd, null, Campaign::DEFAULT_ID, [['Del borrador', $dia(-1), 'pendiente', 'Sandra Pachón']], 'borrador'),
            ...$this->acta(null, $org, Campaign::DEFAULT_ID, [['Vencido organización', $dia(-2), 'pendiente', 'Rosa Gremio']]),
            ...$this->acta(null, $borrada, Campaign::DEFAULT_ID, [['De organización borrada', $dia(-2), 'pendiente', 'Rosa Gremio']]),
            ...$this->acta($this->evX, null, $this->campX, [['Del subgrupo', $dia(-1), 'pendiente', 'Equis']]),
            ...$this->acta($this->evY, null, $this->campY, [['De otro grupo', $dia(-1), 'pendiente', 'Ye']]),
            ...$this->acta($this->evBorrado, null, Campaign::DEFAULT_ID, [['De evento borrado', $dia(-1), 'pendiente', 'Ye']]),
            'org' => $org,
        ];
    }

    private function descripciones(User $user, array $filtros = []): array
    {
        return array_column(array_column($this->lista($user, 'compromisos', $filtros, ['descripcion'])['filas'], 'celdas'), 'descripcion');
    }

    public function test_compromisos_each_origin_only_with_its_permission_and_never_a_borrador_or_something_deleted(): void
    {
        $this->compromisos();

        $this->assertEqualsCanonicalizing(['Vencido evento', 'Por vencer evento', 'Cumplido tarde', 'Sin fecha', 'Lejano', 'Vencido organización', 'Del subgrupo'],
            $this->descripciones($this->cd));
        $soloEventos = $this->eventUser(Campaign::DEFAULT_ID, ['view.reportes', 'view.eventos']);
        $this->assertNotContains('Vencido organización', $this->descripciones($soloEventos));
        $this->assertSame(6, $this->conteo($soloEventos, 'compromisos'));
        $soloEmpresarios = $this->eventUser(Campaign::DEFAULT_ID, ['view.reportes', 'view.empresarios']);
        $this->assertSame(['Vencido organización'], $this->descripciones($soloEmpresarios));
        // An origin the user cannot see is dropped from the filter, never added.
        $this->assertSame(1, $this->conteo($soloEmpresarios, 'compromisos', ['origen' => ['evento']]));
        $this->assertSame(['Del subgrupo'], $this->descripciones($this->eventUser($this->campX, ['view.reportes', 'view.eventos'])));
        $this->assertSame(['De otro grupo'], $this->descripciones($this->eventUser($this->campY, ['view.reportes', 'view.eventos'])));
        $lebrija = $this->eventUser(Campaign::DEFAULT_ID, ['view.reportes', 'view.eventos', 'view.empresarios'], 'R02_ADMIN_FUNCIONAL', [$this->lebrija]);
        $this->assertSame(0, $this->conteo($lebrija, 'compromisos'));

        $origen = fn (User $user) => collect($this->fuente($user, 'compromisos')['filtros'])->pluck('filtros')->flatten(1)->firstWhere('clave', 'origen');
        $this->assertNotNull($origen($this->cd));
        $this->assertNull($origen($soloEventos), 'One origin only: nothing to choose');
    }

    public function test_vencidos_and_por_vencer_are_the_bells_numbers(): void
    {
        $this->compromisos();
        foreach ([$this->cd, $this->eventUser(Campaign::DEFAULT_ID, ['view.reportes', 'view.eventos']), $this->eventUser($this->campX, ['view.reportes', 'view.eventos'])] as $user) {
            $campana = $this->actingAs($user)->getJson('/eventos/compromisos/alertas')->assertOk()->json('data');
            $this->assertSame($campana['vencidos'], $this->conteo($user, 'compromisos', ['vencimiento' => ['vencidos']]));
            $this->assertSame($campana['por_vencer'], $this->conteo($user, 'compromisos', ['vencimiento' => ['por_vencer']]));
        }
        $this->assertEqualsCanonicalizing(['Vencido evento', 'Vencido organización', 'Del subgrupo'], $this->descripciones($this->cd, ['vencimiento' => ['vencidos']]));
        $this->assertSame(['Por vencer evento'], $this->descripciones($this->cd, ['vencimiento' => ['por_vencer']]));

        // The screen's "vencidos" agrees too.
        $pantalla = $this->actingAs($this->cd)->get('/eventos/compromisos?vencidos=1')->viewData('page')['props']['compromisos']['total'];
        $this->assertSame($pantalla, $this->conteo($this->cd, 'compromisos', ['vencimiento' => ['vencidos']]));

        $f = $this->fuente($this->cd, 'compromisos');
        $esta = collect($f['atajos'])->firstWhere('clave', 'esta_semana')['spec']['filtros'];
        $this->assertSame(['Por vencer evento'], $this->descripciones($this->cd, $esta));
        $this->assertSame(3, $this->conteo($this->cd, 'compromisos', collect($f['atajos'])->firstWhere('clave', 'vencidos')['spec']['filtros']));
    }

    public function test_compromisos_cells_accion_filters_and_the_responsable_phone(): void
    {
        $ids = $this->compromisos();
        $fila = collect($this->lista($this->cd, 'compromisos', [], ['descripcion', 'estado', 'vencimiento', 'responsables', 'origen', 'origen_nombre', 'municipio'])['filas'])
            ->firstWhere('celdas.descripcion', 'Vencido evento');
        $this->assertSame(['descripcion' => 'Vencido evento', 'estado' => 'Pendiente', 'vencimiento' => 'Vencido', 'responsables' => 'Sandra Pachón',
            'origen' => 'Evento', 'origen_nombre' => 'Gira Girón', 'municipio' => 'Girón'], $fila['celdas']);
        $this->assertSame(['tipo' => 'compromiso', 'id' => $ids['Vencido evento'], 'url' => '/eventos/compromisos?abrir='.$ids['Vencido evento']], $fila['accion']);
        $this->assertFalse($fila['editable']);

        $this->actingAs($this->cd)->postJson('/reportes/compromisos/lista', ['spec' => $this->spec('compromisos', [], ['descripcion', 'telefono_responsable'])])->assertForbidden();
        $this->assertSame('Rosa Gremio: 3109990000', $this->lista($this->cdDatos, 'compromisos', [], ['descripcion', 'telefono_responsable'])['filas'][0]['celdas']['telefono_responsable']);

        $this->assertSame(1, $this->conteo($this->cd, 'compromisos', ['estado' => ['cumplido']]));
        $this->assertSame(1, $this->conteo($this->cd, 'compromisos', ['responsable' => ['Brayan Aparicio']]));
        $this->assertSame(1, $this->conteo($this->cd, 'compromisos', ['origen' => ['organizacion']]));
        $this->assertSame(7, $this->conteo($this->cd, 'compromisos', ['origen' => ['evento', 'organizacion']]));
        $this->assertSame(1, $this->conteo($this->cd, 'compromisos', [], ['busqueda' => 'lejano']));
        $this->assertSame(['Sandra Pachón'], array_column($this->actingAs($this->cd)->getJson('/reportes/opciones/responsables?fuente=compromisos&q=sandra')->json('opciones'), 'valor'));
        // As the screen: open first by deadline (none last among them), then the closed ones.
        $orden = $this->descripciones($this->cd);
        $this->assertSame('Vencido organización', $orden[0]);
        $this->assertSame(['Sin fecha', 'Cumplido tarde'], array_slice($orden, -2));
        $this->assertSame('Lejano', $this->lista($this->cd, 'compromisos', [], ['descripcion'], ['orden' => ['campo' => 'fecha_limite', 'dir' => 'desc']])['filas'][0]['celdas']['descripcion']);
    }
}
