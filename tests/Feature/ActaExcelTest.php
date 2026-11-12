<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// Acta loaded from the Excel template (docs/eventos-api.md §14): template download, reading of an event's and an
// organization's acta, Excel and text dates, missing sheets, invalid files and permissions. SQLite, fictitious data.
// Each test in its own process, as EmpresariosTest: PhpSpreadsheet would push the shared process over its memory limit.
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class ActaExcelTest extends TestCase
{
    use BuildsEventosScenario;

    private User $manager;

    private CampaignEvent $event;

    private array $books = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEventos();
        (require database_path('migrations/2026_11_10_000001_create_event_actas_tables.php'))->up();
        (require database_path('migrations/2026_11_11_000001_add_created_flags_to_event_acta_participantes.php'))->up();
        (require database_path('migrations/2026_11_13_000002_add_organizacion_to_event_actas.php'))->up();
        Storage::fake('local');
        $this->manager = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos', 'view.eventos', 'view.votantes']);
        $this->event = $this->makeEvent(Campaign::DEFAULT_ID, ['nombre' => 'Evento de prueba']);
    }

    protected function tearDown(): void
    {
        foreach ($this->books as $book) {
            $book->disconnectWorksheets();
        }
        $this->books = [];
        parent::tearDown();
        gc_collect_cycles();
    }

    private function book(): Spreadsheet
    {
        return $this->books[] = new Spreadsheet;
    }

    private function templateBook(string $url, ?User $user = null): Spreadsheet
    {
        $response = $this->actingAs($user ?? $this->manager)->get($url)->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $response->getContent());

        return $this->books[] = IOFactory::load($path);
    }

    // The template filled as a user would: Excel dates and times as numbers, text dates, multi-line cells.
    private function fill(Spreadsheet $book): void
    {
        $acta = $book->getSheetByName('Acta');
        $values = [
            'Título' => 'REUNIÓN DE PRUEBA VEREDA EJEMPLO',
            'Fecha' => Date::PHPToExcel(new \DateTime('2026-11-08')),
            'Hora de inicio' => 10.75 / 24,
            'Hora de fin' => '12:00 m',
            'Municipio' => 'Girón',
            'Lugar' => 'Salón comunal',
            'Objetivo / tema' => 'Necesidades de la vereda',
            'Orden del día' => "1. Seguridad\n2. Vías",
            'Conclusiones' => 'Se acordó volver a reunirse.',
            'Cierre' => 'Siendo las 12:00 m., se dio por terminada la reunión.',
        ];
        foreach ($acta->toArray() as $i => $row) {
            if (array_key_exists($row[0] ?? '', $values)) {
                $acta->setCellValue('B'.($i + 1), $values[$row[0]]);
            }
        }
        $last = $acta->getHighestRow();
        $acta->fromArray([['Desarrollo', 'Se revisó la estación.'], ['desarrollo', 'Se pidió arreglar la vía.']], null, 'A'.($last + 1));

        $participantes = $book->getSheetByName('Participantes');
        $participantes->fromArray([['Persona Excel Uno', null, null, 'Lebrija', 'PRESIDENTE JAC'], [null, null, null, 'Girón', 'Sin nombre']], null, 'A2');
        $participantes->setCellValueExplicit('B2', '1098765432', DataType::TYPE_STRING);
        $participantes->setCellValue('C2', 3112223344);

        $compromisos = $book->getSheetByName('Compromisos');
        $compromisos->fromArray([
            ['Gestionar cámaras', "Responsable Uno\nResponsable Dos", "3205556677\n3001112233", Date::PHPToExcel(new \DateTime('2026-12-01')), '25/11/2026'],
            ['Arreglar la vía', 'Responsable Uno', null, '31/02/2026', null],
        ], null, 'A2');
    }

    private function upload(Spreadsheet $book, string $extension = 'xlsx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'acta').'.'.$extension;
        ($extension === 'xls' ? new Xls($book) : new Xlsx($book))->save($path);

        return new UploadedFile($path, "acta.{$extension}", null, null, true);
    }

    public function test_the_template_has_the_three_sheets_with_clear_headers_and_no_fake_rows(): void
    {
        $book = $this->templateBook("/eventos/{$this->event->id}/actas/plantilla.xlsx");

        $this->assertSame(['Acta', 'Participantes', 'Compromisos', 'Instrucciones'], $book->getSheetNames());
        $this->assertSame(['Campo', 'Valor'], array_slice($book->getSheetByName('Acta')->toArray()[0], 0, 2));
        $this->assertContains('Orden del día', array_column($book->getSheetByName('Acta')->toArray(), 0));
        $this->assertSame(['Nombre', 'Cédula', 'Teléfono', 'Municipio', 'Perfil / cargo'], $book->getSheetByName('Participantes')->toArray()[0]);
        $this->assertSame(['Descripción', 'Responsable', 'Teléfono del responsable', 'Fecha límite', 'Recordar el'], $book->getSheetByName('Compromisos')->toArray()[0]);
        foreach (['Participantes', 'Compromisos'] as $name) {
            $filled = array_filter($book->getSheetByName($name)->toArray(), fn ($row) => array_filter($row, fn ($v) => $v !== null && $v !== '') !== []);
            $this->assertCount(1, $filled, "{$name} only has the headers");
        }
    }

    public function test_an_event_acta_is_read_from_the_filled_template_and_saved_as_a_borrador(): void
    {
        $book = $this->templateBook("/eventos/{$this->event->id}/actas/plantilla.xlsx");
        $this->fill($book);

        $response = $this->actingAs($this->manager)->post("/eventos/{$this->event->id}/actas/leer", ['archivo' => $this->upload($book)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.tipo', 'excel');
        $acta = $response->json('data.acta');

        $this->assertSame('REUNIÓN DE PRUEBA VEREDA EJEMPLO', $acta['titulo']);
        $this->assertSame('2026-11-08', $acta['fecha']);
        $this->assertSame(['10:45', '12:00'], [$acta['hora_inicio'], $acta['hora_fin']]);
        $this->assertSame(['Girón', 'Salón comunal', 'Necesidades de la vereda'], [$acta['municipio'], $acta['sitio'], $acta['introduccion']]);
        $this->assertSame(['Seguridad', 'Vías'], $acta['orden_dia']);
        $this->assertSame([
            ['titulo' => 'Seguridad', 'texto' => 'Se revisó la estación.'],
            ['titulo' => 'Vías', 'texto' => 'Se pidió arreglar la vía.'],
            ['titulo' => 'Conclusiones', 'texto' => 'Se acordó volver a reunirse.'],
        ], $acta['desarrollo']);
        $this->assertStringStartsWith('Siendo las 12:00', $acta['cierre']);

        $this->assertCount(1, $acta['participantes']);
        $this->assertSame(['nombre' => 'Persona Excel Uno', 'perfil' => 'PRESIDENTE JAC', 'municipio' => 'Lebrija', 'telefono' => '3112223344', 'cedula' => '1098765432'], $acta['participantes'][0]);

        $this->assertCount(2, $acta['compromisos']);
        $this->assertSame('2026-12-01', $acta['compromisos'][0]['fecha_limite']);
        $this->assertSame('2026-11-25', $acta['compromisos'][0]['recordar_el']);
        $this->assertSame([['nombre' => 'Responsable Uno', 'telefono' => '3205556677'], ['nombre' => 'Responsable Dos', 'telefono' => '3001112233']], $acta['compromisos'][0]['responsables']);
        // The invalid date does not lose the compromiso; the row without a name is reported.
        $this->assertNull($acta['compromisos'][1]['fecha_limite']);
        $this->assertContains('Compromisos, fila 3: la fecha límite «31/02/2026» no es válida (use dd/mm/aaaa); quedó vacía.', $acta['avisos']);
        $this->assertContains('Participantes, fila 3: falta el nombre; no se cargó.', $acta['avisos']);
        $this->assertSame(0, DB::table('event_actas')->count());

        // What was read is a valid body for the borrador, and the original Excel is kept as the acta's file.
        unset($acta['avisos']);
        $id = $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/actas", $acta)->assertCreated()->json('data.id');
        $this->assertSame(2, DB::table('event_compromisos')->count());
        $this->actingAs($this->manager)->post("/eventos/{$this->event->id}/actas/{$id}/archivo", ['archivo' => $this->upload($book)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.archivo.nombre', 'acta.xlsx');
        $this->actingAs($this->manager)->get("/eventos/{$this->event->id}/actas/{$id}/archivo")->assertOk();
    }

    public function test_an_organization_acta_is_read_from_an_old_xls(): void
    {
        $manager = $this->eventUser(Campaign::DEFAULT_ID, ['manage.empresarios', 'view.empresarios', 'view.votantes']);
        $org = (string) Str::uuid();
        DB::table('organizaciones')->insert([
            'id' => $org, 'campaign_id' => Campaign::DEFAULT_ID, 'tipo' => 'gremio', 'nombre' => 'Gremio de prueba', 'municipio_id' => $this->giron,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $book = $this->templateBook("/empresarios/{$org}/actas/plantilla.xlsx", $manager);
        $this->fill($book);

        $acta = $this->actingAs($manager)->post("/empresarios/{$org}/actas/leer", ['archivo' => $this->upload($book, 'xls')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.tipo', 'excel')->json('data.acta');
        $this->assertSame('2026-11-08', $acta['fecha']);
        $this->assertSame('Persona Excel Uno', $acta['participantes'][0]['nombre']);
        $this->assertSame('2026-12-01', $acta['compromisos'][0]['fecha_limite']);

        unset($acta['avisos']);
        $this->actingAs($manager)->postJson("/empresarios/{$org}/actas", $acta)->assertCreated();
        $this->assertDatabaseHas('event_actas', ['organizacion_id' => $org, 'estado' => 'borrador', 'titulo' => 'REUNIÓN DE PRUEBA VEREDA EJEMPLO']);

        // Without manage.empresarios: no template and no reading.
        $viewer = $this->eventUser(Campaign::DEFAULT_ID, ['view.empresarios']);
        $this->actingAs($viewer)->get("/empresarios/{$org}/actas/plantilla.xlsx")->assertRedirect('/');
        $this->actingAs($viewer)->post("/empresarios/{$org}/actas/leer", ['archivo' => $this->upload($book)], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_missing_sheets_are_avisos_and_a_book_without_any_is_refused(): void
    {
        $book = $this->book();
        $book->getActiveSheet()->setTitle('ACTA')->fromArray([['CAMPO', 'VALOR'], ['TITULO', 'Solo encabezado'], ['FECHA', '8/11/2026']]);
        $acta = $this->actingAs($this->manager)->post("/eventos/{$this->event->id}/actas/leer", ['archivo' => $this->upload($book)], ['Accept' => 'application/json'])
            ->assertOk()->json('data.acta');
        $this->assertSame(['Solo encabezado', '2026-11-08'], [$acta['titulo'], $acta['fecha']]);
        $this->assertSame([
            'No se encontró la hoja «Participantes»: llene esa parte a mano.',
            'No se encontró la hoja «Compromisos»: llene esa parte a mano.',
        ], $acta['avisos']);

        $other = $this->book();
        $other->getActiveSheet()->setTitle('Hoja1')->fromArray([['Nombre'], ['Alguien']]);
        $this->actingAs($this->manager)->post("/eventos/{$this->event->id}/actas/leer", ['archivo' => $this->upload($other)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('archivo');
    }

    public function test_an_invalid_date_in_the_acta_sheet_and_a_fake_excel(): void
    {
        $book = $this->book();
        $book->getActiveSheet()->setTitle('Acta')->fromArray([['Campo', 'Valor'], ['Título', 'Con fecha mala'], ['Fecha', 'el lunes']]);
        $acta = $this->actingAs($this->manager)->post("/eventos/{$this->event->id}/actas/leer", ['archivo' => $this->upload($book)], ['Accept' => 'application/json'])
            ->assertOk()->json('data.acta');
        $this->assertNull($acta['fecha']);
        $this->assertContains('Acta, fila 3: la fecha «el lunes» no es válida (use dd/mm/aaaa); escríbala a mano.', $acta['avisos']);

        // A zip that says .xlsx but is not a workbook, and a text file named .xls.
        $zip = tempnam(sys_get_temp_dir(), 'zip');
        $z = new \ZipArchive;
        $z->open($zip, \ZipArchive::OVERWRITE);
        $z->addFromString('otra/cosa.xml', '<a/>');
        $z->close();
        $this->actingAs($this->manager)->post("/eventos/{$this->event->id}/actas/leer", ['archivo' => new UploadedFile($zip, 'acta.xlsx', null, null, true)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('archivo');
        $txt = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($txt, 'no es un excel');
        $this->actingAs($this->manager)->post("/eventos/{$this->event->id}/actas/leer", ['archivo' => new UploadedFile($txt, 'acta.xls', null, null, true)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('archivo');
    }

    public function test_hours_as_whole_numbers_dates_out_of_range_and_the_template_formats(): void
    {
        $read = function (array $rows) {
            $book = $this->book();
            $book->getActiveSheet()->setTitle('Acta')->fromArray([['Campo', 'Valor'], ['Título', 'Horas'], ...$rows]);

            return $this->actingAs($this->manager)->post("/eventos/{$this->event->id}/actas/leer", ['archivo' => $this->upload($book)], ['Accept' => 'application/json'])
                ->assertOk()->json('data.acta');
        };

        $acta = $read([['Hora de inicio', 10], ['Hora de fin', 45000.5]]);
        $this->assertSame(['10:00', '12:00'], [$acta['hora_inicio'], $acta['hora_fin']]);

        // 30 is not an hour and a date without time in an hour cell is not 00:00: both are avisos.
        $acta = $read([['Hora de inicio', 30], ['Hora de fin', 46334]]);
        $this->assertSame([null, null], [$acta['hora_inicio'], $acta['hora_fin']]);
        $this->assertContains('Acta, fila 3: la hora «30» no es válida (use HH:MM); escríbala a mano.', $acta['avisos']);
        $this->assertContains('Acta, fila 4: la hora «46334» no es válida (use HH:MM); escríbala a mano.', $acta['avisos']);

        // An Excel date number outside 2000–2100 (1900 here) is an aviso, not a date.
        $acta = $read([['Fecha', 100]]);
        $this->assertNull($acta['fecha']);
        $this->assertContains('Acta, fila 3: la fecha «100» no es válida (use dd/mm/aaaa); escríbala a mano.', $acta['avisos']);

        $book = $this->templateBook("/eventos/{$this->event->id}/actas/plantilla.xlsx");
        $this->assertSame('dd/mm/yyyy', $book->getSheetByName('Acta')->getStyle('B3')->getNumberFormat()->getFormatCode());
        $this->assertSame('Fecha', $book->getSheetByName('Acta')->getCell('A3')->getValue());
        $this->assertSame('dd/mm/yyyy', $book->getSheetByName('Compromisos')->getStyle('D2')->getNumberFormat()->getFormatCode());
        $this->assertSame('dd/mm/yyyy', $book->getSheetByName('Compromisos')->getStyle('E2')->getNumberFormat()->getFormatCode());
    }

    public function test_permissions_and_isolation_of_the_event_template_and_reading(): void
    {
        $book = $this->book();
        $book->getActiveSheet()->setTitle('Acta')->fromArray([['Campo', 'Valor'], ['Título', 'X']]);

        $viewer = $this->eventUser(Campaign::DEFAULT_ID, ['view.eventos']);
        $this->actingAs($viewer)->get("/eventos/{$this->event->id}/actas/plantilla.xlsx")->assertRedirect('/');
        $this->actingAs($viewer)->post("/eventos/{$this->event->id}/actas/leer", ['archivo' => $this->upload($book)], ['Accept' => 'application/json'])->assertForbidden();

        $other = $this->eventUser($this->campY, ['manage.eventos', 'view.eventos']);
        $this->actingAs($other)->get("/eventos/{$this->event->id}/actas/plantilla.xlsx")->assertNotFound();
        $this->actingAs($other)->post("/eventos/{$this->event->id}/actas/leer", ['archivo' => $this->upload($book)], ['Accept' => 'application/json'])->assertNotFound();

        $outside = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos', 'view.eventos'], 'R02_ADMIN_FUNCIONAL', [$this->velez]);
        $this->actingAs($outside)->get("/eventos/{$this->event->id}/actas/plantilla.xlsx")->assertForbidden();
    }
}
