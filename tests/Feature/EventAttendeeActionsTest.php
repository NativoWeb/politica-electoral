<?php

namespace Tests\Feature;

use App\Http\Requests\Eventos\AttendeeInput;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// Actions on registered attendees (edit, recover, unlink, delete) and "Deshacer carga" of a confirmed Excel load.
// SQLite.
class EventAttendeeActionsTest extends TestCase
{
    use BuildsEventosScenario;

    private CampaignEvent $event;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEventos();
        $this->addMapaVotantesTables();
        Storage::fake('local');
        $this->event = $this->makeEvent(Campaign::DEFAULT_ID);
        $this->manager = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos']);
    }

    private function attendee(array $extra = [], ?CampaignEvent $event = null): string
    {
        $event ??= $this->event;
        $id = (string) Str::uuid();
        $cedula = $extra['cedula'] ?? (string) random_int(10000000, 99999999);
        DB::table('event_attendees')->insert(array_merge([
            'id' => $id, 'event_id' => $event->id, 'campaign_id' => $event->campaign_id, 'lider_id' => null,
            'nombres' => 'Ana', 'apellidos' => 'Pérez', 'cedula' => $cedula, 'celular' => '3001234567',
            'barrio' => 'Centro', 'barrio_normalizado' => 'centro', 'source' => 'operador', 'link_status' => 'pendiente',
            'client_uuid' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
        ], $extra));

        return $id;
    }

    private function confirmedImport(): string
    {
        $id = (string) Str::uuid();
        $path = "eventos/importaciones/{$this->event->id}/{$id}.xlsx";
        Storage::disk('local')->put($path, 'xlsx');
        DB::table('event_imports')->insert([
            'id' => $id, 'event_id' => $this->event->id, 'campaign_id' => $this->event->campaign_id, 'original_name' => 'lista.xlsx',
            'file_path' => $path, 'file_sha256' => hash('sha256', $id), 'headers' => '[]', 'status' => 'confirmado',
            'total_rows' => 3, 'created_by' => $this->manager->id, 'confirmed_at' => now(), 'kind' => 'excel',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function editPayload(array $overrides = []): array
    {
        return array_merge([
            'nombres' => 'Ana Lucía', 'apellidos' => 'Pérez Ruiz', 'cedula' => '1098765432', 'celular' => '3109876543',
            'fijo' => '6076543210', 'fecha_nacimiento' => '1990-05-06', 'direccion' => 'Cra 1 # 2-3', 'barrio' => '  San   Rafael ',
            'nota' => 'Junta de acción comunal',
        ], $overrides);
    }

    private function approve(array $ids): void
    {
        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/asistentes/aprobar", ['ids' => $ids])->assertOk();
    }

    public function test_editing_a_pending_attendee_saves_only_the_attendee(): void
    {
        $lider = $this->lider(Campaign::DEFAULT_ID, 'Ficha Original', '1098765432');
        $fichaBefore = (array) DB::table('lideres')->where('id', $lider)->first();
        $id = $this->attendee(['cedula' => '12345678']);

        $this->actingAs($this->manager)->putJson("/eventos/{$this->event->id}/asistentes/{$id}", $this->editPayload())
            ->assertOk()->assertJsonPath('success', true);

        $row = DB::table('event_attendees')->where('id', $id)->first();
        $this->assertSame('Ana Lucía', $row->nombres);
        $this->assertSame('1098765432', $row->cedula);
        $this->assertSame('3109876543', $row->celular);
        $this->assertSame('San Rafael', $row->barrio);
        $this->assertSame(AttendeeInput::barrioKey('San Rafael'), $row->barrio_normalizado);
        $this->assertSame('Junta de acción comunal', $row->nota);
        $this->assertSame('pendiente', $row->link_status);
        $this->assertNull($row->lider_id, 'Editing never links or creates a ficha');
        $this->assertSame($fichaBefore, (array) DB::table('lideres')->where('id', $lider)->first(), 'A ficha with that cédula is not changed');
        $this->assertSame(1, DB::table('lideres')->count());

        $log = DB::table('audit_logs')->where('action', 'like', 'PUT eventos.asistentes.update')->latest('created_at')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('Lucía', $log->metadata.$log->after_state);
        $this->assertStringNotContainsString('Rafael', $log->metadata.$log->after_state);
    }

    public function test_editing_rejects_a_minor_a_bad_phone_and_a_cedula_of_another_attendee(): void
    {
        $id = $this->attendee();
        $this->attendee(['cedula' => '55555555']);
        $url = "/eventos/{$this->event->id}/asistentes/{$id}";

        $this->actingAs($this->manager)->putJson($url, $this->editPayload(['fecha_nacimiento' => now()->subYears(10)->toDateString()]))
            ->assertUnprocessable()->assertJsonValidationErrors(['fecha_nacimiento' => 'mayores de 14']);
        $this->actingAs($this->manager)->putJson($url, $this->editPayload(['celular' => '12345']))
            ->assertUnprocessable()->assertJsonValidationErrors('celular');
        $this->actingAs($this->manager)->putJson($url, $this->editPayload(['cedula' => '55.555.555']))
            ->assertUnprocessable()->assertJsonValidationErrors(['cedula' => 'ya está registrada']);
        $this->actingAs($this->manager)->putJson($url, $this->editPayload(['nombres' => '']))
            ->assertUnprocessable()->assertJsonValidationErrors('nombres');
        $this->assertSame('Ana', DB::table('event_attendees')->where('id', $id)->value('nombres'));
    }

    public function test_an_approved_or_linked_attendee_cannot_be_edited(): void
    {
        $approved = $this->attendee(['cedula' => '70000010']);
        $this->approve([$approved]);
        $ficha = DB::table('event_attendees')->where('id', $approved)->value('lider_id');
        $existing = $this->lider(Campaign::DEFAULT_ID, 'Ya estaba', '70000011');
        $linked = $this->attendee(['cedula' => '70000011', 'lider_id' => $existing, 'link_status' => 'vinculado']);
        $fichas = DB::table('lideres')->orderBy('id')->get()->map(fn ($l) => (array) $l)->all();

        foreach ([$approved => '70000010', $linked => '70000011'] as $id => $cedula) {
            $this->actingAs($this->manager)->putJson("/eventos/{$this->event->id}/asistentes/{$id}", $this->editPayload(['cedula' => $cedula]))
                ->assertUnprocessable()->assertJsonValidationErrors(['asistente' => 'ya está en Votantes']);
            $this->assertSame('Ana', DB::table('event_attendees')->where('id', $id)->value('nombres'));
        }
        $this->assertSame($fichas, DB::table('lideres')->orderBy('id')->get()->map(fn ($l) => (array) $l)->all());
        $this->assertNotNull($ficha);
    }

    public function test_an_attendee_without_cedula_can_be_edited_without_inventing_one(): void
    {
        $id = $this->attendee(['cedula' => null, 'link_status' => 'sin_cedula']);

        $this->actingAs($this->manager)->putJson("/eventos/{$this->event->id}/asistentes/{$id}", $this->editPayload(['cedula' => null]))
            ->assertOk();
        $this->assertNull(DB::table('event_attendees')->where('id', $id)->value('cedula'));
    }

    public function test_a_discarded_attendee_is_recovered_as_pending_and_others_are_skipped(): void
    {
        $discarded = $this->attendee(['link_status' => 'descartado']);
        $pending = $this->attendee();

        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/asistentes/recuperar", ['ids' => [$discarded, $pending]])
            ->assertOk()->assertJsonPath('data', ['recuperados' => 1, 'omitidos' => 1]);
        $this->assertSame('pendiente', DB::table('event_attendees')->where('id', $discarded)->value('link_status'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'EVENTO_ASISTENTES_RECUPERADOS')->count());
    }

    public function test_unlinking_returns_to_pending_and_keeps_the_ficha(): void
    {
        $approved = $this->attendee(['cedula' => '70000001']);
        $this->approve([$approved]);
        $ficha = DB::table('event_attendees')->where('id', $approved)->value('lider_id');
        $existing = $this->lider(Campaign::DEFAULT_ID, 'Ya estaba', '70000002');
        $linked = $this->attendee(['cedula' => '70000002', 'lider_id' => $existing, 'link_status' => 'vinculado']);

        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/asistentes/desvincular", ['ids' => [$approved, $linked]])
            ->assertOk()->assertJsonPath('data', ['desvinculados' => 2, 'omitidos' => 0]);

        foreach ([$approved, $linked] as $id) {
            $row = DB::table('event_attendees')->where('id', $id)->first();
            $this->assertSame('pendiente', $row->link_status);
            $this->assertNull($row->lider_id);
        }
        $this->assertTrue(DB::table('lideres')->where('id', $ficha)->exists());
        $this->assertTrue(DB::table('lideres')->where('id', $existing)->exists());
    }

    public function test_deleting_an_approved_attendee_keeps_its_ficha(): void
    {
        $id = $this->attendee(['cedula' => '70000003']);
        $this->approve([$id]);
        $ficha = DB::table('event_attendees')->where('id', $id)->value('lider_id');

        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/asistentes/eliminar", ['ids' => [$id]])
            ->assertOk()->assertJsonPath('data', ['eliminados' => 1, 'conservados' => 0]);

        $this->assertFalse(DB::table('event_attendees')->where('id', $id)->exists());
        $this->assertTrue(DB::table('lideres')->where('id', $ficha)->exists());
        $log = DB::table('audit_logs')->where('action', 'EVENTO_ASISTENTES_ELIMINADOS')->first();
        $this->assertStringContainsString($id, $log->metadata);
    }

    public function test_undoing_a_load_deletes_its_attendees_and_only_the_fichas_without_other_activity(): void
    {
        $import = $this->confirmedImport();
        $plain = $this->attendee(['cedula' => '80000001', 'source' => 'excel', 'import_id' => $import]);
        $withFijo = $this->attendee(['cedula' => '80000002', 'source' => 'excel', 'import_id' => $import, 'fijo' => '6071234567']);
        $edited = $this->attendee(['cedula' => '80000003', 'source' => 'excel', 'import_id' => $import]);
        $otherEvent = $this->attendee(['cedula' => '80000004', 'source' => 'excel', 'import_id' => $import]);
        $withNexo = $this->attendee(['cedula' => '80000005', 'source' => 'excel', 'import_id' => $import]);
        $extraContact = $this->attendee(['cedula' => '80000006', 'source' => 'excel', 'import_id' => $import]);
        $existing = $this->lider(Campaign::DEFAULT_ID, 'Ya estaba', '80000007');
        $linked = $this->attendee(['cedula' => '80000007', 'source' => 'excel', 'import_id' => $import, 'lider_id' => $existing, 'link_status' => 'vinculado']);
        $notInLoad = $this->attendee(['cedula' => '80000008']);
        $this->approve([$plain, $withFijo, $edited, $otherEvent, $withNexo, $extraContact]);
        $ficha = fn ($id) => DB::table('event_attendees')->where('id', $id)->value('lider_id');
        [$fPlain, $fFijo, $fEdited, $fOther, $fNexo, $fContact] = array_map($ficha, [$plain, $withFijo, $edited, $otherEvent, $withNexo, $extraContact]);

        $this->travel(5)->minutes();
        DB::table('lideres')->where('id', $fEdited)->update(['barrio' => 'Otro', 'updated_at' => now()]);
        $second = $this->makeEvent(Campaign::DEFAULT_ID, ['nombre' => 'Otro evento']);
        $this->attendee(['cedula' => '80000004', 'lider_id' => $fOther, 'link_status' => 'vinculado'], $second);
        DB::table('nexos_familiares')->insert(['id' => (string) Str::uuid(), 'campaign_id' => Campaign::DEFAULT_ID, 'person_id' => $fNexo, 'nombre' => 'Hijo']);
        DB::table('lider_contactos')->insert(['id' => (string) Str::uuid(), 'lider_id' => $fContact, 'tipo' => 'email', 'valor' => 'a@b.co']);

        $this->actingAs($this->manager)->getJson("/eventos/{$this->event->id}/cargas/{$import}/deshacer")->assertOk()
            ->assertJsonPath('data', ['asistentes' => 7, 'con_autorizacion' => 0, 'fichas_borrar' => 2, 'fichas_conservar' => 4, 'vinculadas' => 1]);

        $this->actingAs($this->manager)->deleteJson("/eventos/{$this->event->id}/cargas/{$import}")->assertOk()
            ->assertJsonPath('data.asistentes', 7)->assertJsonPath('data.fichas_borrar', 2)->assertJsonPath('data.fichas_conservar', 4);

        $this->assertSame(0, DB::table('event_attendees')->where('event_id', $this->event->id)->where('source', 'excel')->count());
        $this->assertTrue(DB::table('event_attendees')->where('id', $notInLoad)->exists());
        $this->assertFalse(DB::table('lideres')->where('id', $fPlain)->exists());
        $this->assertFalse(DB::table('lideres')->where('id', $fFijo)->exists());
        $this->assertSame(0, DB::table('lider_contactos')->where('lider_id', $fFijo)->count());
        foreach ([$fEdited, $fOther, $fNexo, $fContact, $existing] as $kept) {
            $this->assertTrue(DB::table('lideres')->where('id', $kept)->exists());
        }
        $this->assertSame('Ya estaba', DB::table('lideres')->where('id', $existing)->value('nombre'));
        $this->assertFalse(DB::table('event_imports')->where('id', $import)->exists(), 'The load goes away so the file can be uploaded again');
        Storage::disk('local')->assertMissing("eventos/importaciones/{$this->event->id}/{$import}.xlsx");

        $log = DB::table('audit_logs')->where('action', 'EVENTO_CARGA_DESHECHA')->first();
        $this->assertStringContainsString($import, $log->metadata);
        $this->assertStringNotContainsString('80000001', $log->metadata);
        $this->assertStringNotContainsString('Ana', $log->metadata);
    }

    public function test_the_detail_lists_confirmed_loads_only_to_who_can_edit(): void
    {
        $import = $this->confirmedImport();
        $this->attendee(['source' => 'excel', 'import_id' => $import]);

        $this->actingAs($this->manager)->get("/eventos/{$this->event->id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('cargas', 1)
                ->where('cargas.0.id', $import)->where('cargas.0.asistentes', 1)->where('cargas.0.created_by', $this->manager->name));

        $viewer = $this->eventUser(Campaign::DEFAULT_ID, ['view.eventos'], 'R10_CONSULTA_EJECUTIVA');
        $this->actingAs($viewer)->get("/eventos/{$this->event->id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('cargas', []));
    }

    public function test_another_campaign_and_users_without_manage_cannot_act(): void
    {
        $id = $this->attendee(['link_status' => 'descartado']);
        $import = $this->confirmedImport();
        $this->attendee(['source' => 'excel', 'import_id' => $import]);
        $requests = [
            ['putJson', "/eventos/{$this->event->id}/asistentes/{$id}", $this->editPayload()],
            ['postJson', "/eventos/{$this->event->id}/asistentes/recuperar", ['ids' => [$id]]],
            ['postJson', "/eventos/{$this->event->id}/asistentes/desvincular", ['ids' => [$id]]],
            ['postJson', "/eventos/{$this->event->id}/asistentes/eliminar", ['ids' => [$id]]],
            ['getJson', "/eventos/{$this->event->id}/cargas/{$import}/deshacer", []],
            ['deleteJson', "/eventos/{$this->event->id}/cargas/{$import}", []],
        ];

        $other = $this->eventUser($this->campY, ['manage.eventos']);
        $sub = $this->eventUser($this->campX, ['manage.eventos']);
        $viewer = $this->eventUser(Campaign::DEFAULT_ID, ['view.eventos', 'register.asistentes'], 'R10_CONSULTA_EJECUTIVA');
        $outside = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos'], 'R02_ADMIN_FUNCIONAL', ['municipios' => [$this->velez]]);
        foreach ($requests as [$method, $url, $body]) {
            $this->actingAs($other)->{$method}($url, $body)->assertNotFound();
            $this->actingAs($viewer)->{$method}($url, $body)->assertForbidden();
            $this->actingAs($outside)->{$method}($url, $body)->assertForbidden();
        }
        // A subcampaign does not see the parent's events; the parent sees the subcampaign's only to read (403).
        $this->actingAs($sub)->putJson("/eventos/{$this->event->id}/asistentes/{$id}", ['nombres' => ''])->assertNotFound();
        $subEvent = $this->makeEvent($this->campX, ['nombre' => 'Evento de la subcampaña']);
        $subAttendee = $this->attendee(['link_status' => 'descartado'], $subEvent);
        $this->actingAs($this->manager)->putJson("/eventos/{$subEvent->id}/asistentes/{$subAttendee}", ['nombres' => ''])->assertForbidden();
        $this->actingAs($this->manager)->postJson("/eventos/{$subEvent->id}/asistentes/recuperar", ['ids' => [$subAttendee]])->assertForbidden();
        $this->actingAs($this->manager)->get("/eventos/{$subEvent->id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.edit', false)->where('cargas', []));

        $this->assertSame('descartado', DB::table('event_attendees')->where('id', $id)->value('link_status'));
        $this->assertTrue(DB::table('event_imports')->where('id', $import)->exists());
    }

    public function test_an_attendee_of_another_event_or_a_load_not_confirmed_is_404(): void
    {
        $second = $this->makeEvent(Campaign::DEFAULT_ID, ['nombre' => 'Otro']);
        $foreign = $this->attendee([], $second);

        $this->actingAs($this->manager)->putJson("/eventos/{$this->event->id}/asistentes/{$foreign}", $this->editPayload())->assertNotFound();
        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/asistentes/eliminar", ['ids' => [$foreign]])
            ->assertUnprocessable()->assertJsonValidationErrors('ids');

        $import = $this->confirmedImport();
        DB::table('event_imports')->where('id', $import)->update(['status' => 'revisado']);
        $this->actingAs($this->manager)->deleteJson("/eventos/{$this->event->id}/cargas/{$import}")->assertNotFound();
        $this->assertTrue(DB::table('event_attendees')->where('id', $foreign)->exists());
    }
}
