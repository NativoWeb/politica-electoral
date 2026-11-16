<?php

namespace Tests\Feature;

use App\Http\Requests\Eventos\AttendeeInput;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\User;
use App\Services\Agent\AgentContactQuota;
use App\Services\Agent\AgentIdentityService;
use App\Support\Provincias;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// GET /api/agent/v1/eventos, /eventos/{id}, /eventos/{id}/asistentes(/export), /eventos/territorio and
// /compromisos/{id} on SQLite: read-only, campaign and territory as the web, and contacts of listed persons only if
// they are a lider who authorized them, counted against the daily quota.
// Each test in its own process: the suite already runs close to the memory limit of the shared process.
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class AgentEventoApiTest extends TestCase
{
    use BuildsEventosScenario;

    private const SECRET = 'test-agent-secret-0123456789abcdef';

    private User $manager;

    private User $ev;

    private User $reg;

    private CampaignEvent $event;

    private CampaignEvent $sub;

    private CampaignEvent $velezEvent;

    private CampaignEvent $otraEvent;

    private string $autoriza;

    private string $callado;

    private string $actaA;

    private string $compromisoA;

    private string $compromisoOrg;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent.hmac_secret' => self::SECRET]);
        $this->setUpEventos();
        (require database_path('migrations/2026_10_19_183258_create_personal_access_tokens_table.php'))->up();
        (require database_path('migrations/2026_11_10_000001_create_event_actas_tables.php'))->up();
        (require database_path('migrations/2026_11_11_000001_add_created_flags_to_event_acta_participantes.php'))->up();
        (require database_path('migrations/2026_11_13_000002_add_organizacion_to_event_actas.php'))->up();
        DB::table('campaigns')->update(['whatsapp_enabled' => true]);

        $this->manager = $this->agentUser(['view.eventos', 'manage.eventos', 'view.empresarios', 'manage.empresarios']);
        $this->ev = $this->agentUser(['view.eventos'], [$this->giron]);
        $this->reg = $this->agentUser(['register.asistentes'], [$this->giron]);
        $this->autoriza = $this->lider(Campaign::DEFAULT_ID, 'Ana Autoriza', '1098000001', ['telefono' => '3101111111', 'autoriza_datos' => true]);
        $this->callado = $this->lider(Campaign::DEFAULT_ID, 'Bruno Callado', '1098000002', ['telefono' => '3102222222', 'autoriza_datos' => false]);

        $novena = DB::table('event_types')->where('nombre', 'Novena')->value('id');
        $this->event = $this->makeEvent(Campaign::DEFAULT_ID, ['starts_at' => now()->subDays(2)]);
        $this->sub = $this->makeEvent($this->campX, ['nombre' => 'Novena del barrio', 'event_type_id' => $novena, 'starts_at' => now()->subDay()]);
        $this->velezEvent = $this->makeEvent(Campaign::DEFAULT_ID, ['nombre' => 'Gira Vélez', 'municipio_id' => $this->velez, 'provincia_id' => null]);
        $this->otraEvent = $this->makeEvent($this->campY, ['nombre' => 'Gira Otra']);
        $this->makeEvent(Campaign::DEFAULT_ID, ['nombre' => 'Gira borrada', 'deleted_at' => now()]);

        $this->attendee($this->event, $this->autoriza, '1098000001', '3101111111', 'qr', 'vinculado', 'Ana');
        $this->attendee($this->event, $this->callado, '1098000002', '3102222222', 'operador', 'vinculado', 'Bruno');
        $this->attendee($this->event, null, '1098000003', '3103333333', 'excel', 'pendiente', 'Carla');
        $this->attendee($this->velezEvent, null, '1098000004', '3104444444', 'qr', 'pendiente', 'Dario');

        foreach ([['Ana Autoriza', '1098000001', '3101111111', $this->autoriza], ['Bruno Callado', '1098000002', '3102222222', $this->callado]] as [$nombre, $cedula, $telefono, $lider]) {
            DB::table('event_organizers')->insert(['id' => (string) Str::uuid(), 'event_id' => $this->event->id, 'lider_id' => $lider,
                'nombre' => $nombre, 'cedula' => $cedula, 'telefono' => $telefono, 'rol' => 'Coordinador', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->actaA = $this->eventActa($this->event, 'Acta Girón', [
            $this->compromiso('Arreglar la vía', '2026-01-10'),
            $this->compromiso('Instalar cámaras', '2099-01-10'),
        ]);
        $this->eventActa($this->event, 'Borrador Girón', [$this->compromiso('Borrador abierto', '2099-01-10')], false);

        $this->compromisoA = DB::table('event_compromisos')->where('descripcion', 'Arreglar la vía')->value('id');
        DB::table('event_compromiso_responsables')->where('compromiso_id', $this->compromisoA)->delete();
        foreach ([['Ana Autoriza', '3101111111', $this->autoriza], ['Bruno Callado', '3102222222', $this->callado]] as $i => [$nombre, $telefono, $lider]) {
            DB::table('event_compromiso_responsables')->insert(['id' => (string) Str::uuid(), 'compromiso_id' => $this->compromisoA, 'orden' => $i,
                'nombre' => $nombre, 'telefono' => $telefono, 'lider_id' => $lider, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('event_compromiso_avances')->insert(['id' => (string) Str::uuid(), 'compromiso_id' => $this->compromisoA, 'nota' => 'Se visitó la vía',
            'estado_anterior' => 'pendiente', 'estado_nuevo' => 'en_curso', 'archivo_path' => 'actas/avances/foto.jpg', 'archivo_mime' => 'image/jpeg',
            'archivo_nombre' => 'foto vía.jpg', 'user_id' => $this->manager->id, 'created_at' => now()]);

        $org = (string) Str::uuid();
        DB::table('organizaciones')->insert(['id' => $org, 'campaign_id' => Campaign::DEFAULT_ID, 'tipo' => 'empresa', 'nombre' => 'Lácteos SAS',
            'municipio_id' => $this->giron, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $actaOrg = $this->actingAs($this->manager)->postJson("/empresarios/{$org}/actas", [
            'titulo' => 'Junta Lácteos', 'fecha' => '2026-11-10', 'compromisos' => [$this->compromiso('Enviar propuesta', '2026-01-10')],
        ])->assertCreated()->json('data.id');
        $this->actingAs($this->manager)->postJson("/empresarios/{$org}/actas/{$actaOrg}/confirmar")->assertOk();
        $this->compromisoOrg = DB::table('event_compromisos')->where('descripcion', 'Enviar propuesta')->value('id');
    }

    private function agentUser(array $permissions, ?array $territory = null): User
    {
        $user = $this->eventUser(Campaign::DEFAULT_ID, [...$permissions, 'use.whatsapp'], 'R09_CAMPO', $territory);
        $user->forceFill(['whatsapp_phone' => '+5730'.random_int(10000000, 99999999), 'whatsapp_verified_at' => now()])->save();

        return $user;
    }

    private function attendee(CampaignEvent $event, ?string $liderId, string $cedula, string $celular, string $source, string $link, string $nombres): string
    {
        $id = (string) Str::uuid();
        DB::table('event_attendees')->insert([
            'id' => $id, 'event_id' => $event->id, 'campaign_id' => $event->campaign_id, 'lider_id' => $liderId,
            'nombres' => $nombres, 'apellidos' => 'Asistente', 'cedula' => $cedula, 'celular' => $celular, 'fijo' => '6076000000',
            'direccion' => 'Calle 1 # 2-3', 'fecha_nacimiento' => '1990-05-04', 'barrio' => 'El Poblado',
            'barrio_normalizado' => AttendeeInput::barrioKey('El Poblado'), 'nota' => 'Nota interna',
            'autoriza_datos' => true, 'source' => $source, 'link_status' => $link, 'client_uuid' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function compromiso(string $descripcion, string $limite): array
    {
        return ['descripcion' => $descripcion, 'fecha_limite' => $limite, 'responsables' => [['nombre' => 'Responsable Texto', 'telefono' => '3209999999']]];
    }

    private function eventActa(CampaignEvent $event, string $titulo, array $compromisos, bool $confirm = true): string
    {
        $id = $this->actingAs($this->manager)->postJson("/eventos/{$event->id}/actas", [
            'titulo' => $titulo, 'fecha' => '2026-11-08', 'compromisos' => $compromisos,
        ])->assertCreated()->json('data.id');
        if ($confirm) {
            $this->actingAs($this->manager)->postJson("/eventos/{$event->id}/actas/{$id}/confirmar")->assertOk();
        }

        return $id;
    }

    private function agent(string $path, array $query = [], ?User $user = null, string $method = 'GET'): TestResponse
    {
        $token = ($user ?? $this->ev)->createToken(AgentIdentityService::TOKEN_NAME, ['agent'], now()->addMinutes(15))->plainTextToken;
        $uri = $query === [] ? $path : $path.'?'.http_build_query($query);
        $ts = (string) now()->getTimestamp();
        $nonce = Str::random(32);
        $this->app['auth']->forgetGuards();

        return $this->call($method, $uri, [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_X_AGENT_TIMESTAMP' => $ts,
            'HTTP_X_AGENT_NONCE' => $nonce,
            'HTTP_X_AGENT_SIGNATURE' => hash_hmac('sha256', "{$ts}\n{$nonce}\n{$method}\n{$uri}\n", self::SECRET),
        ]);
    }

    private function ids(TestResponse $response): array
    {
        return array_column($response->json('data'), 'id');
    }

    private function used(User $user): int
    {
        return app(AgentContactQuota::class)->used($user->id);
    }

    // Few test methods on purpose: every one is a separate process, and the suite's shared process runs close to its
    // memory limit.
    public function test_list_of_events(): void
    {
        $this->list_shows_visible_campaigns_and_territory_and_filters_tipo_by_text();
        $this->list_permissions();
        $this->invalid_filters_are_422();
    }

    public function test_detail_of_an_event(): void
    {
        $this->detail_hides_contacts_of_who_did_not_authorize_and_lists_the_actas();
        $this->detail_of_an_event_out_of_reach_is_404();
    }

    public function test_attendees_of_an_event(): void
    {
        $this->attendees_hand_contacts_only_of_authorized_lideres_and_charge_the_quota();
        $this->attendees_search_by_cedula_only_finds_who_authorized();
        $this->attendees_need_view_eventos_and_a_visible_event();
        $this->attendees_over_quota_are_429_and_charge_nothing();
    }

    public function test_export_of_the_attendees(): void
    {
        $this->export_is_the_web_excel_with_only_authorized_contacts();
        $this->export_without_quota_is_429();
    }

    public function test_territory(): void
    {
        $this->territory_view();
    }

    public function test_compromiso_and_nothing_writes(): void
    {
        $this->compromiso_detail();
        $this->compromiso_access();
        $this->nothing_under_eventos_writes();
    }

    // ── A. GET /eventos ──

    private function list_shows_visible_campaigns_and_territory_and_filters_tipo_by_text(): void
    {
        $response = $this->agent('/api/agent/v1/eventos')->assertOk()->assertJsonPath('success', true)->assertJsonPath('meta.total', 2);
        $this->assertSame([$this->sub->id, $this->event->id], $this->ids($response), 'own campaign + subcampaign, Girón only, newest first');
        $starts = array_column($response->json('data'), 'starts_at');
        $this->assertGreaterThan(strtotime($starts[1]), strtotime($starts[0]), 'starts_at desc, as the web');
        $this->assertContains('Gira', $response->json('meta.tipos'));

        $row = collect($response->json('data'))->firstWhere('id', $this->event->id);
        $this->assertArrayNotHasKey('editable', $row);
        $this->assertSame(['Gira', 'Girón'], [$row['tipo']['nombre'], $row['municipio']['nombre']]);
        $this->assertSame([3, 2, 2], [$row['asistentes_total'], $row['actas_total'], $row['compromisos_abiertos']], 'abiertos only of the confirmed acta');

        $this->assertSame([$this->sub->id], $this->ids($this->agent('/api/agent/v1/eventos', ['tipo' => 'NOVÉNA'])));
        $this->assertSame([$this->event->id], $this->ids($this->agent('/api/agent/v1/eventos', ['tipo' => 'gira'])));
        $this->agent('/api/agent/v1/eventos', ['tipo' => 'no existe'])->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertSame([$this->event->id], $this->ids($this->agent('/api/agent/v1/eventos', ['q' => 'gira gir'])));

        $all = $this->agent('/api/agent/v1/eventos', ['per_page' => 50], $this->manager)->assertOk()->assertJsonPath('meta.per_page', 20);
        $this->assertEqualsCanonicalizing([$this->event->id, $this->sub->id, $this->velezEvent->id], $this->ids($all), 'never another campaign nor a deleted event');

        $this->assertSame(5, AuditLog::where('action', 'agent.eventos.list')->where('user_id', $this->ev->id)->count());
        $this->assertStringNotContainsString('gira gir', AuditLog::where('action', 'agent.eventos.list')->pluck('metadata')->toJson());
    }

    private function list_permissions(): void
    {
        $response = $this->agent('/api/agent/v1/eventos', [], $this->reg)->assertOk()->assertJsonPath('meta.total', 2);
        foreach ($response->json('data') as $row) {
            $this->assertSame([null, null, null], [$row['asistentes_total'], $row['actas_total'], $row['compromisos_abiertos']]);
        }

        $this->agent('/api/agent/v1/eventos', [], $this->agentUser(['view.empresarios'], [$this->giron]))->assertForbidden();
        $this->agent('/api/agent/v1/eventos', [], $this->agentUser(['view.votantes', 'view.mapa']))->assertForbidden();
    }

    private function invalid_filters_are_422(): void
    {
        $this->agent('/api/agent/v1/eventos', ['status' => 'activo'])->assertStatus(422);
        $this->agent('/api/agent/v1/eventos', ['desde' => '08/11/2026'])->assertStatus(422);
        $this->agent('/api/agent/v1/eventos', ['municipio_id' => 'giron'])->assertStatus(422);
        $this->agent('/api/agent/v1/eventos', ['tipo_id' => (string) Str::uuid()])->assertStatus(422);
        $this->agent("/api/agent/v1/eventos/{$this->event->id}/asistentes", ['source' => 'web'])->assertStatus(422);
        $this->agent("/api/agent/v1/eventos/{$this->event->id}/asistentes/export", ['page' => 2])->assertStatus(422);
    }

    // ── B. GET /eventos/{id} ──

    private function detail_hides_contacts_of_who_did_not_authorize_and_lists_the_actas(): void
    {
        $before = $this->used($this->ev);
        $response = $this->agent("/api/agent/v1/eventos/{$this->event->id}")->assertOk()
            ->assertJsonPath('data.nombre', 'Gira Girón')
            ->assertJsonPath('data.asistentes_total', 3)
            ->assertJsonPath('data.kpis.total', 3)
            ->assertJsonPath('data.kpis.vinculados', 2)
            ->assertJsonPath('meta.con_datos', 1);
        $data = $response->json('data');
        foreach (['public_url', 'consent_text_id', 'editable'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $data);
        }

        $organizadores = collect($data['organizadores'])->keyBy('nombre');
        $this->assertSame(['1098000001', '3101111111'], [$organizadores['Ana Autoriza']['cedula'], $organizadores['Ana Autoriza']['telefono']]);
        $this->assertSame([null, null], [$organizadores['Bruno Callado']['cedula'], $organizadores['Bruno Callado']['telefono']]);
        $this->assertStringNotContainsString('3102222222', $response->getContent());

        $actas = collect($data['actas'])->keyBy('titulo');
        $this->assertEqualsCanonicalizing(['Acta Girón', 'Borrador Girón'], $actas->keys()->all());
        $this->assertSame(['id' => $this->actaA, 'titulo' => 'Acta Girón', 'fecha' => '2026-11-08', 'estado' => 'confirmada', 'compromisos' => 2, 'abiertos' => 2], $actas['Acta Girón']);

        $this->assertSame($before + 1, $this->used($this->ev));
        $this->assertSame($this->event->id, AuditLog::where('action', 'agent.evento.show')->sole()->entity_id);
    }

    private function detail_of_an_event_out_of_reach_is_404(): void
    {
        $this->agent("/api/agent/v1/eventos/{$this->velezEvent->id}")->assertNotFound();
        $this->agent("/api/agent/v1/eventos/{$this->otraEvent->id}")->assertNotFound();
        $this->agent('/api/agent/v1/eventos/'.Str::uuid())->assertNotFound();
        $this->agent("/api/agent/v1/eventos/{$this->velezEvent->id}", [], $this->manager)->assertOk();
        $this->agent("/api/agent/v1/eventos/{$this->event->id}", [], $this->reg)->assertForbidden();
    }

    // ── C. GET /eventos/{id}/asistentes ──

    private function attendees_hand_contacts_only_of_authorized_lideres_and_charge_the_quota(): void
    {
        $before = $this->used($this->ev);
        $response = $this->agent("/api/agent/v1/eventos/{$this->event->id}/asistentes")->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.con_datos', 1);
        $rows = collect($response->json('data'))->keyBy('nombres');

        $ana = $rows['Ana'];
        $this->assertSame(['1098000001', '3101111111', '6076000000', 'Calle 1 # 2-3', '1990-05-04'],
            [$ana['cedula'], $ana['celular'], $ana['fijo'], $ana['direccion'], $ana['fecha_nacimiento']]);
        $this->assertSame(['QR', 'Ya estaba en Votantes', true, $this->autoriza], [$ana['origen'], $ana['estado'], $ana['en_votantes'], $ana['lider_id']]);
        foreach (['Bruno', 'Carla'] as $nombre) {
            foreach (['cedula', 'celular', 'fijo', 'direccion', 'fecha_nacimiento'] as $field) {
                $this->assertNull($rows[$nombre][$field], "{$nombre}.{$field}");
            }
            $this->assertSame('El Poblado', $rows[$nombre]['barrio']);
        }
        $this->assertFalse($rows['Carla']['en_votantes']);
        $this->assertArrayNotHasKey('nota', $ana);
        $this->assertStringNotContainsString('3102222222', $response->getContent());
        $this->assertSame($before + 1, $this->used($this->ev));

        $this->agent("/api/agent/v1/eventos/{$this->event->id}/asistentes", ['link_status' => 'pendiente'])->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('meta.con_datos', 0);
        $this->assertSame($before + 1, $this->used($this->ev), 'a page without contact data costs nothing');

        $log = AuditLog::where('action', 'agent.evento.asistentes.list')->orderBy('created_at')->first();
        $this->assertSame($this->event->id, $log->entity_id);
        $this->assertStringNotContainsString('1098000001', json_encode($log->getAttributes()));
    }

    private function attendees_over_quota_are_429_and_charge_nothing(): void
    {
        $used = $this->used($this->ev);
        $this->assertGreaterThan(0, $used);
        config(['agent.contact_rows_daily_max' => $used]);

        $this->agent("/api/agent/v1/eventos/{$this->event->id}/asistentes")->assertStatus(429)->assertJsonPath('success', false);
        $this->assertSame($used, $this->used($this->ev));
        $this->assertSame('limite_diario', AuditLog::where('action', 'agent.evento.asistentes.list')->get()->last()->metadata['bloqueado'] ?? null);
    }

    // A cédula only finds an attendee whose lider authorized their data, whole or in part; a name finds anyone.
    private function attendees_search_by_cedula_only_finds_who_authorized(): void
    {
        $search = fn (string $q) => $this->agent("/api/agent/v1/eventos/{$this->event->id}/asistentes", ['q' => $q])->assertOk();
        $this->assertSame(0, $search('1098000002')->json('meta.total'), 'lider who did not authorize');
        $this->assertSame(0, $search('1098000003')->json('meta.total'), 'attendee without lider');
        $this->assertSame(0, $search('000002')->json('meta.total'));
        $this->assertSame(['Ana'], array_column($search('10980000')->json('data'), 'nombres'));
        $this->assertSame(['Ana'], array_column($search('1098000001')->json('data'), 'nombres'));
        $this->assertSame(['Bruno'], array_column($search('bruno')->json('data'), 'nombres'));
        $this->assertSame(['Carla'], array_column($search('CARLA asis')->json('data'), 'nombres'));
    }

    private function attendees_need_view_eventos_and_a_visible_event(): void
    {
        $this->agent("/api/agent/v1/eventos/{$this->event->id}/asistentes", [], $this->reg)->assertForbidden();
        $this->agent("/api/agent/v1/eventos/{$this->event->id}/asistentes/export", [], $this->reg)->assertForbidden();
        $this->agent("/api/agent/v1/eventos/{$this->velezEvent->id}/asistentes")->assertNotFound();
        $this->agent("/api/agent/v1/eventos/{$this->otraEvent->id}/asistentes/export")->assertNotFound();
    }

    // ── D. GET /eventos/{id}/asistentes/export ──

    private function export_is_the_web_excel_with_only_authorized_contacts(): void
    {
        $before = $this->used($this->ev);
        $response = $this->agent("/api/agent/v1/eventos/{$this->event->id}/asistentes/export")->assertOk()
            ->assertHeader('X-Rows-With-Data', '1')
            ->assertHeader('X-Rows-Delivered', '3')
            ->assertHeader('X-Total-Count', '3')
            ->assertHeader('X-Export-Truncated', 'false');
        $this->assertStringContainsString('asistentes-gira-giron.xlsx', $response->headers->get('Content-Disposition'));

        $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray();
        $this->assertSame(['NOMBRES', 'APELLIDOS', 'CÉDULA', 'CELULAR'], array_slice($sheet[0], 0, 4));
        $byName = collect(array_slice($sheet, 1))->keyBy(0);
        $this->assertSame('1098000001', (string) $byName['Ana'][2]);
        $this->assertSame(['—', '—'], [$byName['Bruno'][2], $byName['Bruno'][3]], 'GenericExport writes an empty cell as —');
        $this->assertSame('—', $byName['Carla'][2]);
        $cells = json_encode($sheet);
        foreach (['1098000002', '3102222222', '1098000003', '3103333333'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $cells);
        }

        $this->assertSame($before + 1, $this->used($this->ev));
        $log = AuditLog::where('action', 'EVENTO_ASISTENTES_EXPORTADOS')->sole();
        $this->assertSame([$this->event->id, 'agent', 3, 1], [$log->entity_id, $log->metadata['via'], $log->metadata['filas'], $log->metadata['con_datos']]);

        // A cédula only finds an attendee whose lider authorized their data, whole or in part; a name finds anyone.
        foreach (['1098000002' => 0, '1098000003' => 0, '10980000' => 1, '1098000001' => 1, 'bruno' => 1] as $q => $total) {
            $this->agent("/api/agent/v1/eventos/{$this->event->id}/asistentes/export", ['q' => $q])->assertOk()->assertHeader('X-Total-Count', (string) $total);
        }
    }

    private function export_without_quota_is_429(): void
    {
        $used = $this->used($this->ev);
        config(['agent.contact_rows_daily_max' => $used]);
        $exports = AuditLog::where('action', 'EVENTO_ASISTENTES_EXPORTADOS')->count();
        $this->agent("/api/agent/v1/eventos/{$this->event->id}/asistentes/export")->assertStatus(429);
        $this->assertSame($used, $this->used($this->ev));
        $this->assertSame($exports, AuditLog::where('action', 'EVENTO_ASISTENTES_EXPORTADOS')->count(), 'a refused export is not an export');
        $this->assertSame('limite_diario', AuditLog::where('action', 'agent.evento.asistentes.export')->get()->last()->metadata['bloqueado'] ?? null);
    }

    // ── E. GET /eventos/territorio ──

    private function territory_view(): void
    {
        $this->agent('/api/agent/v1/eventos/territorio')->assertStatus(422);
        $this->agent('/api/agent/v1/eventos/territorio', ['municipio_id' => $this->velez])->assertForbidden();
        $this->agent('/api/agent/v1/eventos/territorio', ['municipio_id' => (string) Str::uuid()])->assertNotFound();
        $this->agent('/api/agent/v1/eventos/territorio', ['municipio_id' => $this->giron], $this->reg)->assertForbidden();

        $before = $this->used($this->ev);
        $response = $this->agent('/api/agent/v1/eventos/territorio', ['municipio_id' => $this->giron, 'barrio' => 'el poblado'])->assertOk()
            ->assertJsonPath('data.municipio.nombre', 'Girón')
            ->assertJsonPath('data.municipio.provincia', Provincias::display('Soto'))
            ->assertJsonPath('data.kpis.asistentes', 3)
            ->assertJsonPath('data.asistentes_total', 3)
            ->assertJsonPath('meta.con_datos', 1);
        $asistentes = collect($response->json('data.asistentes'))->keyBy('nombres');
        $this->assertSame(['1098000001', '3101111111'], [$asistentes['Ana']['cedula'], $asistentes['Ana']['celular']]);
        $this->assertSame([null, null], [$asistentes['Bruno']['cedula'], $asistentes['Bruno']['celular']]);
        $this->assertSame([null, null], [$asistentes['Carla']['cedula'], $asistentes['Carla']['celular']]);
        $this->assertSame($before + 1, $this->used($this->ev));

        $eventos = $this->agent('/api/agent/v1/eventos/territorio', ['municipio_id' => $this->giron])->assertOk()->json('data.eventos');
        $this->assertEqualsCanonicalizing([$this->event->id, $this->sub->id], array_column($eventos, 'id'));
    }

    // ── F. GET /compromisos/{id} ──

    private function compromiso_detail(): void
    {
        $before = $this->used($this->ev);
        $response = $this->agent("/api/agent/v1/compromisos/{$this->compromisoA}")->assertOk()
            ->assertJsonPath('data.descripcion', 'Arreglar la vía')
            ->assertJsonPath('data.origen', 'evento')
            ->assertJsonPath('data.evento.id', $this->event->id)
            ->assertJsonPath('data.acta.id', $this->actaA)
            ->assertJsonPath('meta.con_datos', 1);
        $this->assertArrayNotHasKey('editable', $response->json('data'));
        $responsables = collect($response->json('data.responsables'))->keyBy('nombre');
        $this->assertSame('3101111111', $responsables['Ana Autoriza']['telefono']);
        $this->assertNull($responsables['Bruno Callado']['telefono']);
        $this->assertSame(['nombre' => 'foto vía.jpg'], $response->json('data.avances.0.archivo'));
        $this->assertStringNotContainsString('/archivo', $response->getContent());
        $this->assertStringNotContainsString('3102222222', $response->getContent());
        $this->assertSame($before + 1, $this->used($this->ev));
        $log = AuditLog::where('action', 'agent.compromiso.show')->sole();
        $this->assertSame(['compromiso', $this->compromisoA], [$log->entity_type, $log->entity_id]);
    }

    private function compromiso_access(): void
    {
        $this->agent("/api/agent/v1/compromisos/{$this->compromisoOrg}")->assertNotFound();
        $emp = $this->agentUser(['view.empresarios'], [$this->giron]);
        $this->agent("/api/agent/v1/compromisos/{$this->compromisoOrg}", [], $emp)->assertOk()->assertJsonPath('data.organizacion.nombre', 'Lácteos SAS');
        $this->agent("/api/agent/v1/compromisos/{$this->compromisoA}", [], $emp)->assertNotFound();
        $borrador = DB::table('event_compromisos')->where('descripcion', 'Borrador abierto')->value('id');
        $this->agent("/api/agent/v1/compromisos/{$borrador}")->assertNotFound();
        $this->agent('/api/agent/v1/compromisos/'.Str::uuid())->assertNotFound();
    }

    private function nothing_under_eventos_writes(): void
    {
        $paths = ['/api/agent/v1/eventos', "/api/agent/v1/eventos/{$this->event->id}", "/api/agent/v1/eventos/{$this->event->id}/asistentes",
            "/api/agent/v1/eventos/{$this->event->id}/asistentes/export", '/api/agent/v1/eventos/territorio', "/api/agent/v1/compromisos/{$this->compromisoA}"];
        foreach ($paths as $path) {
            foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $this->assertContains($this->agent($path, [], $this->manager, $method)->status(), [404, 405], "{$method} {$path}");
            }
        }
        $this->assertSame('pendiente', DB::table('event_compromisos')->where('id', $this->compromisoA)->value('estado'));
    }
}
