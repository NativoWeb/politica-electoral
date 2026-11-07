<?php

namespace Tests\Feature;

use App\Http\Requests\Eventos\ImportUploadRequest;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\User;
use App\Services\Audit\AuditDataMasker;
use App\Services\Eventos\EventImportService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// Eventos especiales phase 3: attendees from an Excel/CSV file (template, staging, column mapping, preview with
// corrections, confirm, isolation). Since 2026-11-07 every Excel file is a list: no «Autorizó» column, no declaration
// and no approved text; new people stay pendientes de aprobar. SQLite.
class EventImportTest extends TestCase
{
    use BuildsEventosScenario;

    private const HEADERS = ['Nombres', 'Apellidos', 'Cédula', 'Celular', 'Teléfono fijo', 'Fecha de nacimiento (DD/MM/AAAA)', 'Dirección', 'Barrio'];

    // A file made before 2026-11-07 with the old «Autorizó» column: the column is ignored.
    private const OLD_HEADERS = [...self::HEADERS, 'Autorizó (SÍ/NO)'];

    private const TOO_MANY = 'El archivo tiene más de 2000 filas. Divídalo en varios archivos y cárguelos uno por uno. '
        .'Si no son tantas personas, borre las filas que sobren al final de la hoja.';

    private User $manager;

