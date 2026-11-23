<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\User;
use App\Support\MigracionFlags;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// Correcting a CONFIRMED acta with edit.actas and acta data left incomplete (docs/eventos-api.md §14 "Corregir un acta
// confirmada" and "Datos incompletos"). SQLite, fictitious data. Each test in its own process, as EmpresariosTest.
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class EventActaCorreccionTest extends TestCase
{
    use BuildsEventosScenario;

    private User $manager;

    private User $editor;

    private CampaignEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEventos();
        (require database_path('migrations/2026_11_10_000001_create_event_actas_tables.php'))->up();
        (require database_path('migrations/2026_11_11_000001_add_created_flags_to_event_acta_participantes.php'))->up();
        (require database_path('migrations/2026_11_13_000002_add_organizacion_to_event_actas.php'))->up();
        (require database_path('migrations/2026_11_22_000001_add_version_to_event_actas.php'))->up();
        Schema::table('lideres', fn (Blueprint $t) => $t->json('migracion_flags')->nullable());
        (fn () => self::$hasColumn = null)->bindTo(null, MigracionFlags::class)();
        Storage::fake('local');
        $this->manager = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos', 'view.eventos']);
        $this->editor = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos', 'view.eventos', 'edit.actas']);
        $this->event = $this->makeEvent(Campaign::DEFAULT_ID);
    }

    private function url(string $actaId, string $suffix = ''): string
    {
        return "/eventos/{$this->event->id}/actas/{$actaId}{$suffix}";
    }

    private function confirmedActa(array $participantes, array $compromisos = [], array $extra = []): string
    {
        $id = $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/actas", [
            'titulo' => 'ACTA REUNIÓN PRUEBA', 'fecha' => '2026-11-12', 'participantes' => $participantes, 'compromisos' => $compromisos, ...$extra,
        ])->assertCreated()->json('data.id');
        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/actas/{$id}/confirmar")->assertOk();

        return $id;
    }

    // The acta as the page hands it to the editor: what "Corregir acta" starts from.
    private function current(string $actaId, ?User $user = null): array
    {
        $props = $this->actingAs($user ?? $this->editor)->get($this->url($actaId))->assertOk()->viewData('page')['props'];
        $acta = $props['acta'];

        return [
            'version' => $acta['version'],
            ...array_intersect_key($acta, array_flip(['titulo', 'fecha', 'municipio', 'sitio', 'hora_inicio', 'hora_fin', 'introduccion', 'orden_dia', 'desarrollo', 'cierre'])),
            'participantes' => array_map(fn ($p) => array_intersect_key($p, array_flip(['id', 'nombre', 'perfil', 'municipio', 'telefono', 'cedula', 'lider_id', 'attendee_id'])), $acta['participantes']),
            'compromisos' => array_map(fn ($c) => [
                'id' => $c['id'], 'descripcion' => $c['descripcion'], 'fecha_limite' => $c['fecha_limite'], 'recordar_el' => $c['recordar_el'],
                'responsables' => array_map(fn ($r) => array_intersect_key($r, array_flip(['nombre', 'telefono', 'lider_id'])), $c['responsables']),
            ], $acta['compromisos']),
        ];
    }

    private function correct(string $actaId, array $body, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->editor)->putJson($this->url($actaId, '/correccion'), $body);
    }

    private function row(string $actaId, string $nombre): object
    {
        return DB::table('event_acta_participantes')->where('acta_id', $actaId)->where('nombre', $nombre)->first();
    }

    private function snapshot(): array
    {
        $out = [];
        foreach (['event_actas', 'event_acta_participantes', 'event_compromisos', 'event_compromiso_responsables', 'event_compromiso_avances', 'event_attendees', 'lideres'] as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return $out;
    }

    public function test_a_correction_changes_the_acta_and_keeps_ids_estado_and_avances(): void
    {
        $actaId = $this->confirmedActa(
            [['nombre' => 'PERSONA UNO PRUEBA', 'telefono' => '3001110001', 'cedula' => '70000001', 'municipio' => 'Girón']],
            [
                ['descripcion' => 'Gestionar cámaras', 'fecha_limite' => '2026-12-01', 'responsables' => [['nombre' => 'Responsable Texto', 'telefono' => '3205556677']]],
                ['descripcion' => 'Compromiso que se quita'],
            ],
            ['desarrollo' => [['titulo' => 'Seguridad', 'texto' => 'Texto original']]],
        );
        $camaras = DB::table('event_compromisos')->where('descripcion', 'Gestionar cámaras')->value('id');
        $this->actingAs($this->manager)->putJson("/eventos/compromisos/{$camaras}/estado", ['estado' => 'en_curso', 'nota' => 'Avance previo'])->assertOk();
        $participante = $this->row($actaId, 'PERSONA UNO PRUEBA');

        $body = $this->current($actaId);
        $this->assertSame(0, $body['version']);
        $body['fecha'] = '2026-11-14';
        $body['desarrollo'] = [['titulo' => 'Seguridad', 'texto' => 'Texto corregido']];
        $body['participantes'][0]['nombre'] = 'PERSONA UNO PRUEBA CORREGIDA';
        $body['compromisos'] = [
            [...$body['compromisos'][0], 'descripcion' => 'Gestionar cámaras de seguridad', 'fecha_limite' => '2026-12-15',
                'responsables' => [['nombre' => 'Responsable Nuevo', 'telefono' => '3009998877']]],
            ['descripcion' => 'Compromiso nuevo'],
        ];

        $response = $this->correct($actaId, $body)->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('resumen.participantes_editados', 1)
            ->assertJsonPath('resumen.compromisos_agregados', 1)
            ->assertJsonPath('resumen.compromisos_editados', 1)
            ->assertJsonPath('resumen.compromisos_quitados', 1)
            ->assertJsonPath('resumen.cambios', ['fecha', 'desarrollo']);
        $this->assertStringStartsWith('Acta corregida.', $response->json('message'));

        $acta = DB::table('event_actas')->where('id', $actaId)->first();
        $this->assertSame('2026-11-14', substr((string) $acta->fecha, 0, 10));
        $this->assertSame(1, (int) $acta->version);
        $this->assertSame('confirmada', $acta->estado);
        $this->assertSame($participante->id, $this->row($actaId, 'PERSONA UNO PRUEBA CORREGIDA')->id, 'The participant keeps its row');
        $this->assertSame($participante->lider_id, $this->row($actaId, 'PERSONA UNO PRUEBA CORREGIDA')->lider_id, 'and its ficha');

        $kept = DB::table('event_compromisos')->where('id', $camaras)->first();
        $this->assertSame('Gestionar cámaras de seguridad', $kept->descripcion);
        $this->assertSame('en_curso', $kept->estado, 'The estado stays');
        $this->assertSame(1, DB::table('event_compromiso_avances')->where('compromiso_id', $camaras)->count(), 'The avances stay');
        $this->assertSame(['Responsable Nuevo'], DB::table('event_compromiso_responsables')->where('compromiso_id', $camaras)->pluck('nombre')->all());
        $this->assertSame('pendiente', DB::table('event_compromisos')->where('descripcion', 'Compromiso nuevo')->value('estado'));
        $this->assertFalse(DB::table('event_compromisos')->where('descripcion', 'Compromiso que se quita')->exists());

        $log = DB::table('audit_logs')->where('action', 'EVENTO_ACTA_CORREGIDA')->first();
        $this->assertNotNull($log);
        $metadata = json_decode($log->metadata, true);
        $this->assertSame($actaId, $metadata['acta_id']);
        $this->assertSame('2026-11-12', json_decode($log->before_state, true)['fecha']);
        $this->assertSame('2026-11-14', json_decode($log->after_state, true)['fecha']);
        $this->assertSame('2026-12-15', json_decode($log->after_state, true)['compromisos'][$camaras]['fecha_limite']);
        // Also the row the audit middleware writes for the request: only the names of the fields sent.
        $request = DB::table('audit_logs')->where('action', 'PUT eventos.actas.correct')->first();
        $this->assertSame(['campos' => array_keys($body)], json_decode($request->metadata, true));
        $everything = $log->metadata.$log->before_state.$log->after_state.$request->metadata.$request->before_state.$request->after_state;
        foreach (['PERSONA', 'CORREGIDA', '70000001', '3001110001', 'Responsable', '3009998877', 'cámaras', 'Texto corregido'] as $personal) {
            $this->assertStringNotContainsString($personal, $everything, "No «{$personal}» in the audit");
        }

        $this->actingAs($this->editor)->get($this->url($actaId))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can.edit_confirmed', true)->where('can.edit', false)->where('acta.version', 1)
            ->where('historial.0.accion', 'corregida')
            ->where('historial.0.usuario', $this->editor->name)
            ->where('historial.0.resumen', 'Cambió: fecha (12-nov → 14-nov), desarrollo; 1 participante corregido; 1 compromiso nuevo, 1 compromiso editado, 1 compromiso quitado')
            ->where('historial.1.accion', 'confirmada'));
    }

    public function test_added_participants_go_through_confirmation_and_removed_ones_keep_their_ficha(): void
    {
        $prev = (string) Str::uuid();
        DB::table('event_attendees')->insert([
            'id' => $prev, 'event_id' => $this->event->id, 'campaign_id' => Campaign::DEFAULT_ID, 'nombres' => 'Asistente', 'apellidos' => 'Previo',
            'cedula' => '55667788', 'source' => 'operador', 'link_status' => 'pendiente', 'autoriza_datos' => false,
            'client_uuid' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $actaId = $this->confirmedActa([
            ['nombre' => 'SE QUITA CREADO', 'telefono' => '3001110002'],
            ['nombre' => 'Asistente Previo', 'attendee_id' => $prev],
            ['nombre' => 'SE QUITA CON AUTORIZACION', 'telefono' => '3001110003'],
            ['nombre' => 'SE QUEDA', 'telefono' => '3001110004'],
        ]);
        $creado = $this->row($actaId, 'SE QUITA CREADO');
        $autorizado = $this->row($actaId, 'SE QUITA CON AUTORIZACION');
        $text = $this->approvedText(Campaign::DEFAULT_ID);
        DB::table('data_consents')->insert([
            'id' => (string) Str::uuid(), 'campaign_id' => Campaign::DEFAULT_ID, 'consent_text_id' => $text->id, 'text_sha256' => $text->sha256,
            'attendee_id' => $autorizado->attendee_id, 'event_id' => $this->event->id, 'channel' => 'operador', 'accepted_at' => now(),
        ]);
        $existing = $this->lider(Campaign::DEFAULT_ID, 'Ficha Existente Prueba', '80000001');

        $body = $this->current($actaId);
        $body['participantes'] = [
            ...array_values(array_filter($body['participantes'], fn ($p) => $p['nombre'] === 'SE QUEDA')),
            ['nombre' => 'NUEVA PERSONA AGREGADA', 'telefono' => '3001110005', 'municipio' => 'Lebrija'],
            ['nombre' => 'EXISTENTE POR CEDULA', 'cedula' => '80000001'],
        ];
        $this->correct($actaId, $body)->assertOk()
            ->assertJsonPath('resumen.participantes_agregados', 2)
            ->assertJsonPath('resumen.participantes_quitados', 3)
            ->assertJsonPath('resumen.fichas_creadas', 1)
            ->assertJsonPath('resumen.vinculados', 1)
            ->assertJsonPath('resumen.asistentes_nuevos', 2)
            ->assertJsonPath('resumen.asistentes_quitados', 1);

        $nueva = $this->row($actaId, 'NUEVA PERSONA AGREGADA');
        $this->assertTrue((bool) $nueva->created_lider);
        $this->assertTrue((bool) $nueva->created_attendee);
        $this->assertSame('acta', DB::table('event_attendees')->where('id', $nueva->attendee_id)->value('source'));
        $this->assertSame($this->lebrija, DB::table('lideres')->where('id', $nueva->lider_id)->value('geographic_unit_id'));
        $this->assertSame($existing, $this->row($actaId, 'EXISTENTE POR CEDULA')->lider_id);
        $this->assertFalse((bool) $this->row($actaId, 'EXISTENTE POR CEDULA')->created_lider);

        // Removed: the attendance this acta created goes; the fichas never do.
        $this->assertFalse(DB::table('event_attendees')->where('id', $creado->attendee_id)->exists());
        $this->assertTrue(DB::table('lideres')->where('id', $creado->lider_id)->exists(), 'The ficha of a removed participant stays');
        $this->assertTrue(DB::table('event_attendees')->where('id', $prev)->exists(), 'An attendee that already existed stays');
        $this->assertTrue(DB::table('event_attendees')->where('id', $autorizado->attendee_id)->exists(), 'An attendee with data_consents stays');
        $this->assertTrue(DB::table('lideres')->where('id', $autorizado->lider_id)->exists());
        $this->assertFalse(DB::table('event_acta_participantes')->where('id', $creado->id)->exists());
    }

    public function test_an_existing_participant_only_fills_what_its_ficha_lacks(): void
    {
        $actaId = $this->confirmedActa([['nombre' => 'SIN DATOS PRUEBA']]);
        $ficha = $this->row($actaId, 'SIN DATOS PRUEBA')->lider_id;
        $this->assertSame(['cedula', 'telefono'], json_decode(DB::table('lideres')->where('id', $ficha)->value('migracion_flags'), true)['por_completar'],
            'The event gave the municipio; cédula and teléfono are pending');
        $otra = $this->lider(Campaign::DEFAULT_ID, 'Otra Persona Prueba', '90000009');

        // A cédula that is another ficha's: 422 and nothing is merged nor written.
        $body = $this->current($actaId);
        $body['participantes'][0]['cedula'] = '90000009';
        $body['titulo'] = 'NO SE GUARDA';
        $before = $this->snapshot();
        $this->correct($actaId, $body)->assertUnprocessable()
            ->assertJsonPath('errors', ['participantes.0.cedula' => ['Esa cédula ya pertenece a otra persona en Votantes: corrija la cédula o deje la que tenía.']]);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('90000009', DB::table('lideres')->where('id', $otra)->value('cedula'));

        $body['participantes'][0]['cedula'] = '1.098.000.111';
        $body['participantes'][0]['telefono'] = '300 111 0006';
        $body['titulo'] = 'ACTA REUNIÓN PRUEBA';
        $this->correct($actaId, $body)->assertOk();
        $row = DB::table('lideres')->where('id', $ficha)->first();
        $this->assertSame('1098000111', $row->cedula);
        $this->assertSame('3001110006', $row->telefono);
        $this->assertNull($row->migracion_flags, 'Completed fields stop being pending');

        // Something the ficha already has is never overwritten.
        $body = $this->current($actaId);
        $body['participantes'][0]['cedula'] = '1098000222';
        $body['participantes'][0]['telefono'] = '3109999999';
        $this->correct($actaId, $body)->assertOk();
        $row = DB::table('lideres')->where('id', $ficha)->first();
        $this->assertSame('1098000111', $row->cedula);
        $this->assertSame('3001110006', $row->telefono);
        $this->assertSame('1098000222', $this->row($actaId, 'SIN DATOS PRUEBA')->cedula, 'The acta keeps what was typed');

        // lider_id/attendee_id of the request are ignored for an existing participant.
        $body = $this->current($actaId);
        $body['participantes'][0]['lider_id'] = $otra;
        $this->correct($actaId, $body)->assertOk();
        $this->assertSame($ficha, $this->row($actaId, 'SIN DATOS PRUEBA')->lider_id);
    }

    public function test_a_double_submit_and_an_old_version_get_a_409_and_change_nothing(): void
    {
        $actaId = $this->confirmedActa([['nombre' => 'PERSONA DOBLE']]);
        $body = $this->current($actaId);
        $body['participantes'][] = ['nombre' => 'AGREGADA UNA VEZ', 'telefono' => '3001110007'];

        $this->correct($actaId, $body)->assertOk()->assertJsonPath('data.version', 1);
        $before = $this->snapshot();
        $this->correct($actaId, $body)->assertStatus(409)
            ->assertExactJson(['success' => false, 'code' => 'version', 'message' => 'Otra persona cambió esta acta mientras usted la corregía. Recargue la página para ver los cambios; lo suyo no se guardó.']);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(1, DB::table('event_acta_participantes')->where('nombre', 'AGREGADA UNA VEZ')->count());
        $this->assertSame(1, DB::table('lideres')->where('nombre', 'AGREGADA UNA VEZ')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'EVENTO_ACTA_CORREGIDA')->count());

        // Another user saved meanwhile: the old version is refused.
        $fresh = $this->current($actaId);
        $this->correct($actaId, [...$fresh, 'titulo' => 'OTRO USUARIO'])->assertOk();
        $this->correct($actaId, [...$fresh, 'titulo' => 'VERSION VIEJA'])->assertStatus(409);
        $this->assertSame('OTRO USUARIO', DB::table('event_actas')->value('titulo'));
        $this->correct($actaId, [...$fresh, 'version' => null])->assertUnprocessable()->assertJsonValidationErrors('version');
    }

    public function test_illegal_corrections_are_refused_without_writing_anything(): void
    {
        $actaId = $this->confirmedActa([['nombre' => 'PERSONA ILEGAL', 'telefono' => '3001110008']], [['descripcion' => 'Con avances']]);
        $compromiso = DB::table('event_compromisos')->value('id');
        $this->actingAs($this->manager)->putJson("/eventos/compromisos/{$compromiso}/estado", ['estado' => 'en_curso'])->assertOk();
        $this->actingAs($this->manager)->putJson("/eventos/compromisos/{$compromiso}/estado", ['estado' => 'cumplido'])->assertOk();
        $other = $this->current($this->confirmedActa([['nombre' => 'OTRA ACTA']], [['descripcion' => 'Otro compromiso']]));
        $foreign = $this->lider($this->campY, 'Ajena Prueba', null);
        $body = $this->current($actaId);
        $before = $this->snapshot();

        // A compromiso with avances cannot be removed; the header change sent with it is not saved either.
        $this->correct($actaId, [...$body, 'titulo' => 'CAMBIO PERDIDO', 'compromisos' => []])->assertUnprocessable()
            ->assertJsonPath('errors', ["compromisos_quitados.{$compromiso}" => ['Este compromiso tiene 2 avances: no se puede quitar. Márquelo como No cumplido en Seguimiento.']]);
        // Ids of another acta, or the same row twice.
        $this->correct($actaId, [...$body, 'participantes' => [...$body['participantes'], $other['participantes'][0]]])
            ->assertUnprocessable()->assertJsonValidationErrors('participantes.1.id');
        $this->correct($actaId, [...$body, 'compromisos' => [...$body['compromisos'], $other['compromisos'][0]]])
            ->assertUnprocessable()->assertJsonValidationErrors('compromisos.1.id');
        $this->correct($actaId, [...$body, 'participantes' => [$body['participantes'][0], $body['participantes'][0]]])
            ->assertUnprocessable()->assertJsonValidationErrors('participantes.1.id');
        // A participant without a name.
        $this->correct($actaId, [...$body, 'participantes' => [['nombre' => '   ']]])
            ->assertUnprocessable()->assertJsonValidationErrors('participantes.0.nombre');
        // A ficha of another campaign chosen for a new participant.
        $this->correct($actaId, [...$body, 'participantes' => [...$body['participantes'], ['nombre' => 'Ajena', 'lider_id' => $foreign]]])
            ->assertUnprocessable()->assertJsonValidationErrors('participantes.1.lider_id');

        $this->assertSame($before, $this->snapshot(), 'Nothing was written');
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'EVENTO_ACTA_CORREGIDA')->count());

        // A borrador is corrected with the normal PUT; a confirmed acta still refuses the normal PUT.
        $draft = $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/actas", ['titulo' => 'BORRADOR'])->json('data.id');
        $this->correct($draft, ['titulo' => 'BORRADOR', 'version' => 0])->assertUnprocessable()
            ->assertJsonPath('errors.acta.0', 'El acta no está confirmada: corríjala como borrador.');
        $this->actingAs($this->editor)->putJson($this->url($actaId), ['titulo' => 'Otro'])->assertUnprocessable()->assertJsonValidationErrors('acta');

        // A cancelled event takes no corrections.
        $this->event->forceFill(['status' => 'cancelado'])->save();
        $this->correct($actaId, $body)->assertUnprocessable()->assertJsonValidationErrors('evento');
        $this->actingAs($this->editor)->get($this->url($actaId))->assertInertia(fn (AssertableInertia $page) => $page->where('can.edit_confirmed', false));
    }

    public function test_permissions_campaign_and_territory(): void
    {
        $actaId = $this->confirmedActa([['nombre' => 'PERSONA PERMISOS']]);
        $body = $this->current($actaId);

        // Without edit.actas: no button and 403.
        $this->actingAs($this->manager)->get($this->url($actaId))->assertInertia(fn (AssertableInertia $page) => $page->where('can.edit_confirmed', false));
        $this->correct($actaId, $body, $this->manager)->assertForbidden();
        // edit.actas without manage.eventos: 403.
        $soloEdit = $this->eventUser(Campaign::DEFAULT_ID, ['view.eventos', 'edit.actas']);
        $this->correct($actaId, $body, $soloEdit)->assertForbidden();
        $this->actingAs($soloEdit)->get($this->url($actaId))->assertInertia(fn (AssertableInertia $page) => $page->where('can.edit_confirmed', false));
        // Another campaign, or a subcampaign looking at its parent's acta: 404.
        $other = $this->eventUser($this->campY, ['manage.eventos', 'view.eventos', 'edit.actas']);
        $this->correct($actaId, $body, $other)->assertNotFound();
        $xEditor = $this->eventUser($this->campX, ['manage.eventos', 'view.eventos', 'edit.actas']);
        $this->correct($actaId, $body, $xEditor)->assertNotFound();
        // Outside the user's territory: refused as every write of the module (403).
        $outside = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos', 'view.eventos', 'edit.actas'], 'R02_ADMIN_FUNCIONAL', [$this->velez]);
        $this->correct($actaId, $body, $outside)->assertForbidden();
        // An acta of another event through this one: 404.
        $otherEvent = $this->makeEvent(Campaign::DEFAULT_ID, ['nombre' => 'Otro evento']);
        $this->actingAs($this->editor)->putJson("/eventos/{$otherEvent->id}/actas/{$actaId}/correccion", $body)->assertNotFound();

        $this->assertSame(0, (int) DB::table('event_actas')->value('version'));
        $this->correct($actaId, $body)->assertOk();
    }

    public function test_deleting_a_corrected_acta_undoes_what_the_correction_created(): void
    {
        $deleter = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos', 'view.eventos', 'delete.actas', 'edit.actas']);
        $actaId = $this->confirmedActa([['nombre' => 'PERSONA ORIGINAL', 'telefono' => '3001110009']]);
        $body = $this->current($actaId);
        $body['participantes'][] = ['nombre' => 'PERSONA DE LA CORRECCION', 'telefono' => '3001110010'];
        $this->correct($actaId, $body)->assertOk();
        $nueva = $this->row($actaId, 'PERSONA DE LA CORRECCION');
        $original = $this->row($actaId, 'PERSONA ORIGINAL');

        $this->actingAs($deleter)->deleteJson($this->url($actaId))->assertOk()
            ->assertJsonPath('data.asistentes', 2)->assertJsonPath('data.fichas_borrar', 2);
        $this->assertFalse(DB::table('lideres')->whereIn('id', [$nueva->lider_id, $original->lider_id])->exists());
        $this->assertSame(0, DB::table('event_attendees')->count());
        $this->assertSame(0, DB::table('event_actas')->count());
    }

    // The same person removed and written again in one correction: the new row inherits what the old one created, so
    // deleting the acta afterwards leaves no orphan attendee nor ficha.
    public function test_a_row_that_takes_over_a_removed_ones_attendance_inherits_its_marks(): void
    {
        $deleter = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos', 'view.eventos', 'delete.actas', 'edit.actas']);
        $actaId = $this->confirmedActa([['nombre' => 'PERSONA A ESCRITA MAL', 'cedula' => '71717171']]);
        $a = $this->row($actaId, 'PERSONA A ESCRITA MAL');

        $body = $this->current($actaId);
        $body['participantes'] = [['nombre' => 'PERSONA A BIEN ESCRITA', 'cedula' => '71717171']];
        $this->correct($actaId, $body)->assertOk()->assertJsonPath('resumen.asistentes_quitados', 0)->assertJsonPath('resumen.vinculados', 1);
        $b = $this->row($actaId, 'PERSONA A BIEN ESCRITA');
        $this->assertSame([$a->lider_id, $a->attendee_id], [$b->lider_id, $b->attendee_id], 'Same ficha and attendance');
        $this->assertTrue((bool) $b->created_attendee);
        $this->assertTrue((bool) $b->created_lider);

        $this->actingAs($deleter)->deleteJson($this->url($actaId))->assertOk()->assertJsonPath('data.asistentes', 1)->assertJsonPath('data.fichas_borrar', 1);
        $this->assertSame(0, DB::table('event_attendees')->count(), 'No orphan attendee');
        $this->assertFalse(DB::table('lideres')->where('id', $a->lider_id)->exists());
    }

    public function test_a_confirmed_actas_file_is_replaced_only_with_edit_actas_and_the_old_one_stays(): void
    {
        $actaId = $this->confirmedActa([]);
        $png = function () {
            $path = tempnam(sys_get_temp_dir(), 'png');
            imagepng(imagecreatetruecolor(4, 4), $path);

            return new UploadedFile($path, 'foto.png', null, null, true);
        };
        $this->actingAs($this->manager)->post($this->url($actaId, '/archivo'), ['archivo' => $png()], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->actingAs($this->editor)->post($this->url($actaId, '/archivo'), ['archivo' => $png()], ['Accept' => 'application/json'])->assertOk();
        $first = DB::table('event_actas')->value('archivo_path');
        $this->actingAs($this->editor)->post($this->url($actaId, '/archivo'), ['archivo' => $png()], ['Accept' => 'application/json'])->assertOk();
        $second = DB::table('event_actas')->value('archivo_path');
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertExists($first);
        $anteriores = DB::table('audit_logs')->where('action', 'EVENTO_ACTA_ARCHIVO')->pluck('metadata')->map(fn ($m) => json_decode($m, true)['archivo_anterior'] ?? null)->all();
        $this->assertContains($first, $anteriores, 'The audit keeps where the previous original is');
        $this->actingAs($this->editor)->get($this->url($actaId))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('historial.0.accion', 'archivo')->where('historial.0.resumen', 'Reemplazó el archivo original.'));
    }

    public function test_an_organizations_acta_is_corrected_with_its_members(): void
    {
        $org = (string) Str::uuid();
        DB::table('organizaciones')->insert([
            'id' => $org, 'campaign_id' => Campaign::DEFAULT_ID, 'tipo' => 'empresa', 'nombre' => 'Empresa Prueba', 'municipio_id' => $this->giron,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $orgManager = $this->eventUser(Campaign::DEFAULT_ID, ['manage.empresarios', 'view.empresarios']);
        $orgEditor = $this->eventUser(Campaign::DEFAULT_ID, ['manage.empresarios', 'view.empresarios', 'edit.actas']);
        $actaId = $this->actingAs($orgManager)->postJson("/empresarios/{$org}/actas", ['titulo' => 'Junta', 'participantes' => [['nombre' => 'Miembro Temporal Uno'], ['nombre' => 'Miembro Que Queda']]])->json('data.id');
        $this->actingAs($orgManager)->postJson("/empresarios/{$org}/actas/{$actaId}/confirmar")->assertOk();
        $temporal = $this->row($actaId, 'Miembro Temporal Uno');
        $this->assertSame(2, DB::table('organizacion_miembros')->where('organizacion_id', $org)->count());

        $props = $this->actingAs($orgEditor)->get("/empresarios/{$org}/actas/{$actaId}")->assertOk()->viewData('page')['props'];
        $this->assertTrue($props['can']['edit_confirmed']);
        $body = ['titulo' => 'Junta corregida', 'version' => $props['acta']['version'],
            'participantes' => [['id' => $this->row($actaId, 'Miembro Que Queda')->id, 'nombre' => 'Miembro Que Queda'], ['nombre' => 'Miembro Nuevo']]];
        $this->actingAs($orgManager)->putJson("/empresarios/{$org}/actas/{$actaId}/correccion", $body)->assertForbidden();
        $this->actingAs($orgEditor)->putJson("/empresarios/{$org}/actas/{$actaId}/correccion", $body)->assertOk()
            ->assertJsonPath('resumen.miembros_nuevos', 1)->assertJsonPath('resumen.miembros_quitados', 1);

        $this->assertFalse(DB::table('organizacion_miembros')->where('organizacion_id', $org)->where('lider_id', $temporal->lider_id)->exists());
        $this->assertTrue(DB::table('lideres')->where('id', $temporal->lider_id)->exists(), 'The ficha stays');
        $this->assertSame(2, DB::table('organizacion_miembros')->where('organizacion_id', $org)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'ORGANIZACION_ACTA_CORREGIDA')->where('entity_id', $org)->count());
        $this->actingAs($orgEditor)->putJson("/empresarios/{$org}/actas/{$actaId}/correccion", $body)->assertStatus(409);
        // An event's editor without manage.empresarios cannot correct it.
        $this->actingAs($this->editor)->putJson("/empresarios/{$org}/actas/{$actaId}/correccion", [...$body, 'version' => 1])->assertForbidden();
    }

    public function test_a_name_only_participant_is_suggested_by_name_and_never_linked_alone(): void
    {
        $mismo = $this->lider(Campaign::DEFAULT_ID, 'María José Pérez Gómez', null, ['telefono' => null]);
        $this->lider(Campaign::DEFAULT_ID, 'Pedro Duplicado Doble', null);
        $this->lider(Campaign::DEFAULT_ID, 'PEDRO DUPLICADO DOBLE', null);
        $this->lider(Campaign::DEFAULT_ID, 'Otro Municipio Nombre', null, ['geographic_unit_id' => $this->lebrija, 'municipio' => 'Lebrija']);
        $this->lider($this->campY, 'Otra Campana Nombre', null);

        $suggest = $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/actas/sugerencias", ['participantes' => [
            ['nombre' => 'PEREZ GOMEZ MARIA JOSE'],
            ['nombre' => 'Pedro Duplicado Doble'],
            ['nombre' => 'Otro Municipio Nombre'],
            ['nombre' => 'Otra Campana Nombre'],
            ['nombre' => 'María José Pérez Gómez', 'telefono' => '3150000000'],
            ['nombre' => 'Otro Municipio Nombre', 'municipio' => 'Lebrija'],
            ['nombre' => 'Pérez'],
        ]])->assertOk();
        $this->assertSame(['id' => $mismo, 'por' => 'nombre', 'motivo' => 'nombre'], array_intersect_key($suggest->json('data.participantes.0'), ['id' => 1, 'por' => 1, 'motivo' => 1]));
        $this->assertSame([0, 5], array_keys($suggest->json('data.participantes')), 'A tie, another municipio, another campaign, a phone or one word: no name suggestion');
        $this->assertSame('nombre', $suggest->json('data.participantes.5.motivo'));

        // Nobody answered: a new ficha marked as a possible duplicate, never linked.
        $actaId = $this->confirmedActa([['nombre' => 'PEREZ GOMEZ MARIA JOSE']]);
        $row = $this->row($actaId, 'PEREZ GOMEZ MARIA JOSE');
        $this->assertNotSame($mismo, $row->lider_id);
        $this->assertTrue((bool) $row->created_lider);
        $flags = json_decode(DB::table('lideres')->where('id', $row->lider_id)->value('migracion_flags'), true);
        $this->assertSame([$mismo], $flags['posible_duplicado']);
        $this->assertSame(['cedula', 'telefono'], $flags['por_completar']);
        $aviso = MigracionFlags::forFicha(DB::table('lideres')->where('id', $row->lider_id)->first());
        $this->assertSame([['campo' => 'cedula', 'label' => 'Cédula', 'codigo_electo' => null], ['campo' => 'telefono', 'label' => 'Teléfono', 'codigo_electo' => null]], $aviso['por_completar']);
        $this->assertSame(['Puede ser la misma persona que otra ficha del mismo nombre y municipio.'], $aviso['marcas'], 'No text about Electo');

        // Answered yes: linked to it, nothing created.
        $actaId = $this->confirmedActa([['nombre' => 'PEREZ GOMEZ MARIA JOSE', 'lider_id' => $mismo]]);
        $this->assertSame($mismo, $this->row($actaId, 'PEREZ GOMEZ MARIA JOSE')->lider_id);
    }

    public function test_the_page_shows_what_is_left_to_complete(): void
    {
        $completa = $this->lider(Campaign::DEFAULT_ID, 'Ficha Completa', '60000001');
        $actaId = $this->confirmedActa([
            ['nombre' => 'SOLO NOMBRE PRUEBA'],
            ['nombre' => 'FICHA COMPLETA', 'cedula' => '60000001'],
        ], [
            ['descripcion' => 'Sin fecha ni responsable'],
            ['descripcion' => 'Completo', 'fecha_limite' => '2026-12-01', 'responsables' => [['nombre' => 'Alguien']]],
        ]);
        $this->assertSame($completa, $this->row($actaId, 'FICHA COMPLETA')->lider_id);

        $this->actingAs($this->editor)->get($this->url($actaId))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('acta.indicadores', ['participantes_por_completar' => 1, 'compromisos_incompletos' => 1])
            ->where('acta.participantes.0.por_completar', ['cedula', 'telefono'])
            ->where('acta.participantes.1.por_completar', [])
            ->where('acta.compromisos.0.por_completar', ['fecha_limite', 'responsable'])
            ->where('acta.compromisos.1.por_completar', []));

        // Completed in the ficha: the indicator reads the ficha as it is now.
        DB::table('lideres')->where('id', $this->row($actaId, 'SOLO NOMBRE PRUEBA')->lider_id)->update(['cedula' => '60000002', 'telefono' => '3001110011']);
        $this->actingAs($this->editor)->get($this->url($actaId))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('acta.indicadores.participantes_por_completar', 0)->where('acta.participantes.0.por_completar', []));

        // A borrador reads its own rows.
        $draft = $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/actas", [
            'titulo' => 'BORRADOR', 'participantes' => [['nombre' => 'Fila Sola', 'municipio' => 'Girón']],
        ])->assertCreated()->assertJsonPath('data.participantes.0.por_completar', ['cedula', 'telefono'])->json('data.id');
        $this->actingAs($this->manager)->get($this->url($draft))->assertInertia(fn (AssertableInertia $page) => $page->where('historial', []));
    }

    // The audit middleware keeps only the names of the fields sent on every write of an acta (events and organizations):
    // never a participant's name nor what the acta says.
    public function test_the_request_audit_of_acta_writes_has_no_names_nor_texts(): void
    {
        $nombre = 'ZUNILDA PRIVADA QUINTERO';
        $texto = 'Texto reservado del desarrollo';
        $body = ['titulo' => 'ACTA PRIVACIDAD', 'introduccion' => $texto, 'desarrollo' => [['titulo' => 'Punto', 'texto' => $texto]],
            'participantes' => [['nombre' => $nombre, 'telefono' => '3001110099', 'cedula' => '70700707']],
            'compromisos' => [['descripcion' => $texto, 'responsables' => [['nombre' => $nombre]]]]];
        $png = function () {
            $path = tempnam(sys_get_temp_dir(), 'png');
            imagepng(imagecreatetruecolor(4, 4), $path);

            return new UploadedFile($path, 'foto.png', null, null, true);
        };

        $base = "/eventos/{$this->event->id}/actas";
        $this->actingAs($this->editor)->postJson("{$base}/sugerencias", ['participantes' => [['nombre' => $nombre, 'telefono' => '3001110099']]])->assertOk();
        $this->actingAs($this->editor)->post("{$base}/leer", ['archivo' => $png()], ['Accept' => 'application/json'])->assertOk();
        $id = $this->actingAs($this->editor)->postJson($base, $body)->assertCreated()->json('data.id');
        $this->actingAs($this->editor)->putJson("{$base}/{$id}", $body)->assertOk();
        $this->actingAs($this->editor)->post("{$base}/{$id}/archivo", ['archivo' => $png()], ['Accept' => 'application/json'])->assertOk();
        $this->actingAs($this->editor)->postJson("{$base}/{$id}/confirmar")->assertOk();
        $this->correct($id, [...$body, 'version' => 0, 'participantes' => [...$this->current($id)['participantes'], ['nombre' => "{$nombre} DOS"]]])->assertOk();

        $org = (string) Str::uuid();
        DB::table('organizaciones')->insert([
            'id' => $org, 'campaign_id' => Campaign::DEFAULT_ID, 'tipo' => 'empresa', 'nombre' => 'Empresa Privacidad', 'municipio_id' => $this->giron,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $orgUser = $this->eventUser(Campaign::DEFAULT_ID, ['manage.empresarios', 'view.empresarios', 'edit.actas']);
        $orgActa = $this->actingAs($orgUser)->postJson("/empresarios/{$org}/actas", $body)->assertCreated()->json('data.id');
        $this->actingAs($orgUser)->postJson("/empresarios/{$org}/actas/{$orgActa}/confirmar")->assertOk();

        $rows = DB::table('audit_logs')->where(fn ($q) => $q->where('action', 'like', '% eventos.actas.%')->orWhere('action', 'like', '% empresarios.actas.%'))->get();
        $this->assertSame([
            'POST empresarios.actas.confirm', 'POST empresarios.actas.store', 'POST eventos.actas.attach', 'POST eventos.actas.confirm', 'POST eventos.actas.leer',
            'POST eventos.actas.store', 'POST eventos.actas.sugerencias', 'PUT eventos.actas.correct', 'PUT eventos.actas.update',
        ], $rows->pluck('action')->sort()->values()->all());
        foreach ($rows as $row) {
            $all = $row->metadata.$row->before_state.$row->after_state;
            foreach (['ZUNILDA', 'QUINTERO', $texto, '0707', '0099'] as $privado) {
                $this->assertStringNotContainsString($privado, $all, "«{$privado}» in {$row->action}");
            }
            $this->assertSame(['campos'], array_keys(json_decode((string) $row->metadata, true) ?? ['campos' => []]), $row->action);
        }
        $this->assertSame(['campos' => array_keys($body)], json_decode($rows->firstWhere('action', 'POST eventos.actas.store')->metadata, true));
    }

    // O-7: a correction that changes nothing writes nothing (no version, no history, responsables untouched).
    public function test_a_correction_without_changes_writes_nothing(): void
    {
        $actaId = $this->confirmedActa([['nombre' => 'PERSONA SIN CAMBIOS', 'telefono' => '3001110012']],
            [['descripcion' => 'Compromiso quieto', 'responsables' => [['nombre' => 'Responsable Quieto']]]],
            ['desarrollo' => [['titulo' => 'Punto', 'texto' => 'Texto']], 'orden_dia' => ['Punto']]);
        $before = $this->snapshot();

        $this->correct($actaId, $this->current($actaId))->assertOk()
            ->assertJsonPath('message', 'No había cambios: el acta queda igual.')
            ->assertJsonPath('resumen.sin_cambios', true)->assertJsonPath('data.version', 0);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'EVENTO_ACTA_CORREGIDA')->count());

        // Only the order of the participants changes: that is a change.
        $actaId2 = $this->confirmedActa([['nombre' => 'PRIMERA ORDEN'], ['nombre' => 'SEGUNDA ORDEN']]);
        $body = $this->current($actaId2);
        $body['participantes'] = array_reverse($body['participantes']);
        $this->correct($actaId2, $body)->assertOk()->assertJsonPath('resumen.cambios', ['orden_participantes'])->assertJsonPath('data.version', 1);
    }

    // BUG-08: deleting the acta also removes the originals that corrections replaced.
    public function test_deleting_the_acta_removes_the_replaced_originals_too(): void
    {
        $deleter = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos', 'view.eventos', 'delete.actas', 'edit.actas']);
        $actaId = $this->confirmedActa([]);
        $paths = [];
        foreach ([1, 2, 3] as $n) {
            $file = tempnam(sys_get_temp_dir(), 'png');
            imagepng(imagecreatetruecolor(4, 4), $file);
            $this->actingAs($deleter)->post($this->url($actaId, '/archivo'), ['archivo' => new UploadedFile($file, 'foto.png', null, null, true)], ['Accept' => 'application/json'])->assertOk();
            $paths[] = DB::table('event_actas')->value('archivo_path');
        }
        foreach ($paths as $path) {
            Storage::disk('local')->assertExists($path);
        }
        $this->actingAs($deleter)->deleteJson($this->url($actaId))->assertOk();
        foreach ($paths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
    }

    // BUG-07: a name-only row without municipio uses the owner's municipio and, when the owner has none, the acta's.
    public function test_name_suggestions_fall_back_to_the_owners_and_the_actas_municipio(): void
    {
        $enGiron = $this->lider(Campaign::DEFAULT_ID, 'Ramiro Respaldo Giron', null, ['telefono' => null]);
        $enLebrija = $this->lider(Campaign::DEFAULT_ID, 'Ramiro Respaldo Lebrija', null, ['telefono' => null, 'geographic_unit_id' => $this->lebrija, 'municipio' => 'Lebrija']);
        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/actas/sugerencias", ['participantes' => [['nombre' => 'Ramiro Respaldo Giron', 'municipio' => null]]])
            ->assertOk()->assertJsonPath('data.participantes.0.id', $enGiron)->assertJsonPath('data.participantes.0.motivo', 'nombre');

        $org = (string) Str::uuid();
        DB::table('organizaciones')->insert([
            'id' => $org, 'campaign_id' => Campaign::DEFAULT_ID, 'tipo' => 'gremio', 'nombre' => 'Gremio Sin Municipio', 'municipio_id' => null,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $orgUser = $this->eventUser(Campaign::DEFAULT_ID, ['manage.empresarios', 'view.empresarios']);
        $url = "/empresarios/{$org}/actas/sugerencias";
        $this->actingAs($orgUser)->postJson($url, ['participantes' => [['nombre' => 'Ramiro Respaldo Lebrija', 'municipio' => null]]])
            ->assertOk()->assertJsonPath('data.participantes', []);
        $this->actingAs($orgUser)->postJson($url, ['municipio' => 'LEBRIJA', 'participantes' => [['nombre' => 'Ramiro Respaldo Lebrija', 'municipio' => null]]])
            ->assertOk()->assertJsonPath('data.participantes.0.id', $enLebrija);
    }

    // BUG-05: a phone added as a contact in Votantes completes the pending "Teléfono" of a ficha an acta created.
    public function test_a_phone_contact_clears_the_pending_telefono(): void
    {
        $actaId = $this->confirmedActa([['nombre' => 'FICHA CONTACTO PRUEBA', 'cedula' => '72727272']]);
        $ficha = $this->row($actaId, 'FICHA CONTACTO PRUEBA')->lider_id;
        $this->assertSame(['telefono'], json_decode(DB::table('lideres')->where('id', $ficha)->value('migracion_flags'), true)['por_completar']);
        $writer = $this->eventUser(Campaign::DEFAULT_ID, ['view.votantes', 'write.data', 'manage.eventos', 'view.eventos']);

        $this->actingAs($writer)->postJson("/votantes/{$ficha}/contactos", ['tipo' => 'email', 'valor' => 'ficha@prueba.test'])->assertCreated();
        $this->assertNotNull(DB::table('lideres')->where('id', $ficha)->value('migracion_flags'), 'An email is not a phone');
        $this->actingAs($writer)->postJson("/votantes/{$ficha}/contactos", ['tipo' => 'celular', 'valor' => '3001110013'])->assertCreated();
        $this->assertNull(DB::table('lideres')->where('id', $ficha)->value('migracion_flags'));
        $this->actingAs($this->editor)->get($this->url($actaId))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('acta.participantes.0.por_completar', [])->where('acta.indicadores.participantes_por_completar', 0));
    }

    // BUG-06: a Word table of one column does not take its heading as a person or a compromiso.
    public function test_a_one_column_word_table_skips_its_heading(): void
    {
        $w = fn (string $text) => '<w:p><w:r><w:t xml:space="preserve">'.htmlspecialchars($text, ENT_XML1).'</w:t></w:r></w:p>';
        $table = fn (array $rows) => '<w:tbl>'.implode('', array_map(fn ($r) => '<w:tr><w:tc>'.$w($r).'</w:tc></w:tr>', $rows)).'</w:tbl>';
        $body = $w('ACTA SOLO NOMBRES').$w('PARTICIPANTES').$table(['NOMBRE', 'Uno Solo Nombre', 'Dos Solo Nombre'])
            .$w('COMPROMISOS').$table(['COMPROMISOS:', 'Solo la descripción']);
        $path = tempnam(sys_get_temp_dir(), 'docx').'.docx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>');
        $zip->close();

        $acta = $this->actingAs($this->manager)->post("/eventos/{$this->event->id}/actas/leer", ['archivo' => new UploadedFile($path, 'acta.docx', null, null, true)], ['Accept' => 'application/json'])
            ->assertOk()->json('data.acta');
        $this->assertSame(['Uno Solo Nombre', 'Dos Solo Nombre'], array_column($acta['participantes'], 'nombre'));
        $this->assertSame(['Solo la descripción'], array_column($acta['compromisos'], 'descripcion'));
    }

    public function test_the_version_migration_is_reversible(): void
    {
        $migration = require database_path('migrations/2026_11_22_000001_add_version_to_event_actas.php');
        $actaId = $this->confirmedActa([]);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('event_actas', 'version'));
        $migration->up();
        $this->assertSame(0, (int) DB::table('event_actas')->where('id', $actaId)->value('version'));
    }
}
