<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// Event types created from the event form: in the own campaign, no duplicates by name, invisible to other campaigns. SQLite.
class EventTypeCreateTest extends TestCase
{
    use BuildsEventosScenario;

    private User $cdManager;

    private User $xManager;

    private User $yManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEventos();
        $this->cdManager = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos']);
        $this->xManager = $this->eventUser($this->campX, ['manage.eventos']);
        $this->yManager = $this->eventUser($this->campY, ['manage.eventos']);
    }

    private function eventPayload(string $typeId): array
    {
        return [
            'nombre' => 'Evento de prueba', 'event_type_id' => $typeId, 'starts_at' => '2026-11-20 18:00',
            'sitio_encuentro' => 'Parque principal', 'municipio_id' => $this->giron,
        ];
    }

    private function formTipos(User $user): array
    {
        return collect($this->actingAs($user)->get('/eventos')->assertOk()->viewData('page')['props']['tipos'])
            ->where('usable', true)->pluck('nombre')->all();
    }

    public function test_creates_a_type_in_the_own_campaign_and_it_can_be_used(): void
    {
        $response = $this->actingAs($this->xManager)->postJson('/eventos/tipos', ['nombre' => '  Caravana   motorizada ', 'campaign_id' => Campaign::DEFAULT_ID])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('created', true)
            ->assertJsonPath('data.nombre', 'Caravana motorizada');

        $id = $response->json('data.id');
        $this->assertSame($this->campX, DB::table('event_types')->where('id', $id)->value('campaign_id'));
        $this->assertContains('Caravana motorizada', $this->formTipos($this->xManager));
        $this->actingAs($this->xManager)->postJson('/eventos', $this->eventPayload($id))->assertCreated();

        $audit = DB::table('audit_logs')->where('action', 'POST eventos.tipos.store')->first();
        $this->assertNotNull($audit);
        $this->assertSame($this->xManager->id, $audit->user_id);
        $this->assertSame($id, json_decode($audit->after_state, true)['event_type_id']);
    }

    public function test_a_duplicate_name_returns_the_existing_type_ignoring_case_and_accents(): void
    {
        $global = DB::table('event_types')->where('nombre', 'Reunión')->value('id');
        $this->actingAs($this->xManager)->postJson('/eventos/tipos', ['nombre' => 'REUNION'])
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('data.id', $global);

        $own = $this->actingAs($this->xManager)->postJson('/eventos/tipos', ['nombre' => 'Cabalgata'])->assertCreated()->json('data.id');
        $this->actingAs($this->xManager)->postJson('/eventos/tipos', ['nombre' => 'cabalgáta'])
            ->assertOk()->assertJsonPath('data.id', $own);

        $this->assertSame(1, DB::table('event_types')->where('nombre', 'Cabalgata')->count());
        $this->assertSame(5, DB::table('event_types')->count());
    }

    public function test_another_campaign_neither_sees_nor_uses_the_type_and_creates_its_own(): void
    {
        $xType = $this->actingAs($this->xManager)->postJson('/eventos/tipos', ['nombre' => 'Cabalgata'])->assertCreated()->json('data.id');

        $this->assertNotContains('Cabalgata', $this->formTipos($this->yManager));
        $this->actingAs($this->yManager)->postJson('/eventos', $this->eventPayload($xType))
            ->assertStatus(422)->assertJsonValidationErrors('event_type_id');

        // The parent campaign sees the subcampaign's type in the filter, but cannot use it in its own events.
        $this->assertNotContains('Cabalgata', $this->formTipos($this->cdManager));
        $this->actingAs($this->cdManager)->postJson('/eventos', $this->eventPayload($xType))
            ->assertStatus(422)->assertJsonValidationErrors('event_type_id');

        $yType = $this->actingAs($this->yManager)->postJson('/eventos/tipos', ['nombre' => 'Cabalgata'])->assertCreated()->json('data.id');
        $this->assertNotSame($xType, $yType);
        $this->assertSame($this->campY, DB::table('event_types')->where('id', $yType)->value('campaign_id'));
    }

    public function test_an_inactive_type_is_neither_listed_nor_accepted(): void
    {
        $id = $this->actingAs($this->xManager)->postJson('/eventos/tipos', ['nombre' => 'Cabalgata'])->json('data.id');
        DB::table('event_types')->where('id', $id)->update(['is_active' => false]);

        $this->assertNotContains('Cabalgata', $this->formTipos($this->xManager));
        $this->actingAs($this->xManager)->postJson('/eventos', $this->eventPayload($id))
            ->assertStatus(422)->assertJsonValidationErrors('event_type_id');
    }

    public function test_the_name_is_required_and_at_most_80_characters(): void
    {
        $this->actingAs($this->xManager)->postJson('/eventos/tipos', ['nombre' => '   '])
            ->assertStatus(422)->assertJsonPath('errors.nombre.0', 'Escriba el nombre del tipo de evento.');
        $this->actingAs($this->xManager)->postJson('/eventos/tipos', ['nombre' => str_repeat('a', 81)])
            ->assertStatus(422)->assertJsonValidationErrors('nombre');
        $this->actingAs($this->xManager)->postJson('/eventos/tipos', ['nombre' => str_repeat('a', 80)])->assertCreated();
    }

    public function test_without_manage_eventos_is_403(): void
    {
        foreach ([['view.eventos'], ['register.asistentes'], ['view.mapa', 'write.data']] as $permissions) {
            $user = $this->eventUser(Campaign::DEFAULT_ID, $permissions);
            $this->actingAs($user)->postJson('/eventos/tipos', ['nombre' => 'Cabalgata'])->assertForbidden();
        }
        $this->assertSame(4, DB::table('event_types')->count());
    }
}
