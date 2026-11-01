<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOfflineOwner;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// X-Offline-Owner on the routes the offline queue syncs to: another account's queue gets 409 owner_mismatch and
// nothing is processed; the owner's queue and requests without the header behave as before. SQLite.
class OfflineOwnerTest extends TestCase
{
    use BuildsEventosScenario;

    private CampaignEvent $event;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEventos();
        $this->event = $this->makeEvent(Campaign::DEFAULT_ID, ['status' => 'borrador']);
        $this->operator = $this->eventUser(Campaign::DEFAULT_ID, ['register.asistentes'], 'R10_CONSULTA_EJECUTIVA');
    }

    // Same value as resources/js/lib/userKey.js.
    private function ownerKey(User $user): string
    {
        return hash('sha256', 'electoral:'.$user->id);
    }

    private function register(array $headers, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->operator)->withHeaders($headers)
            ->postJson("/eventos/{$this->event->id}/asistentes", $this->attendeePayload($this->provisionalText()->id));
    }

    public function test_the_owner_key_matches_the_frontend_formula(): void
    {
        $this->assertSame(
            [hash('sha256', 'electoral:'.$this->operator->id), 'id:'.$this->operator->id],
            EnsureOfflineOwner::keysFor($this->operator->id),
        );
    }

    public function test_the_owners_queue_is_processed(): void
    {
        $this->register(['X-Offline-Owner' => $this->ownerKey($this->operator)])->assertCreated();
        $this->register(['X-Offline-Owner' => strtoupper($this->ownerKey($this->operator))])->assertOk()->assertJsonPath('data.duplicate', true);
        $this->assertSame(1, DB::table('event_attendees')->count());
    }

    public function test_the_raw_id_key_of_browsers_without_webcrypto_is_accepted(): void
    {
        $this->register(['X-Offline-Owner' => 'id:'.$this->operator->id])->assertCreated();
    }

    public function test_without_the_header_nothing_changes(): void
    {
        $this->register([])->assertCreated();
    }

    public function test_another_accounts_queue_is_409_and_nothing_is_stored(): void
    {
        $other = $this->eventUser(Campaign::DEFAULT_ID, ['register.asistentes']);
        $auditBefore = DB::table('audit_logs')->count();

        foreach ([$this->ownerKey($other), 'id:'.$other->id, '', 'basura'] as $key) {
            $this->register(['X-Offline-Owner' => $key])->assertStatus(409)
                ->assertJsonPath('success', false)
                ->assertJsonPath('code', 'owner_mismatch')
                ->assertJsonPath('message', 'Estos cambios se guardaron sin conexión con otra cuenta. Inicie sesión con esa cuenta para enviarlos.');
        }

        $this->assertSame(0, DB::table('event_attendees')->count());
        $this->assertSame(0, DB::table('data_consents')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count(), 'A refused request is not audited');
    }

    public function test_the_owner_check_runs_before_permissions_so_the_queue_is_never_dropped_by_a_403(): void
    {
        $owner = $this->eventUser(Campaign::DEFAULT_ID, ['register.asistentes', 'view.mapa']);
        $current = $this->eventUser(Campaign::DEFAULT_ID, ['view.dashboard'], 'R10_CONSULTA_EJECUTIVA');
        $header = ['X-Offline-Owner' => $this->ownerKey($owner)];
        $id = (string) Str::uuid();

        $this->register($header, $current)->assertStatus(409)->assertJsonPath('code', 'owner_mismatch');
        $this->actingAs($current)->withHeaders($header)->postJson('/mapa-politico/crear-lider', ['nombre' => 'X'])
            ->assertStatus(409)->assertJsonPath('code', 'owner_mismatch');
        $this->actingAs($current)->withHeaders($header)->putJson("/mapa-politico/persona/{$id}", ['nombre' => 'X'])
            ->assertStatus(409)->assertJsonPath('code', 'owner_mismatch');
        $this->actingAs($current)->withHeaders($header)->postJson("/mapa-politico/persona/{$id}/nexos", [])
            ->assertStatus(409)->assertJsonPath('code', 'owner_mismatch');
        $this->actingAs($current)->withHeaders($header)->deleteJson("/mapa-politico/nexos/{$id}")
            ->assertStatus(409)->assertJsonPath('code', 'owner_mismatch');

        // With the right owner the request goes on to the usual checks (here: no view.mapa / no write role → 403).
        $own = ['X-Offline-Owner' => $this->ownerKey($current)];
        $this->actingAs($current)->withHeaders($own)->putJson("/mapa-politico/persona/{$id}", ['nombre' => 'X'])->assertForbidden();
        $this->actingAs($current)->withHeaders($own)->postJson("/eventos/{$this->event->id}/asistentes", [])->assertForbidden();
    }

    public function test_a_queue_of_another_account_in_another_campaign_is_409_not_404(): void
    {
        $other = $this->makeEvent($this->campY);
        $owner = $this->eventUser($this->campY, ['register.asistentes']);

        $this->actingAs($this->operator)->withHeaders(['X-Offline-Owner' => $this->ownerKey($owner)])
            ->postJson("/eventos/{$other->id}/asistentes", $this->attendeePayload($this->provisionalText()->id))
            ->assertStatus(409)->assertJsonPath('code', 'owner_mismatch');
    }

    public function test_without_a_session_the_login_check_comes_first(): void
    {
        $this->withHeaders(['X-Offline-Owner' => 'x'])->postJson("/eventos/{$this->event->id}/asistentes", [])->assertUnauthorized();
    }

    public function test_the_planilla_allows_10_downloads_per_minute_per_user(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->actingAs($this->operator)->get("/eventos/{$this->event->id}/planilla.pdf?paginas=1")->assertOk();
        }
        $this->actingAs($this->operator)->get("/eventos/{$this->event->id}/planilla.pdf?paginas=1")
            ->assertStatus(429)->assertHeader('Retry-After');

        $other = $this->eventUser(Campaign::DEFAULT_ID, ['register.asistentes']);
        $this->actingAs($other)->get("/eventos/{$this->event->id}/planilla.pdf?paginas=1")->assertOk();

        $this->assertSame(11, DB::table('audit_logs')->where('action', 'EVENTO_PLANILLA_DESCARGADA')->count());
    }
}
