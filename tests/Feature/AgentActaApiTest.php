<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\User;
use App\Services\Agent\AgentContactQuota;
use App\Services\Agent\AgentIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// GET /api/agent/v1/actas, /actas/{acta}, /actas/{acta}/pdf and /compromisos on SQLite: read-only, each origin with
// its own permission, campaign and territory as the web, and contacts of listed persons only if they authorized.
// Each test in its own process: the suite already runs close to the memory limit of the shared process.
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class AgentActaApiTest extends TestCase
{
    use BuildsEventosScenario;

    private const SECRET = 'test-agent-secret-0123456789abcdef';

    private User $manager;

    private User $ev;

    private CampaignEvent $event;

    private string $autoriza;

    private string $callado;

    private string $actaA;

    private string $borrador;

    private string $actaOrg;

    private string $actaVelez;

    private string $actaOtra;

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
        $this->autoriza = $this->lider(Campaign::DEFAULT_ID, 'Ana Autoriza', '1098000001', ['telefono' => '3101111111', 'autoriza_datos' => true]);
        $this->callado = $this->lider(Campaign::DEFAULT_ID, 'Bruno Callado', '1098000002', ['telefono' => '3102222222', 'autoriza_datos' => false]);

        $this->event = $this->makeEvent(Campaign::DEFAULT_ID);
        $this->actaA = $this->eventActa($this->event, 'Acta Girón', [
            $this->compromiso('Arreglar la vía', '2026-01-10'),
            $this->compromiso('Instalar cámaras', '2099-01-10'),
        ]);
        $this->borrador = $this->eventActa($this->event, 'Borrador Girón', [$this->compromiso('Borrador vencido', '2026-01-10')], false);
        $this->actaVelez = $this->eventActa($this->makeEvent(Campaign::DEFAULT_ID, ['nombre' => 'Gira Vélez', 'municipio_id' => $this->velez, 'provincia_id' => null]),
            'Acta Vélez', [$this->compromiso('Fuera del territorio', '2026-01-10')]);
        $otraUser = $this->eventUser($this->campY, ['view.eventos', 'manage.eventos']);
        $this->actaOtra = $this->eventActa($this->makeEvent($this->campY, ['nombre' => 'Gira Otra']), 'Acta Otra', [$this->compromiso('Otra campaña', '2026-01-10')], true, $otraUser);

        $org = (string) Str::uuid();
        DB::table('organizaciones')->insert(['id' => $org, 'campaign_id' => Campaign::DEFAULT_ID, 'tipo' => 'empresa', 'nombre' => 'Lácteos SAS',
            'municipio_id' => $this->giron, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actaOrg = $this->actingAs($this->manager)->postJson("/empresarios/{$org}/actas", [
            'titulo' => 'Junta Lácteos', 'fecha' => '2026-11-10', 'compromisos' => [$this->compromiso('Enviar propuesta', '2026-01-10')],
        ])->assertCreated()->json('data.id');
        $this->actingAs($this->manager)->postJson("/empresarios/{$org}/actas/{$this->actaOrg}/confirmar")->assertOk();

        // Who each person is, as confirming or the suggestions would have linked them.
        DB::table('event_acta_participantes')->where('acta_id', $this->actaA)->delete();
        foreach ([['Ana Autoriza', '1098000001', '3101111111', $this->autoriza], ['Bruno Callado', '1098000002', '3102222222', $this->callado], ['Texto Libre', '1098000003', '3103333333', null]] as $i => [$nombre, $cedula, $telefono, $lider]) {
            DB::table('event_acta_participantes')->insert(['id' => (string) Str::uuid(), 'acta_id' => $this->actaA, 'orden' => $i, 'nombre' => $nombre,
                'cedula' => $cedula, 'telefono' => $telefono, 'lider_id' => $lider, 'created_at' => now(), 'updated_at' => now()]);
        }
        $compromisoA = DB::table('event_compromisos')->where('acta_id', $this->actaA)->where('descripcion', 'Arreglar la vía')->value('id');
        DB::table('event_compromiso_responsables')->where('compromiso_id', $compromisoA)->delete();
        foreach ([['Ana Autoriza', '3101111111', $this->autoriza], ['Bruno Callado', '3102222222', $this->callado]] as $i => [$nombre, $telefono, $lider]) {
            DB::table('event_compromiso_responsables')->insert(['id' => (string) Str::uuid(), 'compromiso_id' => $compromisoA, 'orden' => $i,
                'nombre' => $nombre, 'telefono' => $telefono, 'lider_id' => $lider, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function agentUser(array $permissions, ?array $territory = null): User
    {
        $user = $this->eventUser(Campaign::DEFAULT_ID, [...$permissions, 'use.whatsapp'], 'R09_CAMPO', $territory);
        $user->forceFill(['whatsapp_phone' => '+5730'.random_int(10000000, 99999999), 'whatsapp_verified_at' => now()])->save();

        return $user;
    }

    private function compromiso(string $descripcion, string $limite): array
    {
        return ['descripcion' => $descripcion, 'fecha_limite' => $limite, 'responsables' => [['nombre' => 'Responsable Texto', 'telefono' => '3209999999']]];
    }

    private function eventActa(CampaignEvent $event, string $titulo, array $compromisos, bool $confirm = true, ?User $user = null): string
    {
        $id = $this->actingAs($user ?? $this->manager)->postJson("/eventos/{$event->id}/actas", [
            'titulo' => $titulo, 'fecha' => '2026-11-08', 'compromisos' => $compromisos,
        ])->assertCreated()->json('data.id');
        if ($confirm) {
            $this->actingAs($user ?? $this->manager)->postJson("/eventos/{$event->id}/actas/{$id}/confirmar")->assertOk();
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

    public function test_list_shows_each_origin_only_with_its_permission_and_territory(): void
    {
        $response = $this->agent('/api/agent/v1/actas')->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertEqualsCanonicalizing([$this->actaA, $this->borrador], $this->ids($response));
        $row = collect($response->json('data'))->firstWhere('id', $this->actaA);
        $this->assertSame(['id' => $this->event->id, 'nombre' => 'Gira Girón'], $row['evento']);
        $this->assertSame(['evento', null, 2, 2, 'confirmada'], [$row['origen'], $row['organizacion'], $row['compromisos'], $row['abiertos'], $row['estado']]);
        $this->assertSame($this->manager->name, $row['created_by']);

        $emp = $this->agentUser(['view.empresarios'], [$this->giron]);
        $this->assertSame([$this->actaOrg], $this->ids($this->agent('/api/agent/v1/actas', [], $emp)->assertOk()));

        $this->agent('/api/agent/v1/actas', [], $this->agentUser(['register.asistentes'], [$this->giron]))->assertForbidden();

        $this->assertSame([$this->borrador], $this->ids($this->agent('/api/agent/v1/actas', ['estado' => 'borrador'])));
        $this->assertSame([$this->actaA], $this->ids($this->agent('/api/agent/v1/actas', ['q' => 'acta gir'])));
        $this->assertSame([], $this->ids($this->agent('/api/agent/v1/actas', ['origen' => 'organizacion'])));
        $this->assertSame(4, AuditLog::where('action', 'agent.actas.list')->where('user_id', $this->ev->id)->count());
        $this->assertStringNotContainsString('acta gir', AuditLog::where('action', 'agent.actas.list')->pluck('metadata')->toJson());
    }

    public function test_detail_hands_contacts_only_of_who_authorized(): void
    {
        $before = app(AgentContactQuota::class)->used($this->ev->id);
        $response = $this->agent("/api/agent/v1/actas/{$this->actaA}")->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.origen', 'evento')
            ->assertJsonPath('data.evento.municipio', 'Girón')
            ->assertJsonPath('data.organizacion', null)
            ->assertJsonPath('meta.con_datos', 1);

        $participantes = collect($response->json('data.participantes'))->keyBy('nombre');
        $this->assertSame(['1098000001', '3101111111'], [$participantes['Ana Autoriza']['cedula'], $participantes['Ana Autoriza']['telefono']]);
        $this->assertSame([null, null], [$participantes['Bruno Callado']['cedula'], $participantes['Bruno Callado']['telefono']]);
        $this->assertSame([null, null], [$participantes['Texto Libre']['cedula'], $participantes['Texto Libre']['telefono']]);

        $compromiso = collect($response->json('data.compromisos'))->firstWhere('descripcion', 'Arreglar la vía');
        $this->assertTrue($compromiso['vencido']);
        $this->assertSame('2026-01-10', $compromiso['fecha_limite']);
        $responsables = collect($compromiso['responsables'])->keyBy('nombre');
        $this->assertSame('3101111111', $responsables['Ana Autoriza']['telefono']);
        $this->assertNull($responsables['Bruno Callado']['telefono']);
        $this->assertStringNotContainsString('3102222222', $response->getContent());
        $this->assertStringNotContainsString('3209999999', $response->getContent());

        $this->assertSame($before + 1, app(AgentContactQuota::class)->used($this->ev->id));
        $log = AuditLog::where('action', 'agent.acta.show')->sole();
        $this->assertSame($this->actaA, $log->entity_id);
        $this->assertStringNotContainsString('3101111111', json_encode($log->getAttributes()));
    }

    public function test_detail_access_matrix(): void
    {
        $emp = $this->agentUser(['view.empresarios'], [$this->giron]);
        $this->agent("/api/agent/v1/actas/{$this->actaA}", [], $emp)->assertForbidden();
        $this->agent("/api/agent/v1/actas/{$this->actaOrg}", [], $emp)->assertOk()->assertJsonPath('data.organizacion.nombre', 'Lácteos SAS');
        $this->agent("/api/agent/v1/actas/{$this->actaOrg}")->assertForbidden();
        $this->agent("/api/agent/v1/actas/{$this->actaVelez}")->assertForbidden();
        $this->agent("/api/agent/v1/actas/{$this->actaOtra}")->assertNotFound();
        $this->agent('/api/agent/v1/actas/'.Str::uuid())->assertNotFound();
        $this->agent("/api/agent/v1/actas/{$this->actaA}/pdf", [], $emp)->assertForbidden();
    }

    public function test_pdf_of_an_acta(): void
    {
        $response = $this->agent("/api/agent/v1/actas/{$this->actaA}/pdf")->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Rows-With-Data', '1');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('filename=acta-acta-giron.pdf', $response->headers->get('Content-Disposition'));
        $this->assertSame($this->actaA, AuditLog::where('action', 'agent.acta.pdf')->sole()->entity_id);
    }

    public function test_vencidos_are_open_overdue_compromisos_of_confirmed_actas_only(): void
    {
        $response = $this->agent('/api/agent/v1/compromisos', ['vencidos' => 'true'])->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.con_datos', 1)
            ->assertJsonPath('data.0.descripcion', 'Arreglar la vía')
            ->assertJsonPath('data.0.vencido', true);
        $this->assertArrayNotHasKey('editable', $response->json('data.0'));
        $responsables = collect($response->json('data.0.responsables'))->keyBy('nombre');
        $this->assertSame('3101111111', $responsables['Ana Autoriza']['telefono']);
        $this->assertNull($responsables['Bruno Callado']['telefono']);

        DB::table('event_compromisos')->where('descripcion', 'Arreglar la vía')->update(['estado' => 'cumplido']);
        $this->agent('/api/agent/v1/compromisos', ['vencidos' => 'true'])->assertOk()->assertJsonPath('meta.total', 0);

        $all = $this->agent('/api/agent/v1/compromisos', ['vencidos' => 'true'], $this->manager)->assertOk();
        $this->assertEqualsCanonicalizing(['Fuera del territorio', 'Enviar propuesta'], array_column($all->json('data'), 'descripcion'));
        $this->assertSame(2, AuditLog::where('action', 'agent.compromisos.list')->where('user_id', $this->ev->id)->count());
        $this->assertStringNotContainsString('3101111111', AuditLog::where('action', 'agent.compromisos.list')->pluck('metadata')->toJson());
    }

    public function test_compromisos_filter_by_evento_id_estado_and_creadas_por_mi(): void
    {
        $otro = $this->makeEvent(Campaign::DEFAULT_ID, ['nombre' => 'Gira Dos']);
        $this->eventActa($otro, 'Acta Dos', [$this->compromiso('Compromiso del otro evento', '2099-02-01')]);

        $all = $this->agent('/api/agent/v1/compromisos')->assertOk();
        $this->assertContains('Compromiso del otro evento', array_column($all->json('data'), 'descripcion'));

        $filtered = $this->agent('/api/agent/v1/compromisos', ['evento_id' => $this->event->id])->assertOk();
        $this->assertEqualsCanonicalizing(['Arreglar la vía', 'Instalar cámaras'], array_column($filtered->json('data'), 'descripcion'));
        $this->assertSame([$otro->id], array_unique(array_column(array_column(
            $this->agent('/api/agent/v1/compromisos', ['evento_id' => $otro->id])->json('data'), 'evento'), 'id')));

        $this->agent('/api/agent/v1/compromisos', ['estado' => 'pendiente', 'q' => 'cámaras'])->assertOk()->assertJsonPath('meta.total', 1);
        $this->agent('/api/agent/v1/compromisos', ['responsable' => 'bruno'])->assertOk()->assertJsonPath('meta.total', 1);
        $this->agent('/api/agent/v1/compromisos', ['creadas_por_mi' => 'true'])->assertOk()->assertJsonPath('meta.total', 0);
        $this->agent('/api/agent/v1/compromisos', ['creadas_por_mi' => 'true', 'evento_id' => $this->event->id], $this->manager)->assertOk()->assertJsonPath('meta.total', 2);
        $this->agent('/api/agent/v1/compromisos', ['desde' => '2026-11-08', 'hasta' => '2026-11-08', 'per_page' => 50])->assertOk()->assertJsonPath('meta.per_page', 20);
    }

    public function test_invalid_filters_are_422(): void
    {
        $this->agent('/api/agent/v1/compromisos', ['estado' => 'abierto'])->assertStatus(422);
        $this->agent('/api/agent/v1/compromisos', ['desde' => '08/11/2026'])->assertStatus(422);
        $this->agent('/api/agent/v1/compromisos', ['otro' => '1'])->assertStatus(422);
        $this->agent('/api/agent/v1/actas', ['estado' => 'cerrada'])->assertStatus(422);
    }

    public function test_nothing_under_actas_or_compromisos_writes(): void
    {
        $paths = ['/api/agent/v1/actas', "/api/agent/v1/actas/{$this->actaA}", "/api/agent/v1/actas/{$this->actaA}/pdf", '/api/agent/v1/compromisos',
            '/api/agent/v1/compromisos/'.DB::table('event_compromisos')->value('id')];
        foreach ($paths as $path) {
            foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $this->assertContains($this->agent($path, [], $this->manager, $method)->status(), [404, 405], "{$method} {$path}");
            }
        }
        $this->assertSame('confirmada', DB::table('event_actas')->where('id', $this->actaA)->value('estado'));
    }
}
