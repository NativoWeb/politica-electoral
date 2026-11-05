<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\User;
use App\Services\Eventos\AttendeeMatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// Lists of attendees without cédula or authorization (docs/eventos-api.md §11, "Listas sin autorización"):
// suggestion by phone + name confirmed by the operator, link by cédula as history only, the rest only in the event's
// list (never approvable), and workbooks with one sheet per jornada. Synthetic data only. SQLite.
class EventListImportTest extends TestCase
{
    use BuildsEventosScenario;

    private const LIST_HEADERS = ['MUNICIPIO', 'NOMBRE', 'LIDERAZGO', 'TELÉFONO'];

    private User $manager;

    private CampaignEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEventos();
        Storage::fake('local');
        $this->manager = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos', 'view.votantes']);
        // Only the provisional text: a list without authorization does not need the approved one.
        $this->event = $this->makeEvent(Campaign::DEFAULT_ID, ['status' => 'cerrado']);
    }

    // $sheets: [sheet name => [rows above the header..., header, data rows...]] written from A1.
    private function workbook(array $sheets, string $name = 'lista.xlsx'): UploadedFile
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        foreach ($sheets as $title => $rows) {
            $sheet = $book->createSheet()->setTitle($title);
            foreach ($rows as $r => $row) {
                foreach (array_values($row) as $c => $value) {
                    if ($value === null) {
                        continue;
                    }
                    $coordinate = chr(65 + $c).($r + 1);
                    is_string($value)
                        ? $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING)
                        : $sheet->setCellValue($coordinate, $value);
                }
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'lst').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function listFile(array $rows, string $name = 'lista.xlsx'): UploadedFile
    {
        return $this->workbook(['Hoja1' => [self::LIST_HEADERS, ...$rows]], $name);
    }

    private function base(): string
    {
        return "/eventos/{$this->event->id}/importar";
    }

    // Upload + automatic columns; returns the import id.
    private function load(UploadedFile $file): string
    {
        $id = $this->actingAs($this->manager)->postJson($this->base(), ['archivo' => $file])->assertCreated()->json('data.id');
        $mapping = json_decode(DB::table('event_imports')->where('id', $id)->value('column_mapping'), true);
        $this->actingAs($this->manager)->putJson("{$this->base()}/{$id}/columnas", ['columnas' => $mapping])->assertOk();

        return $id;
    }

    private function row(string $importId, int $rowNumber): object
    {
        return DB::table('event_import_rows')->where('import_id', $importId)->where('row_number', $rowNumber)->first();
    }

    private function confirm(string $importId, array $body = [])
    {
        return $this->actingAs($this->manager)->postJson("{$this->base()}/{$importId}/confirmar", $body);
    }

    public function test_phones_are_read_with_spaces_country_code_and_two_numbers(): void
    {
        $this->assertSame(['3001234567', '3107654321'], AttendeeMatcher::phones('+57 300 123 4567 / 310-765-4321'));
        $this->assertSame(['3001234567'], AttendeeMatcher::phones('573001234567'));
        $this->assertSame(['6076543210'], AttendeeMatcher::phones('(607) 654 3210'));
        $this->assertSame([], AttendeeMatcher::phones('sin teléfono'));
    }

    public function test_a_row_without_cedula_matching_phone_and_name_is_suggested_and_linked_only_after_confirming(): void
    {
        $ana = $this->lider(Campaign::DEFAULT_ID, 'Ana María Pérez Gómez', '63525141', ['telefono' => '300 123 4567', 'cargo' => 'Edil']);
        $before = DB::table('lideres')->where('id', $ana)->first();
        $id = $this->load($this->listFile([['GIRÓN', 'ANA PEREZ', 'Líder comunal', '+57 300 123 4567 / 310 765 4321']]));

        $row = $this->row($id, 2);
        $this->assertSame('sugerida', $row->status);
        $this->assertSame($ana, $row->suggested_lider_id);
        $this->assertNull($row->match_lider_id, 'never linked without the operator');
        $this->assertSame('omitir', $row->decision);
        $this->assertSame(0, json_decode(DB::table('event_imports')->where('id', $id)->value('counts'), true)['a_importar']);

        $this->actingAs($this->manager)->get("{$this->base()}/{$id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filas.data.0.sugerencia.nombre', 'Ana María Pérez Gómez')
            ->where('filas.data.0.sugerencia.cargo', 'Edil')
            ->where('filas.data.0.sugerencia.municipio', 'Girón')
            ->where('importacion.sin_autorizacion', true));

        $this->actingAs($this->manager)->patchJson("{$this->base()}/{$id}/filas/{$row->id}", ['sugerencia' => 'confirmar'])->assertOk()
            ->assertJsonPath('data.fila.status', 'valida')->assertJsonPath('data.fila.en_plataforma', true)
            ->assertJsonPath('data.counts.a_importar', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'EVENTO_IMPORTACION_SUGERENCIA')->count());
        $audit = DB::table('audit_logs')->where('action', 'EVENTO_IMPORTACION_SUGERENCIA')->value('metadata');
        $this->assertStringNotContainsString('300', $audit);
        $this->assertStringNotContainsString('PEREZ', strtoupper($audit));

        // No declaration and no approved text needed: nothing is registered with consent.
        $this->confirm($id)->assertOk()->assertJsonPath('data.nuevos', 1)->assertJsonPath('data.historial', 1);
        $attendee = DB::table('event_attendees')->first();
        $this->assertSame($ana, $attendee->lider_id);
        $this->assertSame('vinculado', $attendee->link_status);
        $this->assertSame(0, (int) $attendee->autoriza_datos);
        $this->assertNull($attendee->consent_id);
        $this->assertSame('Líder comunal', $attendee->nota);
        $this->assertSame(['3001234567', '3107654321'], [$attendee->celular, $attendee->fijo]);
        $this->assertSame(0, DB::table('data_consents')->count());
        $this->assertEquals($before, DB::table('lideres')->where('id', $ana)->first(), 'the ficha is never changed');
        $this->assertSame(1, DB::table('lideres')->count());

        // History of the ficha, and confirming again (or loading the same list as another file) does not duplicate.
        $this->actingAs($this->manager)->getJson("/eventos/persona/{$ana}")->assertOk()->assertJsonPath('data.asistio', 1);
        $this->confirm($id)->assertOk()->assertJsonPath('data.nuevos', 0);
        $id2 = $this->load($this->listFile([['GIRÓN', 'ANA PEREZ', 'Líder comunal', '3001234567']], 'otra.xlsx'));
        $row2 = $this->row($id2, 2);
        $this->actingAs($this->manager)->patchJson("{$this->base()}/{$id2}/filas/{$row2->id}", ['sugerencia' => 'confirmar'])->assertOk()
            ->assertJsonPath('data.fila.status', 'en_evento');
        $this->assertSame(1, DB::table('event_attendees')->count());

        // The event page shows the cargo of the linked ficha and that it is a registered lider.
        $this->actingAs($this->manager)->get("/eventos/{$this->event->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('asistentes.data.0.ficha.cargo', 'Edil')->where('asistentes.data.0.ficha.lider', true)
            ->where('asistentes.data.0.consentimiento_provisional', false));
        $noVotantes = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos']);
        $this->actingAs($noVotantes)->get("/eventos/{$this->event->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('asistentes.data.0.ficha', null));
    }

    public function test_rejecting_the_suggestion_leaves_the_row_only_in_the_event(): void
    {
        $this->lider(Campaign::DEFAULT_ID, 'Ana Pérez', null, ['telefono' => '3001234567']);
        $id = $this->load($this->listFile([['Girón', 'Ana Pérez', null, '3001234567']]));
        $row = $this->row($id, 2);
        $this->actingAs($this->manager)->patchJson("{$this->base()}/{$id}/filas/{$row->id}", ['sugerencia' => 'rechazar'])->assertOk()
            ->assertJsonPath('data.fila.status', 'sin_ficha');
        $this->confirm($id)->assertOk()->assertJsonPath('data.solo_evento', 1);
        $this->assertNull(DB::table('event_attendees')->value('lider_id'));
        $this->assertSame('sin_autorizacion', DB::table('event_attendees')->value('link_status'));
    }

    public function test_same_phone_with_another_name_or_outside_the_territory_is_not_suggested(): void
    {
        $this->lider(Campaign::DEFAULT_ID, 'Carlos Rueda', null, ['telefono' => '3001234567']);
        $this->lider(Campaign::DEFAULT_ID, 'Marta Gil', null, ['telefono' => '3009998877', 'geographic_unit_id' => $this->velez, 'municipio' => 'Vélez']);
        // Another campaign's lider with the same phone and name is never suggested.
        $this->lider($this->campY, 'Luis Mora', null, ['telefono' => '3115550000']);
        $giron = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos'], 'R02_ADMIN_FUNCIONAL', [$this->giron]);
        $this->manager = $giron;
        $id = $this->load($this->listFile([
            ['Girón', 'Ana Pérez', null, '3001234567'],
            ['Vélez', 'Marta Gil', null, '3009998877'],
            ['Girón', 'Luis Mora', null, '3115550000'],
        ]));
        foreach ([2, 3, 4] as $n) {
            $row = $this->row($id, $n);
            $this->assertSame('sin_ficha', $row->status, "row {$n}");
            $this->assertNull($row->suggested_lider_id);
        }
    }

    public function test_new_people_without_authorization_stay_in_the_event_and_cannot_be_approved(): void
    {
        $id = $this->load($this->listFile([['Girón', 'Pedro Pablo Díaz', 'Edil', '3201112233'], ['Girón', 'Rosa', null, null]]));
        $this->assertSame('sin_ficha', $this->row($id, 2)->status);
        $this->assertSame('sin_ficha', $this->row($id, 3)->status, 'one word is enough: only the name is required');

        $this->confirm($id)->assertOk()->assertJsonPath('data.solo_evento', 2)
            ->assertJsonPath('message', 'Se registraron 2 asistentes sin autorización de datos: 0 quedaron en el historial de su ficha y 2 quedaron solo en la lista del evento.');
        $attendees = DB::table('event_attendees')->get();
        $this->assertCount(2, $attendees);
        $this->assertTrue($attendees->every(fn ($a) => $a->lider_id === null && $a->link_status === 'sin_autorizacion' && ! $a->autoriza_datos && $a->consent_id === null));
        $this->assertSame(0, DB::table('data_consents')->count());

        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/asistentes/aprobar", ['ids' => $attendees->pluck('id')->all()])->assertOk()
            ->assertJsonPath('data.aprobados', 0);
        $this->assertSame(0, DB::table('lideres')->count(), 'no ficha in Votantes/Mapa without authorization');
        $this->actingAs($this->manager)->get("/eventos/{$this->event->id}?link_status=sin_autorizacion")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('asistentes.total', 2)->where('kpis.sin_autorizacion', 2));
    }

    public function test_a_row_with_an_existing_cedula_is_linked_directly(): void
    {
        $luis = $this->lider(Campaign::DEFAULT_ID, 'Luis Rojas', '63525141');
        $file = $this->workbook(['Hoja1' => [['NOMBRES Y APELLIDOS', 'CÉDULA', 'CELULAR'], ['Luis Rojas', 63525141, null]]]);
        $id = $this->load($file);
        $row = $this->row($id, 2);
        $this->assertSame('valida', $row->status);
        $this->assertSame($luis, $row->match_lider_id);
        $this->confirm($id)->assertOk()->assertJsonPath('data.historial', 1);
        $this->assertSame($luis, DB::table('event_attendees')->value('lider_id'));
        $this->assertSame(1, DB::table('lideres')->count());
    }

    public function test_a_workbook_with_one_sheet_per_jornada_loads_the_sheet_of_the_event_and_can_switch(): void
    {
        $header = [null, null, 'NOMBRES Y APELLIDOS', "NO. DOCUMENTO\nIDENTIDAD (CEDULA)", 'CELULAR /FIJO', "FECHA DE \nCUMPLEAÑOS", 'DIRECCIÓN', 'BARRIO', 'ACTIVIDAD'];
        $title = [null, 'NO.', 'JORNADA "DEL CONGRESO AL BARRIO" VIERNES 23.10.2026 PUNTO DE ENCUENTRO: PARQUE'];
        $file = $this->workbook([
            'LEBRIJA' => [[], $title, [], $header, [null, 1, 'Persona Lebrija', 1091000001, 3001000001, 29000, 'Calle 1', 'La Popa', 'SALUD PREVENTIVA']],
            'GIRÓN' => [[], [], $title, [], $header,
                [null, 1, 'Persona Uno', 1091000002, 3001000002, 29000, 'Calle 2', 'Villa Carolina II', 'BIENESTAR ANIMAL'],
                [null, 2, 'Persona Dos', null, 3001000003, 45000, 'Calle 3', 'Villa Carolina II', 'CPS']],
        ], 'jornadas.xlsx');

        $id = $this->actingAs($this->manager)->postJson($this->base(), ['archivo' => $file])->assertCreated()
            ->assertJsonPath('data.filas', 2)->json('data.id');
        $import = DB::table('event_imports')->where('id', $id)->first();
        $this->assertSame('GIRÓN', $import->sheet_name, 'the sheet named like the event municipio');
        $this->assertSame(5, (int) $import->header_row);
        $sheets = json_decode($import->sheets, true);
        $this->assertSame(['LEBRIJA', 'GIRÓN'], array_column($sheets, 'nombre'));
        $this->assertStringStartsWith('JORNADA "DEL CONGRESO AL BARRIO"', $sheets[1]['titulo']);
        $mapping = json_decode($import->column_mapping, true);
        $this->assertSame([2, 3, 4, 5, 6, 7, 8, null], [$mapping['nombre_completo'], $mapping['cedula'], $mapping['celular'],
            $mapping['fecha_nacimiento'], $mapping['direccion'], $mapping['barrio'], $mapping['liderazgo'], $mapping['autoriza']]);

        $this->actingAs($this->manager)->putJson("{$this->base()}/{$id}/columnas", ['columnas' => $mapping])->assertOk();
        $this->assertSame('sin_ficha', $this->row($id, 6)->status);
        $this->assertSame('sin_ficha', $this->row($id, 7)->status);
        $this->confirm($id)->assertOk()->assertJsonPath('data.solo_evento', 2);
        $uno = DB::table('event_attendees')->where('cedula', '1091000002')->first();
        $this->assertSame('1979-05-25', substr((string) $uno->fecha_nacimiento, 0, 10), 'Excel date 29000');
        $this->assertSame('BIENESTAR ANIMAL', $uno->nota);
        $this->assertNull(DB::table('event_attendees')->whereNull('cedula')->value('fecha_nacimiento'), 'a birthday of a minor is left out');

        // Another event takes the other sheet of the same file.
        $lebrija = $this->makeEvent(Campaign::DEFAULT_ID, ['nombre' => 'Jornada Lebrija', 'municipio_id' => $this->lebrija]);
        $other = $this->actingAs($this->manager)->postJson("/eventos/{$lebrija->id}/importar", ['archivo' => $file])->assertCreated()->json('data.id');
        $this->assertSame('LEBRIJA', DB::table('event_imports')->where('id', $other)->value('sheet_name'));
        $this->actingAs($this->manager)->putJson("/eventos/{$lebrija->id}/importar/{$other}/hoja", ['hoja' => 'GIRÓN'])->assertOk()
            ->assertJsonPath('data.filas', 2);
        $this->actingAs($this->manager)->putJson("/eventos/{$lebrija->id}/importar/{$other}/hoja", ['hoja' => 'NO EXISTE'])->assertUnprocessable()
            ->assertJsonValidationErrors('hoja');
        $this->actingAs($this->manager)->putJson("{$this->base()}/{$id}/hoja", ['hoja' => 'LEBRIJA'])->assertUnprocessable()
            ->assertJsonValidationErrors('importacion');
    }

    public function test_the_authorized_flow_is_unchanged_when_the_file_has_si(): void
    {
        $file = $this->workbook(['Asistentes' => [
            ['Nombres', 'Apellidos', 'Cédula', 'Celular', 'Barrio', 'Autorizó (SÍ/NO)'],
            ['Ana', 'Pérez', '1090000001', '3001234567', 'Centro', 'SÍ'],
            ['Beto', 'Gil', '1090000002', '3001234568', 'Centro', ''],
        ]]);
        $id = $this->load($file);
        $this->assertSame('valida', $this->row($id, 2)->status);
        $this->assertSame('no_autorizo', $this->row($id, 3)->status, 'with SÍ in some rows an empty cell is still "no autorizó"');
        $this->confirm($id, ['declaracion' => true])->assertUnprocessable()->assertJsonValidationErrors('consent');
        $this->approvedText(Campaign::DEFAULT_ID);
        $this->confirm($id)->assertUnprocessable()->assertJsonValidationErrors('declaracion');
        $this->confirm($id, ['declaracion' => true])->assertOk()->assertJsonPath('data.nuevos', 1);
        $this->assertSame('pendiente', DB::table('event_attendees')->value('link_status'));
        $this->assertSame(1, DB::table('data_consents')->count());
    }

    // A list attendee with cédula (no authorization, no ficha) of an event that is open for QR and operator.
    private function listAttendee(string $cedula = '1098765432'): object
    {
        $this->event->forceFill(['status' => 'abierto'])->save();
        $file = $this->workbook(['Hoja1' => [['NOMBRES Y APELLIDOS', 'CÉDULA', 'CELULAR'], ['Ana María Pérez Gómez', $cedula, '3001234567']]]);
        $this->confirm($this->load($file))->assertOk()->assertJsonPath('data.solo_evento', 1);
        $attendee = DB::table('event_attendees')->first();
        $this->assertSame('sin_autorizacion', $attendee->link_status);

        return $attendee;
    }

    private function assertAuthorized(object $before, string $status = 'pendiente'): void
    {
        $this->assertSame(1, DB::table('event_attendees')->count(), 'the same attendee is completed, not duplicated');
        $after = DB::table('event_attendees')->first();
        $this->assertSame($before->id, $after->id);
        $this->assertSame(1, (int) $after->autoriza_datos);
        $this->assertSame($status, $after->link_status);
        $this->assertNotNull($after->consent_id);
        $this->assertSame($after->consent_id, DB::table('data_consents')->where('attendee_id', $after->id)->value('id'));
    }

    public function test_a_list_attendee_who_then_authorizes_by_qr_gets_the_authorization(): void
    {
        $text = $this->approvedText(Campaign::DEFAULT_ID);
        $before = $this->listAttendee();
        $this->postJson("/e/{$this->event->public_token}", $this->publicPayload($this->event, $text->id))->assertOk();
        $this->assertAuthorized($before);
        $this->assertSame('autorizo', json_decode(DB::table('audit_logs')->where('action', 'EVENTO_ASISTENTE_QR')->value('metadata'), true)['resultado']);
    }

    public function test_a_list_attendee_who_then_authorizes_with_the_operator_gets_the_authorization_and_its_ficha(): void
    {
        $text = $this->approvedText(Campaign::DEFAULT_ID);
        $before = $this->listAttendee();
        $ana = $this->lider(Campaign::DEFAULT_ID, 'Ana María Pérez Gómez', '1098765432');
        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/asistentes", $this->attendeePayload($text->id))->assertSuccessful();
        $this->assertAuthorized($before, 'vinculado');
        $this->assertSame($ana, DB::table('event_attendees')->value('lider_id'));
    }

    public function test_a_list_attendee_who_then_comes_in_an_excel_with_si_gets_the_authorization(): void
    {
        $this->approvedText(Campaign::DEFAULT_ID);
        $before = $this->listAttendee();
        $id = $this->load($this->workbook(['Asistentes' => [
            ['Nombres', 'Apellidos', 'Cédula', 'Celular', 'Barrio', 'Autorizó (SÍ/NO)'],
            ['Ana María', 'Pérez Gómez', '1098765432', '3001234567', 'Centro', 'SÍ'],
        ]], 'firmadas.xlsx'));
        $this->assertSame('valida', $this->row($id, 2)->status, 'not "ya está en el evento": it brings the authorization');
        $this->confirm($id, ['declaracion' => true])->assertOk()->assertJsonPath('data.nuevos', 1);
        $this->assertAuthorized($before);
        $this->assertSame('Centro', DB::table('event_attendees')->value('barrio'), 'an empty field is filled');
    }

    public function test_without_view_permissions_nobody_is_suggested_and_a_tie_suggests_nobody(): void
    {
        $this->lider(Campaign::DEFAULT_ID, 'Ana Pérez', null, ['telefono' => '3001234567']);
        $this->manager = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos']);
        $id = $this->load($this->listFile([['Girón', 'Ana Pérez', null, '3001234567']]));
        $row = $this->row($id, 2);
        $this->assertSame('sin_ficha', $row->status);
        $this->assertNull($row->suggested_lider_id);

        $this->manager = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos', 'view.mapa']);
        $this->lider(Campaign::DEFAULT_ID, 'Ana Gómez', null, ['telefono' => '3001234567']);
        $id = $this->load($this->listFile([['Girón', 'Ana Ruiz', null, '3001234567']], 'empate.xlsx'));
        $this->assertSame('sin_ficha', $this->row($id, 2)->status, 'two fichas with one word in common: no guess');
    }

    public function test_confirming_a_suggestion_outside_the_territory_is_refused(): void
    {
        $ana = $this->lider(Campaign::DEFAULT_ID, 'Ana Pérez', null, ['telefono' => '3001234567']);
        $id = $this->load($this->listFile([['Girón', 'Ana Pérez', null, '3001234567']]));
        $row = $this->row($id, 2);
        $this->assertSame($ana, $row->suggested_lider_id);
        DB::table('lideres')->where('id', $ana)->update(['geographic_unit_id' => $this->velez]);
        $this->manager = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos', 'view.votantes'], 'R02_ADMIN_FUNCIONAL', [$this->giron]);
        $this->actingAs($this->manager)->patchJson("{$this->base()}/{$id}/filas/{$row->id}", ['sugerencia' => 'confirmar'])
            ->assertUnprocessable()->assertJsonValidationErrors('sugerencia');
        $this->assertNull($this->row($id, 2)->match_lider_id);
    }
}
