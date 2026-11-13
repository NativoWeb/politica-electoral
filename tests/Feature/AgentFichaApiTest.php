<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\User;
use App\Services\Agent\AgentContactQuota;
use App\Services\Agent\AgentFicha;
use App\Services\Agent\AgentIdentityService;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// GET /api/agent/v1/personas/{id}/ficha(/pdf) on SQLite for lideres (source='lider'). Electoral persons (source=
// 'persona', ilike) and the parity with the web on PostgreSQL are in AgentFichaApiPostgresTest.
// Each test in its own process: the suite already runs close to the memory limit of the shared process.
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class AgentFichaApiTest extends TestCase
{
    use BuildsEventosScenario;

    private const SECRET = 'test-agent-secret-0123456789abcdef';

    private string $bruno;

    private string $carla;

    private User $campo;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent.hmac_secret' => self::SECRET]);
        $this->setUpEventos();
        $this->addMapaVotantesTables();
        (require database_path('migrations/2026_10_19_183258_create_personal_access_tokens_table.php'))->up();
        (require database_path('migrations/2026_10_20_000001_create_lider_seguimientos_table.php'))->up();
        (require database_path('migrations/2026_11_10_000001_create_event_actas_tables.php'))->up();
        (require database_path('migrations/2026_11_11_000001_add_created_flags_to_event_acta_participantes.php'))->up();
        (require database_path('migrations/2026_11_13_000002_add_organizacion_to_event_actas.php'))->up();
        DB::table('campaigns')->update(['whatsapp_enabled' => true]);

        $this->bruno = $this->lider(Campaign::DEFAULT_ID, 'Bruno Sin Autorizar', '1011223344', [
            'telefono' => '3151112233', 'email' => 'bruno@correo.co', 'direccion' => 'Calle 1 # 2-3', 'observacion' => 'Prefiere llamadas',
            'barrio' => 'El Poblado', 'autoriza_datos' => false, 'fecha_nacimiento' => '1980-05-12', 'puesto_votacion' => 'Colegio Central',
            'mesa_votacion' => '4', 'profesion' => 'Abogado',
        ]);
        $this->carla = $this->lider(Campaign::DEFAULT_ID, 'Carla Fuera', '1055667788', ['geographic_unit_id' => $this->velez, 'municipio' => 'Vélez']);
        DB::table('lider_contactos')->insert(['id' => (string) Str::uuid(), 'lider_id' => $this->bruno, 'tipo' => 'celular', 'valor' => '3159990000', 'principal' => true, 'estado' => 'activo']);
        DB::table('nexos_familiares')->insert(['id' => (string) Str::uuid(), 'campaign_id' => Campaign::DEFAULT_ID, 'person_id' => $this->bruno,
            'nombre' => 'Marta Hermana', 'parentesco' => 'Hermana', 'cedula' => '2233445566', 'telefono' => '3004445566']);
        DB::table('lider_seguimientos')->insert(['id' => (string) Str::uuid(), 'lider_id' => $this->bruno, 'tipo' => 'llamada',
            'nota' => 'Confirmó asistencia', 'fecha' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);

        $this->campo = $this->agentUser(['view.mapa'], [$this->giron]);
    }

    private function agentUser(array $permissions, ?array $territory = null, string $campaignId = Campaign::DEFAULT_ID): User
    {
        $user = $this->eventUser($campaignId, [...$permissions, 'use.whatsapp'], 'R09_CAMPO', $territory);
        $user->forceFill(['whatsapp_phone' => '+5730'.random_int(10000000, 99999999), 'whatsapp_verified_at' => now()])->save();

        return $user;
    }

    private function agentGet(string $path, ?User $user = null, string $method = 'GET'): TestResponse
    {
        $token = ($user ?? $this->campo)->createToken(AgentIdentityService::TOKEN_NAME, ['agent'], now()->addMinutes(15))->plainTextToken;
        $ts = (string) now()->getTimestamp();
        $nonce = Str::random(32);
        $this->app['auth']->forgetGuards();

        return $this->call($method, $path, [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_X_AGENT_TIMESTAMP' => $ts,
            'HTTP_X_AGENT_NONCE' => $nonce,
            'HTTP_X_AGENT_SIGNATURE' => hash_hmac('sha256', "{$ts}\n{$nonce}\n{$method}\n{$path}\n", self::SECRET),
        ]);
    }

    private function ficha(string $id, ?User $user = null): TestResponse
    {
        return $this->agentGet("/api/agent/v1/personas/{$id}/ficha", $user);
    }

    public function test_ficha_delivers_the_contact_data_of_a_person_who_did_not_authorize_and_costs_one(): void
    {
        $quota = app(AgentContactQuota::class);
        $before = $quota->used($this->campo->id);

        $response = $this->ficha($this->bruno)->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $this->bruno)
            ->assertJsonPath('data.source', 'lider')
            ->assertJsonPath('data.cedula', '1011223344')
            ->assertJsonPath('data.telefono', '3151112233')
            ->assertJsonPath('data.email', 'bruno@correo.co')
            ->assertJsonPath('data.direccion', 'Calle 1 # 2-3')
            ->assertJsonPath('data.observacion', 'Prefiere llamadas')
            ->assertJsonPath('data.contactos.0.valor', '3159990000')
            ->assertJsonPath('data.nexos.0.cedula', '2233445566')
            ->assertJsonPath('data.datos_autorizados', false)
            ->assertJsonPath('data.coincidencia_por_nombre', false)
            ->assertJsonPath('data.cumple_mm_dd', '05-12')
            ->assertJsonPath('data.seguimientos.total', 1)
            ->assertJsonPath('data.seguimientos.items.0.nota', 'Confirmó asistencia')
            ->assertJsonPath('data.eventos', null)
            ->assertJsonPath('data.organizaciones', null)
            ->assertJsonPath('data.compromisos', null)
            ->assertJsonPath('meta.con_datos', 1);

        $this->assertArrayNotHasKey('partidos', $response->json('data'));
        $this->assertArrayNotHasKey('foto', $response->json('data'));
        $this->assertSame($before + 1, $quota->used($this->campo->id));
        $response->assertJsonPath('meta.cupo_restante', $quota->remaining($this->campo->id));
    }

    public function test_ficha_is_audited_with_the_resolved_id_and_without_personal_values(): void
    {
        $this->ficha($this->bruno)->assertOk();

        $log = AuditLog::where('action', 'agent.persona.ficha')->sole();
        $this->assertSame($this->bruno, $log->entity_id);
        $this->assertSame('persona', $log->entity_type);
        $this->assertSame('lider', $log->metadata['source']);
        $this->assertFalse($log->metadata['datos_autorizados']);
        $this->assertSame(1, $log->metadata['con_datos']);
        $this->assertSame(['seguimientos'], $log->metadata['secciones']);
        $json = json_encode($log->getAttributes());
        foreach (['1011223344', '3151112233', 'bruno@correo.co', 'Calle 1', 'Prefiere', '3159990000', '2233445566', 'Bruno'] as $value) {
            $this->assertStringNotContainsString($value, $json);
        }
    }

    public function test_parity_with_the_web_ficha_of_the_same_user(): void
    {
        $web = $this->actingAs($this->campo)->getJson("/mapa-politico/persona/{$this->bruno}")->assertOk()->json();
        $agent = $this->ficha($this->bruno)->assertOk()->json('data');

        $this->assertEqualsCanonicalizing(['foto', 'partidos'], array_values(array_diff(array_keys($web), array_keys($agent))));
        foreach (array_intersect_key($web, $agent) as $key => $value) {
            $this->assertSame($value, $agent[$key], "La llave {$key} difiere de la web.");
        }
    }

    public function test_access_matrix(): void
    {
        $this->ficha($this->carla)->assertForbidden();
        $this->ficha((string) Str::uuid())->assertNotFound();

        $otra = $this->lider($this->campY, 'Otra Campaña', '1077777777');
        $this->ficha($otra)->assertNotFound();

        $sinPermiso = $this->agentUser(['view.eventos'], [$this->giron]);
        $this->ficha($this->bruno, $sinPermiso)->assertForbidden();

        $votantes = $this->agentUser(['view.votantes'], [$this->giron]);
        $this->ficha($this->bruno, $votantes)->assertOk()->assertJsonPath('data.cedula', '1011223344');

        $person = (string) Str::uuid();
        DB::table('persons')->insert(['id' => $person, 'full_name' => 'Elena Electa', 'normalized_name' => 'elena electa']);
        $this->ficha($person, $votantes)->assertForbidden();

        $this->assertSame(0, AuditLog::where('action', 'agent.persona.ficha')->where('user_id', '!=', $votantes->id)->count());
    }

    public function test_a_merged_person_id_resolves_to_the_survivor(): void
    {
        $person = (string) Str::uuid();
        DB::table('persons')->insert(['id' => $person, 'full_name' => 'Fusionado', 'normalized_name' => 'fusionado', 'merged_into_id' => $this->bruno]);

        $this->ficha($person)->assertOk()->assertJsonPath('data.id', $this->bruno);
    }

    public function test_quota_exhausted_is_429_with_restantes_and_audited(): void
    {
        config(['agent.contact_rows_daily_max' => 1]);
        $this->ficha($this->bruno)->assertOk();

        $this->ficha($this->bruno)->assertStatus(429)
            ->assertJson(['success' => false, 'restantes' => 0]);
        $this->assertSame('limite_diario', AuditLog::where('action', 'agent.persona.ficha')->latest('created_at')->get()
            ->firstWhere(fn ($l) => isset($l->metadata['bloqueado']))?->metadata['bloqueado']);
    }

    public function test_a_ficha_without_contact_data_costs_nothing(): void
    {
        $vacio = $this->lider(Campaign::DEFAULT_ID, 'Sin Datos', null, ['telefono' => null, 'observacion' => null]);

        $this->ficha($vacio)->assertOk()->assertJsonPath('meta.con_datos', 0);
        $this->assertSame(0, app(AgentContactQuota::class)->used($this->campo->id));
    }

    public function test_sections_follow_their_own_permissions(): void
    {
        $eventos = $this->agentUser(['view.mapa', 'view.eventos'], [$this->giron]);
        $this->ficha($this->bruno, $eventos)->assertOk()
            ->assertJsonPath('data.eventos.total', 0)
            ->assertJsonPath('data.compromisos', [])
            ->assertJsonPath('data.organizaciones', null);

        $empresas = $this->agentUser(['view.mapa', 'view.empresarios'], [$this->giron]);
        $this->ficha($this->bruno, $empresas)->assertOk()
            ->assertJsonPath('data.eventos', null)
            ->assertJsonPath('data.organizaciones', [])
            ->assertJsonPath('data.compromisos', []);
    }

    public function test_referidos_and_responsables_keep_contacts_only_if_they_authorized(): void
    {
        $autoriza = $this->lider(Campaign::DEFAULT_ID, 'Referido Autoriza', '1200000001', ['telefono' => '3201111111', 'autoriza_datos' => true, 'referente_documento' => '1011223344']);
        $noAutoriza = $this->lider(Campaign::DEFAULT_ID, 'Referido Callado', '1200000002', ['telefono' => '3202222222', 'referente_documento' => '1011223344']);

        $manager = $this->agentUser(['view.mapa', 'view.eventos', 'manage.eventos']);
        $event = $this->makeEvent(Campaign::DEFAULT_ID);
        $acta = $this->actingAs($manager)->postJson("/eventos/{$event->id}/actas", ['titulo' => 'Acta', 'fecha' => '2026-11-08', 'compromisos' => [
            ['descripcion' => 'Llevar sillas', 'responsables' => [
                ['nombre' => 'Bruno Sin Autorizar', 'telefono' => '3151112233', 'lider_id' => $this->bruno],
                ['nombre' => 'Referido Autoriza', 'telefono' => '3201111111', 'lider_id' => $autoriza],
            ]],
        ]])->assertCreated()->json('data.id');
        $this->actingAs($manager)->postJson("/eventos/{$event->id}/actas/{$acta}/confirmar")->assertOk();

        $response = $this->ficha($this->bruno, $manager)->assertOk();
        $data = $response->json('data');
        $referidos = collect($data['referidos'])->keyBy('id');
        $this->assertSame('3201111111', $referidos[$autoriza]['telefono']);
        $this->assertSame('1200000001', $referidos[$autoriza]['cedula']);
        $this->assertNull($referidos[$noAutoriza]['telefono']);
        $this->assertNull($referidos[$noAutoriza]['cedula']);

        $responsables = collect($data['compromisos'][0]['responsables'])->keyBy('lider_id');
        $this->assertSame('3151112233', $responsables[$this->bruno]['telefono'], 'The own person keeps its phone');
        $this->assertSame('3201111111', $responsables[$autoriza]['telefono']);
        $this->assertArrayNotHasKey('editable', $data['compromisos'][0]);

        // Bruno himself (1) + Referido Autoriza, once although referido and responsable (1); never Bruno twice.
        $response->assertJsonPath('meta.con_datos', 2);
        $this->assertSame(2, app(AgentContactQuota::class)->used($manager->id));
        $this->assertSame(2, AuditLog::where('action', 'agent.persona.ficha')->where('user_id', $manager->id)->sole()->metadata['con_datos']);
    }

    public function test_each_authorized_referido_costs_one_more(): void
    {
        foreach (['1300000001', '1300000002', '1300000003'] as $cedula) {
            $this->lider(Campaign::DEFAULT_ID, "Referido {$cedula}", $cedula, ['telefono' => '320'.substr($cedula, -7), 'autoriza_datos' => true, 'referente_documento' => '1011223344']);
        }
        $this->lider(Campaign::DEFAULT_ID, 'Referido Callado', '1300000009', ['referente_documento' => '1011223344']);

        $this->ficha($this->bruno)->assertOk()->assertJsonPath('meta.con_datos', 4);
        $this->assertSame(4, app(AgentContactQuota::class)->used($this->campo->id));

        $this->agentGet("/api/agent/v1/personas/{$this->bruno}/ficha/pdf")->assertOk()->assertHeader('X-Rows-With-Data', '4');
        $this->assertSame(8, app(AgentContactQuota::class)->used($this->campo->id));
    }

    public function test_a_ficha_the_quota_left_cannot_cover_is_refused_whole(): void
    {
        $this->lider(Campaign::DEFAULT_ID, 'Referido Uno', '1300000001', ['autoriza_datos' => true, 'referente_documento' => '1011223344']);
        config(['agent.contact_rows_daily_max' => 3]);
        $this->ficha($this->vacio())->assertOk();
        app(AgentContactQuota::class)->consume($this->campo->id, 2);

        $this->ficha($this->bruno)->assertStatus(429)->assertJson(['success' => false, 'restantes' => 1]);
        $this->assertSame(2, app(AgentContactQuota::class)->used($this->campo->id));
        $this->ficha($this->vacio())->assertOk()->assertJsonPath('meta.con_datos', 0);
    }

    private function vacio(): string
    {
        return DB::table('lideres')->where('nombre', 'Sin Datos')->value('id')
            ?? $this->lider(Campaign::DEFAULT_ID, 'Sin Datos', null, ['telefono' => null, 'observacion' => null]);
    }

    public function test_pdf_is_a_pdf_with_headers_quota_and_audit(): void
    {
        $response = $this->agentGet("/api/agent/v1/personas/{$this->bruno}/ficha/pdf")->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Rows-With-Data', '1');

        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('attachment; filename=ficha-bruno-sin-autorizar.pdf', $response->headers->get('Content-Disposition'));
        $this->assertSame(1, app(AgentContactQuota::class)->used($this->campo->id));
        $this->assertSame($this->bruno, AuditLog::where('action', 'agent.persona.ficha_pdf')->sole()->entity_id);
    }

    public function test_pdf_has_the_same_403_and_404(): void
    {
        $this->agentGet("/api/agent/v1/personas/{$this->carla}/ficha/pdf")->assertForbidden();
        $this->agentGet('/api/agent/v1/personas/'.Str::uuid().'/ficha/pdf')->assertNotFound();
        $this->assertSame(0, app(AgentContactQuota::class)->used($this->campo->id));
    }

    public function test_a_failed_render_gives_the_quota_back(): void
    {
        $pdf = Mockery::mock(DomPdf::class);
        $pdf->shouldReceive('setPaper', 'setOption')->andReturnSelf();
        $pdf->shouldReceive('download')->andThrow(new RuntimeException('render'));
        Pdf::shouldReceive('loadView')->andReturn($pdf);

        $this->agentGet("/api/agent/v1/personas/{$this->bruno}/ficha/pdf")->assertServerError();
        $this->assertSame(0, app(AgentContactQuota::class)->used($this->campo->id));
        $this->assertSame(0, AuditLog::where('action', 'agent.persona.ficha_pdf')->count());
    }

    public function test_pdf_view_renders_every_section_and_escapes(): void
    {
        DB::table('lideres')->where('id', $this->bruno)->update(['nombre' => '<script>alert(1)</script> Bruno']);
        $manager = $this->agentUser(['view.mapa']);
        $this->actingAs($manager);
        request()->setUserResolver(fn () => $manager);
        [$data] = app(AgentFicha::class)->build(request(), $this->bruno);

        $html = view('reports.ficha', ['f' => $data, 'date' => '12/11/2026'])->render();
        foreach (['FICHA PERSONAL', '1011223344', 'Marta Hermana', 'Confirmó asistencia', 'El Poblado', 'Colegio Central', '3159990000'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_write_verbs_on_the_ficha_are_not_routes(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $status = $this->agentGet("/api/agent/v1/personas/{$this->bruno}/ficha", null, $method)->status();
            $this->assertContains($status, [404, 405], $method);
        }
    }
}