    private CampaignEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEventos();
        Storage::fake('local');
        $this->manager = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos']);
        $this->event = $this->makeEvent(Campaign::DEFAULT_ID, ['status' => 'cerrado']);
    }

    private function xlsx(array $headers, array $rows, string $name = 'asistentes.xlsx', ?callable $tweak = null): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Asistentes');
        $sheet->fromArray($headers, null, 'A1');
        foreach ($rows as $i => $row) {
            foreach (array_values($row) as $c => $value) {
                if ($value === null) {
                    continue;
                }
                $coordinate = chr(65 + $c).($i + 2);
                is_string($value)
                    ? $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING)
                    : $sheet->setCellValue($coordinate, $value);
            }
        }
        if ($tweak) {
            $tweak($sheet);
        }
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, $name, null, null, true);
    }

    // The same bytes again (a regenerated xlsx would differ in its timestamps, so it would not be "the same file").
    private function copyOf(UploadedFile $file): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.'.$file->getClientOriginalExtension();
        copy($file->getPathname(), $path);

        return new UploadedFile($path, $file->getClientOriginalName(), null, null, true);
    }

    private function csv(string $content, string $name = 'asistentes.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function person(int $i, array $overrides = []): array
    {
        return array_merge([
            'nombres' => "Persona{$i}",
            'apellidos' => "Apellido{$i} Prueba",
            'cedula' => (string) (1090000000 + $i),
            'celular' => '300'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
            'fijo' => null,
            'fecha' => '02/04/1980',
            'direccion' => "Calle {$i}",
            'barrio' => 'El Poblado',
        ], $overrides);
    }

    // 50 rows: 32 new, 10 already in lideres, 5 with one wrong cell each, 3 repeating an earlier cédula.
    private function fiftyRows(): array
    {
        $rows = [];
        for ($i = 1; $i <= 42; $i++) {
            $rows[] = $this->person($i);
        }
        $rows[] = $this->person(43, ['cedula' => '12AB']);
        $rows[] = $this->person(44, ['celular' => '2001234567']);
        $rows[] = $this->person(45, ['nombres' => null]);
        $rows[] = $this->person(46, ['fecha' => '02/04/2015']);
        $rows[] = $this->person(47, ['fecha' => '31/02/1980']);
        $rows[] = $this->person(48, ['cedula' => '1090000001']);
        $rows[] = $this->person(49, ['cedula' => '1.090.000.002']);
        $rows[] = $this->person(50, ['cedula' => '1090000003']);

        return $rows;
    }

    private function upload(UploadedFile $file, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->manager)->postJson("/eventos/{$this->event->id}/importar", ['archivo' => $file]);
    }

    private function detected(string $importId): array
    {
        return json_decode(DB::table('event_imports')->where('id', $importId)->value('column_mapping'), true);
    }

    private function review(string $importId, ?array $mapping = null)
    {
        return $this->actingAs($this->manager)->putJson("/eventos/{$this->event->id}/importar/{$importId}/columnas", [
            'columnas' => $mapping ?? $this->detected($importId),
        ]);
    }

    private function rowOf(string $importId, int $rowNumber): object
    {
        return DB::table('event_import_rows')->where('import_id', $importId)->where('row_number', $rowNumber)->first();
    }

    private function seedLideres(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->lider(Campaign::DEFAULT_ID, "Líder {$i}", (string) (1090000000 + 32 + $i));
        }
    }

    public function test_the_template_has_the_fixed_headers_text_cells_and_no_authorization(): void
    {
        $response = $this->actingAs($this->manager)->get("/eventos/{$this->event->id}/plantilla.xlsx")->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('plantilla-asistentes-gira-giron.xlsx', $response->headers->get('Content-Disposition'));

        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $response->getContent());
        $book = IOFactory::load($path);
        $sheet = $book->getSheetByName('Asistentes');
        $this->assertSame([...self::HEADERS, 'Municipio', 'Liderazgo o actividad'], $sheet->rangeToArray('A1:J1')[0]);
        $this->assertNull($sheet->getCell('K1')->getValue());
        foreach (['C', 'D', 'E', 'F'] as $column) {
            $this->assertSame(NumberFormat::FORMAT_TEXT, $sheet->getStyle("{$column}2")->getNumberFormat()->getFormatCode(), $column);
            $this->assertSame(NumberFormat::FORMAT_TEXT, $sheet->getStyle("{$column}2001")->getNumberFormat()->getFormatCode(), $column);
        }
        $this->assertSame([], $sheet->getDataValidationCollection());

        $instructions = implode("\n", array_map(fn ($r) => (string) $r[0], $book->getSheetByName('Instrucciones')->toArray()));
        $this->assertStringContainsString('pendiente de aprobar', $instructions);
        foreach (['Autoriz', 'autoriz', 'Ley 1581', 'PROVISIONAL', 'firmad'] as $gone) {
            $this->assertStringNotContainsString($gone, $instructions);
        }
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'EVENTO_PLANTILLA_EXCEL_DESCARGADA')->count());
    }

    public function test_fifty_rows_preview_confirm_and_repeat(): void
    {
        $this->seedLideres(10);
        $file = $this->xlsx(self::HEADERS, $this->fiftyRows());
        $same = $this->copyOf($file);

        $id = $this->upload($file)->assertCreated()->assertJsonPath('data.filas', 50)->json('data.id');
        $this->assertSame(['nombres' => 0, 'apellidos' => 1, 'nombre_completo' => null, 'cedula' => 2, 'celular' => 3, 'fijo' => 4,
            'fecha_nacimiento' => 5, 'direccion' => 6, 'barrio' => 7, 'municipio' => null, 'liderazgo' => null], $this->detected($id));

        // A list: only the name is required; a wrong phone or birth date does not stop the row (the date is left out).
        $counts = $this->review($id)->assertOk()->json('data.counts');
        $this->assertSame(50, $counts['total']);
        $this->assertSame(10, $counts['validas']);
        $this->assertSame(10, $counts['en_plataforma']);
        $this->assertSame(35, $counts['sin_ficha']);
        $this->assertSame(2, $counts['errores']);
        $this->assertSame(3, $counts['duplicadas']);
        $this->assertSame(0, $counts['en_evento']);
        $this->assertSame(45, $counts['a_importar']);

        // Each invalid row points at its cell (data starts on row 2: person 43 is row 44).
        foreach ([44 => 'cedula', 46 => 'nombres'] as $rowNumber => $field) {
            $row = $this->rowOf($id, $rowNumber);
            $this->assertSame('error', $row->status);
            $this->assertSame('omitir', $row->decision);
            $this->assertSame([$field], array_keys(json_decode($row->errors, true)), "fila {$rowNumber}");
        }
        foreach ([45, 47, 48] as $rowNumber) {
            $this->assertSame('sin_ficha', $this->rowOf($id, $rowNumber)->status, "fila {$rowNumber}");
        }
        $this->assertSame('duplicada', $this->rowOf($id, 50)->status, '1.090.000.002 is the same cédula as row 3');
        $this->assertSame('vincular', $this->rowOf($id, 34)->decision);
        $this->assertSame('crear', $this->rowOf($id, 2)->decision);

        $this->actingAs($this->manager)->get("/eventos/{$this->event->id}/importar/{$id}?estado=error")
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Eventos/Importar')
                ->missing('consent')
                ->where('importacion.counts.a_importar', 45)
                ->where('filas.total', 2)
                ->where('filas.data.0.fila', 44)
                ->where('filas.data.0.errores.cedula', 'La cédula debe tener solo números (entre 5 y 10 dígitos).'));

        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/importar/{$id}/confirmar")
            ->assertOk()->assertJsonPath('data.nuevos', 45)->assertJsonPath('data.ya_estaban', 0)
            ->assertJsonPath('data.historial', 10)->assertJsonPath('data.solo_evento', 35);

        $this->assertSame(45, DB::table('event_attendees')->where('event_id', $this->event->id)->count());
        $this->assertSame(45, DB::table('event_attendees')->where('source', 'excel')->count());
        $this->assertSame(10, DB::table('event_attendees')->where('link_status', 'vinculado')->count());
        $this->assertSame(35, DB::table('event_attendees')->where('link_status', 'pendiente')->count());
        $this->assertSame(0, DB::table('lideres')->where('tipo', 'asistente_evento')->count(), 'Nothing goes to Votantes without approval');
        $first = DB::table('event_attendees')->where('cedula', '1090000001')->first();
        $this->assertSame(EventImportService::clientUuid($id, 2), $first->client_uuid);
        $this->assertSame($this->manager->id, $first->registered_by);
        $this->assertSame('1980-04-02', substr((string) $first->fecha_nacimiento, 0, 10));

        // The same file again: not imported twice.
        $this->upload($same)->assertOk()
            ->assertJsonPath('success', false)->assertJsonPath('data.repetido', true)->assertJsonPath('data.id', $id)
            ->assertJsonPath('message', 'Este archivo ya fue cargado en este evento. No se volvió a importar.');
        $this->assertSame(1, DB::table('event_imports')->count());

        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/importar/{$id}/confirmar")
            ->assertOk()->assertJsonPath('data.nuevos', 0);
        $this->assertSame(45, DB::table('event_attendees')->count());
        $this->assertSame(0, DB::table('data_consents')->count());

        // A confirmed import cannot be changed or discarded.
        $this->actingAs($this->manager)->putJson("/eventos/{$this->event->id}/importar/{$id}/columnas", ['columnas' => $this->detected($id)])
            ->assertUnprocessable()->assertJsonValidationErrors('importacion');
        $this->actingAs($this->manager)->deleteJson("/eventos/{$this->event->id}/importar/{$id}")->assertUnprocessable();
    }

    public function test_rows_already_in_the_event_are_not_imported_again(): void
    {
        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/asistentes", $this->attendeePayload(null, ['cedula' => '1090000001']))
            ->assertStatus(410);
        $this->event->forceFill(['status' => 'abierto'])->save();
        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/asistentes", $this->attendeePayload(null, ['cedula' => '1090000001']))
            ->assertCreated();

        $id = $this->upload($this->xlsx(self::HEADERS, [$this->person(1), $this->person(2)]))->json('data.id');
        $counts = $this->review($id)->json('data.counts');
        $this->assertSame(1, $counts['en_evento']);
        $this->assertSame(1, $counts['sin_ficha']);

        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/importar/{$id}/confirmar")
            ->assertJsonPath('data.nuevos', 1);
        $this->assertSame(2, DB::table('event_attendees')->count());
    }

    public function test_someone_registered_between_the_preview_and_the_confirmation_is_not_duplicated(): void
    {
        $id = $this->upload($this->xlsx(self::HEADERS, [$this->person(1), $this->person(2), $this->person(3)]))->json('data.id');
        $this->review($id)->assertJsonPath('data.counts.a_importar', 3);

        $this->event->forceFill(['status' => 'abierto'])->save();
        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/asistentes",
            $this->attendeePayload(null, ['cedula' => '1090000002']))->assertCreated();

        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/importar/{$id}/confirmar")
            ->assertOk()->assertJsonPath('data.nuevos', 2)->assertJsonPath('data.ya_estaban', 1);
        $this->assertSame(3, DB::table('event_attendees')->count());
        $this->assertSame('operador', DB::table('event_attendees')->where('cedula', '1090000002')->value('source'));
        $this->assertSame(3, DB::table('event_import_rows')->where('import_id', $id)->whereNotNull('attendee_id')->count());
    }

    public function test_other_headers_are_mapped_automatically_or_by_hand_and_remembered_for_the_campaign(): void
    {
        $rows = [['Ana María Pérez Gómez', '1.098.765.432', '300 123 4567'], ['Luis Rojas', '63525141', '3109876543']];
        $id = $this->upload($this->xlsx(['CC', 'Nombre completo', 'Tel', 'Autorizó'], array_map(fn ($r) => [$r[1], $r[0], $r[2], 'SI'], $rows)))->json('data.id');
        $mapping = $this->detected($id);
        $this->assertSame(0, $mapping['cedula']);
        $this->assertSame(1, $mapping['nombre_completo']);
        $this->assertSame(2, $mapping['celular']);
        $this->assertNull($mapping['nombres']);
        $this->assertArrayNotHasKey('autoriza', $mapping, 'An old «Autorizó» column is left unmapped');

        $this->review($id)->assertOk()->assertJsonPath('data.counts.a_importar', 2);
        $mapped = json_decode($this->rowOf($id, 2)->mapped, true);
        $this->assertSame(['Ana María', 'Pérez Gómez'], [$mapped['nombres'], $mapped['apellidos']]);
        $this->assertSame(['Luis', 'Rojas'], array_values(array_intersect_key(json_decode($this->rowOf($id, 3)->mapped, true), array_flip(['nombres', 'apellidos']))));

        // Headers nobody could guess: mapped by hand, then reused by the next file of the campaign.
        $odd = ['Doc ID', 'Persona', 'Contacto WA', 'Sector', 'Visto bueno'];
        $id2 = $this->upload($this->xlsx($odd, [['1098000001', 'Pedro Pablo Díaz Ruiz', '3201112233', 'Centro', 'SI']], 'otro.xlsx'))->json('data.id');
        $this->assertNull($this->detected($id2)['cedula']);
        $this->review($id2, ['cedula' => 0])->assertUnprocessable()->assertJsonValidationErrors('columnas');
        // Only the name and the cédula or the phone are needed; an «autoriza» sent by an old screen is ignored.
        $this->review($id2, ['nombre_completo' => 1])->assertUnprocessable()
            ->assertJsonPath('errors.columnas.0', 'Elija la columna de la cédula o la del teléfono: sin ninguna no se puede saber si la persona ya está en la plataforma.');
        $this->review($id2, ['cedula' => 0, 'nombre_completo' => 0, 'celular' => 2])->assertUnprocessable()
            ->assertJsonPath('errors.columnas.0', 'Una misma columna está elegida para dos datos distintos. Revise las columnas.');
        $this->review($id2, ['cedula' => 9, 'nombre_completo' => 1, 'celular' => 2])->assertUnprocessable();
        $this->review($id2, ['cedula' => 0, 'nombre_completo' => 1, 'celular' => 2, 'barrio' => 3, 'autoriza' => 4])->assertOk()
            ->assertJsonPath('data.counts.a_importar', 1);

        $other = $this->makeEvent(Campaign::DEFAULT_ID, ['nombre' => 'Otra gira']);
        $id3 = $this->actingAs($this->manager)->postJson("/eventos/{$other->id}/importar", [
            'archivo' => $this->xlsx($odd, [['1098000002', 'Marta Gil', '3201112234', 'Norte', 'SI']], 'tercero.xlsx'),
        ])->assertCreated()->json('data.id');
        $saved = json_decode(DB::table('event_imports')->where('id', $id3)->value('column_mapping'), true);
        $this->assertSame(0, $saved['cedula']);
        $this->assertSame(1, $saved['nombre_completo']);
        $this->assertSame(2, $saved['celular']);
        $this->assertSame(3, $saved['barrio']);
        $this->assertArrayNotHasKey('autoriza', $saved);
    }

    public function test_cedulas_typed_as_numbers_or_with_dots_are_normalized(): void
    {
        $file = $this->xlsx(['Nombres', 'Apellidos', 'Cédula', 'Celular', 'Autorizó'], [
            ['Ana', 'Uno', 1098765432, 3001234567, 'SI'],
            ['Bea', 'Dos', 1098765433.0, '3001234568', 'SI'],
        ]);
        $id = $this->upload($file)->json('data.id');
        $this->review($id)->assertJsonPath('data.counts.a_importar', 2);
        $this->assertSame('1098765432', json_decode($this->rowOf($id, 2)->mapped, true)['cedula']);
        $this->assertSame('3001234567', json_decode($this->rowOf($id, 2)->mapped, true)['celular']);
        $this->assertSame('1098765433', json_decode($this->rowOf($id, 3)->mapped, true)['cedula']);

        $csv = "Nombres;Apellidos;Cédula;Celular;Autorizó\r\nCarla;Tres;1.098765432E9;3001234569;SI\r\nDora;Cuatro;1098765431.0;3001234570;SI\r\n"
            ."Eva;Cinco;1.098.765.430;300-123-4571;SI\r\nFabio;Seis;63.525.141;+57 300 123 4572;SI\r\n";
        $id = $this->upload($this->csv(mb_convert_encoding($csv, 'Windows-1252', 'UTF-8')))->assertCreated()->json('data.id');
        $this->assertSame(2, $this->detected($id)['cedula'], 'Windows-1252 "Cédula" is read');
        $this->review($id)->assertJsonPath('data.counts.a_importar', 4)->assertJsonPath('data.counts.errores', 0);
        $cedulas = DB::table('event_import_rows')->where('import_id', $id)->orderBy('row_number')->pluck('mapped')
            ->map(fn ($m) => json_decode($m, true)['cedula'])->all();
        $this->assertSame(['1098765432', '1098765431', '1098765430', '63525141'], $cedulas);
    }

    public function test_rounded_scientific_notation_is_an_error_of_the_row(): void
    {
        $csv = "Nombres;Apellidos;Cédula;Celular;Teléfono fijo;Autorizó\r\n"
            ."Ana;Uno;1,09877E+09;3001234567;;SI\r\n"
            ."Bea;Dos;1098765433;3,00123E+09;;SI\r\n"
            ."Eva;Tres;1098765434;3001234568;6,07654E+09;SI\r\n"
            ."Ivo;Cuatro;1.098765435E9;3.001234569E9;;SI\r\n";
        $id = $this->upload($this->csv($csv))->assertCreated()->json('data.id');
        // A list checks the cédula and the phone it uses to find people; the landline is not checked.
        $this->review($id)->assertJsonPath('data.counts.errores', 2)->assertJsonPath('data.counts.a_importar', 2);

        $this->assertSame(['cedula' => 'La cédula llegó en notación científica (1,09877E+09); escríbala completa.'], json_decode($this->rowOf($id, 2)->errors, true));
        $this->assertSame(['celular' => 'El celular llegó en notación científica (3,00123E+09); escríbalo completo.'], json_decode($this->rowOf($id, 3)->errors, true));
        $this->assertNull($this->rowOf($id, 4)->errors);
        $exact = json_decode($this->rowOf($id, 5)->mapped, true);
        $this->assertSame(['1098765435', '3001234569'], [$exact['cedula'], $exact['celular']]);
    }

    // Decision of the campaign (2026-11-07): what «Autorizó» says no longer matters, confirming needs no declaration
    // and no approved text, and the new people stay pendientes de aprobar (approvable, without data authorization).
    public function test_every_valid_row_is_imported_whatever_autorizo_says_and_without_a_declaration(): void
    {
        DB::table('consent_texts')->update(['is_active' => false]);
        $rows = [
            [...$this->person(1), 'SÍ'], [...$this->person(2), 'NO'], [...$this->person(3), null],
            [...$this->person(4), 'quizás'], [...$this->person(5, ['cedula' => '12AB']), 'x'],
            [...$this->person(6), 'no'], [...$this->person(7, ['cedula' => '1090000006']), 'si'],
        ];
        $id = $this->upload($this->xlsx(self::OLD_HEADERS, $rows))->json('data.id');
        $counts = $this->review($id)->json('data.counts');
        $this->assertSame(0, $counts['no_autorizaron']);
        $this->assertSame(1, $counts['errores']);
        $this->assertSame(1, $counts['duplicadas']);
        $this->assertSame(5, $counts['a_importar']);
        $this->assertSame(0, DB::table('event_import_rows')->where('status', 'no_autorizo')->count());
        $this->assertArrayNotHasKey('autoriza', json_decode($this->rowOf($id, 3)->mapped, true));

        $this->actingAs($this->manager)->get("/eventos/{$this->event->id}/importar/{$id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->missing('consent')->missing('importacion.declaro_planillas'));

        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/importar/{$id}/confirmar")->assertOk()
            ->assertJsonPath('data.nuevos', 5)->assertJsonPath('data.ya_estaban', 0)
            ->assertJsonPath('message', 'Se registraron 5 asistentes: 5 quedaron pendientes de aprobar para pasar a Votantes.');
        $attendees = DB::table('event_attendees')->get();
        $this->assertSame(['1090000001', '1090000002', '1090000003', '1090000004', '1090000006'], $attendees->pluck('cedula')->sort()->values()->all());
        $this->assertTrue($attendees->every(fn ($a) => (int) $a->autoriza_datos === 0 && $a->consent_id === null && $a->import_id === $id
            && $a->source === 'excel' && $a->link_status === 'pendiente'));
        $this->assertSame(0, DB::table('data_consents')->count());

        $imported = DB::table('event_import_rows')->where('import_id', $id)->whereNotNull('attendee_id')->get();
        $this->assertCount(5, $imported);
        $this->assertTrue($imported->every(fn ($r) => (int) $r->declared === 0 && $r->confirmed_by === $this->manager->id && $r->confirmed_at !== null));

        // A second confirmation by someone else does not rewrite who confirmed the import.
        $first = DB::table('event_imports')->where('id', $id)->first(['confirmed_by', 'confirmed_at', 'consent_declared', 'consent_text_id']);
        $this->assertEquals([0, null], [(int) $first->consent_declared, $first->consent_text_id]);
        $other = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos']);
        $this->travel(5)->minutes();
        $this->actingAs($other)->postJson("/eventos/{$this->event->id}/importar/{$id}/confirmar", ['declaracion' => true])->assertOk()->assertJsonPath('data.nuevos', 0)
            ->assertJsonPath('message', 'No había filas nuevas para registrar: este archivo ya estaba importado.');
        $this->assertEquals($first, DB::table('event_imports')->where('id', $id)->first(['confirmed_by', 'confirmed_at', 'consent_declared', 'consent_text_id']));

        // In the event page they are Excel attendees pending approval; approving creates their fichas without authorization.
        $this->actingAs($this->manager)->get("/eventos/{$this->event->id}?source=excel")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('kpis.excel', 5)->where('kpis.pendientes', 5)->where('asistentes.total', 5)->missing('kpis.sin_autorizacion'));
        $ids = DB::table('event_attendees')->pluck('id')->all();
        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/asistentes/aprobar", ['ids' => $ids])
            ->assertOk()->assertJsonPath('data.aprobados', 5);
        $this->assertSame(5, DB::table('lideres')->where('tipo', 'asistente_evento')->where('autoriza_datos', false)->count());
    }

    public function test_messages_use_singular_and_plural(): void
    {
        $this->upload($this->xlsx(self::HEADERS, [$this->person(1)]))->assertCreated()
            ->assertJsonPath('message', 'Archivo recibido: 1 fila. Revise las columnas.');
        $id = $this->upload($this->xlsx(self::HEADERS, [$this->person(2), $this->person(3)], 'dos.xlsx'))
            ->assertJsonPath('message', 'Archivo recibido: 2 filas. Revise las columnas.')->json('data.id');
        $this->review($id);
        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/importar/{$id}/confirmar")
            ->assertJsonPath('message', 'Se registraron 2 asistentes: 2 quedaron pendientes de aprobar para pasar a Votantes.');

        $this->lider(Campaign::DEFAULT_ID, 'Líder Uno', '1090000004');
        $id = $this->upload($this->xlsx(self::HEADERS, [$this->person(4), $this->person(5)], 'tres.xlsx'))->json('data.id');
        $this->review($id);
        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/importar/{$id}/confirmar")
            ->assertJsonPath('message', 'Se registraron 2 asistentes: 1 quedó en el historial de su ficha; 1 quedó pendiente de aprobar para pasar a Votantes.');
    }

    public function test_too_large_body_answers_in_spanish(): void
    {
        $server = ['CONTENT_LENGTH' => (string) (1024 * 1024 * 1024), 'CONTENT_TYPE' => 'multipart/form-data; boundary=x'];
        $this->actingAs($this->manager)->call('POST', "/eventos/{$this->event->id}/importar", [], [], [], $server + ['HTTP_ACCEPT' => 'application/json'])
            ->assertStatus(413)->assertJsonPath('success', false)
            ->assertJsonPath('errors.archivo.0', 'El archivo es demasiado grande para subirlo. Divídalo en varios archivos más pequeños e intente de nuevo.');
        $this->actingAs($this->manager)->call('POST', "/eventos/{$this->event->id}/importar", [], [], [], $server)
            ->assertStatus(413)->assertSee('Archivo demasiado grande')->assertSee('Divídalo en varios archivos más pequeños', false);
    }

    public function test_the_column_mapping_is_audited_without_masking_the_indexes(): void
    {
        $id = $this->upload($this->xlsx(self::HEADERS, [$this->person(1)]))->json('data.id');
        $this->review($id)->assertOk();
        $log = DB::table('audit_logs')->where('action', 'PUT eventos.importar.columnas')->first();
        $columnas = json_decode($log->metadata, true)['columnas'];
        $this->assertSame(2, $columnas['cedula']);
        $this->assertSame(3, $columnas['celular']);
        $this->assertSame(7, $columnas['barrio']);
        $this->assertArrayNotHasKey('cedula_last4', $columnas);

        // Elsewhere the same keys are still masked.
        $masked = app(AuditDataMasker::class)->mask(['cedula' => '1098765432', 'barrio' => 'Centro']);
        $this->assertSame(['cedula_last4' => '5432', 'barrio' => '[omitido]'], $masked);

        // "columnas" is only kept when it holds indexes: personal data under it is masked.
        $masker = app(AuditDataMasker::class);
        $this->assertSame(['columnas' => ['cedula' => 2, 'fijo' => null]], $masker->mask(['columnas' => ['cedula' => 2, 'fijo' => null]]));
        $this->assertSame(['columnas' => ['cedula_last4' => '5432', 'barrio' => '[omitido]', 'nombres' => 'Ana']],
            $masker->mask(['columnas' => ['cedula' => '1098765432', 'barrio' => 'Centro', 'nombres' => 'Ana']]));
        $this->assertSame(['columnas' => ['cedula_last4' => '5432', 'celular_last4' => '3']], $masker->mask(['columnas' => ['cedula' => '1098765432', 'celular' => 3]]));
    }

    public function test_a_cell_corrected_in_the_preview_is_validated_again(): void
    {
        $rows = [$this->person(1), $this->person(2, ['cedula' => '12AB']), $this->person(3)];
        $id = $this->upload($this->xlsx(self::HEADERS, $rows))->json('data.id');
        $this->review($id)->assertJsonPath('data.counts.errores', 1);
        $bad = $this->rowOf($id, 3);

        $this->actingAs($this->manager)->patchJson("/eventos/{$this->event->id}/importar/{$id}/filas/{$bad->id}", ['decision' => 'importar'])
            ->assertUnprocessable()->assertJsonValidationErrors('decision');

        $this->actingAs($this->manager)->patchJson("/eventos/{$this->event->id}/importar/{$id}/filas/{$bad->id}", ['cedula' => '1.090.000.001'])
            ->assertOk()->assertJsonPath('data.fila.status', 'duplicada')->assertJsonPath('data.counts.duplicadas', 1);

        $this->actingAs($this->manager)->patchJson("/eventos/{$this->event->id}/importar/{$id}/filas/{$bad->id}", ['cedula' => '1098000999', 'nombres' => ''])
            ->assertOk()->assertJsonPath('data.fila.status', 'error')->assertJsonPath('data.fila.errores.nombres', 'Escriba el nombre de la persona.');

        $this->actingAs($this->manager)->patchJson("/eventos/{$this->event->id}/importar/{$id}/filas/{$bad->id}", ['nombres' => 'Persona Dos'])
            ->assertOk()->assertJsonPath('data.fila.status', 'sin_ficha')->assertJsonPath('data.fila.decision', 'crear')
            ->assertJsonPath('data.fila.corregida', true)->assertJsonPath('data.counts.a_importar', 3);

        $good = $this->rowOf($id, 2);
        $this->actingAs($this->manager)->patchJson("/eventos/{$this->event->id}/importar/{$id}/filas/{$good->id}", ['decision' => 'omitir'])
            ->assertOk()->assertJsonPath('data.counts.a_importar', 2);

        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/importar/{$id}/confirmar")
            ->assertJsonPath('data.nuevos', 2);
        $this->assertSame(['1090000003', '1098000999'], DB::table('event_attendees')->orderBy('cedula')->pluck('cedula')->all());

        // The audit trail has the route names and which fields changed, never the values.
        $logs = DB::table('audit_logs')->get();
        $this->assertTrue($logs->contains('action', 'PATCH eventos.importar.fila'));
        $this->assertTrue($logs->contains('action', 'EVENTO_IMPORTACION_CONFIRMADA'));
        $all = $logs->pluck('metadata')->implode(' ');
        foreach (['1098000999', '1090000001', 'Persona Dos', 'Persona2', 'Apellido'] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
        $this->assertTrue($logs->where('action', 'PATCH eventos.importar.fila')
            ->contains(fn ($log) => str_contains($log->metadata, '"campos":["cedula","nombres"]')));
    }

    public function test_bad_files_are_rejected_with_a_clear_message(): void
    {
        $this->upload(UploadedFile::fake()->create('foto.pdf', 10, 'application/pdf'))->assertUnprocessable()
            ->assertJsonPath('errors.archivo.0', 'El archivo debe ser de Excel (.xlsx o .xls) o .csv.');
        $this->upload(UploadedFile::fake()->create('grande.xlsx', 5200))->assertUnprocessable()
            ->assertJsonPath('errors.archivo.0', 'El archivo pesa más de '.ImportUploadRequest::maxMb().' MB. Divídalo en varios archivos.');
        $this->upload($this->csv('esto no es excel', 'roto.xlsx'))->assertUnprocessable()
            ->assertJsonPath('errors.archivo.0', 'No se pudo leer el archivo. Ábralo en Excel, guárdelo como .xlsx e intente de nuevo.');
        $this->upload($this->xlsx(self::HEADERS, []))->assertUnprocessable()
            ->assertJsonPath('errors.archivo.0', 'El archivo no tiene filas con datos debajo de los encabezados.');

        $lines = "Nombres,Apellidos,Cédula,Celular\n";
        for ($i = 1; $i <= 2001; $i++) {
            $lines .= "N{$i},A{$i},".(1000000000 + $i).",3000000000\n";
        }
        $this->upload($this->csv($lines))->assertUnprocessable()
            ->assertJsonPath('errors.archivo.0', self::TOO_MANY);

        $this->event->forceFill(['status' => 'cancelado'])->save();
        $this->upload($this->xlsx(self::HEADERS, [$this->person(1)]))->assertUnprocessable()->assertJsonValidationErrors('evento');
        $this->assertSame(0, DB::table('event_imports')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_data_below_the_row_limit_is_found_even_after_empty_rows(): void
    {
        $xlsx = $this->xlsx(self::HEADERS, [$this->person(1), $this->person(2)], 'hueco.xlsx', function ($sheet) {
            $sheet->setCellValueExplicit('C2600', '1090009999', DataType::TYPE_STRING);
        });
        $this->upload($xlsx)->assertUnprocessable()->assertJsonPath('errors.archivo.0', self::TOO_MANY);

        $csv = "Nombres;Apellidos;Cédula;Celular;Autorizó\n".'Ana;Uno;1090000001;3001234567;SI'."\n".str_repeat(";;;;\n", 2100)
            .'Bea;Dos;1090000002;3001234568;SI'."\n";
        $this->upload($this->csv($csv))->assertUnprocessable()->assertJsonPath('errors.archivo.0', self::TOO_MANY);

        // Empty lines at the end of a CSV are fine.
        $this->upload($this->csv("Nombres;Apellidos;Cédula;Celular;Autorizó\nAna;Uno;1090000001;3001234567;SI\n".str_repeat(";;;;\n", 3000), 'final.csv'))
            ->assertCreated();
    }

    public function test_an_inflated_xlsx_is_refused_before_reading_it(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bomb').'.xlsx';
        copy($this->xlsx(self::HEADERS, [$this->person(1)])->getPathname(), $path);
        $zip = new \ZipArchive;
        $zip->open($path);
        $zip->addFromString('xl/media/relleno.bin', str_repeat("\0", 3 * 1024 * 1024));
        $zip->close();
        $this->assertLessThan(200 * 1024, filesize($path), 'small on disk');

        $this->upload(new UploadedFile($path, 'inflado.xlsx', null, null, true))->assertUnprocessable()
            ->assertJsonPath('errors.archivo.0', 'El archivo es demasiado grande por dentro para leerlo. Copie solo las filas de los asistentes en un libro nuevo de Excel, guárdelo como .xlsx e intente de nuevo.');
        $this->assertSame(0, DB::table('event_imports')->count());

        // The real template (2000 formatted rows) is not mistaken for one.
        $template = $this->actingAs($this->manager)->get("/eventos/{$this->event->id}/plantilla.xlsx")->getContent();
        $tpl = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($tpl, $template);
        $this->upload(new UploadedFile($tpl, 'plantilla.xlsx', null, null, true))->assertUnprocessable()
            ->assertJsonPath('errors.archivo.0', 'El archivo no tiene filas con datos debajo de los encabezados.');
    }

    public function test_cleanup_deletes_old_unconfirmed_imports_and_empties_old_confirmed_ones(): void
    {
        $old = $this->upload($this->xlsx(self::HEADERS, [$this->person(1)], 'viejo.xlsx'))->json('data.id');
        $recent = $this->upload($this->xlsx(self::HEADERS, [$this->person(2)], 'reciente.xlsx'))->json('data.id');
        $done = $this->upload($this->xlsx(self::HEADERS, [$this->person(3)], 'confirmado.xlsx'))->json('data.id');
        $this->review($done);
        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/importar/{$done}/confirmar")->assertOk();
        $oldPath = DB::table('event_imports')->where('id', $old)->value('file_path');
        $donePath = DB::table('event_imports')->where('id', $done)->value('file_path');
        DB::table('event_imports')->where('id', $old)->update(['created_at' => now()->subDays(8)]);
        DB::table('event_imports')->where('id', $done)->update(['created_at' => now()->subDays(40), 'confirmed_at' => now()->subDays(31)]);

        $this->artisan('eventos:limpiar-importaciones')
            ->expectsOutput('Cargas sin confirmar borradas: 1. Cargas confirmadas vaciadas: 1.')->assertSuccessful();

        $this->assertFalse(DB::table('event_imports')->where('id', $old)->exists());
        Storage::disk('local')->assertMissing($oldPath);
        $this->assertTrue(DB::table('event_imports')->where('id', $recent)->exists());

        $row = DB::table('event_import_rows')->where('import_id', $done)->first();
        $this->assertNull($row->raw);
        $this->assertNull($row->mapped);
        $this->assertSame('sin_ficha', $row->status);
        $this->assertNotNull($row->attendee_id);
        $this->assertSame(0, (int) $row->declared);
        $import = DB::table('event_imports')->where('id', $done)->first();
        $this->assertNotNull($import->staging_purged_at);
        $this->assertSame(64, strlen($import->file_sha256));
        Storage::disk('local')->assertExists($donePath);
        $attendee = DB::table('event_attendees')->where('id', $row->attendee_id)->first();
        $this->assertSame($done, $attendee->import_id, 'traceability does not depend on the staging');

        $this->actingAs($this->manager)->get("/eventos/{$this->event->id}/importar/{$done}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('importacion.datos_borrados', true)->where('filas.data.0.valores', []));

        $this->artisan('eventos:limpiar-importaciones')->expectsOutput('Cargas sin confirmar borradas: 0. Cargas confirmadas vaciadas: 0.');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'EVENTO_IMPORTACIONES_LIMPIEZA')->count());
        $this->assertTrue(collect(app(Schedule::class)->events())
            ->contains(fn ($e) => str_contains($e->command, 'eventos:limpiar-importaciones')));
    }

    public function test_cleanup_removes_every_expired_import_even_beyond_one_page(): void
    {
        EventImportService::$purgeChunk = 3;
        $now = now();
        foreach (range(1, 10) as $i) {
            $status = $i <= 7 ? 'subido' : 'confirmado';
            $id = (string) Str::uuid();
            DB::table('event_imports')->insert([
                'id' => $id, 'event_id' => $this->event->id, 'campaign_id' => Campaign::DEFAULT_ID, 'original_name' => "c{$i}.xlsx",
                'file_path' => "eventos/importaciones/{$this->event->id}/{$id}.xlsx", 'file_sha256' => hash('sha256', "carga-{$i}"),
                'header_row' => 1, 'headers' => '[]', 'status' => $status, 'total_rows' => 1, 'consent_declared' => $status === 'confirmado',
                'confirmed_at' => $status === 'confirmado' ? $now->copy()->subDays(31) : null,
                'created_at' => $now->copy()->subDays(40), 'updated_at' => $now,
            ]);
            DB::table('event_import_rows')->insert(['id' => (string) Str::uuid(), 'import_id' => $id, 'row_number' => 2, 'raw' => '{"0":"x"}',
                'mapped' => '{"nombres":"x"}', 'status' => 'valida', 'decision' => 'crear', 'edited' => false, 'declared' => false]);
            Storage::disk('local')->put("eventos/importaciones/{$this->event->id}/{$id}.xlsx", 'x');
        }

        try {
            $this->artisan('eventos:limpiar-importaciones')
                ->expectsOutput('Cargas sin confirmar borradas: 7. Cargas confirmadas vaciadas: 3.')->assertSuccessful();
        } finally {
            EventImportService::$purgeChunk = 200;
        }

        $this->assertSame(0, DB::table('event_imports')->where('status', 'subido')->count());
        $this->assertSame(3, DB::table('event_imports')->whereNotNull('staging_purged_at')->count());
        $this->assertSame(0, DB::table('event_import_rows')->whereNotNull('raw')->count());
        $this->assertCount(3, Storage::disk('local')->allFiles());
    }

    public function test_row_edits_and_discards_are_throttled_and_memory_is_never_lowered(): void
    {
        $routes = app('router')->getRoutes();
        foreach (['eventos.importar.fila', 'eventos.importar.descartar'] as $name) {
            $this->assertContains('throttle:eventos-importar-fila', $routes->getByName($name)->gatherMiddleware(), $name);
        }
        foreach (['eventos.importar.subir', 'eventos.importar.columnas', 'eventos.importar.confirmar'] as $name) {
            $this->assertContains('throttle:eventos-importar', $routes->getByName($name)->gatherMiddleware(), $name);
        }

        $before = ini_get('memory_limit');
        ini_set('memory_limit', '1024M');
        $this->actingAs($this->manager)->get("/eventos/{$this->event->id}/plantilla.xlsx")->assertOk();
        $this->assertSame('1024M', ini_get('memory_limit'));
        ini_set('memory_limit', $before);
    }

    public function test_an_unconfirmed_import_can_be_discarded_and_uploaded_again(): void
    {
        $file = $this->xlsx(self::HEADERS, [$this->person(1)]);
        [$again, $third] = [$this->copyOf($file), $this->copyOf($file)];
        $id = $this->upload($file)->json('data.id');
        $path = DB::table('event_imports')->where('id', $id)->value('file_path');
        Storage::disk('local')->assertExists($path);
        $this->assertStringStartsWith("eventos/importaciones/{$this->event->id}/", $path);

        $this->upload($again)->assertOk()
            ->assertJsonPath('message', 'Este archivo ya estaba subido: puede seguir donde lo dejó.');

        $this->actingAs($this->manager)->deleteJson("/eventos/{$this->event->id}/importar/{$id}")->assertOk();
        Storage::disk('local')->assertMissing($path);
        $this->assertSame(0, DB::table('event_import_rows')->count());
        $this->upload($third)->assertCreated();
    }

    public function test_isolation_permissions_and_private_file(): void
    {
        $id = $this->upload($this->xlsx(self::HEADERS, [$this->person(1)]))->json('data.id');
        $base = "/eventos/{$this->event->id}";
        $row = DB::table('event_import_rows')->where('import_id', $id)->value('id');
        $calls = [
            ['getJson', "{$base}/plantilla.xlsx", []],
            ['getJson', "{$base}/importar", []],
            ['getJson', "{$base}/importar/{$id}", []],
            ['postJson', "{$base}/importar", ['archivo' => $this->xlsx(self::HEADERS, [$this->person(2)], 'x.xlsx')]],
            ['putJson', "{$base}/importar/{$id}/columnas", ['columnas' => ['cedula' => 2, 'nombres' => 0, 'apellidos' => 1, 'celular' => 3]]],
            ['patchJson', "{$base}/importar/{$id}/filas/{$row}", ['decision' => 'omitir']],
            ['postJson', "{$base}/importar/{$id}/confirmar", []],
            ['deleteJson', "{$base}/importar/{$id}", []],
        ];

        // Another, independent campaign: 404. A subcampaign looking at its parent: also not visible → 404.
        foreach ([$this->campY, $this->campX] as $campaign) {
            $user = $this->eventUser($campaign, ['manage.eventos']);
            foreach ($calls as [$method, $uri, $body]) {
                $this->actingAs($user)->{$method}($uri, $body)->assertNotFound();
            }
        }

        // The parent campaign sees a subcampaign's event read-only: 403.
        $subEvent = $this->makeEvent($this->campX);
        $this->actingAs($this->manager)->getJson("/eventos/{$subEvent->id}/importar")->assertForbidden();
        $this->actingAs($this->manager)->get("/eventos/{$subEvent->id}/plantilla.xlsx")->assertForbidden();

        // Outside the territory: 403. Without manage.eventos: 403 (JSON) / redirect (page).
        $outside = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos'], 'R02_ADMIN_FUNCIONAL', [$this->lebrija]);
        foreach ([$outside, $this->eventUser(Campaign::DEFAULT_ID, ['view.eventos', 'register.asistentes', 'write.data'])] as $user) {
            foreach ($calls as [$method, $uri, $body]) {
                $this->actingAs($user)->{$method}($uri, $body)->assertForbidden();
            }
        }
        $this->actingAs($this->eventUser(Campaign::DEFAULT_ID, ['view.eventos']))->get("{$base}/importar")->assertRedirect('/');

        // Unknown or foreign ids.
        $this->actingAs($this->manager)->getJson("{$base}/importar/".Str::uuid())->assertNotFound();
        $other = $this->makeEvent(Campaign::DEFAULT_ID);
        $this->actingAs($this->manager)->getJson("/eventos/{$other->id}/importar/{$id}")->assertNotFound();
        $this->actingAs($this->manager)->get("{$base}/importar/no-es-uuid")->assertNotFound();

        // The file lives on the private disk and is not served by URL.
        $path = DB::table('event_imports')->where('id', $id)->value('file_path');
        Storage::disk('local')->assertExists($path);
        $this->assertFalse(Storage::disk('public')->exists($path));
        foreach (["/storage/{$path}", "/{$path}"] as $url) {
            $status = $this->actingAs($this->manager)->get($url)->getStatusCode();
            $this->assertContains($status, [403, 404], $url);
        }
        $this->assertSame(1, DB::table('event_imports')->count());
        $this->assertSame(0, DB::table('event_attendees')->count());
    }

    public function test_two_thousand_rows_fit_in_time_and_memory(): void
    {
        $rows = [];
        for ($i = 1; $i <= 2000; $i++) {
            $rows[] = $this->person($i, ['celular' => '3'.str_pad((string) $i, 9, '0', STR_PAD_LEFT)]);
        }
        $file = $this->xlsx(self::HEADERS, $rows, 'grande.xlsx');
        memory_reset_peak_usage();
        $start = microtime(true);

        $id = $this->upload($file)->assertCreated()->json('data.id');
        $this->review($id)->assertOk()->assertJsonPath('data.counts.a_importar', 2000);
        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/importar/{$id}/confirmar")
            ->assertOk()->assertJsonPath('data.nuevos', 2000);

        $seconds = microtime(true) - $start;
        $peakMb = memory_get_peak_usage(true) / 1048576;
        fwrite(STDERR, sprintf("\n[2000 filas SQLite] subir+revisar+confirmar: %.1f s, pico de memoria %.0f MB\n", $seconds, $peakMb));
        $this->assertSame(2000, DB::table('event_attendees')->count());
        $this->assertLessThan(200, $peakMb);
        $this->assertLessThan(90, $seconds);
    }
}
