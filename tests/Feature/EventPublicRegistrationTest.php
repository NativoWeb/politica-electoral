<?php

namespace Tests\Feature;

use App\Http\Controllers\EventoPublicoController;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// Public registration by QR (/e/{token}): same answer whatever happened to the cédula, nothing becomes a
// lider without approval, no data authorization asked since 2026-11-07 (no consent text needed, no data_consents
// row), bots and closed events stopped. SQLite.
class EventPublicRegistrationTest extends TestCase
{
    use BuildsEventosScenario;

    private CampaignEvent $event;

    // No approved consent text anywhere: only the provisional one the migration seeds.
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEventos();
        $this->event = $this->makeEvent(Campaign::DEFAULT_ID);
    }

    private function submit(array $overrides = [], ?CampaignEvent $event = null, string $ip = '10.0.0.1')
    {
        $event ??= $this->event;

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson("/e/{$event->public_token}", $this->publicPayload($event, null, $overrides));
    }

    private function assertThanks($response): void
    {
        $response->assertOk()->assertExactJson(['success' => true, 'message' => EventoPublicoController::THANKS]);
    }

    public function test_the_public_page_renders_the_form_without_an_approved_text(): void
    {
        $this->assertFalse((bool) DB::table('consent_texts')->where('approved', true)->exists());
        $this->get("/e/{$this->event->public_token}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Eventos/Publico')
                ->where('estado', 'abierto')
                ->missing('consent')
                ->where('evento.nombre', 'Gira Girón')
                ->where('evento.municipio', 'Girón')
                ->where('evento.provincia', 'Área Metropolitana')
                ->has('form_token')
                ->has('client_uuid')
                ->where('submitted', false));
    }

    public function test_an_existing_cedula_is_linked_without_touching_the_lider(): void
    {
        $liderId = $this->lider(Campaign::DEFAULT_ID, 'Ana Existente', '1098765432');
        $before = (array) DB::table('lideres')->where('id', $liderId)->first();

        $this->assertThanks($this->submit(['barrio' => 'Otro barrio', 'celular' => '3119998877']));

        $attendee = DB::table('event_attendees')->where('event_id', $this->event->id)->first();
        $this->assertSame($liderId, $attendee->lider_id);
        $this->assertSame('vinculado', $attendee->link_status);
        $this->assertSame('qr', $attendee->source);
        $this->assertSame($before, (array) DB::table('lideres')->where('id', $liderId)->first());
        $this->assertSame(1, DB::table('lideres')->count());
    }

    public function test_a_new_cedula_stays_pending_without_any_consent_record(): void
    {
        $this->assertThanks($this->submit());

        $attendee = DB::table('event_attendees')->where('event_id', $this->event->id)->first();
        $this->assertSame('pendiente', $attendee->link_status);
        $this->assertNull($attendee->lider_id);
        $this->assertSame(Campaign::DEFAULT_ID, $attendee->campaign_id);
        $this->assertSame('3001234567', $attendee->celular);
        $this->assertSame('el poblado', $attendee->barrio_normalizado);
        $this->assertSame(0, DB::table('lideres')->count());
        $this->assertFalse((bool) $attendee->autoriza_datos);
        $this->assertNull($attendee->consent_id);
        $this->assertSame(0, DB::table('data_consents')->count());
    }

    public function test_repeating_a_registration_answers_the_same_and_does_not_duplicate(): void
    {
        $payload = ['client_uuid' => (string) Str::uuid()];
        $this->assertThanks($this->submit($payload));
        $this->assertThanks($this->submit($payload));
        $this->assertThanks($this->submit(['cedula' => '1.098.765.432']));
        $this->assertThanks($this->submit(['cedula' => '1098765432', 'nombres' => 'Otro nombre']));

        $this->assertSame(1, DB::table('event_attendees')->count());
        $this->assertSame(0, DB::table('data_consents')->count());
        $this->assertSame('1098765432', DB::table('event_attendees')->value('cedula'));
    }

    public function test_no_authorization_is_asked_and_the_old_consent_fields_are_ignored(): void
    {
        $this->submit(['nombres' => ['x']])->assertStatus(422)->assertJsonPath('errors.nombres.0', 'El campo nombres debe ser un texto.')
            ->assertJsonMissingValidationErrors(['autoriza_datos', 'consent_text_id']);
        $this->assertThanks($this->submit(['autoriza_datos' => false, 'cedula' => '11111111']));
        $this->assertThanks($this->submit(['autoriza_datos' => null, 'consent_text_id' => 'no-es-uuid', 'cedula' => '22222222']));
        $this->assertThanks($this->submit(['consent_text_id' => $this->provisionalText()->id, 'autoriza_datos' => true, 'cedula' => '33333333']));

        $this->assertSame(['pendiente'], DB::table('event_attendees')->distinct()->pluck('link_status')->all());
        $this->assertSame(3, DB::table('event_attendees')->where('autoriza_datos', false)->whereNull('consent_id')->count());
        $this->assertSame(0, DB::table('data_consents')->count());
    }

    public function test_required_fields_and_age_are_validated(): void
    {
        $this->submit(['nombres' => '', 'apellidos' => '', 'cedula' => 'abc', 'celular' => '12345', 'barrio' => ' '])
            ->assertStatus(422)->assertJsonValidationErrors(['nombres', 'apellidos', 'cedula', 'celular', 'barrio']);
        $this->submit(['fecha_nacimiento' => now()->subYears(17)->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('fecha_nacimiento');

        $this->assertThanks($this->submit(['fecha_nacimiento' => '1980-04-02', 'fijo' => '607 654 3210', 'direccion' => 'Cra 1 # 2-3']));
        $this->assertSame('6076543210', DB::table('event_attendees')->value('fijo'));
    }

    public function test_a_closed_cancelled_draft_or_expired_event_answers_410(): void
    {
        foreach (['cerrado', 'cancelado', 'borrador'] as $status) {
            $event = $this->makeEvent(Campaign::DEFAULT_ID, ['status' => $status]);
            $this->submit([], $event)->assertStatus(410);
            $this->get("/e/{$event->public_token}")->assertStatus(410)
                ->assertInertia(fn (AssertableInertia $page) => $page->component('Eventos/Publico')
                    ->where('estado', 'cerrado')->missing('consent')->where('form_token', null));
        }

        $expired = $this->makeEvent(Campaign::DEFAULT_ID, ['registration_closes_at' => now()->subMinute()]);
        $this->submit([], $expired)->assertStatus(410);

        $past = $this->makeEvent(Campaign::DEFAULT_ID, ['starts_at' => now()->subDays(2)]);
        $this->submit([], $past)->assertStatus(410);

        $this->assertSame(0, DB::table('event_attendees')->count());
    }

    public function test_an_open_event_receives_registrations_even_without_any_consent_text(): void
    {
        DB::table('consent_texts')->update(['is_active' => false]);

        $this->get("/e/{$this->event->public_token}")->assertOk();
        $this->assertThanks($this->submit());
        $this->assertSame(1, DB::table('event_attendees')->count());
    }

    public function test_an_unknown_or_deleted_token_is_404(): void
    {
        $this->postJson('/e/'.Str::random(43), $this->publicPayload($this->event))->assertNotFound();
        $this->get('/e/'.Str::random(43))->assertNotFound();

        $this->event->delete();
        $this->get("/e/{$this->event->public_token}")->assertNotFound();
        $this->submit()->assertNotFound();
    }

    public function test_the_61st_submission_in_a_minute_from_one_ip_is_429(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->submit(['cedula' => (string) (50000000 + $i)], null, '10.0.0.9')->assertOk();
        }

        $this->submit(['cedula' => '59999999'], null, '10.0.0.9')->assertStatus(429);
        $this->submit(['cedula' => '59999998'], null, '10.0.0.10')->assertOk();
        $this->assertSame(61, DB::table('event_attendees')->count());
    }

    public function test_bot_traffic_does_not_spend_the_event_quota(): void
    {
        for ($i = 0; $i < 600; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.1.'.intdiv($i, 50).'.'.($i % 50)])
                ->postJson("/e/{$this->event->public_token}", ['website' => 'bot'])->assertOk();
        }
        $this->submit(['nombres' => ''], null, '10.2.0.2')->assertStatus(422);

        $this->assertThanks($this->submit([], null, '10.2.0.1'));
        $this->assertSame(1, DB::table('event_attendees')->count());
        $this->assertSame(1, RateLimiter::attempts(EventoPublicoController::quotaKey($this->event)));
    }

    public function test_real_registrations_above_the_event_quota_are_429(): void
    {
        Log::spy();
        $key = EventoPublicoController::quotaKey($this->event);
        RateLimiter::increment($key, 3600, 2399);

        $this->assertThanks($this->submit(['cedula' => '70000001'], null, '10.3.0.1'));
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, 'cerca del tope'));

        RateLimiter::increment($key, 3600, EventoPublicoController::EVENT_HOURLY_LIMIT - 2400);
        $this->submit(['cedula' => '70000002'], null, '10.3.0.2')->assertStatus(429)->assertHeader('Retry-After');
        $this->assertThanks($this->submit(['website' => 'bot'], null, '10.3.0.3'));

        $this->assertSame(1, DB::table('event_attendees')->count());
        $other = $this->makeEvent(Campaign::DEFAULT_ID);
        $this->assertThanks($this->submit(['cedula' => '70000003'], $other, '10.3.0.4'));
    }

    public function test_an_expired_form_token_is_treated_as_a_bot(): void
    {
        $this->assertThanks($this->submit(['form_token' => $this->formToken($this->event, 6 * 3600 + 5)]));
        $this->assertSame(0, DB::table('event_attendees')->count());

        $this->assertThanks($this->submit(['form_token' => $this->formToken($this->event, 6 * 3600 - 60)]));
        $this->assertSame(1, DB::table('event_attendees')->count());
    }

    public function test_bots_get_the_same_answer_and_nothing_is_stored(): void
    {
        $this->assertThanks($this->submit(['website' => 'http://spam.example']));
        $this->assertThanks($this->submit(['form_token' => $this->formToken($this->event, 1)]));
        $this->assertThanks($this->submit(['form_token' => 'manipulado']));
        $this->assertThanks($this->submit(['form_token' => null]));
        $other = $this->makeEvent(Campaign::DEFAULT_ID);
        $this->assertThanks($this->submit(['form_token' => $this->formToken($other)]));

        $this->assertSame(0, DB::table('event_attendees')->count());
        $this->assertSame(0, DB::table('data_consents')->count());
    }

    public function test_an_old_form_with_a_consent_text_that_changed_still_registers(): void
    {
        $this->assertThanks($this->submit(['consent_text_id' => (string) Str::uuid(), 'autoriza_datos' => true]));
        $this->assertSame(1, DB::table('event_attendees')->count());
        $this->assertSame(0, DB::table('data_consents')->count());
    }

    public function test_the_audit_trail_has_no_user_and_no_cedula(): void
    {
        $this->assertThanks($this->submit());
        $this->assertThanks($this->submit());

        $logs = DB::table('audit_logs')->get();
        $this->assertCount(2, $logs);
        foreach ($logs as $log) {
            $this->assertNull($log->user_id);
            $this->assertSame('EVENTO_ASISTENTE_QR', $log->action);
            $this->assertSame('evento_asistente', $log->entity_type);
            $this->assertStringNotContainsString('1098765432', $log->metadata);
            $this->assertStringNotContainsString('5432', $log->metadata);
        }
        $this->assertSame(['nuevo', 'repetido'], $logs->map(fn ($l) => json_decode($l->metadata, true)['resultado'])->all());
    }

    public function test_a_subcampaign_event_keeps_everything_in_the_subcampaign(): void
    {
        $event = $this->makeEvent($this->campX);
        $cdLider = $this->lider(Campaign::DEFAULT_ID, 'Ana del CD', '1098765432');
        $xLider = $this->lider($this->campX, 'Beto de X', '222333444');

        $this->assertThanks($this->submit([], $event));
        $this->assertThanks($this->submit(['cedula' => '222.333.444', 'nombres' => 'Beto'], $event));

        $attendees = DB::table('event_attendees')->orderBy('nombres')->get()->keyBy('cedula');
        $this->assertSame($this->campX, $attendees['1098765432']->campaign_id);
        $this->assertSame('pendiente', $attendees['1098765432']->link_status, 'A lider of the parent campaign is not linked');
        $this->assertNull($attendees['1098765432']->lider_id);
        $this->assertSame($xLider, $attendees['222333444']->lider_id);
        $this->assertSame('vinculado', $attendees['222333444']->link_status);
        $this->assertSame(0, DB::table('data_consents')->count());
        $this->assertNotNull(DB::table('lideres')->where('id', $cdLider)->value('id'));
    }

    public function test_an_inertia_submission_renders_the_thanks_page(): void
    {
        $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
            ->post("/e/{$this->event->public_token}", $this->publicPayload($this->event))
            ->assertOk()
            ->assertJsonPath('component', 'Eventos/Publico')
            ->assertJsonPath('props.submitted', true)
            ->assertJsonPath('props.message', EventoPublicoController::THANKS);

        $this->assertSame(1, DB::table('event_attendees')->count());
    }
}
