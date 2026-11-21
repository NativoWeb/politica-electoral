<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsReportesB3Scenario;
use Tests\TestCase;

// Reportes B-3, fuente empresarios (docs/reportes.md): both views, never a deleted organization, the columns of each
// view, personal columns, members outside the territory, open compromisos; and the four fuentes of the page. SQLite.
class ReportesEmpresariosTest extends TestCase
{
    use BuildsReportesB3Scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReportesB3();
    }

    private function organizaciones(): array
    {
        $o = [
            'empresa' => $this->org(Campaign::DEFAULT_ID, 'Lácteos Girón', ['representante_nombre' => 'Rosa Rep', 'representante_cedula' => '9001',
                'representante_celular' => '3115550000', 'correo' => 'rosa@lacteos.test', 'nit' => '890903938', 'nit_dv' => '8', 'empleados' => 25]),
            'gremio' => $this->org(Campaign::DEFAULT_ID, 'Gremio Lebrija', ['tipo' => 'gremio', 'municipio_id' => $this->lebrija]),
            'inactiva' => $this->org(Campaign::DEFAULT_ID, 'Inactiva', ['is_active' => false]),
            'borrada' => $this->org(Campaign::DEFAULT_ID, 'Borrada', ['deleted_at' => now()]),
            'x' => $this->org($this->campX, 'Empresa X'),
            'y' => $this->org($this->campY, 'Empresa Y'),
        ];
        foreach ([[$o['empresa'], $this->fichaGiron, 'presidente'], [$o['empresa'], $this->fichaLebrija, 'socio'], [$o['borrada'], $this->fichaGiron, 'socio']] as [$org, $lider, $rol]) {
            DB::table('organizacion_miembros')->insert(['id' => (string) Str::uuid(), 'organizacion_id' => $org, 'lider_id' => $lider, 'rol' => $rol,
                'created_at' => now(), 'updated_at' => now()]);
        }

        return $o;
    }

    public function test_each_fuente_reaches_the_page_and_the_api_only_with_its_permission(): void
    {
        $this->assertSame(['personas', 'asistencias', 'compromisos', 'empresarios'], $this->fuentes($this->cd));

        $soloVotantes = $this->eventUser(Campaign::DEFAULT_ID, ['view.reportes', 'view.votantes', 'export.datos_personales']);
        $this->assertSame(['personas'], $this->fuentes($soloVotantes));
        foreach (['asistencias', 'compromisos', 'empresarios'] as $fuente) {
            foreach (['conteo', 'lista', 'excel', 'pdf'] as $accion) {
                $this->actingAs($soloVotantes)->postJson("/reportes/{$fuente}/{$accion}", ['spec' => $this->spec($fuente)])
                    ->assertForbidden()->assertJson(['success' => false]);
            }
            $this->actingAs($soloVotantes)->getJson("/reportes/opciones/municipios?fuente={$fuente}")->assertForbidden();
        }

        $soloEventos = $this->eventUser(Campaign::DEFAULT_ID, ['view.reportes', 'view.eventos']);
        $this->assertSame(['asistencias', 'compromisos'], $this->fuentes($soloEventos));
        $soloEmpresarios = $this->eventUser(Campaign::DEFAULT_ID, ['view.reportes', 'view.empresarios']);
        $this->assertSame(['compromisos', 'empresarios'], $this->fuentes($soloEmpresarios));
        $operador = $this->eventUser(Campaign::DEFAULT_ID, ['view.reportes', 'register.asistentes']);
        $this->assertSame([], $this->fuentes($operador));
        $this->actingAs($operador)->getJson('/reportes/opciones/eventos')->assertForbidden();

        // view.eventos without view.reportes: nothing.
        $sinReportes = $this->eventUser(Campaign::DEFAULT_ID, ['view.eventos', 'view.empresarios']);
        $this->actingAs($sinReportes)->postJson('/reportes/asistencias/conteo', ['spec' => $this->spec('asistencias')])->assertForbidden();
        $this->assertSame(0, AuditLog::count());
    }

    public function test_empresarios_both_views_never_a_deleted_organization(): void
    {
        $o = $this->organizaciones();
        $nombres = fn (User $user, array $filtros = []) => array_column(array_column($this->lista($user, 'empresarios', $filtros)['filas'], 'celdas'), 'nombre');

        $this->assertSame(['Empresa X', 'Gremio Lebrija', 'Lácteos Girón'], $nombres($this->cd));
        $this->assertSame(['Inactiva'], $nombres($this->cd, ['estado' => 'inactivas']));
        $this->assertNotContains('Borrada', $nombres($this->cd, ['estado' => 'todas']));
        $this->assertSame(['Empresa X', 'Lácteos Girón'], $nombres($this->deGiron), 'Territory by the organization’s municipio');
        $this->assertSame(['Empresa Y'], $nombres($this->eventUser($this->campY, ['view.reportes', 'view.empresarios'])));
        $this->assertSame(['Gremio Lebrija'], $nombres($this->cd, ['tipo' => ['gremio']]));

        $miembros = $this->lista($this->cdDatos, 'empresarios', ['vista' => 'miembros'], ['nombre', 'organizacion', 'cedula']);
        $this->assertSame(2, $miembros['total'], 'The deleted organization’s member is not listed');
        $this->assertEqualsCanonicalizing(['Ficha Girón', 'Ficha Lebrija'], array_column(array_column($miembros['filas'], 'celdas'), 'nombre'));
        $this->assertSame(['tipo' => 'organizacion', 'id' => $o['empresa'], 'url' => '/empresarios?abrir='.$o['empresa']], $miembros['filas'][0]['accion']);

        // A member whose ficha is outside the territory: name and role only.
        $giron = collect($this->lista($this->deGiron, 'empresarios', ['vista' => 'miembros'], ['nombre', 'rol', 'cedula', 'celular'])['filas'])->keyBy('celdas.nombre');
        $this->assertSame('1111', $giron['Ficha Girón']['celdas']['cedula']);
        $this->assertSame(['nombre' => 'Ficha Lebrija', 'rol' => 'Socio', 'cedula' => null, 'celular' => null], $giron['Ficha Lebrija']['celdas']);
    }

    public function test_empresarios_columns_belong_to_their_view_and_personal_ones_need_the_permission(): void
    {
        $o = $this->organizaciones();
        $post = fn (User $user, array $filtros, array $columnas) => $this->actingAs($user)
            ->postJson('/reportes/empresarios/lista', ['spec' => $this->spec('empresarios', $filtros, $columnas)]);

        $post($this->cdDatos, ['vista' => 'organizaciones'], ['nombre', 'rol'])->assertStatus(422)->assertJson(['message' => 'Una de las columnas pedidas no existe.']);
        $post($this->cdDatos, ['vista' => 'miembros'], ['nombre', 'nit'])->assertStatus(422);
        $post($this->cdDatos, ['vista' => 'cualquiera'], ['nombre', 'nit'])->assertOk();
        foreach (['representante_cedula', 'representante_celular', 'correo'] as $col) {
            $post($this->cd, [], ['nombre', $col])->assertForbidden();
        }
        $post($this->cd, ['vista' => 'miembros'], ['nombre', 'cedula'])->assertForbidden();

        $fila = collect($post($this->cdDatos, [], ['nombre', 'tipo', 'nit', 'representante', 'representante_cedula', 'representante_celular', 'correo', 'empleados', 'miembros'])
            ->json('filas'))->firstWhere('celdas.nombre', 'Lácteos Girón');
        $this->assertSame(['nombre' => 'Lácteos Girón', 'tipo' => 'Empresa', 'nit' => '890.903.938-8', 'representante' => 'Rosa Rep', 'representante_cedula' => '9001',
            'representante_celular' => '3115550000', 'correo' => 'rosa@lacteos.test', 'empleados' => '25', 'miembros' => 2], $fila['celdas']);
        $this->assertSame(['tipo' => 'organizacion', 'id' => $o['empresa'], 'url' => '/empresarios?abrir='.$o['empresa']], $fila['accion']);

        // Without columns the default preset of the view is used.
        $this->assertSame(['nombre', 'organizacion', 'rol', 'municipio'], $this->lista($this->cd, 'empresarios', ['vista' => 'miembros'], [])['columnas']);
        $f = $this->fuente($this->cd, 'empresarios');
        $this->assertSame('organizaciones', collect($f['filtros'])->pluck('filtros')->flatten(1)->firstWhere('clave', 'vista')['por_defecto']);
        $this->assertSame(['miembros'], collect($f['columnas'])->firstWhere('clave', 'rol')['vistas']);
        $this->assertArrayNotHasKey('vistas', collect($f['columnas'])->firstWhere('clave', 'nombre'));
        $this->assertSame('organizaciones', $f['unidad']['plural']);
    }

    public function test_empresarios_with_open_compromisos(): void
    {
        $o = $this->organizaciones();
        $dia = Carbon::now('America/Bogota')->addDays(5)->toDateString();
        $this->acta(null, $o['empresa'], Campaign::DEFAULT_ID, [['Abierto', $dia, 'pendiente', 'Rosa']]);
        $this->acta(null, $o['gremio'], Campaign::DEFAULT_ID, [['Cerrado', $dia, 'cumplido', 'Rosa']]);
        $this->acta(null, $o['gremio'], Campaign::DEFAULT_ID, [['De borrador', $dia, 'pendiente', 'Rosa']], 'borrador');

        $this->assertSame(['Lácteos Girón'], array_column(array_column($this->lista($this->cd, 'empresarios', ['compromisos_abiertos' => true])['filas'], 'celdas'), 'nombre'));
    }

    public function test_without_view_empresarios_the_fuente_is_absent_and_refused(): void
    {
        $this->organizaciones();
        $user = $this->eventUser(Campaign::DEFAULT_ID, ['view.reportes', 'view.eventos', 'view.votantes', 'manage.eventos']);
        $this->assertNotContains('empresarios', $this->fuentes($user));
        $this->actingAs($user)->postJson('/reportes/empresarios/conteo', ['spec' => $this->spec('empresarios')])->assertForbidden();

        $manage = $this->eventUser(Campaign::DEFAULT_ID, ['view.reportes', 'manage.empresarios']);
        $this->assertSame(3, $this->conteo($manage, 'empresarios'));
    }
}
