<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportesController;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\User;
use App\Services\Reportes\DescargaGuard;
use App\Services\Reportes\Fuentes\PersonasFuente;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// B-2: the institutional PDF of a report (docs/reportes.md). Same guard, quota and audit as the Excel; synthetic data.
class ReportesPdfTest extends TestCase
{
    use BuildsEventosScenario;

    private User $reportes;

    private ?array $vista = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEventos();
        $this->addMapaVotantesTables();
        PersonasFuente::olvidarEsquema();
        $this->reportes = $this->eventUser(Campaign::DEFAULT_ID, ['view.reportes', 'view.votantes']);
        $this->reportes->forceFill(['name' => 'Marta Prueba'])->save();
        $this->lider(Campaign::DEFAULT_ID, 'Ana Gran Elector', '0123', ['gran_elector' => true]);
        $this->lider(Campaign::DEFAULT_ID, 'Beto Normal', '0456', ['geographic_unit_id' => $this->lebrija, 'municipio' => 'Lebrija']);
        $this->lider($this->campY, 'Ceci Otro Grupo', '0789', ['gran_elector' => true]);

        View::composer('reportes.lista', function ($view) {
            $this->vista = $view->getData();
        });
    }

    private function spec(array $filtros = [], array $columnas = ['nombre', 'municipio']): array
    {
        return ['v' => 1, 'fuente' => 'personas', 'filtros' => $filtros, 'columnas' => $columnas,
            'orden' => ['campo' => 'nombre', 'dir' => 'asc'], 'busqueda' => ''];
    }

    private function pdf(User $user, array $spec)
    {
        return $this->actingAs($user)->postJson('/reportes/personas/pdf', ['spec' => $spec]);
    }

    public function test_the_pdf_comes_with_its_cover_its_rows_and_an_audit_row_written_first(): void
    {
        $response = $this->pdf($this->reportes, $this->spec(['marcas' => ['gran_elector']]))->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertMatchesRegularExpression('/filename=Reporte_Gran_elector_\d{4}-\d{2}-\d{2}\.pdf/', $response->headers->get('Content-Disposition'));
        $this->assertSame('1', $response->headers->get('X-Reporte-Filas'));
        $this->assertSame('1', $response->headers->get('X-Reporte-Total'));
        $this->assertSame('0', $response->headers->get('X-Reporte-Recortado'));
        $this->assertNull($response->headers->get('X-Reporte-Columnas-Omitidas'));

        $this->assertSame('Reporte de personas — Gran elector', $this->vista['titulo']);
        $this->assertSame('Centro Democrático', $this->vista['grupo']);
        $this->assertStringStartsWith('data:image/png;base64,', $this->vista['logo']);
        $this->assertMatchesRegularExpression('/^Generado por Marta Prueba, \d{2}\/\d{2}\/\d{4} \d{1,2}:\d{2} [ap]\. m\.$/u', $this->vista['generado']);
        $this->assertSame(['Gran elector'], $this->vista['filtros']);
        $this->assertSame(['nombre' => 'Nombre', 'municipio' => 'Municipio'], $this->vista['columnas']);
        $this->assertSame([['nombre' => 'Ana Gran Elector', 'municipio' => 'Girón']], $this->vista['filas']);
        $this->assertSame(['municipios' => [['nombre' => 'Girón', 'total' => 1]], 'otros' => 0], $this->vista['desglose']);
        $this->assertNull($this->vista['aviso']);

        $log = AuditLog::where('action', 'EXPORT reportes.personas.pdf')->sole();
        $this->assertSame('reporte', $log->entity_type);
        $this->assertSame('pdf', $log->metadata['formato']);
        $this->assertSame(1, $log->metadata['filas']);
        $this->assertFalse($log->metadata['con_datos_personales']);
        $this->assertSame('reportes.descargar.pdf', $log->metadata['ruta']);
        $this->assertSame(1, AuditLog::count(), 'AuditActivity must not log the download again');
    }

    public function test_the_time_is_bogota_in_twelve_hour_form(): void
    {
        $this->assertSame('20/11/2026 3:40 p. m.', ReportesController::fechaHora(CarbonImmutable::parse('2026-11-20 15:40', 'America/Bogota')));
        $this->assertSame('01/12/2026 9:05 a. m.', ReportesController::fechaHora(CarbonImmutable::parse('2026-12-01 09:05', 'America/Bogota')));
    }

    public function test_another_grupo_gets_its_name_and_no_centro_democratico_logo(): void
    {
        $otra = $this->eventUser($this->campY, ['view.reportes', 'view.votantes']);

        $this->pdf($otra, $this->spec())->assertOk();

        $this->assertSame('Otra', $this->vista['grupo']);
        $this->assertNull($this->vista['logo']);
        $this->assertSame([['nombre' => 'Ceci Otro Grupo', 'municipio' => 'Girón']], $this->vista['filas']);
    }

    public function test_the_pdf_is_cut_at_the_ajuste_and_the_cover_counts_everyone(): void
    {
        $now = now()->toDateTimeString();
        foreach (array_chunk(range(1, 1644), 400) as $chunk) {
            DB::table('lideres')->insert(array_map(fn ($i) => [
                'id' => (string) Str::uuid(), 'campaign_id' => Campaign::DEFAULT_ID,
                'geographic_unit_id' => $i % 4 === 0 ? $this->velez : $this->giron, 'nombre' => sprintf('Sintética %04d', $i),
                'municipio' => 'x', 'tipo' => 'votante', 'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }

        $response = $this->pdf($this->reportes, $this->spec([], ['nombre']))->assertOk();

        $this->assertSame('500', $response->headers->get('X-Reporte-Filas'));
        $this->assertSame('1646', $response->headers->get('X-Reporte-Total'));
        $this->assertSame('1', $response->headers->get('X-Reporte-Recortado'));
        $this->assertCount(500, $this->vista['filas']);
        $this->assertSame('Ana Gran Elector', $this->vista['filas'][0]['nombre']);
        $this->assertSame('Este PDF muestra las primeras 500 de 1.646 personas. Para tenerlas todas, descarga el Excel.', $this->vista['aviso']);
        $this->assertSame(1646, $this->vista['total']);
        $this->assertSame([
            ['nombre' => 'Girón', 'total' => 1234],
            ['nombre' => 'Vélez', 'total' => 411],
            ['nombre' => 'Lebrija', 'total' => 1],
        ], $this->vista['desglose']['municipios']);

        $log = AuditLog::where('action', 'EXPORT reportes.personas.pdf')->sole();
        $this->assertTrue($log->metadata['recortado']);
        $this->assertSame(500, $log->metadata['filas']);
        $this->assertSame(1646, $log->metadata['total']);
    }

    public function test_beyond_ten_municipios_the_rest_is_otros(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $geo = $this->geo('municipality', sprintf('Municipio %02d', $i), $this->soto);
            for ($j = 0; $j < 13 - $i; $j++) {
                $this->lider(Campaign::DEFAULT_ID, "Persona {$i}-{$j}", null, ['geographic_unit_id' => $geo]);
            }
        }

        $this->pdf($this->reportes, $this->spec())->assertOk();

        $desglose = $this->vista['desglose'];
        $this->assertCount(ReportesController::PDF_MUNICIPIOS, $desglose['municipios']);
        $this->assertSame(['nombre' => 'Municipio 01', 'total' => 12], $desglose['municipios'][0]);
        // Municipios 11 and 12 (2 + 1) and Girón and Lebrija (1 + 1) are left for «Otros».
        $this->assertSame(5, $desglose['otros']);
        $this->assertSame($this->vista['total'], array_sum(array_column($desglose['municipios'], 'total')) + $desglose['otros']);
    }

    public function test_twelve_columns_give_eight_and_name_the_rest(): void
    {
        $columnas = ['nombre', 'tipo_documento', 'municipio', 'provincia', 'barrio', 'zona', 'puesto_votacion', 'genero',
            'mesa_votacion', 'municipio_votacion', 'edad', 'profesion'];

        $response = $this->pdf($this->reportes, $this->spec([], $columnas))->assertOk();

        $this->assertSame('mesa_votacion,municipio_votacion,edad,profesion', $response->headers->get('X-Reporte-Columnas-Omitidas'));
        $this->assertSame(array_slice($columnas, 0, 8), array_keys($this->vista['columnas']));
        $this->assertSame(array_slice($columnas, 0, 8), array_keys($this->vista['filas'][0]));
        $log = AuditLog::where('action', 'EXPORT reportes.personas.pdf')->sole();
        $this->assertSame(array_slice($columnas, 0, 8), $log->metadata['columnas']);
        $this->assertSame(['mesa_votacion', 'municipio_votacion', 'edad', 'profesion'], $log->metadata['columnas_omitidas']);
    }

    public function test_a_sensitive_column_without_the_permission_is_refused_without_an_audit_row(): void
    {
        $this->pdf($this->reportes, $this->spec([], ['nombre', 'cedula']))
            ->assertForbidden()->assertJson(['success' => false]);
        // Also past the eighth column: what is asked for is checked, not only what fits.
        $this->pdf($this->reportes, $this->spec([], ['nombre', 'municipio', 'provincia', 'barrio', 'zona', 'genero', 'edad', 'profesion', 'telefono']))
            ->assertForbidden();
        $this->pdf($this->reportes, $this->spec([], ['password']))->assertStatus(422);
        $this->assertSame(0, AuditLog::count());

        $conDatos = $this->eventUser(Campaign::DEFAULT_ID, ['view.reportes', 'view.votantes', 'export.datos_personales']);
        $this->pdf($conDatos, $this->spec(['marcas' => ['gran_elector']], ['nombre', 'cedula', 'telefono']))->assertOk();
        $this->assertSame([['nombre' => 'Ana Gran Elector', 'cedula' => '0123', 'telefono' => '3000000000']], $this->vista['filas']);
        $this->assertTrue(AuditLog::where('action', 'EXPORT reportes.personas.pdf')->sole()->metadata['con_datos_personales']);
    }

    public function test_a_name_with_markup_is_printed_as_text(): void
    {
        $this->lider(Campaign::DEFAULT_ID, '<script>alert(1)</script><img src=x onerror=alert(2)>', null, ['barrio' => '<b>Barrio</b>']);

        $this->pdf($this->reportes, $this->spec([], ['nombre', 'barrio']))->assertOk();

        $html = view('reportes.lista', $this->vista)->render();
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;&lt;img src=x onerror=alert(2)&gt;', $html);
        $this->assertStringContainsString('&lt;b&gt;Barrio&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
    }

    public function test_the_quota_is_shared_with_the_excel(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($this->reportes)->postJson('/reportes/personas/excel', ['spec' => $this->spec()])->assertOk()->streamedContent();
        }
        $this->pdf($this->reportes, $this->spec())->assertOk();
        $this->pdf($this->reportes, $this->spec())->assertOk();

        $this->pdf($this->reportes, $this->spec())
            ->assertStatus(429)->assertJson(['success' => false, 'motivo' => 'seguidas', 'message' => DescargaGuard::SEGUIDAS])
            ->assertHeader('Retry-After');
        $this->actingAs($this->reportes)->postJson('/reportes/personas/excel', ['spec' => $this->spec()])->assertStatus(429);
        $this->assertSame(3, AuditLog::where('action', 'EXPORT reportes.personas.excel')->count());
        $this->assertSame(2, AuditLog::where('action', 'EXPORT reportes.personas.pdf')->count());
    }

    public function test_a_download_in_progress_blocks_the_pdf(): void
    {
        $lock = Cache::lock('reporte-descarga:'.$this->reportes->id, DescargaGuard::LOCK_SEGUNDOS);
        $lock->get();

        $this->pdf($this->reportes, $this->spec())->assertStatus(429)->assertJson(['motivo' => 'en_curso']);
        $this->assertSame(0, AuditLog::count());

        $lock->release();
        $this->pdf($this->reportes, $this->spec())->assertOk();
    }

    public function test_without_view_reportes_or_a_session_there_is_no_pdf(): void
    {
        $sinReportes = $this->eventUser(Campaign::DEFAULT_ID, ['view.votantes', 'view.mapa', 'export.datos_personales']);
        $this->pdf($sinReportes, $this->spec())->assertForbidden();

        $sinFuente = $this->eventUser(Campaign::DEFAULT_ID, ['view.reportes']);
        $this->pdf($sinFuente, $this->spec())->assertForbidden();

        auth()->logout();
        $this->postJson('/reportes/personas/pdf', ['spec' => $this->spec()])->assertUnauthorized();
        $this->assertSame(0, AuditLog::count());
    }

    public function test_an_empty_list_still_gives_a_cover(): void
    {
        $response = $this->pdf($this->reportes, $this->spec(['marcas' => ['reservista']]))->assertOk();

        $this->assertSame('0', $response->headers->get('X-Reporte-Filas'));
        $this->assertSame([], $this->vista['filas']);
        $this->assertSame(['municipios' => [], 'otros' => 0], $this->vista['desglose']);
        $this->assertStringContainsString('Nadie cumple todos estos filtros.', view('reportes.lista', $this->vista)->render());
    }

    public function test_without_the_permission_the_search_does_not_look_at_the_cedula(): void
    {
        $buscar = fn (User $u, string $q) => $this->actingAs($u)->postJson('/reportes/personas/lista', ['spec' => array_merge($this->spec(), ['busqueda' => $q])])
            ->assertOk()->json('total');
        $conDatos = $this->eventUser(Campaign::DEFAULT_ID, ['view.reportes', 'view.votantes', 'export.datos_personales']);

        $this->assertSame(0, $buscar($this->reportes, '0123'));
        $this->assertSame(1, $buscar($this->reportes, 'Ana Gran'));
        $this->assertSame(1, $buscar($conDatos, '0123'));
    }

    public function test_a_searched_document_is_never_printed_whole(): void
    {
        $conDatos = $this->eventUser(Campaign::DEFAULT_ID, ['view.reportes', 'view.votantes', 'export.datos_personales']);
        $this->lider(Campaign::DEFAULT_ID, 'Buscada', '1098765432', ['gran_elector' => true]);

        // With the permission the cédula finds her; without it, nobody.
        foreach ([[$conDatos, ['Buscada']], [$this->reportes, []]] as [$user, $encontradas]) {
            $response = $this->pdf($user, array_merge($this->spec(['marcas' => ['gran_elector']]), ['busqueda' => '1098765432']))->assertOk();

            $this->assertContains('Búsqueda por documento terminado en 5432', $this->vista['filtros']);
            $html = view('reportes.lista', $this->vista)->render();
            $this->assertStringNotContainsString('1098765432', $html);
            $this->assertStringNotContainsString('1.098.765.432', $html);
            $this->assertStringNotContainsString('5432', $response->headers->get('Content-Disposition'));
            $this->assertSame($encontradas, array_column($this->vista['filas'], 'nombre'));
        }
        $this->assertSame('Búsqueda por documento terminado en 5432', PersonasFuente::busquedaEnPalabras('1.098.765.432'));
        $this->assertSame('Búsqueda «Ana»', PersonasFuente::busquedaEnPalabras('Ana'));
        $this->assertSame('Búsqueda «3001»', PersonasFuente::busquedaEnPalabras('3001'));
    }

    public function test_a_full_cover_fits_on_one_page(): void
    {
        $municipios = array_map(fn ($i) => ['nombre' => "San Vicente de Chucurí Municipio {$i}", 'total' => 1000 - $i], range(1, 10));
        $pdf = Pdf::loadView('reportes.lista', [
            'titulo' => 'Reporte de personas — Gran elector o Militante o Destacado · Votante o Líder · Soto o Vélez',
            'grupo' => 'Centro Democrático', 'logo' => null,
            'generado' => 'Generado por Marta Prueba, 20/11/2026 3:40 p. m.',
            'filtros' => ['Gran elector o Militante o Destacado', 'Votante o Líder', 'Soto o Vélez', 'De 41 a 60 años',
                'Confianza: Alta', 'Barrio Centro o San Francisco', 'Autorizaron sus datos', 'Por completar: barrio',
                'Búsqueda por documento terminado en 5432'],
            'total' => 123456,
            'desglose' => ['municipios' => $municipios, 'otros' => 50000],
            'aviso' => 'Este PDF muestra las primeras 500 de 123.456 personas. Para tenerlas todas, descarga el Excel.',
            'columnas' => ['nombre' => 'Nombre'], 'filas' => [],
        ])->setPaper('a4', 'landscape');
        $pdf->render();

        $this->assertSame(1, $pdf->getDomPDF()->getCanvas()->get_page_count());
    }
}
