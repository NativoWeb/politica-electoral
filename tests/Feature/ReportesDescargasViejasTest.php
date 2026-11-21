<?php

namespace Tests\Feature;

use App\Exports\GenericExport;
use App\Exports\SheetsExport;
use App\Http\Requests\Eventos\AttendeeInput;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\User;
use App\Services\Reportes\DescargaGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// Reportes B-3 (docs/reportes.md, Descargas): the old Excel/PDF of Eventos and Empresarios go through DescargaGuard:
// one at a time, the shared quota, an audit row before the file, and no personal column without
// export.datos_personales (notice in the file + X-Columnas-Quitadas). SQLite.
class ReportesDescargasViejasTest extends TestCase
{
    use BuildsEventosScenario;

    private const EVENTOS = ['view.eventos', 'view.votantes'];

    private User $sinDatos;

    private User $conDatos;

    private CampaignEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEventos();
        (require database_path('migrations/2026_11_10_000001_create_event_actas_tables.php'))->up();
        (require database_path('migrations/2026_11_11_000001_add_created_flags_to_event_acta_participantes.php'))->up();
        (require database_path('migrations/2026_11_13_000002_add_organizacion_to_event_actas.php'))->up();
        $this->sinDatos = $this->eventUser(Campaign::DEFAULT_ID, [...self::EVENTOS, 'view.empresarios']);
        $this->conDatos = $this->eventUser(Campaign::DEFAULT_ID, [...self::EVENTOS, 'view.empresarios', 'export.datos_personales']);
        $this->event = $this->makeEvent(Campaign::DEFAULT_ID, ['nombre' => 'Gira Girón']);
        $this->lider(Campaign::DEFAULT_ID, 'Líder Girón', '1111');
        DB::table('event_attendees')->insert([
            'id' => (string) Str::uuid(), 'event_id' => $this->event->id, 'campaign_id' => Campaign::DEFAULT_ID, 'nombres' => 'Ana',
            'apellidos' => 'Asistente', 'cedula' => '1098765432', 'celular' => '3001234567', 'fijo' => '6076000000', 'direccion' => 'Calle 1',
            'barrio' => 'Centro', 'barrio_normalizado' => AttendeeInput::barrioKey('Centro'), 'autoriza_datos' => false, 'source' => 'qr',
            'link_status' => 'pendiente', 'client_uuid' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // [headings, rows] of the GenericExport downloaded under $name (Excel faked).
    private function descarga(User $user, string $url, string $name, ?string $quitadas): array
    {
        Excel::fake();
        $response = $this->actingAs($user)->get($url)->assertOk();
        $quitadas === null ? $response->assertHeaderMissing('X-Columnas-Quitadas') : $response->assertHeader('X-Columnas-Quitadas', $quitadas);
        $out = null;
        Excel::assertDownloaded($name, function ($export) use (&$out) {
            $sheets = $export instanceof SheetsExport ? $export->sheets() : [$export];
            $out = array_map(fn (GenericExport $s) => ['headings' => $s->headings(), 'data' => $s->array()], $sheets);

            return true;
        });

        return $out;
    }

    public function test_attendees_excel_without_the_permission_has_no_personal_column_and_says_so(): void
    {
        [$sheet] = $this->descarga($this->sinDatos, "/eventos/{$this->event->id}/asistentes.xlsx", 'asistentes-gira-giron.xlsx',
            'cedula,celular,fijo,fecha_nacimiento,direccion');
        $this->assertSame([DescargaGuard::AVISO_SIN_DATOS], $sheet['headings'][0]);
        $this->assertSame(['NOMBRES', 'APELLIDOS', 'BARRIO', 'ORIGEN', 'REGISTRADO POR', 'ESTADO DE VÍNCULO', 'EN VOTANTES', 'FECHA DE REGISTRO'], $sheet['headings'][1]);
        $this->assertStringNotContainsString('1098765432', json_encode($sheet['data']));
        $this->assertStringNotContainsString('3001234567', json_encode($sheet['data']));

        $log = AuditLog::where('action', 'EXPORT eventos.asistentes.excel')->sole();
        $this->assertSame('reporte', $log->entity_type);
        $this->assertSame($this->event->id, $log->metadata['evento_id']);
        $this->assertSame(['cedula', 'celular', 'fijo', 'fecha_nacimiento', 'direccion'], $log->metadata['columnas_quitadas']);
        $this->assertFalse($log->metadata['con_datos_personales']);
        $this->assertSame(1, $log->metadata['filas']);
        $this->assertSame('eventos.asistentes.excel', $log->metadata['ruta']);

        [$sheet] = $this->descarga($this->conDatos, "/eventos/{$this->event->id}/asistentes.xlsx", 'asistentes-gira-giron.xlsx', null);
        $this->assertContains('CÉDULA', $sheet['headings']);
        $this->assertSame('1098765432', $sheet['data'][0][2]);
        $this->assertTrue(AuditLog::where('user_id', $this->conDatos->id)->sole()->metadata['con_datos_personales']);
    }

    public function test_a_refused_download_spends_no_quota_and_writes_nothing(): void
    {
        $otro = $this->makeEvent($this->campY, ['nombre' => 'Otro grupo']);
        $this->actingAs($this->sinDatos)->get("/eventos/{$otro->id}/asistentes.xlsx")->assertNotFound();
        $lebrija = $this->eventUser(Campaign::DEFAULT_ID, self::EVENTOS, 'R02_ADMIN_FUNCIONAL', [$this->lebrija]);
        $this->actingAs($lebrija)->get("/eventos/{$this->event->id}/asistentes.xlsx")->assertForbidden();
        $this->actingAs($lebrija)->get("/eventos/territorio/excel?municipio={$this->giron}")->assertForbidden();
        $this->actingAs($this->sinDatos)->get('/eventos/territorio/excel')->assertNotFound();
        $sinEmpresarios = $this->eventUser(Campaign::DEFAULT_ID, self::EVENTOS);
        $this->actingAs($sinEmpresarios)->getJson('/empresarios/exportar.xlsx')->assertForbidden();
        $this->assertSame(0, AuditLog::count());

        // The quota was not touched: five downloads still go through.
        Excel::fake();
        foreach (range(1, DescargaGuard::POR_MINUTO) as $i) {
            $this->actingAs($this->sinDatos)->get("/eventos/{$this->event->id}/asistentes.xlsx")->assertOk();
        }
        $this->actingAs($this->sinDatos)->get("/eventos/{$this->event->id}/asistentes.xlsx")->assertStatus(429);
        $this->assertSame(DescargaGuard::POR_MINUTO, AuditLog::count());
    }

    public function test_the_old_exports_share_the_quota_and_the_lock_with_reportes(): void
    {
        Excel::fake();
        $urls = ["/eventos/{$this->event->id}/asistentes.xlsx", "/eventos/territorio/excel?municipio={$this->giron}", '/eventos/compromisos.xlsx',
            '/empresarios/exportar.xlsx', '/exportar/excel?source=votantes'];
        foreach ($urls as $url) {
            $this->actingAs($this->sinDatos)->get($url)->assertOk();
        }
        $this->actingAs($this->sinDatos)->get('/empresarios/exportar.pdf')->assertStatus(429)->assertSee(DescargaGuard::SEGUIDAS);
        $this->actingAs($this->sinDatos)->getJson('/eventos/compromisos.xlsx')->assertStatus(429)->assertJson(['success' => false, 'motivo' => 'seguidas']);
        $this->assertSame(5, AuditLog::where('user_id', $this->sinDatos->id)->count());

        // A download still being written holds the lock: the next one is refused without an audit row.
        $lock = Cache::lock('reporte-descarga:'.$this->conDatos->id, DescargaGuard::LOCK_SEGUNDOS);
        $lock->get();
        $this->actingAs($this->conDatos)->getJson("/eventos/{$this->event->id}/asistentes.xlsx")
            ->assertStatus(429)->assertJson(['motivo' => 'en_curso', 'message' => DescargaGuard::EN_CURSO]);
        $lock->release();
        $this->assertSame(0, AuditLog::where('user_id', $this->conDatos->id)->count());
    }

    public function test_territory_excel_drops_the_personal_columns_of_each_sheet(): void
    {
        $sheets = $this->descarga($this->sinDatos, "/eventos/territorio/excel?municipio={$this->giron}", 'territorio-giron.xlsx', 'cedula,telefono,celular');
        [$lideres, $eventos, $asistentes] = $sheets;
        $this->assertSame([DescargaGuard::AVISO_SIN_DATOS], $lideres['headings'][0]);
        $this->assertNotContains('CÉDULA', $lideres['headings'][1]);
        $this->assertNotContains('TELÉFONO', $lideres['headings'][1]);
        $this->assertSame('NOMBRE', $eventos['headings'][0], 'The events sheet has no personal data: no notice');
        $this->assertSame([DescargaGuard::AVISO_SIN_DATOS], $asistentes['headings'][0]);
        $this->assertSame(['NOMBRES', 'APELLIDOS', 'BARRIO', 'EVENTO', 'FECHA', 'ORIGEN', 'ESTADO', 'GRUPO POLÍTICO'], $asistentes['headings'][1]);
        $this->assertStringNotContainsString('1098765432', json_encode($sheets));

        $log = AuditLog::where('action', 'EXPORT eventos.territorio.excel')->sole();
        $this->assertSame(['cedula', 'telefono', 'celular'], $log->metadata['columnas_quitadas']);
        $this->assertSame(1, $log->metadata['asistentes']);

        $sheets = $this->descarga($this->conDatos, "/eventos/territorio/excel?municipio={$this->giron}", 'territorio-giron.xlsx', null);
        $this->assertContains('CÉDULA', $sheets[0]['headings']);
        $this->assertContains('CELULAR', $sheets[2]['headings']);
    }

    public function test_compromisos_excel_without_the_permission_has_no_phones(): void
    {
        $this->actingAs($this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos', 'view.eventos']));
        $manager = auth()->user();
        $acta = $this->actingAs($manager)->postJson("/eventos/{$this->event->id}/actas", ['titulo' => 'Acta', 'fecha' => '2026-11-08',
            'compromisos' => [['descripcion' => 'Gestionar cámaras', 'fecha_limite' => null, 'responsables' => [['nombre' => 'Sandra', 'telefono' => '3109990000']]]]])
            ->assertCreated()->json('data.id');
        $this->actingAs($manager)->postJson("/eventos/{$this->event->id}/actas/{$acta}/confirmar")->assertOk();

        [$sheet] = $this->descarga($this->sinDatos, '/eventos/compromisos.xlsx', 'compromisos.xlsx', 'telefono');
        $this->assertSame([DescargaGuard::AVISO_SIN_DATOS], $sheet['headings'][0]);
        $this->assertNotContains('TELÉFONOS', $sheet['headings'][1]);
        $this->assertStringNotContainsString('3109990000', json_encode($sheet['data']));
        $this->assertSame(['telefono'], AuditLog::where('action', 'EXPORT eventos.compromisos.excel')->sole()->metadata['columnas_quitadas']);

        [$sheet] = $this->descarga($this->conDatos, '/eventos/compromisos.xlsx', 'compromisos.xlsx', null);
        $this->assertContains('Sandra: 3109990000', $sheet['data'][0]);
    }

    public function test_empresarios_excel_and_pdf_without_the_permission(): void
    {
        DB::table('organizaciones')->insert(['id' => (string) Str::uuid(), 'campaign_id' => Campaign::DEFAULT_ID, 'tipo' => 'empresa', 'nombre' => 'Lácteos',
            'representante_nombre' => 'Rosa', 'representante_cedula' => '9001234', 'representante_celular' => '3115550000', 'telefono' => '6071234567',
            'correo' => 'rosa@lacteos.test', 'direccion' => 'Calle 9', 'observaciones' => 'Nota', 'municipio_id' => $this->giron, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now()]);

        [$sheet] = $this->descarga($this->sinDatos, '/empresarios/exportar.xlsx', 'empresarios-y-gremios.xlsx',
            'representante_cedula,representante_celular,telefono,correo,direccion,observaciones');
        $this->assertSame([DescargaGuard::AVISO_SIN_DATOS], $sheet['headings'][0]);
        foreach (['CÉDULA REPRESENTANTE', 'CELULAR REPRESENTANTE', 'TELÉFONO', 'CORREO', 'DIRECCIÓN', 'OBSERVACIONES'] as $h) {
            $this->assertNotContains($h, $sheet['headings'][1]);
        }
        $this->assertContains('REPRESENTANTE LEGAL', $sheet['headings'][1]);
        foreach (['9001234', '3115550000', '6071234567', 'rosa@lacteos.test', 'Calle 9'] as $dato) {
            $this->assertStringNotContainsString($dato, json_encode($sheet['data']));
        }

        $vista = null;
        View::composer('exports.table', function ($view) use (&$vista) {
            $vista = $view->getData();
        });
        $this->actingAs($this->sinDatos)->get('/empresarios/exportar.pdf')->assertOk()->assertHeader('X-Columnas-Quitadas', 'representante_celular');
        $this->assertSame(DescargaGuard::AVISO_SIN_DATOS, $vista['aviso']);
        $this->assertNotContains('representante_celular', $vista['columns']);
        $this->assertSame(['excel', 'pdf'], AuditLog::where('user_id', $this->sinDatos->id)->orderBy('created_at')->get()
            ->map(fn ($l) => $l->metadata['formato'])->sort()->values()->all());

        $this->actingAs($this->conDatos)->get('/empresarios/exportar.pdf')->assertOk()->assertHeaderMissing('X-Columnas-Quitadas');
        $this->assertContains('representante_celular', $vista['columns']);
        $this->assertNull($vista['aviso']);
    }
}
