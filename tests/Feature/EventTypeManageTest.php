<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// Renaming and deleting event types: own campaign only, global ones only for the superadmin; a used type is deactivated. SQLite.
class EventTypeManageTest extends TestCase
{
    use BuildsEventosScenario;

    private User $xManager;

    private User $yManager;

    private string $global;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEventos();
        $this->xManager = $this->eventUser($this->campX, ['manage.eventos']);
        $this->yManager = $this->eventUser($this->campY, ['manage.eventos']);
        $this->global = DB::table('event_types')->where('nombre', 'Gira')->value('id');
    }

    private function ownType(User $user, string $nombre): string
    {
        return $this->actingAs($user)->postJson('/eventos/tipos', ['nombre' => $nombre])->assertCreated()->json('data.id');
    }

    private function superadmin(): User
    {
        $this->roles['R01_SUPERADMIN'] = Role::create(['code' => 'R01_SUPERADMIN', 'name' => 'Superadmin', 'level' => 1])->id;

        return $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos'], 'R01_SUPERADMIN');
    }

    private function formTipos(User $user): array
    {
        return collect($this->actingAs($user)->get('/eventos')->assertOk()->viewData('page')['props']['tipos'])->keyBy('nombre')->all();
    }

    public function test_renames_an_own_type_and_audits_it(): void
    {
        $id = $this->ownType($this->xManager, 'Cabalgata');

        $this->actingAs($this->xManager)->putJson("/eventos/tipos/{$id}", ['nombre' => '  Cabalgata   rural '])
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.nombre', 'Cabalgata rural');

        $this->assertSame('Cabalgata rural', DB::table('event_types')->where('id', $id)->value('nombre'));
        $tipos = $this->formTipos($this->xManager);
        $this->assertTrue($tipos['Cabalgata rural']['editable']);
        $this->assertFalse($tipos['Gira']['editable']);

        $audit = DB::table('audit_logs')->where('action', 'PUT eventos.tipos.update')->first();
        $this->assertNotNull($audit);
        $this->assertSame('Cabalgata', json_decode($audit->before_state, true)['nombre']);
        $this->assertSame('Cabalgata rural', json_decode($audit->after_state, true)['nombre']);
    }

    public function test_cannot_touch_another_campaigns_type_nor_a_global_one(): void
    {
        $yType = $this->ownType($this->yManager, 'Cabalgata');
        $xType = $this->ownType($this->xManager, 'Caravana');
        $parentManager = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos']);

        $this->actingAs($this->xManager)->putJson("/eventos/tipos/{$yType}", ['nombre' => 'Otro'])->assertNotFound();
        $this->actingAs($this->xManager)->deleteJson("/eventos/tipos/{$yType}")->assertNotFound();
        // The parent campaign sees the subcampaign's type, but it is not its own.
        $this->actingAs($parentManager)->putJson("/eventos/tipos/{$xType}", ['nombre' => 'Otro'])->assertForbidden();
        $this->actingAs($this->xManager)->putJson("/eventos/tipos/{$this->global}", ['nombre' => 'Otro'])->assertForbidden();
        $this->actingAs($this->xManager)->deleteJson("/eventos/tipos/{$this->global}")->assertForbidden();

        $this->assertSame('Cabalgata', DB::table('event_types')->where('id', $yType)->value('nombre'));
        $this->assertSame('Caravana', DB::table('event_types')->where('id', $xType)->value('nombre'));
        $this->assertSame('Gira', DB::table('event_types')->where('id', $this->global)->value('nombre'));
    }

    public function test_the_superadmin_manages_global_types(): void
    {
        $admin = $this->superadmin();
        $this->assertTrue($this->formTipos($admin)['Gira']['editable']);

        $this->actingAs($admin)->putJson("/eventos/tipos/{$this->global}", ['nombre' => 'Gira regional'])->assertOk();
        $this->assertSame('Gira regional', DB::table('event_types')->where('id', $this->global)->value('nombre'));

        $unused = DB::table('event_types')->where('nombre', 'Reunión')->value('id');
        $this->actingAs($admin)->deleteJson("/eventos/tipos/{$unused}")->assertOk()->assertJsonPath('deleted', true);
        $this->assertFalse(DB::table('event_types')->where('id', $unused)->exists());
    }

    public function test_a_duplicate_name_is_422_ignoring_case_and_accents(): void
    {
        $id = $this->ownType($this->xManager, 'Cabalgata');
        $this->ownType($this->xManager, 'Caravana');

        foreach (['CARAVANA', 'reunion', 'GIRA'] as $nombre) {
            $this->actingAs($this->xManager)->putJson("/eventos/tipos/{$id}", ['nombre' => $nombre])
                ->assertStatus(422)->assertJsonPath('errors.nombre.0', 'Ya existe un tipo con ese nombre.');
        }
        // Another campaign's type with that name does not count, nor does the type itself.
        $this->ownType($this->yManager, 'Desfile');
        $this->actingAs($this->xManager)->putJson("/eventos/tipos/{$id}", ['nombre' => 'Desfile'])->assertOk();
        $this->actingAs($this->xManager)->putJson("/eventos/tipos/{$id}", ['nombre' => 'DESFILE'])->assertOk();

        $this->actingAs($this->xManager)->putJson("/eventos/tipos/{$id}", ['nombre' => '  '])
            ->assertStatus(422)->assertJsonPath('errors.nombre.0', 'Escriba el nombre del tipo de evento.');
        $this->actingAs($this->xManager)->putJson("/eventos/tipos/{$id}", ['nombre' => str_repeat('a', 81)])
            ->assertStatus(422)->assertJsonValidationErrors('nombre');
    }

    public function test_deleting_an_unused_type_removes_it(): void
    {
        $id = $this->ownType($this->xManager, 'Cabalgata');

        $this->actingAs($this->xManager)->deleteJson("/eventos/tipos/{$id}")
            ->assertOk()->assertJsonPath('deleted', true)->assertJsonPath('message', 'Tipo de evento eliminado.');

        $this->assertFalse(DB::table('event_types')->where('id', $id)->exists());
        $this->assertNotNull(DB::table('audit_logs')->where('action', 'DELETE eventos.tipos.destroy')->first());
        $this->actingAs($this->xManager)->deleteJson("/eventos/tipos/{$id}")->assertNotFound();
    }

    public function test_deleting_a_used_type_deactivates_it_and_its_events_keep_it(): void
    {
        $id = $this->ownType($this->xManager, 'Cabalgata');
        $event = $this->makeEvent($this->campX, ['event_type_id' => $id, 'nombre' => 'Cabalgata Girón']);
        $deletedEventType = $this->ownType($this->xManager, 'Caravana');
        $this->makeEvent($this->campX, ['event_type_id' => $deletedEventType, 'deleted_at' => now()]);

        $this->actingAs($this->xManager)->deleteJson("/eventos/tipos/{$id}")
            ->assertOk()->assertJsonPath('deleted', false)->assertJsonPath('deactivated', true)
            ->assertJsonPath('message', 'Se desactivó: ya hay eventos con este tipo.');
        // A soft-deleted event also keeps its type.
        $this->actingAs($this->xManager)->deleteJson("/eventos/tipos/{$deletedEventType}")->assertJsonPath('deactivated', true);

        $this->assertFalse((bool) DB::table('event_types')->where('id', $id)->value('is_active'));
        $this->assertTrue(DB::table('event_types')->where('id', $deletedEventType)->exists());
        $this->assertArrayNotHasKey('Cabalgata', $this->formTipos($this->xManager));

        $page = $this->actingAs($this->xManager)->get('/eventos')->assertOk()->viewData('page')['props'];
        $this->assertSame('Cabalgata', collect($page['eventos']['data'])->firstWhere('id', $event->id)['tipo']['nombre']);
        $detail = $this->actingAs($this->xManager)->get("/eventos/{$event->id}")->assertOk()->viewData('page')['props']['evento'];
        $this->assertSame(['id' => $id, 'nombre' => 'Cabalgata'], $detail['tipo']);

        // Editing that event may keep its deactivated type, but cannot switch another event to it.
        $this->actingAs($this->xManager)->putJson("/eventos/{$event->id}", ['nombre' => 'Cabalgata Girón 2', 'event_type_id' => $id])->assertOk();
        $other = $this->makeEvent($this->campX);
        $this->actingAs($this->xManager)->putJson("/eventos/{$other->id}", ['event_type_id' => $id])
            ->assertStatus(422)->assertJsonValidationErrors('event_type_id');
    }

    public function test_a_deactivated_type_is_not_accepted_in_a_new_event(): void
    {
        $id = $this->ownType($this->xManager, 'Cabalgata');
        $this->makeEvent($this->campX, ['event_type_id' => $id]);
        $this->actingAs($this->xManager)->deleteJson("/eventos/tipos/{$id}")->assertJsonPath('deactivated', true);

        $this->actingAs($this->xManager)->postJson('/eventos', [
            'nombre' => 'Evento nuevo', 'event_type_id' => $id, 'starts_at' => '2026-11-20 18:00',
            'sitio_encuentro' => 'Parque principal', 'municipio_id' => $this->giron,
        ])->assertStatus(422)->assertJsonValidationErrors('event_type_id');
        $this->actingAs($this->xManager)->putJson("/eventos/tipos/{$id}", ['nombre' => 'Otra'])->assertNotFound();
    }

    public function test_without_manage_eventos_is_403(): void
    {
        $id = $this->ownType($this->xManager, 'Cabalgata');
        foreach ([['view.eventos'], ['register.asistentes'], ['view.mapa', 'write.data']] as $permissions) {
            $user = $this->eventUser($this->campX, $permissions);
            $this->actingAs($user)->putJson("/eventos/tipos/{$id}", ['nombre' => 'Otro'])->assertForbidden();
            $this->actingAs($user)->deleteJson("/eventos/tipos/{$id}")->assertForbidden();
        }
        $this->assertSame('Cabalgata', DB::table('event_types')->where('id', $id)->value('nombre'));
    }
}
