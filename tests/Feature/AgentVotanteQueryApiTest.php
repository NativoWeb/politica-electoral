<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\Agent\AgentIdentityService;
use App\Services\Audit\AuditDataMasker;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\WithCampaignColumns;
use Tests\TestCase;

// GET /api/agent/v1/votantes and /votantes/export on SQLite (tables built by hand, agent tables from their migrations).
// The name/cédula search (ilike) is covered on PostgreSQL by AgentVotanteQueryApiPostgresTest.
class AgentVotanteQueryApiTest extends TestCase
{
    use WithCampaignColumns;

    private const SECRET = 'test-agent-secret-0123456789abcdef';

    private string $munA;

    private string $munB;

    private string $autorizadoA;

    private string $sinAutorizarA;

    private string $liderB;

    private User $campo;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent.hmac_secret' => self::SECRET]);
        $this->createSchema();
        $this->addCampaignColumns();

        $this->munA = $this->geo('Girón');
        $this->munB = $this->geo('Lebrija');
        $this->autorizadoA = $this->lider($this->munA, 'Ana Autoriza', '1098765432', true, ['email' => 'ana@correo.co', 'gran_elector' => true]);
        $this->sinAutorizarA = $this->lider($this->munA, 'Bruno Sin Autorizar', '1011223344', false, ['email' => 'bruno@correo.co']);
        $this->liderB = $this->lider($this->munB, 'Carla Territorio B', '1055667788', true);
        DB::table('lider_contactos')->insert([
            ['id' => (string) Str::uuid(), 'lider_id' => $this->autorizadoA, 'tipo' => 'celular', 'valor' => '3157778899', 'principal' => true, 'estado' => 'activo'],
            ['id' => (string) Str::uuid(), 'lider_id' => $this->autorizadoA, 'tipo' => 'fijo', 'valor' => '6076001122', 'principal' => false, 'estado' => 'activo'],
            ['id' => (string) Str::uuid(), 'lider_id' => $this->sinAutorizarA, 'tipo' => 'celular', 'valor' => '3159990000', 'principal' => true, 'estado' => 'activo'],
        ]);

