<?php

namespace Tests\Feature;

use App\Actions\Eventos\ApproveAttendees;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// Operator mode (register.asistentes, outside can.write) and the approval of pending attendees into Votantes
// of the event's campaign. SQLite.
class EventOperatorTest extends TestCase
{
    use BuildsEventosScenario;

    private CampaignEvent $event;

    private User $operator;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEventos();
        $this->event = $this->makeEvent(Campaign::DEFAULT_ID, ['status' => 'borrador']);
        // R10 is not a write role: the operator routes must not depend on can.write.
        $this->operator = $this->eventUser(Campaign::DEFAULT_ID, ['register.asistentes'], 'R10_CONSULTA_EJECUTIVA');
        $this->manager = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos']);
    }

    private function register(array $overrides = [], ?CampaignEvent $event = null, ?User $user = null)
    {
        $event ??= $this->event;

        return $this->actingAs($user ?? $this->operator)
            ->postJson("/eventos/{$event->id}/asistentes", $this->attendeePayload($this->provisionalText()->id, $overrides));
    }

    public function test_an_operator_registers_with_the_provisional_text_but_cannot_manage_events(): void
    {
        $response = $this->register()->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.duplicate', false);

        $attendee = DB::table('event_attendees')->where('id', $response->json('data.id'))->first();
        $this->assertSame('operador', $attendee->source);
        $this->assertSame($this->operator->id, $attendee->registered_by);
        $this->assertSame('pendiente', $attendee->link_status);
        $consent = DB::table('data_consents')->first();
        $this->assertSame($this->provisionalText()->id, $consent->consent_text_id);
        $this->assertSame('operador', $consent->channel);
        $this->assertSame(0, DB::table('lideres')->count());

        $this->actingAs($this->operator)->postJson('/eventos', ['nombre' => 'X'])->assertForbidden();
        $this->actingAs($this->operator)->postJson("/eventos/{$this->event->id}/abrir")->assertForbidden();
        $this->actingAs($this->operator)->postJson("/eventos/{$this->event->id}/asistentes/aprobar", ['ids' => [$attendee->id]])->assertForbidden();
        $this->actingAs($this->operator)->getJson("/eventos/{$this->event->id}")->assertForbidden();
        $this->actingAs($this->operator)->get('/eventos')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Eventos/Index')
                ->where('eventos.data.0.asistentes_total', null)
                ->where('can', ['manage' => false, 'view' => false, 'register' => true]));
        $this->actingAs($this->operator)->get("/eventos/{$this->event->id}/operador")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Eventos/Operador')
                ->where('consent.approved', false)
                ->where('registrados', 1)
                ->where('evento.accepts_registrations', true)
                ->has('mis_registros', 1));
    }

    public function test_without_an_event_permission_the_operator_routes_are_closed(): void
    {
        $user = $this->eventUser(Campaign::DEFAULT_ID, ['view.mapa', 'view.eventos']);

        $this->register([], null, $user)->assertForbidden();
        $this->actingAs($user)->get("/eventos/{$this->event->id}/operador")->assertRedirect('/');
    }

    public function test_the_same_client_uuid_is_idempotent(): void
    {
        $payload = ['client_uuid' => (string) Str::uuid()];
        $id = $this->register($payload)->assertCreated()->json('data.id');

        $this->register($payload)->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.duplicate', true);
        $this->register(['cedula' => '1.098.765.432'])->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.duplicate', true);

        $this->assertSame(1, DB::table('event_attendees')->count());
        $this->assertSame(1, DB::table('data_consents')->count());
    }

    public function test_a_client_uuid_of_another_event_is_409(): void
    {
        $other = $this->makeEvent(Campaign::DEFAULT_ID, ['status' => 'borrador']);
        $payload = ['client_uuid' => (string) Str::uuid()];
        $this->register($payload)->assertCreated();

        $this->register($payload + ['cedula' => '55555555'], $other)->assertStatus(409);
        $this->assertSame(0, DB::table('event_attendees')->where('event_id', $other->id)->count());
    }

    public function test_closed_or_cancelled_events_do_not_take_registrations(): void
    {
        foreach (['cerrado', 'cancelado'] as $status) {
            $event = $this->makeEvent(Campaign::DEFAULT_ID, ['status' => $status]);
            $this->register([], $event)->assertStatus(410);
        }
        $this->assertSame(0, DB::table('event_attendees')->count());
    }

    public function test_operator_validation_errors(): void
    {
        $this->register(['autoriza_datos' => false, 'celular' => '123'])->assertStatus(422)
            ->assertJsonValidationErrors(['autoriza_datos', 'celular']);
        $this->register(['consent_text_id' => (string) Str::uuid()])->assertStatus(422)->assertJsonValidationErrors('consent_text_id');
        $this->register(['client_uuid' => 'no-es-uuid'])->assertStatus(422)->assertJsonValidationErrors('client_uuid');

        $this->assertSame(0, DB::table('event_attendees')->count());
    }

    public function test_approving_a_pending_attendee_creates_the_lider_in_the_event_campaign(): void
    {
        $id = $this->register(['fecha_nacimiento' => '1980-04-02', 'fijo' => '6076543210', 'direccion' => 'Cra 1 # 2-3'])->json('data.id');

        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/asistentes/aprobar", ['ids' => [$id]])
            ->assertOk()->assertJsonPath('data', ['aprobados' => 1, 'vinculados' => 0, 'omitidos' => 0]);

        $attendee = DB::table('event_attendees')->where('id', $id)->first();
        $this->assertSame('aprobado', $attendee->link_status);
        $lider = DB::table('lideres')->where('id', $attendee->lider_id)->first();
        $this->assertSame(Campaign::DEFAULT_ID, $lider->campaign_id);
        $this->assertSame(ApproveAttendees::TIPO, $lider->tipo);
        $this->assertSame('Ana María Pérez Gómez', $lider->nombre);
        $this->assertSame('1098765432', $lider->cedula);
        $this->assertSame('3001234567', $lider->telefono);
        $this->assertSame('Girón', $lider->municipio);
        $this->assertSame('Soto', $lider->provincia, 'Canonical province name, as CreateVotante stores it');
        $this->assertSame($this->giron, $lider->geographic_unit_id);
        $this->assertSame('El Poblado', $lider->barrio);
        $this->assertSame('1980-04-02', substr($lider->fecha_nacimiento, 0, 10));
        $this->assertEquals(0, $lider->autoriza_datos, 'Accepted over the provisional text: not an authorization in Votantes');
        $this->assertStringContainsString('Gira Girón', $lider->observacion);
        $this->assertSame('6076543210', DB::table('lider_contactos')->where('lider_id', $lider->id)->where('tipo', 'fijo')->value('valor'));

        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/asistentes/aprobar", ['ids' => [$id]])
            ->assertOk()->assertJsonPath('data', ['aprobados' => 0, 'vinculados' => 0, 'omitidos' => 1]);
        $this->assertSame(1, DB::table('lideres')->count());
    }

    public function test_only_a_consent_over_an_approved_text_authorizes_the_data_in_votantes(): void
    {
        $provisional = $this->register()->json('data.id');
        $text = $this->approvedText(Campaign::DEFAULT_ID);
        $approved = $this->actingAs($this->operator)->postJson("/eventos/{$this->event->id}/asistentes",
            $this->attendeePayload($text->id, ['cedula' => '22334455']))->assertCreated()->json('data.id');

        $this->actingAs($this->manager)->get("/eventos/{$this->event->id}")
            ->assertInertia(function (AssertableInertia $page) use ($provisional, $approved) {
                $flags = collect($page->toArray()['props']['asistentes']['data'])->pluck('consentimiento_provisional', 'id');
                $this->assertTrue($flags[$provisional]);
                $this->assertFalse($flags[$approved]);
            });

        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/asistentes/aprobar", ['ids' => [$provisional, $approved]])
            ->assertOk()->assertJsonPath('data.aprobados', 2);

        $flag = fn (string $id) => (bool) DB::table('lideres')
            ->where('id', DB::table('event_attendees')->where('id', $id)->value('lider_id'))->value('autoriza_datos');
        $this->assertFalse($flag($provisional));
        $this->assertTrue($flag($approved));
    }

    public function test_an_approved_lider_gets_the_province_as_the_platform_stores_it(): void
    {
        $area = $this->geo('province', 'Area Metropolitana');
        $bucaramanga = $this->geo('municipality', 'Bucaramanga', $area);
        $event = $this->makeEvent(Campaign::DEFAULT_ID, ['status' => 'borrador', 'municipio_id' => $bucaramanga, 'provincia_id' => $area]);
        $id = $this->register([], $event)->assertCreated()->json('data.id');

        $this->actingAs($this->manager)->postJson("/eventos/{$event->id}/asistentes/aprobar", ['ids' => [$id]])->assertOk();

        $lider = DB::table('lideres')->where('id', DB::table('event_attendees')->where('id', $id)->value('lider_id'))->first();
        $this->assertSame('Area Metropolitana', $lider->provincia);
        $this->assertSame('Bucaramanga', $lider->municipio);
    }

    public function test_approving_when_the_cedula_already_exists_only_links(): void
    {
        $id = $this->register()->json('data.id');
        $existing = $this->lider(Campaign::DEFAULT_ID, 'Ana Creada Después', '1098765432');
        $before = (array) DB::table('lideres')->where('id', $existing)->first();

        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/asistentes/aprobar", ['ids' => [$id]])
            ->assertOk()->assertJsonPath('data', ['aprobados' => 0, 'vinculados' => 1, 'omitidos' => 0]);

        $this->assertSame(1, DB::table('lideres')->count());
        $this->assertSame($before, (array) DB::table('lideres')->where('id', $existing)->first());
        $attendee = DB::table('event_attendees')->where('id', $id)->first();
        $this->assertSame('vinculado', $attendee->link_status);
        $this->assertSame($existing, $attendee->lider_id);
    }

    public function test_approving_in_a_subcampaign_event_creates_the_lider_there(): void
    {
        $xEvent = $this->makeEvent($this->campX, ['status' => 'borrador']);
        $xOperator = $this->eventUser($this->campX, ['register.asistentes'], 'R10_CONSULTA_EJECUTIVA');
        $xManager = $this->eventUser($this->campX, ['manage.eventos']);
        // Same cédula in the parent campaign: a subcampaign lider is a separate record.
        $this->lider(Campaign::DEFAULT_ID, 'Ana del CD', '1098765432');

        $id = $this->register([], $xEvent, $xOperator)->assertCreated()->json('data.id');
        $this->assertSame('pendiente', DB::table('event_attendees')->where('id', $id)->value('link_status'));

        $this->actingAs($this->manager)->postJson("/eventos/{$xEvent->id}/asistentes/aprobar", ['ids' => [$id]])->assertForbidden();
        $this->actingAs($xManager)->postJson("/eventos/{$xEvent->id}/asistentes/aprobar", ['ids' => [$id]])
            ->assertOk()->assertJsonPath('data.aprobados', 1);

        $liderId = DB::table('event_attendees')->where('id', $id)->value('lider_id');
        $this->assertSame($this->campX, DB::table('lideres')->where('id', $liderId)->value('campaign_id'));
        $this->assertSame(2, DB::table('lideres')->where('cedula', '1098765432')->count());
    }

    public function test_discarding_and_foreign_ids(): void
    {
        $a = $this->register()->json('data.id');
        $b = $this->register(['cedula' => '22334455'])->json('data.id');
        $other = $this->makeEvent(Campaign::DEFAULT_ID, ['status' => 'borrador']);
        $foreign = $this->register(['cedula' => '99887766'], $other)->json('data.id');

        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/asistentes/descartar", ['ids' => [$a, $foreign]])
            ->assertStatus(422)->assertJsonValidationErrors('ids');
        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/asistentes/descartar", ['ids' => [$a]])
            ->assertOk()->assertJsonPath('data', ['descartados' => 1, 'omitidos' => 0]);
        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/asistentes/aprobar", ['ids' => [$a, $b]])
            ->assertOk()->assertJsonPath('data', ['aprobados' => 1, 'vinculados' => 0, 'omitidos' => 1]);

        $this->assertSame('descartado', DB::table('event_attendees')->where('id', $a)->value('link_status'));
        $this->assertSame(1, DB::table('lideres')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'EVENTO_ASISTENTES_DESCARTADOS')->count());
    }

    public function test_show_lists_attendees_with_kpis(): void
    {
        $this->lider(Campaign::DEFAULT_ID, 'Ana Existente', '1098765432');
        $this->register();
        $this->register(['cedula' => '22334455', 'nombres' => 'Beto']);

        $this->actingAs($this->manager)->get("/eventos/{$this->event->id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Eventos/Show')
                ->where('kpis.total', 2)->where('kpis.vinculados', 1)->where('kpis.pendientes', 1)->where('kpis.operador', 2)
                ->where('asistentes.total', 2)
                ->where('can.edit', true)
                ->has('evento.public_url'));
        $this->actingAs($this->manager)->get("/eventos/{$this->event->id}?link_status=pendiente&q=beto")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('asistentes.total', 1)
                ->where('asistentes.data.0.cedula', '22334455'));
    }

    public function test_the_operator_request_is_audited_with_masked_data(): void
    {
        $this->register()->assertCreated();

        $explicit = DB::table('audit_logs')->where('action', 'EVENTO_ASISTENTE_OPERADOR')->first();
        $this->assertSame($this->operator->id, $explicit->user_id);
        $this->assertStringNotContainsString('1098765432', $explicit->metadata);

        $request = DB::table('audit_logs')->where('action', 'POST eventos.asistentes.store')->first();
        $this->assertSame('evento_asistente', $request->entity_type);
        $this->assertStringNotContainsString('1098765432', $request->metadata);
        $this->assertStringNotContainsString('3001234567', $request->metadata);
    }

    public function test_the_landline_and_the_other_personal_fields_are_masked_in_the_audit_log(): void
    {
        $this->register(['fijo' => '607 654 3210', 'direccion' => 'Cra 1 # 2-3', 'fecha_nacimiento' => '1980-04-02'])->assertCreated();

        $metadata = json_decode(DB::table('audit_logs')->where('action', 'POST eventos.asistentes.store')->value('metadata'), true);
        $this->assertSame('3210', $metadata['fijo_last4']);
        $this->assertArrayNotHasKey('fijo', $metadata);
        $this->assertSame('5432', $metadata['cedula_last4']);
        $this->assertSame('4567', $metadata['celular_last4']);
        $this->assertSame('[omitido]', $metadata['direccion']);
        $this->assertSame('[omitido]', $metadata['barrio']);
        $this->assertSame('[omitido]', $metadata['fecha_nacimiento']);
        foreach (['6076543210', '607 654 3210', 'Cra 1', '1980-04-02', 'El Poblado'] as $clear) {
            $this->assertStringNotContainsString($clear, json_encode($metadata, JSON_UNESCAPED_UNICODE));
        }
    }
}
