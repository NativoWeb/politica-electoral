<?php

namespace Tests\Feature;

use App\Exports\GenericExport;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\User;
use App\Services\Reportes\DescargaGuard;
use App\Services\Reportes\Fuentes\PersonasFuente;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// Módulo Reportes on SQLite: who gets in, what a spec may ask for, and the download guard (lock, quota, audit) also
// on the old /exportar/*. The filters and the files on the real schema are in ReportesPostgresTest.
class ReportesAccessTest extends TestCase
{
    use BuildsEventosScenario;

    private const SPEC = ['v' => 1, 'fuente' => 'personas', 'filtros' => [], 'columnas' => ['nombre'], 'orden' => ['campo' => 'nombre', 'dir' => 'asc'], 'busqueda' => ''];

    private User $reportes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEventos();
        $this->addMapaVotantesTables();
        PersonasFuente::olvidarEsquema();
        $this->reportes = $this->eventUser(Campaign::DEFAULT_ID, ['view.reportes', 'view.votantes']);
        $this->lider(Campaign::DEFAULT_ID, 'Ana Gran Elector', '0123', ['gran_elector' => true]);
        $this->lider(Campaign::DEFAULT_ID, 'Beto Normal', '0456');
        $this->lider($this->campY, 'Ceci Otro Grupo', '0789', ['gran_elector' => true]);
    }

    private function spec(array $cambios = []): array
    {
        return array_merge(self::SPEC, $cambios);
    }

    public function test_without_view_reportes_nothing_answers(): void
    {
        $user = $this->eventUser(Campaign::DEFAULT_ID, ['view.votantes', 'view.mapa', 'export.datos_personales']);

        $this->actingAs($user)->get('/reportes')->assertRedirect('/');
        $this->actingAs($user)->postJson('/reportes/personas/conteo', ['spec' => self::SPEC])->assertForbidden();
        $this->actingAs($user)->postJson('/reportes/personas/lista', ['spec' => self::SPEC])->assertForbidden();
        $this->actingAs($user)->postJson('/reportes/personas/excel', ['spec' => self::SPEC])->assertForbidden();
        $this->actingAs($user)->getJson('/reportes/opciones/barrios?q=a')->assertForbidden();
        $this->assertSame(0, AuditLog::count());
    }

    public function test_guests_go_to_the_login(): void
    {
        $this->get('/reportes')->assertRedirect('/login');
        $this->postJson('/reportes/personas/conteo', ['spec' => self::SPEC])->assertUnauthorized();
    }

    public function test_view_reportes_without_votantes_or_mapa_has_no_fuente(): void
    {
        $user = $this->eventUser(Campaign::DEFAULT_ID, ['view.reportes', 'export.datos_personales']);

        $this->actingAs($user)->get('/reportes')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Reportes/Index', false)->where('fuentes', []));
        $this->actingAs($user)->postJson('/reportes/personas/conteo', ['spec' => self::SPEC])
            ->assertForbidden()->assertJson(['success' => false]);
        $this->actingAs($user)->getJson('/reportes/opciones/barrios')->assertForbidden();

        // view.mapa alone is enough.
        $mapa = $this->eventUser(Campaign::DEFAULT_ID, ['view.reportes', 'view.mapa']);
        $this->actingAs($mapa)->postJson('/reportes/personas/conteo', ['spec' => self::SPEC])->assertOk()->assertJson(['total' => 2]);
    }

    public function test_counts_on_sqlite_with_the_grupo_politico_applied(): void
    {
        $this->actingAs($this->reportes)->postJson('/reportes/personas/conteo', ['spec' => $this->spec(['filtros' => ['marcas' => ['gran_elector']]])])
            ->assertOk()->assertExactJson(['success' => true, 'total' => 1]);
        $this->actingAs($this->reportes)->get('/reportes')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('fuentes.0.total', 2)->where('puedeDatosPersonales', false)
                ->where('fuentes.0.filtros', fn ($grupos) => ! collect($grupos)->pluck('filtros')->flatten(1)->contains('clave', 'evento')));
    }

    public function test_a_spec_outside_the_contract_is_refused_without_an_audit_row(): void
    {
        $post = fn (string $url, array $body) => $this->actingAs($this->reportes)->postJson($url, $body);

        $post('/reportes/personas/conteo', ['spec' => $this->spec(['v' => 2])])->assertStatus(422)->assertJson(['success' => false]);
        $post('/reportes/personas/conteo', ['spec' => $this->spec(['v' => '1'])])->assertStatus(422);
        $post('/reportes/x/conteo', ['spec' => $this->spec(['fuente' => 'x'])])->assertStatus(422)->assertJson(['success' => false]);
        $post('/reportes/x/conteo', ['spec' => self::SPEC])->assertStatus(422);
        $post('/reportes/personas/conteo', [])->assertStatus(422);
        $post('/reportes/personas/conteo', ['spec' => $this->spec(['filtros' => ['a', 'b']])])->assertStatus(422);
        $post('/reportes/personas/lista', ['spec' => self::SPEC, 'pagina' => 'abc'])->assertStatus(422);

        foreach (['/reportes/personas/lista', '/reportes/personas/excel'] as $url) {
            $post($url, ['spec' => $this->spec(['columnas' => ['password']])])->assertStatus(422)->assertJson(['success' => false]);
            $post($url, ['spec' => $this->spec(['columnas' => ['lideres.cedula; drop table lideres']])])->assertStatus(422);
            $post($url, ['spec' => $this->spec(['columnas' => [['cedula']]])])->assertStatus(422);
            $post($url, ['spec' => $this->spec(['columnas' => ['nombre', 'cedula']])])->assertForbidden()
                ->assertJson(['success' => false, 'message' => 'Necesitas permiso para ver o descargar datos de contacto. Pídeselo a tu administrador.']);
            $post($url, ['spec' => $this->spec(['columnas' => ['observacion']])])->assertForbidden();
        }
        // Event columns do not exist without view.eventos.
        $post('/reportes/personas/lista', ['spec' => $this->spec(['columnas' => ['eventos']])])->assertStatus(422);

        $this->assertSame(0, AuditLog::count());
        $this->assertSame(2, DB::table('lideres')->where('campaign_id', Campaign::DEFAULT_ID)->count());
    }

    public function test_unknown_filters_and_values_are_dropped(): void
    {
        $total = fn (array $filtros) => $this->actingAs($this->reportes)
            ->postJson('/reportes/personas/conteo', ['spec' => $this->spec(['filtros' => $filtros])])->assertOk()->json('total');

        $this->assertSame(2, $total([]));
        $this->assertSame(0, $total(['municipio' => [$this->velez]]));
        $this->assertSame(2, $total(['campaign_id' => $this->campY, 'sql' => '1=1', 'marcas' => ['is_admin', "x' or '1'='1"],
            'tipo' => ['asistente_evento'], 'municipio' => [$this->soto, 'no-es-uuid'], 'grupo' => [$this->campY],
            'evento' => [(string) Str::uuid()], 'eventos_min' => 1, 'estado' => 'borradas']));
    }

    public function test_counting_and_listing_leave_no_audit_row(): void
    {
        $this->actingAs($this->reportes)->postJson('/reportes/personas/conteo', ['spec' => self::SPEC])->assertOk();
        $this->actingAs($this->reportes)->postJson('/reportes/personas/lista', ['spec' => self::SPEC])->assertOk();
        $this->assertSame(0, AuditLog::count());
    }

    public function test_the_list_page_and_the_sensitive_columns_with_the_permission(): void
    {
        $this->actingAs($this->reportes)->postJson('/reportes/personas/lista', ['spec' => $this->spec(['columnas' => ['municipio']]), 'pagina' => 1])
            ->assertOk()
            ->assertJson(['success' => true, 'total' => 2, 'pagina' => 1, 'por_pagina' => 50])
            ->assertJsonPath('filas.0.celdas', ['nombre' => 'Ana Gran Elector', 'municipio' => 'Girón'])
            ->assertJsonPath('filas.0.editable', false);

        $conDatos = $this->eventUser(Campaign::DEFAULT_ID, ['view.reportes', 'view.votantes', 'export.datos_personales', 'write.data']);
        $this->actingAs($conDatos)->postJson('/reportes/personas/lista', ['spec' => $this->spec(['columnas' => ['nombre', 'cedula', 'telefono']])])
            ->assertOk()
            ->assertJsonPath('filas.0.celdas', ['nombre' => 'Ana Gran Elector', 'cedula' => '0123', 'telefono' => '3000000000'])
            ->assertJsonPath('filas.0.editable', true);
    }

    public function test_a_double_click_gives_one_file_one_audit_row_and_a_429(): void
    {
        $spec = ['spec' => self::SPEC];
        $primera = $this->actingAs($this->reportes)->postJson('/reportes/personas/excel', $spec);
        $primera->assertOk();

        // The first file is still being written (its stream has not run): the second request is refused.
        $this->actingAs($this->reportes)->postJson('/reportes/personas/excel', $spec)
            ->assertStatus(429)->assertJson(['success' => false, 'motivo' => 'en_curso', 'message' => DescargaGuard::EN_CURSO]);
        $this->assertSame(1, AuditLog::where('action', 'EXPORT reportes.personas.excel')->count());

        $this->assertStringStartsWith('PK', $primera->streamedContent());
        $this->actingAs($this->reportes)->postJson('/reportes/personas/excel', $spec)->assertOk()->streamedContent();
        $this->assertSame(2, AuditLog::where('action', 'EXPORT reportes.personas.excel')->count());
        $this->assertSame(2, AuditLog::count(), 'No other audit row (AuditActivity skips the download route)');
    }

    public function test_the_sixth_download_in_a_minute_is_refused_without_an_audit_row(): void
    {
        foreach (range(1, DescargaGuard::POR_MINUTO) as $i) {
            $this->actingAs($this->reportes)->postJson('/reportes/personas/excel', ['spec' => self::SPEC])->assertOk()->streamedContent();
        }
        $this->actingAs($this->reportes)->postJson('/reportes/personas/excel', ['spec' => self::SPEC])
            ->assertStatus(429)->assertJson(['success' => false, 'motivo' => 'seguidas', 'message' => DescargaGuard::SEGUIDAS])
            ->assertHeader('Retry-After');
        $this->assertSame(DescargaGuard::POR_MINUTO, AuditLog::where('action', 'EXPORT reportes.personas.excel')->count());

        // The quota is per user and shared with the old exports.
        $otra = $this->eventUser(Campaign::DEFAULT_ID, ['view.reportes', 'view.votantes']);
        $this->actingAs($otra)->postJson('/reportes/personas/excel', ['spec' => self::SPEC])->assertOk()->streamedContent();
        $this->actingAs($this->reportes)->get('/exportar/excel?source=votantes')->assertStatus(429);
    }

    public function test_the_old_votantes_export_drops_the_cedula_without_the_permission_and_is_audited(): void
    {
        Excel::fake();
        $this->actingAs($this->reportes)->get('/exportar/excel?source=votantes&title=Votantes&columns=nombre,cedula')
            ->assertOk()->assertHeader('X-Columnas-Quitadas', 'cedula');
        Excel::assertDownloaded('Votantes.xlsx', function (GenericExport $export) {
            $this->assertSame([[DescargaGuard::AVISO_SIN_DATOS], ['NOMBRE']], $export->headings());
            $this->assertSame([['Ana Gran Elector'], ['Beto Normal']], $export->array());

            return true;
        });
        $log = AuditLog::where('action', 'EXPORT exportar.votantes.excel')->sole();
        $this->assertSame('reporte', $log->entity_type);
        $this->assertSame(['nombre'], $log->metadata['columnas']);
        $this->assertSame(['cedula'], $log->metadata['columnas_quitadas']);
        $this->assertFalse($log->metadata['con_datos_personales']);

        Excel::fake();
        $conDatos = $this->eventUser(Campaign::DEFAULT_ID, ['view.votantes', 'export.datos_personales']);
        $this->actingAs($conDatos)->get('/exportar/excel?source=votantes&title=Votantes&columns=nombre,cedula')
            ->assertOk()->assertHeaderMissing('X-Columnas-Quitadas');
        Excel::assertDownloaded('Votantes.xlsx', function (GenericExport $export) {
            $this->assertSame(['NOMBRE', 'CEDULA'], $export->headings());
            $this->assertSame([['Ana Gran Elector', '0123'], ['Beto Normal', '0456']], $export->array());

            return true;
        });
        $this->assertTrue(AuditLog::where('user_id', $conDatos->id)->sole()->metadata['con_datos_personales']);

        // When every requested column is personal the file has the name only, and so does its audit row.
        Excel::fake();
        $this->actingAs($this->reportes)->get('/exportar/excel?source=votantes&title=Votantes&columns=cedula,telefono')->assertOk();
        Excel::assertDownloaded('Votantes.xlsx', fn (GenericExport $export) => $export->headings() === [[DescargaGuard::AVISO_SIN_DATOS], ['NOMBRE']]);
        $ultimo = AuditLog::where('user_id', $this->reportes->id)->where('action', 'EXPORT exportar.votantes.excel')->get()
            ->firstWhere(fn ($l) => $l->metadata['columnas_quitadas'] === ['cedula', 'telefono']);
        $this->assertSame(['nombre'], $ultimo->metadata['columnas']);

        // A refused export (no view.votantes) writes nothing.
        $sinVista = $this->eventUser(Campaign::DEFAULT_ID, ['view.mapa']);
        $this->actingAs($sinVista)->get('/exportar/excel?source=votantes')->assertForbidden();
        $this->assertSame(0, AuditLog::where('user_id', $sinVista->id)->count());
    }

    public function test_the_real_old_file_and_pdf_carry_the_notice_above_the_headings(): void
    {
        $response = $this->actingAs($this->reportes)->get('/exportar/excel?source=votantes&title=Votantes&columns=nombre,cedula')->assertOk();
        $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();
        $this->assertSame(DescargaGuard::AVISO_SIN_DATOS, $sheet->getCell('A1')->getValue());
        $this->assertSame('NOMBRE', $sheet->getCell('A2')->getValue());
        $this->assertSame('Ana Gran Elector', $sheet->getCell('A3')->getValue());
        $this->assertNull($sheet->getCell('B2')->getValue());

        $aviso = null;
        View::composer('exports.table', function ($view) use (&$aviso) {
            $aviso = $view->getData()['aviso'] ?? null;
        });
        $this->actingAs($this->eventUser(Campaign::DEFAULT_ID, ['view.votantes']))->get('/exportar/pdf?source=votantes')
            ->assertOk()->assertHeader('X-Columnas-Quitadas', 'telefono,observacion');
        $this->assertSame(DescargaGuard::AVISO_SIN_DATOS, $aviso);
    }

    public function test_the_old_exports_are_limited_too(): void
    {
        Excel::fake();
        $user = $this->eventUser(Campaign::DEFAULT_ID, ['view.votantes']);
        foreach (range(1, DescargaGuard::POR_MINUTO) as $i) {
            $this->actingAs($user)->get('/exportar/excel?source=votantes')->assertOk();
        }
        $this->actingAs($user)->get('/exportar/excel?source=votantes')->assertStatus(429)->assertSee(DescargaGuard::SEGUIDAS);
        $this->assertSame(DescargaGuard::POR_MINUTO, AuditLog::where('user_id', $user->id)->count());
    }
}