        $this->campo = $this->linkedUser('R09_CAMPO', '+573001110000', [$this->munA], ['view.votantes']);
    }

    private function createSchema(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code');
            $t->string('name');
            $t->string('description')->nullable();
            $t->integer('level')->default(1);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('email');
            $t->string('password');
            $t->uuid('role_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('must_change_password')->default(false);
            $t->json('permissions')->nullable();
            $t->json('territory_scope')->nullable();
            $t->string('preferred_density')->nullable();
            $t->boolean('executive_mode')->default(false);
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('geographic_units', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->string('canonical_name');
            $t->uuid('parent_id')->nullable();
            $t->timestamps();
        });
        $booleans = ['is_active', 'destacado', 'militante', 'autoriza_datos', 'verificado', 'fallecido',
            'empresario', 'reservista', 'funcionario', 'exfuncionario', 'gran_elector'];
        Schema::create('lideres', function (Blueprint $t) use ($booleans) {
            $t->uuid('id')->primary();
            $t->uuid('geographic_unit_id')->nullable();
            $t->integer('votos')->default(0);
            foreach ($booleans as $col) {
                $t->boolean($col)->default($col === 'is_active');
            }
            foreach (['nombre', 'provincia', 'municipio', 'cargo', 'tipo', 'telefono', 'email', 'observacion', 'partido',
                'referente_documento', 'municipio_votacion', 'puesto_votacion', 'mesa_votacion'] as $col) {
                $t->string($col)->nullable();
            }
            $t->string('cedula')->nullable()->unique();
            $t->string('nivel_confianza')->default('sin_llamar');
            $t->timestamps();
        });
        Schema::create('lider_contactos', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('lider_id');
            $t->string('tipo')->nullable();
            $t->string('valor')->nullable();
            $t->boolean('principal')->default(false);
            $t->string('estado')->nullable();
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->nullable();
            $t->string('action', 80);
            $t->string('entity_type', 80)->nullable();
            $t->uuid('entity_id')->nullable();
            $t->json('metadata')->nullable();
            $t->json('before_state')->nullable();
            $t->json('after_state')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->string('correlation_id', 50)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
        // persons: the agent_ro views migration (2026_10_19) joins it.
        Schema::create('persons', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('full_name');
            $t->timestamps();
        });
        Schema::create('nexos_familiares', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('person_id');
            $t->timestamps();
        });
        // Only read by the listing's eventos_count, for users who can see event attendees.
        Schema::create('events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('campaign_id')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('event_attendees', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('event_id');
            $t->uuid('lider_id')->nullable();
        });

        foreach ([...glob(database_path('migrations/2026_10_19_*.php')), ...glob(database_path('migrations/2026_10_20_*.php'))] as $file) {
            (require $file)->up();
        }
    }

    private function geo(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('geographic_units')->insert(['id' => $id, 'type' => 'municipality', 'canonical_name' => $name]);

        return $id;
    }

    private function lider(string $geoId, string $nombre, string $cedula, ?bool $autoriza, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('lideres')->insert([
            'id' => $id, 'geographic_unit_id' => $geoId, 'nombre' => $nombre, 'cedula' => $cedula,
            'municipio' => DB::table('geographic_units')->where('id', $geoId)->value('canonical_name'),
            'telefono' => '300'.substr($cedula, -7), 'is_active' => true, 'nivel_confianza' => 'contactado',
            'autoriza_datos' => $autoriza, 'puesto_votacion' => 'Colegio Central', 'mesa_votacion' => '4',
            ...$extra,
        ]);

        return $id;
    }

    private function linkedUser(string $roleCode, string $phone, ?array $scope, array $permissions): User
    {
        $role = Role::firstOrCreate(['code' => $roleCode], ['name' => $roleCode, 'level' => 1]);
        $user = User::create([
            'name' => $roleCode.' user', 'email' => Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => $role->id, 'territory_scope' => $scope, 'permissions' => [...$permissions, 'use.whatsapp'],
        ]);
        $user->forceFill(['whatsapp_phone' => $phone, 'whatsapp_verified_at' => now()])->save();

        return $user;
    }

    private function tokenFor(User $user, int $minutes = 15): string
    {
        return $user->createToken(AgentIdentityService::TOKEN_NAME, ['agent'], now()->addMinutes($minutes))->plainTextToken;
    }

    private function agentGet(string $path, array $query, ?string $token, array $headers = [], bool $sign = true): TestResponse
    {
        $uri = $query === [] ? $path : $path.'?'.http_build_query($query);
        $ts = (string) now()->getTimestamp();
        $nonce = Str::random(32);
        $server = ['HTTP_ACCEPT' => 'application/json'];
        if ($sign) {
            $server += [
                'HTTP_X_AGENT_TIMESTAMP' => $ts,
                'HTTP_X_AGENT_NONCE' => $nonce,
                'HTTP_X_AGENT_SIGNATURE' => hash_hmac('sha256', "{$ts}\n{$nonce}\nGET\n{$uri}\n", self::SECRET),
            ];
        }
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $this->app['auth']->forgetGuards();

        return $this->call('GET', $uri, [], [], [], $server);
    }

    private function list(array $query = [], ?User $user = null): TestResponse
    {
        return $this->agentGet('/api/agent/v1/votantes', $query, $this->tokenFor($user ?? $this->campo));
    }

    private function auditJson(): string
    {
        return DB::table('audit_logs')->pluck('metadata')->implode("\n");
    }

    public function test_scoped_user_only_lists_its_territory(): void
    {
        $response = $this->list()->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.last_page', 1);

        $this->assertEqualsCanonicalizing([$this->autorizadoA, $this->sinAutorizarA], array_column($response->json('data'), 'id'));
        $this->assertStringNotContainsString('Carla Territorio B', $response->getContent());
    }

    public function test_filtering_by_a_municipio_outside_the_territory_is_403(): void
    {
        $this->list(['municipio_id' => $this->munB])
            ->assertForbidden()
            ->assertJson(['success' => false, 'message' => 'Ese municipio está fuera de tu territorio.'])
            ->assertJsonMissing(['nombre' => 'Carla Territorio B']);
        $this->assertSame(0, DB::table('audit_logs')->count());

        $this->list(['municipio_id' => $this->munA])->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_unscoped_user_sees_every_territory_and_can_filter_by_municipio(): void
    {
        $consulta = $this->linkedUser('R10_CONSULTA', '+573002220000', null, ['view.votantes']);

        $this->list([], $consulta)->assertOk()->assertJsonPath('meta.total', 3);
        $this->list(['municipio_id' => $this->munB], $consulta)->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $this->liderB);
    }

    public function test_contact_data_only_for_persons_who_authorized_it(): void
    {
        $rows = collect($this->list()->assertOk()->json('data'))->keyBy('id');

        $ana = $rows[$this->autorizadoA];
        $this->assertTrue($ana['datos_autorizados']);
        $this->assertSame('1098765432', $ana['cedula']);
        $this->assertSame('3008765432', $ana['telefono']);
        $this->assertSame('ana@correo.co', $ana['email']);
        $this->assertSame([['tipo' => 'celular', 'valor' => '3157778899', 'estado' => 'activo']], $ana['contactos']);
        $this->assertSame('Girón', $ana['municipio']);
        $this->assertSame('contactado', $ana['nivel_confianza']);
        $this->assertSame('Colegio Central', $ana['puesto_votacion']);
        $this->assertSame('4', $ana['mesa_votacion']);
        $this->assertTrue($ana['gran_elector']);
        $this->assertFalse($ana['militante']);

        $bruno = $rows[$this->sinAutorizarA];
        $this->assertFalse($bruno['datos_autorizados']);
        foreach (['cedula', 'telefono', 'email', 'contactos'] as $field) {
            $this->assertArrayNotHasKey($field, $bruno);
        }
        $this->assertSame('Bruno Sin Autorizar', $bruno['nombre']);
        $this->assertSame('Colegio Central', $bruno['puesto_votacion']);
    }

    public function test_filters_and_pagination(): void
    {
        $this->list(['gran_elector' => 'true'])->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->autorizadoA);
        $this->list(['gran_elector' => '0'])->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->sinAutorizarA);
        $this->list(['nivel_confianza' => 'confirmado'])->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);

        DB::table('lideres')->where('id', $this->sinAutorizarA)->update(['is_active' => false]);
        $this->list()->assertJsonPath('meta.total', 1);
        $this->list(['is_active' => 'false'])->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->sinAutorizarA);

        $this->list(['per_page' => 1, 'is_active' => '1'])->assertJsonPath('meta.last_page', 1);
        DB::table('lideres')->where('id', $this->sinAutorizarA)->update(['is_active' => true]);
        $this->list(['per_page' => 1, 'page' => 2])->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.id', $this->sinAutorizarA);
    }

    public function test_per_page_above_50_is_capped(): void
    {
        for ($i = 0; $i < 55; $i++) {
            $this->lider($this->munA, 'Relleno '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), '20000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), false);
        }

        $this->list(['per_page' => 500])->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.total', 57)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_invalid_or_unknown_query_parameters_are_422(): void
    {
        foreach ([['nivel_confianza' => 'inventado'], ['municipio_id' => 'no-uuid'], ['municipio_id' => (string) Str::uuid()],
            ['gran_elector' => 'quizas'], ['per_page' => 0], ['page' => 'x'], ['campana' => 'otra'], ['cedula' => '1']] as $query) {
            $this->list($query)->assertStatus(422)->assertJson(['success' => false])->assertJsonValidationErrors(array_keys($query));
        }
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_permission_signature_and_token_are_enforced(): void
    {
        $sinPermiso = $this->linkedUser('R09_CAMPO', '+573004440000', [$this->munA], []);
        $this->list([], $sinPermiso)->assertForbidden()->assertJson(['success' => false]);
        $this->agentGet('/api/agent/v1/votantes/export', [], $this->tokenFor($sinPermiso))->assertForbidden();

        $this->agentGet('/api/agent/v1/votantes', [], $this->tokenFor($this->campo), [], false)->assertUnauthorized()->assertJson(['success' => false]);
        $this->agentGet('/api/agent/v1/votantes', [], null)->assertUnauthorized()->assertJson(['success' => false, 'message' => 'Unauthenticated.']);

        $expired = $this->tokenFor($this->campo);
        $this->travel(16)->minutes();
        $this->agentGet('/api/agent/v1/votantes', [], $expired)->assertUnauthorized();
        $this->agentGet('/api/agent/v1/votantes/export', [], $expired)->assertUnauthorized();
        $this->travelBack();

        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_superadmin_wildcard_permission_lists(): void
    {
        $admin = $this->linkedUser('R01_SUPERADMIN', '+573003330000', null, ['*']);

        $data = $this->list([], $admin)->assertOk()->assertJsonPath('meta.total', 3)->json('data');

        // '*' includes view.eventos, so the web column of attended events comes along.
        $this->assertSame([0, 0, 0], array_column($data, 'eventos_count'));
        $this->assertArrayNotHasKey('eventos_count', $this->list()->json('data.0'));
    }

    public function test_list_is_audited_with_counts_and_without_personal_data(): void
    {
        $correlation = (string) Str::uuid();
        $this->agentGet('/api/agent/v1/votantes', ['municipio_id' => $this->munA, 'militante' => '0'], $this->tokenFor($this->campo), ['X-Correlation-Id' => $correlation])
            ->assertOk();

        $log = DB::table('audit_logs')->sole();
        $this->assertSame('agent.votantes.list', $log->action);
        $this->assertSame($this->campo->id, $log->user_id);
        $this->assertSame('votante', $log->entity_type);
        $this->assertSame($correlation, $log->correlation_id);
        $meta = json_decode($log->metadata, true);
        $this->assertSame(2, $meta['total']);
        $this->assertSame(2, $meta['entregadas']);
        $this->assertSame(1, $meta['con_datos']);
        $this->assertSame($this->munA, $meta['filtros']['municipio_id']);
        $this->assertSame('[omitido]', $meta['filtros']['militante']);
        $this->assertTrue($meta['filtros']['is_active']);

        $json = $this->auditJson();
        foreach (['1098765432', '3008765432', 'ana@correo.co', '3157778899', 'Ana Autoriza', '1011223344'] as $personal) {
            $this->assertStringNotContainsString($personal, $json);
        }
    }

    public function test_search_term_is_masked_in_the_audit(): void
    {
        // The search itself needs PostgreSQL (ilike); here only the masking of the stored filter is checked.
        $masker = app(AuditDataMasker::class);

        $this->assertSame(['filtros' => ['q_last4' => '5432']], $masker->mask(['filtros' => ['q' => '1098765432']]));
        $this->assertSame(['filtros' => ['q_last4' => null]], $masker->mask(['filtros' => ['q' => 'Ana Autoriza']]));
    }

    public function test_daily_contact_quota_returns_429_and_delivers_nothing_more(): void
    {
        config(['agent.contact_rows_daily_max' => 2]);
        $token = $this->tokenFor($this->campo);

        // Only rows with contact data count: Ana (authorized) is 1 per call, Bruno never counts.
        $this->agentGet('/api/agent/v1/votantes', [], $token)->assertOk()->assertJsonPath('meta.con_datos', 1)->assertJsonPath('meta.cupo_restante', 1);
        $this->agentGet('/api/agent/v1/votantes', ['gran_elector' => '0'], $token)->assertOk()->assertJsonPath('meta.cupo_restante', 1);
        $this->agentGet('/api/agent/v1/votantes', [], $token)->assertOk()->assertJsonPath('meta.cupo_restante', 0);

        $blocked = $this->agentGet('/api/agent/v1/votantes', [], $token)
            ->assertStatus(429)
            ->assertExactJson(['success' => false, 'message' => 'Alcanzaste el límite diario de datos de contacto.', 'restantes' => 0]);
        $this->assertStringNotContainsString('1098765432', $blocked->getContent());
        $this->agentGet('/api/agent/v1/votantes/export', [], $token)->assertStatus(429);

        // Persons without contact data are still listed.
        $this->agentGet('/api/agent/v1/votantes', ['gran_elector' => '0'], $token)->assertOk()->assertJsonPath('data.0.id', $this->sinAutorizarA);

        $this->assertSame(2, DB::table('audit_logs')->where('metadata', 'like', '%limite_diario%')->count());

        // Another user has their own quota; the next day (Bogotá) it starts again.
        $otro = $this->linkedUser('R09_CAMPO', '+573005550000', [$this->munA], ['view.votantes']);
        $this->list([], $otro)->assertOk();
        $this->travelTo(now('America/Bogota')->addDay()->startOfDay()->addHour());
        $this->agentGet('/api/agent/v1/votantes', [], $this->tokenFor($this->campo))->assertOk()->assertJsonPath('meta.con_datos', 1);
    }

    public function test_a_page_that_would_pass_the_quota_is_refused_whole(): void
    {
        config(['agent.contact_rows_daily_max' => 1]);
        $this->lider($this->munA, 'Ana Dos Autoriza', '1077777777', true);

        $this->list()->assertStatus(429)->assertJsonPath('restantes', 1);
        $this->list(['per_page' => 1])->assertOk()->assertJsonPath('meta.con_datos', 1)->assertJsonPath('meta.cupo_restante', 0);
    }

    public function test_reads_have_their_own_throttle(): void
    {
        config(['agent.contact_rows_daily_max' => 1000]);
        $token = $this->tokenFor($this->campo);

        for ($i = 0; $i < 20; $i++) {
            $this->agentGet('/api/agent/v1/votantes', [], $token)->assertOk();
        }
        $this->agentGet('/api/agent/v1/votantes', [], $token)->assertStatus(429)->assertJson(['success' => false]);
        $this->agentGet('/api/agent/v1/votantes/export', [], $token)->assertStatus(429);

        $otro = $this->linkedUser('R09_CAMPO', '+573006660000', [$this->munA], ['view.votantes']);
        $this->list([], $otro)->assertOk();
    }

    public function test_search_by_cedula_only_finds_who_authorized_their_data(): void
    {
        $this->list(['q' => 'bruno'])->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->sinAutorizarA);
        $this->list(['q' => '1098765432'])->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->autorizadoA);
        $this->list(['q' => '98765'])->assertOk()->assertJsonPath('meta.total', 1);

        $this->list(['q' => '1011223344'])->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);
        $this->list(['q' => '11223'])->assertOk()->assertJsonPath('meta.total', 0);
        $this->agentGet('/api/agent/v1/votantes/export', ['q' => '1011223344'], $this->tokenFor($this->campo))
            ->assertOk()->assertHeader('X-Total-Count', '0');
    }

    public function test_export_is_cut_to_the_quota_left_and_429_once_it_is_spent(): void
    {
        config(['agent.contact_rows_daily_max' => 1]);
        $this->lider($this->munA, 'Ana Dos Autoriza', '1077777777', true);
        $token = $this->tokenFor($this->campo);

        // By nombre: Ana Autoriza (fits), Ana Dos Autoriza (over the quota: the file stops there), Bruno.
        $response = $this->agentGet('/api/agent/v1/votantes/export', [], $token)
            ->assertOk()
            ->assertHeader('X-Quota-Truncated', 'true')->assertHeader('X-Rows-Delivered', '1')
            ->assertHeader('X-Total-Count', '3');
        $rows = $this->sheetRows($response);
        $this->assertCount(2, $rows);
        $this->assertSame('Ana Autoriza', $rows[1][0]);
        $this->assertStringNotContainsString('1077777777', json_encode($rows));

        $meta = json_decode(DB::table('audit_logs')->sole()->metadata, true);
        $this->assertSame([3, 1, 1, true], [$meta['total'], $meta['entregadas'], $meta['con_datos'], $meta['recortado_por_cupo']]);

        $this->agentGet('/api/agent/v1/votantes/export', [], $token)
            ->assertStatus(429)
            ->assertJson(['success' => false, 'message' => 'Alcanzaste el límite diario de datos de contacto.', 'restantes' => 0]);
    }

    public function test_export_within_the_quota_is_not_marked_as_cut(): void
    {
        $this->agentGet('/api/agent/v1/votantes/export', [], $this->tokenFor($this->campo))
            ->assertOk()->assertHeader('X-Quota-Truncated', 'false');
    }

    public function test_export_respects_territory_authorization_and_row_cap(): void
    {
        $correlation = (string) Str::uuid();
        $response = $this->agentGet('/api/agent/v1/votantes/export', [], $this->tokenFor($this->campo), ['X-Correlation-Id' => $correlation])
            ->assertOk()
            ->assertHeader('X-Total-Count', '2')
            ->assertHeader('X-Export-Truncated', 'false')->assertHeader('X-Rows-Delivered', '2');
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression('/attachment; filename=votantes_\d{8}_\d{6}\.xlsx/', $response->headers->get('Content-Disposition'));

        $rows = $this->sheetRows($response);
        $this->assertSame(['NOMBRE', 'MUNICIPIO', 'NIVEL DE CONFIANZA', 'PARTIDO', 'PUESTO', 'MESA', 'GRAN ELECTOR', 'MILITANTE',
            'VERIFICADO', 'AUTORIZA DATOS', 'CÉDULA', 'TELÉFONO', 'EMAIL', 'OTROS CONTACTOS'], $rows[0]);
        $this->assertCount(3, $rows);
        $byName = collect(array_slice($rows, 1))->keyBy(0);
        $this->assertSame(['Sí', '1098765432', '3008765432', 'ana@correo.co', 'celular: 3157778899'], array_slice($byName['Ana Autoriza'], 9));
        $this->assertSame(['No', '—', '—', '—', '—'], array_slice($byName['Bruno Sin Autorizar'], 9));
        $this->assertFalse($byName->has('Carla Territorio B'));

        $log = DB::table('audit_logs')->sole();
        $this->assertSame('agent.votantes.export', $log->action);
        $this->assertSame($correlation, $log->correlation_id);
        $meta = json_decode($log->metadata, true);
        $this->assertSame([2, 2, 1], [$meta['total'], $meta['entregadas'], $meta['con_datos']]);
        $this->assertStringNotContainsString('1098765432', $this->auditJson());

        config(['agent.export_max_rows' => 1]);
        $capped = $this->agentGet('/api/agent/v1/votantes/export', [], $this->tokenFor($this->campo))
            ->assertOk()
            ->assertHeader('X-Total-Count', '2')
            ->assertHeader('X-Export-Truncated', 'true');
        $this->assertCount(2, $this->sheetRows($capped));

        $this->agentGet('/api/agent/v1/votantes/export', ['municipio_id' => $this->munB], $this->tokenFor($this->campo))->assertForbidden();
    }

    private function sheetRows(TestResponse $response): array
    {
        $path = $response->baseResponse->getFile()->getPathname();

        return IOFactory::load($path)->getActiveSheet()->toArray(null, false, false);
    }
}
