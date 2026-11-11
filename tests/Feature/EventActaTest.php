<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\User;
use App\Services\Eventos\Actas\ActaDocxReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;
use ZipArchive;

// "Acta de reunión" of an event (docs/eventos-api.md §14): write, download (Word/PDF), read a .docx, participants
// into Votantes and attendees, responsables, isolation and permissions. SQLite.
class EventActaTest extends TestCase
{
    use BuildsEventosScenario;

    private User $manager;

    private CampaignEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEventos();
        (require database_path('migrations/2026_11_10_000001_create_event_actas_tables.php'))->up();
        (require database_path('migrations/2026_11_11_000001_add_created_flags_to_event_acta_participantes.php'))->up();
        Storage::fake('local');
        $this->manager = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos', 'view.eventos', 'view.votantes']);
        $this->event = $this->makeEvent(Campaign::DEFAULT_ID, ['nombre' => 'Del Congreso al campo']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'titulo' => 'ACTA REUNIÓN VEREDA BERLÍN, MUNICIPIO GIRÓN',
            'fecha' => '2026-11-08',
            'municipio' => 'GIRÓN',
            'sitio' => 'Casa comunal',
            'hora_inicio' => '10:45',
            'hora_fin' => '12:00',
            'introduccion' => 'Propuestas de gestión:',
            'orden_dia' => ['Seguridad', 'Vías'],
            'desarrollo' => [['titulo' => 'Seguridad', 'texto' => "• Revisar la estación\n• Cámaras"], ['titulo' => 'Vías', 'texto' => 'Mejorar vías']],
            'participantes' => [
                ['nombre' => 'Persona Nueva Uno', 'perfil' => 'PRESIDENTE JAC VEREDA', 'municipio' => 'Lebrija', 'telefono' => '311 222 3344'],
            ],
            'compromisos' => [
                ['descripcion' => 'Gestionar cámaras', 'fecha_limite' => '2026-12-01', 'responsables' => [['nombre' => 'Responsable Texto', 'telefono' => '3205556677']]],
            ],
            'cierre' => 'Siendo las 12:00 m., se dio por terminada la reunión.',
        ], $overrides);
    }

    private function store(array $payload, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->manager)->postJson("/eventos/{$this->event->id}/actas", $payload);
    }

    private function confirm(string $actaId)
    {
        return $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/actas/{$actaId}/confirmar");
    }

    // A .docx like the campaign's actas: auto-numbered section titles, a numbered order of the day with an
    // introduction, "N. Título" points with bulleted details, and tables with empty paragraphs inside the cells.
    private function syntheticDocx(): string
    {
        $w = fn (string $text, bool $num = false) => '<w:p>'.($num ? '<w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr>' : '')
            .($text !== '' ? '<w:r><w:t xml:space="preserve">'.htmlspecialchars($text, ENT_XML1).'</w:t></w:r>' : '').'</w:p>';
        $cell = fn (array $lines) => '<w:tc>'.implode('', array_map(fn ($l) => $w($l), $lines ?: [''])).'</w:tc>';
        $table = fn (array $rows) => '<w:tbl>'.implode('', array_map(fn ($r) => '<w:tr>'.implode('', array_map($cell, $r)).'</w:tr>', $rows)).'</w:tbl>';

        $body = $w('ACTA REUNIÓN VEREDA BERLÍN, MUNICIPIO RIONEGRO').$w('08 DE NOVIEMBRE 2026').$w('').$w('Municipio: RIONEGRO')
            .$w('Sitio:  Casa del presidente JAC, Vereda Berlín').$w('Hora: 10.45 A.M.').$w('')
            .$w('PARTICIPANTES', true)
            .$table([
                [['NOMBRE'], ['PERFIL'], ['MUNICIPIO'], ['TELEFONO']],
                [['DR JUAN PRUEBA PEREZ'], ['SENADOR DE LA REPUBLICA'], [''], ['3001112233']],
                [['MARIA EJEMPLO'], ['LIDER VEREDA BERLIN'], ['RIONEGRO'], ['310 444 5566']],
                [['PEDRO SIN TELEFONO'], ['LIDER'], ['MATANZA'], ['']],
                [[''], [''], [''], ['']],
            ])
            .$w('PUNTOS DEL ORDEN DEL DÍA', true).$w('').$w('Propuestas de gestión municipios Rionegro y Matanza:')
            .$w('Seguridad y presencia institucional', true).$w('Infraestructura vial', true).$w('Saneamiento básico', true)
            .$w('DESARROLLO DE LA REUNIÓN', true).$w('Propuestas de gestión para Rionegro y Matanza:')
            .$w('1. Seguridad y presencia institucional').$w('Revisar la estación de policía.', true).$w('Fortalecer frentes de seguridad.', true)
            .$w('2. Infraestructura vial').$w('Mejorar vías terciarias.', true)
            .$w('3. Saneamiento básico').$w('Pozos sépticos.', true)
            .$w('COMPROMISOS', true).$w('')
            .$table([
                [['NOMBRE'], ['RESPONSABLE'], ['TELEFONO']],
                [['Acompañamiento a los frentes de seguridad', ''], ['', '', 'GIOVANY PRUEBA'], ['', '', '3009998877']],
                [['Incluir proyectos de vías'], ['', 'MIGUEL PRUEBA', '', 'CARLOS EJEMPLO'], ['', '', '3101112222', '', '', '3152223333']],
                [['Reunión con la Unidad para las Víctimas'], ['', '', 'ALEJANDRA', 'NAVAS', ''], ['', '', '', '', '3184445555']],
            ])
            .$w('').$w('Siendo las 12:00 m, se dio por teminada la reunión.');

        $path = tempnam(sys_get_temp_dir(), 'docx').'.docx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>');
        $zip->close();

        return $path;
    }

    private function upload(string $path, string $name = 'acta.docx'): UploadedFile
    {
        return new UploadedFile($path, $name, null, null, true);
    }

    private function png(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'png').'.png';
        $img = imagecreatetruecolor(4, 4);
        imagepng($img, $path);

        return $path;
    }

    public function test_reading_a_docx_prefills_the_form_by_its_sections(): void
    {
        $response = $this->actingAs($this->manager)->post("/eventos/{$this->event->id}/actas/leer", ['archivo' => $this->upload($this->syntheticDocx())], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.tipo', 'docx');
        $acta = $response->json('data.acta');

        $this->assertSame('ACTA REUNIÓN VEREDA BERLÍN, MUNICIPIO RIONEGRO', $acta['titulo']);
        $this->assertSame('2026-11-08', $acta['fecha']);
        $this->assertSame('RIONEGRO', $acta['municipio']);
        $this->assertSame('Casa del presidente JAC, Vereda Berlín', $acta['sitio']);
        $this->assertSame(['10:45', '12:00'], [$acta['hora_inicio'], $acta['hora_fin']]);
        $this->assertCount(3, $acta['participantes'], 'The empty row is skipped');
        $this->assertSame(['nombre' => 'DR JUAN PRUEBA PEREZ', 'perfil' => 'SENADOR DE LA REPUBLICA', 'municipio' => null, 'telefono' => '3001112233', 'cedula' => null], $acta['participantes'][0]);
        $this->assertNull($acta['participantes'][2]['telefono']);
        $this->assertSame('Propuestas de gestión municipios Rionegro y Matanza:', $acta['introduccion']);
        $this->assertSame(['Seguridad y presencia institucional', 'Infraestructura vial', 'Saneamiento básico'], $acta['orden_dia']);
        $this->assertCount(4, $acta['desarrollo']);
        $this->assertSame('', $acta['desarrollo'][0]['titulo']);
        $this->assertSame(['titulo' => 'Seguridad y presencia institucional', 'texto' => "• Revisar la estación de policía.\n• Fortalecer frentes de seguridad."], $acta['desarrollo'][1]);
        $this->assertCount(3, $acta['compromisos']);
        $this->assertSame([['nombre' => 'GIOVANY PRUEBA', 'telefono' => '3009998877']], $acta['compromisos'][0]['responsables']);
        $this->assertSame([['nombre' => 'MIGUEL PRUEBA', 'telefono' => '3101112222'], ['nombre' => 'CARLOS EJEMPLO', 'telefono' => '3152223333']], $acta['compromisos'][1]['responsables']);
        $this->assertSame([['nombre' => 'ALEJANDRA NAVAS', 'telefono' => '3184445555']], $acta['compromisos'][2]['responsables'], 'A name over two lines with one phone is one person');
        $this->assertSame('Siendo las 12:00 m, se dio por teminada la reunión.', $acta['cierre']);
        $this->assertSame([], $acta['avisos']);
        $this->assertSame(0, DB::table('event_actas')->count(), 'Reading saves nothing');
    }

    public function test_a_pdf_or_a_photo_is_only_an_attachment_and_other_files_are_refused_by_content(): void
    {
        $pdf = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($pdf, "%PDF-1.4\n%fake\n");
        $this->actingAs($this->manager)->post("/eventos/{$this->event->id}/actas/leer", ['archivo' => $this->upload($pdf, 'acta.pdf')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.tipo', 'adjunto')->assertJsonPath('data.acta', null);
        $this->actingAs($this->manager)->post("/eventos/{$this->event->id}/actas/leer", ['archivo' => $this->upload($this->png(), 'foto.png')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.tipo', 'adjunto');

        $fake = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($fake, 'esto no es un word');
        $this->actingAs($this->manager)->post("/eventos/{$this->event->id}/actas/leer", ['archivo' => $this->upload($fake, 'acta.docx')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('archivo');

        $big = UploadedFile::fake()->create('grande.pdf', 10241);
        $this->actingAs($this->manager)->post("/eventos/{$this->event->id}/actas/leer", ['archivo' => $big], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('archivo');
    }

    public function test_the_original_file_is_kept_privately_and_downloaded_only_with_access(): void
    {
        $actaId = $this->store($this->payload())->assertCreated()->json('data.id');
        $this->actingAs($this->manager)->post("/eventos/{$this->event->id}/actas/{$actaId}/archivo", ['archivo' => $this->upload($this->syntheticDocx(), 'ACTA RIONEGRO.docx')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.archivo.nombre', 'ACTA RIONEGRO.docx');
        $path = DB::table('event_actas')->value('archivo_path');
        $this->assertStringStartsWith("eventos/actas/{$this->event->id}/", $path);
        Storage::disk('local')->assertExists($path);

        $this->actingAs($this->manager)->get("/eventos/{$this->event->id}/actas/{$actaId}/archivo")->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $other = $this->eventUser($this->campY, ['view.eventos', 'manage.eventos']);
        $this->actingAs($other)->get("/eventos/{$this->event->id}/actas/{$actaId}/archivo")->assertNotFound();
        auth()->logout();
        $this->get("/eventos/{$this->event->id}/actas/{$actaId}/archivo")->assertRedirect();
    }

    public function test_the_acta_downloads_in_word_and_pdf_with_its_content(): void
    {
        $actaId = $this->store($this->payload())->assertCreated()->json('data.id');

        $word = $this->actingAs($this->manager)->get("/eventos/{$this->event->id}/actas/{$actaId}/acta.docx")->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $file = $word->baseResponse->getFile()->getPathname();
        $read = (new ActaDocxReader)->read($file);
        $this->assertSame('ACTA REUNIÓN VEREDA BERLÍN, MUNICIPIO GIRÓN', $read['titulo']);
        $this->assertSame('2026-11-08', $read['fecha']);
        $this->assertSame(['10:45', '12:00'], [$read['hora_inicio'], $read['hora_fin']]);
        $this->assertSame(['Seguridad', 'Vías'], $read['orden_dia']);
        $this->assertSame('Persona Nueva Uno', $read['participantes'][0]['nombre']);
        $this->assertSame('Gestionar cámaras', $read['compromisos'][0]['descripcion']);
        $this->assertSame('2026-12-01', $read['compromisos'][0]['fecha_limite']);
        $this->assertSame([['nombre' => 'Responsable Texto', 'telefono' => '3205556677']], $read['compromisos'][0]['responsables']);

        $pdf = $this->actingAs($this->manager)->get("/eventos/{$this->event->id}/actas/{$actaId}/acta.pdf")->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'EVENTO_ACTA_DESCARGADA')->count());
    }

    public function test_participants_are_linked_or_created_and_all_become_attendees_once(): void
    {
        $byCedula = $this->lider(Campaign::DEFAULT_ID, 'Carlos Cedula Ruiz', '91000111', ['telefono' => '3150000000']);
        $byPhone = $this->lider(Campaign::DEFAULT_ID, 'Maria Ejemplo Gomez', null, ['telefono' => '3104445566']);
        $this->lider($this->campY, 'Maria Ejemplo Gomez', null, ['telefono' => '3104445566']);
        $alreadyAttendee = $this->makeAttendee(['cedula' => '91000111', 'lider_id' => null, 'link_status' => 'pendiente']);
        $picked = $this->makeAttendee(['nombres' => 'Asistente', 'apellidos' => 'Elegido', 'cedula' => '55667788', 'celular' => '3001231234']);

        $participantes = [
            ['nombre' => 'CARLOS RUIZ', 'perfil' => 'LIDER', 'cedula' => '91.000.111'],
            ['nombre' => 'MARIA EJEMPLO', 'perfil' => 'Lider vereda', 'telefono' => '310 444 5566'],
            ['nombre' => 'DR JUAN NUEVO', 'perfil' => 'SENADOR DE LA REPUBLICA', 'municipio' => 'Lebrija', 'telefono' => '300 111 2233 / 301 999 8877'],
            ['nombre' => 'Asistente Elegido', 'attendee_id' => $picked],
        ];

        $suggest = $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/actas/sugerencias", ['participantes' => $participantes])->assertOk();
        $this->assertSame(['id' => $byCedula, 'por' => 'cedula'], array_intersect_key($suggest->json('data.participantes.0'), ['id' => 1, 'por' => 1]));
        $this->assertSame($byPhone, $suggest->json('data.participantes.1.id'), 'Only the own campaign');
        $this->assertSame('telefono', $suggest->json('data.participantes.1.por'));
        $this->assertNull($suggest->json('data.participantes.2'));

        $participantes[1]['lider_id'] = $byPhone;
        $actaId = $this->store($this->payload(['participantes' => $participantes]))->assertCreated()
            ->assertJsonPath('data.estado', 'borrador')->json('data.id');
        $this->assertSame(3, DB::table('lideres')->count(), 'A borrador creates no ficha');
        $this->assertSame(2, DB::table('event_attendees')->count(), 'A borrador adds no attendee');
        $this->assertNull(DB::table('event_attendees')->where('id', $alreadyAttendee)->value('lider_id'));

        $this->confirm($actaId)->assertOk()->assertJsonPath('data.estado', 'confirmada')
            ->assertJsonPath('resumen.vinculados', 2)->assertJsonPath('resumen.fichas_creadas', 2)->assertJsonPath('resumen.asistentes_nuevos', 2);

        $new = DB::table('lideres')->where('nombre', 'DR JUAN NUEVO')->first();
        $this->assertSame(Campaign::DEFAULT_ID, $new->campaign_id);
        $this->assertSame('Senador de la Republica', $new->cargo);
        $this->assertSame('directorio', $new->tipo);
        $this->assertSame($this->lebrija, $new->geographic_unit_id, 'Municipio of the participant');
        $this->assertSame('3001112233', $new->telefono);
        $this->assertSame('3019998877', DB::table('lider_contactos')->where('lider_id', $new->id)->value('valor'));
        $this->assertSame('Líder', DB::table('lideres')->where('nombre', 'Asistente Elegido')->value('cargo'));

        $this->assertSame($byCedula, DB::table('event_attendees')->where('id', $alreadyAttendee)->value('lider_id'), 'The attendee with that cédula is linked, not repeated');
        $this->assertSame(1, DB::table('event_attendees')->where('event_id', $this->event->id)->where('cedula', '91000111')->count());
        $this->assertSame('aprobado', DB::table('event_attendees')->where('id', $picked)->value('link_status'));
        $acta = DB::table('event_attendees')->where('event_id', $this->event->id)->where('source', 'acta')->pluck('lider_id')->all();
        $this->assertEqualsCanonicalizing([$byPhone, $new->id], $acta);
        $this->assertSame(4, DB::table('event_attendees')->where('event_id', $this->event->id)->count());
        $this->assertSame(4, DB::table('event_acta_participantes')->whereNotNull('lider_id')->whereNotNull('attendee_id')->count());
        $this->assertSame(4, DB::table('lideres')->where('campaign_id', Campaign::DEFAULT_ID)->count());

        // Confirming again does nothing: the acta is read-only.
        $this->confirm($actaId)->assertUnprocessable()->assertJsonValidationErrors('acta');
        $this->assertSame(4, DB::table('lideres')->where('campaign_id', Campaign::DEFAULT_ID)->count());
        $this->assertSame(4, DB::table('event_attendees')->where('event_id', $this->event->id)->count());

        $audit = DB::table('audit_logs')->where('action', 'EVENTO_ACTA_CONFIRMADA')->value('metadata');
        $this->assertStringNotContainsString('JUAN', $audit, 'No personal data in the audit');
    }

    public function test_a_confirmed_ficha_must_belong_to_the_events_campaign_and_territory(): void
    {
        $foreign = $this->lider($this->campY, 'Ajeno', null);
        $outside = $this->lider(Campaign::DEFAULT_ID, 'Fuera Territorio', null, ['geographic_unit_id' => $this->velez]);
        $territorial = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos'], 'R02_ADMIN_FUNCIONAL', [$this->giron]);

        $this->store($this->payload(['participantes' => [['nombre' => 'X', 'lider_id' => $foreign]]]))->assertUnprocessable()
            ->assertJsonValidationErrors('participantes.0.lider_id');
        $this->store($this->payload(['participantes' => [['nombre' => 'X', 'lider_id' => $outside]]]), $territorial)->assertUnprocessable();
        $this->store($this->payload(['compromisos' => [['descripcion' => 'C', 'responsables' => [['nombre' => 'R', 'lider_id' => $foreign]]]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('compromisos.0.responsables.0.lider_id');
        $this->assertSame(0, DB::table('event_actas')->count(), 'Nothing is saved on an error');

        // A choice that stopped being valid between saving and confirming blocks the confirmation.
        $chosen = $this->lider(Campaign::DEFAULT_ID, 'Elegido Luego Inactivo', null);
        $actaId = $this->store($this->payload(['participantes' => [['nombre' => 'Elegido', 'lider_id' => $chosen]]]))->assertCreated()->json('data.id');
        DB::table('lideres')->where('id', $chosen)->update(['is_active' => false]);
        $this->confirm($actaId)->assertUnprocessable()->assertJsonValidationErrors('participantes.0.lider_id');
        $this->assertSame('borrador', DB::table('event_actas')->value('estado'));
        $this->assertSame(0, DB::table('event_attendees')->count(), 'Nothing is written when the confirmation fails');
    }

    public function test_responsables_are_linked_when_confirmed_or_kept_as_text_and_show_in_the_ficha(): void
    {
        $sandra = $this->lider(Campaign::DEFAULT_ID, 'Sandra Pachon', null, ['telefono' => '3177778888']);
        $suggest = $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/actas/sugerencias", [
            'responsables' => [['nombre' => 'SANDRA PACHÓN M.', 'telefono' => '317 777 8888'], ['nombre' => 'Otro', 'telefono' => '3001234567']],
        ])->assertOk();
        $this->assertSame($sandra, $suggest->json('data.responsables.0.id'));
        $this->assertNull($suggest->json('data.responsables.1'));

        $actaId = $this->store($this->payload(['participantes' => [], 'compromisos' => [
            ['descripcion' => 'Gestionar reunión', 'responsables' => [['nombre' => 'SANDRA PACHÓN M.', 'telefono' => '317 777 8888', 'lider_id' => $sandra], ['nombre' => 'Otro', 'telefono' => '3001234567']]],
        ]]))->assertCreated()->json('data.id');
        $this->actingAs($this->manager)->getJson("/eventos/persona/{$sandra}/compromisos")->assertOk()->assertJsonCount(0, 'data');

        $this->confirm($actaId)->assertOk()->assertJsonPath('resumen.responsables_vinculados', 1);
        $this->assertSame(1, DB::table('lideres')->count(), 'A responsable never creates a ficha');
        $this->assertSame([$sandra, null], DB::table('event_compromiso_responsables')->orderBy('orden')->pluck('lider_id')->all());

        $this->actingAs($this->manager)->getJson("/eventos/persona/{$sandra}/compromisos")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.descripcion', 'Gestionar reunión');
        $viewer = $this->eventUser(Campaign::DEFAULT_ID, ['view.votantes']);
        $this->actingAs($viewer)->getJson("/eventos/persona/{$sandra}/compromisos")->assertForbidden();
    }

    public function test_a_borrador_is_edited_freely_and_keeps_pending_choices(): void
    {
        $maria = $this->lider(Campaign::DEFAULT_ID, 'Maria Ejemplo', null, ['telefono' => '3104445566']);
        $response = $this->store($this->payload())->assertCreated();
        $actaId = $response->json('data.id');

        $this->actingAs($this->manager)->putJson("/eventos/{$this->event->id}/actas/{$actaId}", $this->payload([
            'titulo' => 'Título corregido', 'orden_dia' => ['Solo uno'],
            'participantes' => [
                ['nombre' => 'MARIA EJEMPLO', 'telefono' => '3104445566', 'lider_id' => $maria],
                ['nombre' => 'Otra Persona', 'telefono' => '3104445566', 'sugerencia_rechazada' => $maria],
            ],
            'compromisos' => [['descripcion' => 'Nuevo compromiso', 'recordar_el' => '2026-11-20', 'responsables' => []]],
        ]))->assertOk()->assertJsonPath('data.titulo', 'Título corregido')->assertJsonPath('data.orden_dia', ['Solo uno'])
            ->assertJsonPath('data.participantes.0.lider_id', $maria)->assertJsonPath('data.participantes.1.sugerencia_rechazada', $maria)
            ->assertJsonPath('data.compromisos.0.descripcion', 'Nuevo compromiso')->assertJsonPath('data.compromisos.0.recordar_el', '2026-11-20');
        $this->assertSame(2, DB::table('event_acta_participantes')->count());
        $this->assertSame(['Nuevo compromiso'], DB::table('event_compromisos')->pluck('descripcion')->all());
        $this->assertSame(1, DB::table('lideres')->count());
        $this->assertSame(0, DB::table('event_attendees')->count());
    }

    public function test_a_borrador_can_be_deleted_with_everything_and_nothing_reached_votantes(): void
    {
        $actaId = $this->store($this->payload())->assertCreated()->json('data.id');
        $this->actingAs($this->manager)->post("/eventos/{$this->event->id}/actas/{$actaId}/archivo", ['archivo' => $this->upload($this->png(), 'foto.png')], ['Accept' => 'application/json'])->assertOk();
        $path = DB::table('event_actas')->value('archivo_path');

        $this->actingAs($this->eventUser(Campaign::DEFAULT_ID, ['view.eventos']))->deleteJson("/eventos/{$this->event->id}/actas/{$actaId}")->assertForbidden();
        $this->actingAs($this->manager)->deleteJson("/eventos/{$this->event->id}/actas/{$actaId}")->assertOk();

        foreach (['event_actas', 'event_acta_participantes', 'event_compromisos', 'event_compromiso_responsables', 'event_attendees', 'lideres'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        Storage::disk('local')->assertMissing($path);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'EVENTO_ACTA_BORRADA')->count());
    }

    public function test_a_confirmed_acta_cannot_be_edited_deleted_or_get_another_file(): void
    {
        $actaId = $this->store($this->payload())->assertCreated()->json('data.id');
        $this->confirm($actaId)->assertOk();

        $this->actingAs($this->manager)->putJson("/eventos/{$this->event->id}/actas/{$actaId}", $this->payload(['titulo' => 'Otro']))
            ->assertUnprocessable()->assertJsonValidationErrors('acta');
        $this->actingAs($this->manager)->deleteJson("/eventos/{$this->event->id}/actas/{$actaId}")->assertForbidden();
        $this->actingAs($this->manager)->post("/eventos/{$this->event->id}/actas/{$actaId}/archivo", ['archivo' => $this->upload($this->png(), 'foto.png')], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $this->assertSame('ACTA REUNIÓN VEREDA BERLÍN, MUNICIPIO GIRÓN', DB::table('event_actas')->value('titulo'));

        // Still downloadable, and the page has no edit nor delete.
        $this->actingAs($this->manager)->get("/eventos/{$this->event->id}/actas/{$actaId}/acta.docx")->assertOk();
        $this->actingAs($this->manager)->get("/eventos/{$this->event->id}/actas/{$actaId}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.edit', false)->where('can.delete', false)->where('can.delete_confirmed', false)
                ->where('acta.estado', 'confirmada')->where('acta.confirmed_by', $this->manager->name));
    }

    public function test_a_docx_with_a_dtd_is_refused_before_parsing(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;">]>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>&b;&b;&b;&b;</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();

        $this->actingAs($this->manager)->post("/eventos/{$this->event->id}/actas/leer", ['archivo' => $this->upload($path)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('archivo');
    }

    public function test_the_form_starts_with_the_events_data_and_the_attendees(): void
    {
        $this->makeAttendee(['nombres' => 'Ana', 'apellidos' => 'Asistente']);
        $this->actingAs($this->manager)->get("/eventos/{$this->event->id}/actas/nueva")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Eventos/Acta')
                ->where('acta', null)
                ->where('defaults.municipio', 'GIRÓN')
                ->where('defaults.sitio', 'Parque principal')
                ->where('defaults.titulo', 'ACTA REUNIÓN DEL CONGRESO AL CAMPO')
                ->where('asistentes.0.nombre', 'Ana Asistente')
                ->where('can.edit', true));
    }

    public function test_a_cancelled_event_takes_no_actas(): void
    {
        $this->event->forceFill(['status' => 'cancelado'])->save();
        $this->store($this->payload())->assertUnprocessable()->assertJsonValidationErrors('evento');
    }

    public function test_isolation_and_permissions(): void
    {
        $actaId = $this->store($this->payload())->assertCreated()->json('data.id');
        $sub = $this->makeEvent($this->campX, ['nombre' => 'Evento de la subcampaña']);
        $xManager = $this->eventUser($this->campX, ['manage.eventos', 'view.eventos']);
        $subActa = $this->actingAs($xManager)->postJson("/eventos/{$sub->id}/actas", $this->payload(['participantes' => []]))->assertCreated()->json('data.id');

        // The parent campaign sees the subcampaign's acta read-only.
        $this->actingAs($this->manager)->get("/eventos/{$sub->id}/actas/{$subActa}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.edit', false)->where('asistentes', []));
        $this->actingAs($this->manager)->putJson("/eventos/{$sub->id}/actas/{$subActa}", $this->payload())->assertForbidden();
        // The subcampaign and an independent campaign do not see the parent's.
        $this->actingAs($xManager)->get("/eventos/{$this->event->id}/actas/{$actaId}")->assertNotFound();
        $other = $this->eventUser($this->campY, ['manage.eventos', 'view.eventos']);
        $this->actingAs($other)->getJson("/eventos/{$this->event->id}/actas")->assertNotFound();
        $this->actingAs($other)->postJson("/eventos/{$this->event->id}/actas", ['titulo' => ''])->assertNotFound();
        // An acta of another event is not reachable through this one.
        $this->actingAs($this->manager)->get("/eventos/{$this->event->id}/actas/{$subActa}")->assertNotFound();
        // Territory.
        $outside = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos', 'view.eventos'], 'R02_ADMIN_FUNCIONAL', [$this->velez]);
        $this->actingAs($outside)->getJson("/eventos/{$this->event->id}/actas")->assertForbidden();

        // view.eventos reads and downloads; it does not write. register.asistentes alone sees nothing.
        $viewer = $this->eventUser(Campaign::DEFAULT_ID, ['view.eventos']);
        $this->actingAs($viewer)->getJson("/eventos/{$this->event->id}/actas")->assertOk()->assertJsonPath('data.0.id', $actaId);
        $this->actingAs($viewer)->get("/eventos/{$this->event->id}/actas/{$actaId}")->assertOk();
        $this->actingAs($viewer)->get("/eventos/{$this->event->id}/actas/{$actaId}/acta.docx")->assertOk();
        $this->actingAs($viewer)->postJson("/eventos/{$this->event->id}/actas", $this->payload())->assertForbidden();
        $this->actingAs($viewer)->get("/eventos/{$this->event->id}/actas/nueva")->assertRedirect('/');
        $operator = $this->eventUser(Campaign::DEFAULT_ID, ['register.asistentes']);
        $this->actingAs($operator)->getJson("/eventos/{$this->event->id}/actas")->assertForbidden();
        $this->assertSame(2, DB::table('event_actas')->count());
    }

    private function makeAttendee(array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('event_attendees')->insert(array_merge([
            'id' => $id, 'event_id' => $this->event->id, 'campaign_id' => $this->event->campaign_id, 'nombres' => 'Asistente',
            'apellidos' => 'Previo', 'cedula' => null, 'source' => 'operador', 'link_status' => 'pendiente', 'autoriza_datos' => false,
            'client_uuid' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
        ], $extra));

        return $id;
    }
}
