<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// Eventos especiales and multi-campaign: events are created in the own campaign, the parent campaign sees its
// subcampaigns' events read-only, nobody sees an independent campaign, and territory and permissions hold. SQLite.
class EventIsolationTest extends TestCase
{
    use BuildsEventosScenario;

    private User $cdManager;

    private User $xManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEventos();
        $this->cdManager = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos']);
        $this->xManager = $this->eventUser($this->campX, ['manage.eventos']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'nombre' => 'Del Senado al barrio Girón',
            'event_type_id' => DB::table('event_types')->where('nombre', 'Del Senado al barrio')->value('id'),
            'starts_at' => '2026-11-05 18:00',
            'ends_at' => '2026-11-05 21:00',
            'sitio_encuentro' => 'Parque principal',
            'municipio_id' => $this->giron,
            'barrios' => ['El Poblado', 'el  poblado', 'Centro'],
            'organizadores' => [['nombre' => 'Juan Gómez', 'cedula' => '13.952.615', 'rol' => 'Coordinador']],
            'figuras' => [['nombre' => 'Senador X', 'cargo' => 'Senador']],
        ], $overrides);
    }

    public function test_creating_an_event_puts_it_in_the_own_campaign(): void
    {
        $response = $this->actingAs($this->xManager)->postJson('/eventos', $this->payload(['campaign_id' => Campaign::DEFAULT_ID]))
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'borrador')
            ->assertJsonPath('data.provincia.nombre', 'Área Metropolitana')
            ->assertJsonPath('data.starts_at', '2026-11-05T18:00:00-05:00')
            ->assertJsonPath('data.barrios', ['Centro', 'El Poblado'])
            ->assertJsonPath('data.organizadores.0.cedula', '13952615');

        $event = DB::table('events')->where('id', $response->json('data.id'))->first();
        $this->assertSame($this->campX, $event->campaign_id);
        $this->assertSame($this->soto, $event->provincia_id);
        $this->assertSame($this->xManager->id, $event->created_by);
        $this->assertSame(43, strlen($event->public_token));
        $this->assertSame('2026-11-05 23:00:00', $event->starts_at, 'Stored in UTC');
    }

    public function test_an_inertia_create_redirects_to_the_event(): void
    {
        $response = $this->actingAs($this->cdManager)->post('/eventos', $this->payload());

        $id = DB::table('events')->value('id');
        $response->assertRedirect("/eventos/{$id}");
        $this->assertSame(Campaign::DEFAULT_ID, DB::table('events')->value('campaign_id'));
    }

    public function test_creating_without_manage_eventos_is_403(): void
    {
        foreach ([['view.eventos'], ['register.asistentes'], ['view.mapa', 'write.data']] as $permissions) {
            $user = $this->eventUser(Campaign::DEFAULT_ID, $permissions);
            $this->actingAs($user)->postJson('/eventos', $this->payload())->assertForbidden();
        }

        $this->assertSame(0, DB::table('events')->count());
    }

    public function test_manage_eventos_alone_manages_events_without_write_data_or_a_write_role(): void
    {
        $this->approvedText(Campaign::DEFAULT_ID);
        $user = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos'], 'R10_CONSULTA_EJECUTIVA');

        $this->actingAs($user)->get('/eventos')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.user.canManageEventos', true)->where('auth.user.canWrite', false)->where('can.manage', true));

        $id = $this->actingAs($user)->postJson('/eventos', $this->payload())->assertCreated()->json('data.id');
        $this->actingAs($user)->putJson("/eventos/{$id}", ['nombre' => 'Renombrado'])->assertOk();
        $this->actingAs($user)->postJson("/eventos/{$id}/abrir")->assertOk();
        $this->actingAs($user)->postJson("/eventos/{$id}/token")->assertOk();
        $a = $this->actingAs($user)->postJson("/eventos/{$id}/asistentes", $this->attendeePayload(DB::table('consent_texts')
            ->where('campaign_id', Campaign::DEFAULT_ID)->value('id')))->assertCreated()->json('data.id');
        $b = $this->actingAs($user)->postJson("/eventos/{$id}/asistentes", $this->attendeePayload(DB::table('consent_texts')
            ->where('campaign_id', Campaign::DEFAULT_ID)->value('id'), ['cedula' => '22334455']))->assertCreated()->json('data.id');
        $this->actingAs($user)->postJson("/eventos/{$id}/asistentes/aprobar", ['ids' => [$a]])->assertOk()->assertJsonPath('data.aprobados', 1);
        $this->actingAs($user)->postJson("/eventos/{$id}/asistentes/descartar", ['ids' => [$b]])->assertOk()->assertJsonPath('data.descartados', 1);
        $this->actingAs($user)->postJson("/eventos/{$id}/cerrar")->assertOk();
        $this->actingAs($user)->postJson("/eventos/{$id}/cancelar")->assertOk();
        $empty = $this->actingAs($user)->postJson('/eventos', $this->payload())->json('data.id');
        $this->actingAs($user)->deleteJson("/eventos/{$empty}")->assertOk();
    }

    public function test_write_data_without_manage_eventos_cannot_manage_events(): void
    {
        $event = $this->makeEvent(Campaign::DEFAULT_ID, ['status' => 'borrador']);
        $user = $this->eventUser(Campaign::DEFAULT_ID, ['view.eventos', 'write.data', 'view.mapa']);

        $this->actingAs($user)->get('/eventos')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.user.canManageEventos', false)->where('can.manage', false));
        foreach ([
            ['postJson', '/eventos', $this->payload()],
            ['putJson', "/eventos/{$event->id}", ['nombre' => 'X']],
            ['deleteJson', "/eventos/{$event->id}", []],
            ['postJson', "/eventos/{$event->id}/abrir", []],
            ['postJson', "/eventos/{$event->id}/cerrar", []],
            ['postJson', "/eventos/{$event->id}/cancelar", []],
            ['postJson', "/eventos/{$event->id}/token", []],
            ['postJson', "/eventos/{$event->id}/asistentes/aprobar", ['ids' => [(string) Str::uuid()]]],
            ['postJson', "/eventos/{$event->id}/asistentes/descartar", ['ids' => [(string) Str::uuid()]]],
        ] as [$method, $uri, $body]) {
            $this->actingAs($user)->{$method}($uri, $body)->assertForbidden();
        }
        $this->assertSame('borrador', DB::table('events')->where('id', $event->id)->value('status'));
    }

    public function test_province_names_are_shown_as_the_rest_of_the_platform(): void
    {
        $area = $this->geo('province', 'Area Metropolitana');
        $floridablanca = $this->geo('municipality', 'Floridablanca', $area);

        $this->actingAs($this->cdManager)->postJson('/eventos', $this->payload())->assertCreated()
            ->assertJsonPath('data.provincia.nombre', 'Área Metropolitana');
        $this->actingAs($this->cdManager)->postJson('/eventos', $this->payload(['municipio_id' => $floridablanca]))->assertCreated()
            ->assertJsonPath('data.provincia.nombre', 'Área Metropolitana');
        $this->actingAs($this->cdManager)->get('/eventos')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('provincias', fn ($ps) => collect($ps)->pluck('name')->filter(fn ($n) => $n === 'Área Metropolitana')->count() === 2
                && ! collect($ps)->pluck('name')->contains('Soto') && ! collect($ps)->pluck('name')->contains('Area Metropolitana')));
    }

    public function test_validation_messages_are_in_spanish_with_field_names(): void
    {
        $this->actingAs($this->cdManager)->postJson('/eventos', $this->payload([
            'starts_at' => 'no-es-fecha', 'event_type_id' => 'x', 'nombre' => ['a'], 'barrios' => 'Centro',
        ]))->assertStatus(422)
            ->assertJsonPath('errors.starts_at.0', 'La fecha y hora de inicio no es válida.')
            ->assertJsonPath('errors.event_type_id.0', 'El valor elegido en tipo de evento no es válido.')
            ->assertJsonPath('errors.nombre.0', 'El campo nombre del evento debe ser un texto.')
            ->assertJsonPath('errors.barrios.0', 'El campo barrios debe ser una lista.');
    }

    public function test_a_municipio_outside_the_territory_is_403(): void
    {
        $user = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos'], 'R09_CAMPO', [$this->giron]);

        $this->actingAs($user)->postJson('/eventos', $this->payload(['municipio_id' => $this->velez]))->assertForbidden();
        $this->assertSame(0, DB::table('events')->count());

        $this->actingAs($user)->postJson('/eventos', $this->payload())->assertCreated();
    }

    public function test_a_territory_user_does_not_reach_events_outside_it(): void
    {
        $velez = $this->makeEvent(Campaign::DEFAULT_ID, ['nombre' => 'Evento Vélez', 'municipio_id' => $this->velez]);
        $giron = $this->makeEvent(Campaign::DEFAULT_ID, ['nombre' => 'Evento Girón']);
        $user = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos'], 'R09_CAMPO', [$this->giron]);

        $this->actingAs($user)->get('/eventos')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Eventos/Index')
                ->where('eventos.total', 1)
                ->where('eventos.data.0.id', $giron->id)
                ->where('provincias.0.municipios', [['id' => $this->giron, 'name' => 'Girón']]));
        $this->actingAs($user)->get("/eventos/{$velez->id}")->assertForbidden();
        $this->actingAs($user)->putJson("/eventos/{$velez->id}", ['nombre' => 'X'])->assertForbidden();
    }

    public function test_a_subcampaign_does_not_see_the_parent_campaign_events(): void
    {
        $cdEvent = $this->makeEvent(Campaign::DEFAULT_ID, ['nombre' => 'Evento CD']);
        $xEvent = $this->makeEvent($this->campX, ['nombre' => 'Evento X']);

        $this->actingAs($this->xManager)->get('/eventos')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Eventos/Index')
                ->where('eventos.total', 1)
                ->where('eventos.data.0.id', $xEvent->id)
                ->where('eventos.data.0.editable', true));

        $this->actingAs($this->xManager)->get("/eventos/{$cdEvent->id}")->assertNotFound();
        $this->actingAs($this->xManager)->get("/eventos/{$cdEvent->id}/operador")->assertNotFound();
        $this->actingAs($this->xManager)->putJson("/eventos/{$cdEvent->id}", ['nombre' => 'Hackeado'])->assertNotFound();
        $this->actingAs($this->xManager)->postJson("/eventos/{$cdEvent->id}/cerrar")->assertNotFound();
        $this->assertSame('Evento CD', DB::table('events')->where('id', $cdEvent->id)->value('nombre'));
    }

    public function test_the_parent_campaign_sees_subcampaign_events_but_cannot_edit_them(): void
    {
        $this->approvedText($this->campX);
        $xEvent = $this->makeEvent($this->campX, ['nombre' => 'Evento X', 'status' => 'borrador']);
        $yEvent = $this->makeEvent($this->campY, ['nombre' => 'Evento Y']);
        $text = $this->provisionalText();

        $this->actingAs($this->cdManager)->get('/eventos')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('eventos.total', 1)
                ->where('eventos.data.0.id', $xEvent->id)
                ->where('eventos.data.0.editable', false)
                ->where('eventos.data.0.campaign.nombre', 'Alejandro'));
        $this->actingAs($this->cdManager)->get("/eventos/{$xEvent->id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Eventos/Show')
                ->where('evento.id', $xEvent->id)->where('can.edit', false));

        $writes = [
            ['putJson', "/eventos/{$xEvent->id}", ['nombre' => 'Hackeado']],
            ['deleteJson', "/eventos/{$xEvent->id}", []],
            ['postJson', "/eventos/{$xEvent->id}/abrir", []],
            ['postJson', "/eventos/{$xEvent->id}/cancelar", []],
            ['postJson', "/eventos/{$xEvent->id}/token", []],
            ['postJson', "/eventos/{$xEvent->id}/asistentes", $this->attendeePayload($text->id)],
            ['postJson', "/eventos/{$xEvent->id}/asistentes/aprobar", ['ids' => [(string) Str::uuid()]]],
        ];
        foreach ($writes as [$method, $uri, $body]) {
            $this->actingAs($this->cdManager)->{$method}($uri, $body)->assertForbidden();
        }
        $this->actingAs($this->cdManager)->get("/eventos/{$xEvent->id}/operador")->assertForbidden();

        $row = DB::table('events')->where('id', $xEvent->id)->first();
        $this->assertSame('Evento X', $row->nombre);
        $this->assertSame('borrador', $row->status);
        $this->assertNull($row->deleted_at);
        $this->assertSame(0, DB::table('event_attendees')->count());

        $this->actingAs($this->cdManager)->get("/eventos/{$yEvent->id}")->assertNotFound();
    }

    public function test_opening_needs_no_consent_text_and_follows_the_status_rules(): void
    {
        DB::table('consent_texts')->update(['is_active' => false]);
        $event = $this->makeEvent(Campaign::DEFAULT_ID, ['status' => 'borrador']);

        $this->actingAs($this->cdManager)->postJson("/eventos/{$event->id}/abrir")
            ->assertOk()->assertJsonPath('data.status', 'abierto')->assertJsonPath('data.public_open', true);

        $this->actingAs($this->cdManager)->postJson("/eventos/{$event->id}/abrir")->assertStatus(422)->assertJsonValidationErrors('status');
        $this->actingAs($this->cdManager)->postJson("/eventos/{$event->id}/cerrar")->assertOk()->assertJsonPath('data.status', 'cerrado');
        $this->actingAs($this->cdManager)->postJson("/eventos/{$event->id}/cancelar")->assertOk()->assertJsonPath('data.status', 'cancelado');
        $this->actingAs($this->cdManager)->postJson("/eventos/{$event->id}/abrir")->assertStatus(422);
    }

    public function test_regenerating_the_token_kills_the_old_qr(): void
    {
        $this->approvedText(Campaign::DEFAULT_ID);
        $event = $this->makeEvent(Campaign::DEFAULT_ID);
        $old = $event->public_token;

        $url = $this->actingAs($this->cdManager)->postJson("/eventos/{$event->id}/token")->assertOk()->json('data.public_url');

        $new = DB::table('events')->where('id', $event->id)->value('public_token');
        $this->assertNotSame($old, $new);
        $this->assertStringEndsWith("/e/{$new}", $url);
        auth()->logout();
        $this->get("/e/{$old}")->assertNotFound();
        $this->get("/e/{$new}")->assertOk();
    }

    public function test_update_replaces_lists_and_validates_dates(): void
    {
        $id = $this->actingAs($this->cdManager)->postJson('/eventos', $this->payload())->json('data.id');

        $this->actingAs($this->cdManager)->putJson("/eventos/{$id}", ['barrios' => ['San Juan'], 'figuras' => []])
            ->assertOk()->assertJsonPath('data.barrios', ['San Juan'])->assertJsonPath('data.figuras', [])
            ->assertJsonPath('data.organizadores.0.nombre', 'Juan Gómez');
        $this->actingAs($this->cdManager)->putJson("/eventos/{$id}", ['ends_at' => '2026-11-04 10:00'])
            ->assertStatus(422)->assertJsonValidationErrors('ends_at');
        $this->actingAs($this->cdManager)->putJson("/eventos/{$id}", ['municipio_id' => $this->lebrija])
            ->assertOk()->assertJsonPath('data.municipio.nombre', 'Lebrija');
    }

    public function test_an_organizer_must_be_a_lider_of_the_own_campaign(): void
    {
        $own = $this->lider(Campaign::DEFAULT_ID, 'Organizador CD', '777');
        $foreign = $this->lider($this->campX, 'Organizador X', '888');

        $this->actingAs($this->cdManager)->postJson('/eventos', $this->payload([
            'organizadores' => [['lider_id' => $foreign, 'nombre' => 'Organizador X']],
        ]))->assertStatus(422)->assertJsonValidationErrors('organizadores');

        $this->actingAs($this->cdManager)->postJson('/eventos', $this->payload([
            'organizadores' => [['lider_id' => $own, 'nombre' => 'Organizador CD']],
        ]))->assertCreated()->assertJsonPath('data.organizadores.0.lider_id', $own);
    }

    public function test_an_event_with_attendees_cannot_be_deleted(): void
    {
        $event = $this->makeEvent(Campaign::DEFAULT_ID, ['status' => 'borrador']);
        $empty = $this->makeEvent(Campaign::DEFAULT_ID, ['status' => 'borrador']);
        $this->actingAs($this->cdManager)->postJson("/eventos/{$event->id}/asistentes", $this->attendeePayload($this->provisionalText()->id))
            ->assertCreated();

        $this->actingAs($this->cdManager)->deleteJson("/eventos/{$event->id}")->assertStatus(422)->assertJsonValidationErrors('evento');
        $this->actingAs($this->cdManager)->deleteJson("/eventos/{$empty->id}")->assertOk();
        $this->assertNotNull(DB::table('events')->where('id', $empty->id)->value('deleted_at'));
        $this->actingAs($this->cdManager)->get("/eventos/{$empty->id}")->assertNotFound();
    }

    public function test_index_filters(): void
    {
        $this->actingAs($this->cdManager)->postJson('/eventos', $this->payload())->assertCreated();
        $this->actingAs($this->cdManager)->postJson('/eventos', $this->payload([
            'nombre' => 'Novena Lebrija', 'municipio_id' => $this->lebrija, 'starts_at' => '2026-12-20 19:00', 'ends_at' => null,
            'barrios' => ['La Campiña'], 'event_type_id' => DB::table('event_types')->where('nombre', 'Novena')->value('id'),
        ]))->assertCreated();

        $total = fn (string $query) => $this->actingAs($this->cdManager)->get("/eventos?{$query}")->assertOk()
            ->viewData('page')['props']['eventos']['total'];

        $this->assertSame(2, $total(''));
        $this->assertSame(1, $total("municipio={$this->lebrija}"));
        $this->assertSame(2, $total("provincia={$this->soto}"));
        $this->assertSame(1, $total('barrio=campina'));
        $this->assertSame(1, $total('tipo='.DB::table('event_types')->where('nombre', 'Novena')->value('id')));
        $this->assertSame(1, $total('desde=2026-12-01'));
        $this->assertSame(1, $total('hasta=2026-11-05'));
        $this->assertSame(1, $total('q=novena'));
        $this->assertSame(2, $total('status=borrador'));
    }

    public function test_searches_only_return_own_campaign_lideres(): void
    {
        $this->lider(Campaign::DEFAULT_ID, 'Gómez del CD', '111');
        $this->lider($this->campX, 'Gómez de X', '222');
        DB::table('persons')->insert(['id' => (string) Str::uuid(), 'full_name' => 'Álvaro Uribe Vélez', 'normalized_name' => 'ALVARO URIBE VELEZ']);

        $this->actingAs($this->cdManager)->getJson('/eventos/buscar/organizadores?q=gómez')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nombre', 'Gómez del CD');
        $this->actingAs($this->cdManager)->getJson('/eventos/buscar/figuras?q=uribe')
            ->assertOk()->assertJsonPath('data.0.nombre', 'Álvaro Uribe Vélez')->assertJsonPath('data.0.ultimo_cargo', null);

        $viewer = $this->eventUser(Campaign::DEFAULT_ID, ['view.eventos']);
        $this->actingAs($viewer)->getJson('/eventos/buscar/organizadores?q=gómez')->assertForbidden();
    }

    public function test_figures_search_tells_namesakes_apart_by_their_latest_candidacy(): void
    {
        $older = (string) Str::uuid();
        $newer = (string) Str::uuid();
        $none = (string) Str::uuid();
        DB::table('persons')->insert([
            ['id' => $older, 'full_name' => 'Juan Pérez', 'normalized_name' => 'JUAN PEREZ'],
            ['id' => $newer, 'full_name' => 'Juan Pérez Gómez', 'normalized_name' => 'JUAN PEREZ GOMEZ'],
            ['id' => $none, 'full_name' => 'Juan Pérez Sin', 'normalized_name' => 'JUAN PEREZ SIN'],
        ]);
        $alcaldia = (string) Str::uuid();
        DB::table('offices')->insert(['id' => $alcaldia, 'name' => 'Alcaldía']);
        $senado = (string) Str::uuid();
        DB::table('corporations')->insert(['id' => $senado, 'name' => 'Senado']);
        $e2023 = (string) Str::uuid();
        $e2026 = (string) Str::uuid();
        DB::table('electoral_events')->insert([
            ['id' => $e2023, 'name' => 'Territoriales 2023', 'election_date' => '2023-10-29'],
            ['id' => $e2026, 'name' => 'Legislativas 2026', 'election_date' => '2026-03-08'],
        ]);
        $contest = function (string $event, ?string $office, ?string $corp, ?string $geo) {
            $id = (string) Str::uuid();
            DB::table('contests')->insert(['id' => $id, 'electoral_event_id' => $event, 'office_id' => $office,
                'corporation_id' => $corp, 'geographic_unit_id' => $geo, 'name' => 'Contienda']);

            return $id;
        };
        $candidacy = fn (string $person, string $contestId) => DB::table('candidacies')
            ->insert(['id' => (string) Str::uuid(), 'person_id' => $person, 'contest_id' => $contestId]);
        $candidacy($older, $contest($e2023, $alcaldia, null, $this->giron));
        $candidacy($newer, $contest($e2023, $alcaldia, null, $this->lebrija));
        $candidacy($newer, $contest($e2026, null, $senado, null));

        $data = $this->actingAs($this->cdManager)->getJson('/eventos/buscar/figuras?q=juan perez')
            ->assertOk()->json('data');

        $this->assertSame([
            ['id' => $older, 'nombre' => 'Juan Pérez', 'ultimo_cargo' => 'Alcaldía', 'ultimo_anio' => 2023, 'ultimo_lugar' => 'Girón'],
            ['id' => $newer, 'nombre' => 'Juan Pérez Gómez', 'ultimo_cargo' => 'Senado', 'ultimo_anio' => 2026, 'ultimo_lugar' => null],
            ['id' => $none, 'nombre' => 'Juan Pérez Sin', 'ultimo_cargo' => null, 'ultimo_anio' => null, 'ultimo_lugar' => null],
        ], $data);
    }

    public function test_an_edit_cannot_empty_a_required_field(): void
    {
        $id = $this->actingAs($this->cdManager)->postJson('/eventos', $this->payload())->json('data.id');

        $this->actingAs($this->cdManager)->putJson("/eventos/{$id}", [
            'nombre' => '', 'event_type_id' => '', 'starts_at' => '', 'sitio_encuentro' => '   ', 'municipio_id' => null,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['nombre', 'event_type_id', 'starts_at', 'sitio_encuentro', 'municipio_id'])
            ->assertJsonPath('errors.nombre.0', 'Escriba el nombre del evento.')
            ->assertJsonPath('errors.sitio_encuentro.0', 'Indique el sitio de encuentro.');

        $this->assertSame('Del Senado al barrio Girón', DB::table('events')->where('id', $id)->value('nombre'));
        $this->actingAs($this->cdManager)->putJson("/eventos/{$id}", ['descripcion' => ''])->assertOk()
            ->assertJsonPath('data.nombre', 'Del Senado al barrio Girón')->assertJsonPath('data.descripcion', null);
    }

    // The command stays (the texts are kept), but opening an event no longer depends on it.
    public function test_the_consent_text_command_still_publishes_versions(): void
    {
        $event = $this->makeEvent($this->campX, ['status' => 'borrador']);
        $file = tempnam(sys_get_temp_dir(), 'consent');
        file_put_contents($file, 'Autorizo a la campaña de Alejandro y al Centro Democrático...
');

        $this->artisan('eventos:consent-text', ['campaign' => 'alejandro', 'file' => $file, '--text-version' => 'v1'])->assertSuccessful();
        $this->artisan('eventos:consent-text', ['campaign' => 'alejandro', 'file' => $file, '--text-version' => 'v2', '--approve' => true])->assertSuccessful();
        $this->artisan('eventos:consent-text', ['campaign' => 'no-existe', 'file' => $file])->assertFailed();
        unlink($file);

        $texts = DB::table('consent_texts')->where('campaign_id', $this->campX)->orderBy('version')->get();
        $this->assertSame([false, true], $texts->map(fn ($t) => (bool) $t->is_active)->all());
        $this->assertSame(hash('sha256', 'Autorizo a la campaña de Alejandro y al Centro Democrático...'), $texts[1]->sha256);
        $this->actingAs($this->xManager)->postJson("/eventos/{$event->id}/abrir")->assertOk()->assertJsonPath('data.status', 'abierto');
    }
}
