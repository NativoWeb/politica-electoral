<?php

namespace Tests\Feature;

use App\Actions\Eventos\ApproveAttendees;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use App\Services\Agent\AgentContactQuota;
use App\Services\Agent\AgentIdentityService;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Tests\Concerns\BuildsElectoralSchema;
use Tests\TestCase;

// GET /api/agent/v1/mapa and /mapa/export on SQLite (tables built by hand), with tipo=alcaldia,lideres: the directorio
// section, the search and the barrio filter need PostgreSQL and are covered by AgentMapaApiPostgresTest.
class AgentMapaApiTest extends TestCase
{
    use BuildsElectoralSchema;

    private const SECRET = 'test-agent-secret-0123456789abcdef';

    private const TIPOS = ['tipo' => 'alcaldia,lideres'];

    private string $provincia;

    private string $munA;

    private string $munB;

    private string $office;

    private string $event;

    private string $ana;

    private string $bruno;

    private User $campo;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent.hmac_secret' => self::SECRET]);
        $this->createSchema();

        $this->provincia = $this->row('geographic_units', ['type' => 'province', 'canonical_name' => 'Soto Norte']);
        $otra = $this->row('geographic_units', ['type' => 'province', 'canonical_name' => 'Comunera']);
        $this->munA = $this->row('geographic_units', ['type' => 'municipality', 'canonical_name' => 'Girón', 'parent_id' => $this->provincia]);
        $this->munB = $this->row('geographic_units', ['type' => 'municipality', 'canonical_name' => 'Socorro', 'parent_id' => $otra]);
        $this->office = $this->row('offices', ['name' => 'Alcaldía']);
        $this->event = $this->row('electoral_events', ['name' => 'Territoriales 2023']);

        $this->ana = $this->lider($this->munA, 'Ana Autoriza', '1098765432', true, ['barrio' => 'Poblado', 'zona' => 'Norte', 'profesion' => 'Abogada', 'destacado' => true, 'cargo' => 'Coordinadora']);
        $this->bruno = $this->lider($this->munA, 'Bruno Sin Autorizar', '1011223344', false, ['barrio' => 'Centro']);
        $this->lider($this->munB, 'Carla Fuera', '1055667788', true);

        $this->campo = $this->linkedUser('+573001110000', [$this->munA], ['view.mapa']);
    }

    // A PDF export raises the process limits, which in a test run would stay for every later test.
    protected function tearDown(): void
    {
        ini_restore('memory_limit');
        parent::tearDown();
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

        $this->buildElectoralSchema();

        Schema::table('lideres', function (Blueprint $t) {
            foreach (['telefono', 'email', 'barrio', 'direccion', 'zona', 'profesion'] as $col) {
                $t->string($col)->nullable();
            }
            $t->integer('votos')->default(0);
            $t->boolean('destacado')->default(false);
            $t->boolean('autoriza_datos')->nullable();
        });

        foreach (['2026_10_19_000001_add_whatsapp_link_to_users', '2026_10_19_183258_create_personal_access_tokens_table'] as $migration) {
            (require database_path("migrations/{$migration}.php"))->up();
        }
    }

    private function lider(string $geoId, string $nombre, string $cedula, ?bool $autoriza, array $extra = []): string
    {
        return $this->row('lideres', [
            'geographic_unit_id' => $geoId, 'nombre' => $nombre, 'cedula' => $cedula,
            'municipio' => DB::table('geographic_units')->where('id', $geoId)->value('canonical_name'),
            'telefono' => '300'.substr($cedula, -7), 'email' => "{$cedula}@correo.co", 'direccion' => "Calle {$cedula}",
            'observacion' => "Nota de {$cedula}", 'autoriza_datos' => $autoriza, 'is_active' => true, 'cargo' => 'Líder', ...$extra,
        ]);
    }

    private function candidate(string $nombre, string $outcome = 'not_elected', ?string $geo = null): string
    {
        $contest = $this->row('contests', ['electoral_event_id' => $this->event, 'office_id' => $this->office, 'geographic_unit_id' => $geo ?? $this->munA]);
        $person = $this->row('persons', ['full_name' => $nombre, 'normalized_name' => mb_strtolower($nombre)]);
        $this->row('candidacies', ['person_id' => $person, 'contest_id' => $contest, 'outcome' => $outcome]);

        return $person;
    }

    private function linkedUser(string $phone, ?array $scope, array $permissions, string $roleCode = 'R09_CAMPO'): User
    {
        $role = Role::firstOrCreate(['code' => $roleCode], ['name' => $roleCode, 'level' => 1]);
        $user = User::create([
            'name' => 'Agente Mapa', 'email' => Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => $role->id, 'territory_scope' => $scope, 'permissions' => [...$permissions, 'use.whatsapp'],
        ]);
        $user->forceFill(['whatsapp_phone' => $phone, 'whatsapp_verified_at' => now(), 'campaign_id' => Campaign::DEFAULT_ID])->save();

        return $user;
    }

    private function agentGet(string $path, array $query, ?User $user = null, bool $sign = true): TestResponse
    {
        $uri = $query === [] ? $path : $path.'?'.http_build_query($query);
        $ts = (string) now()->getTimestamp();
        $nonce = Str::random(32);
        $server = ['HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.($user ?? $this->campo)->createToken(AgentIdentityService::TOKEN_NAME, ['agent'], now()->addMinutes(15))->plainTextToken];
        if ($sign) {
            $server += [
                'HTTP_X_AGENT_TIMESTAMP' => $ts,
                'HTTP_X_AGENT_NONCE' => $nonce,
                'HTTP_X_AGENT_SIGNATURE' => hash_hmac('sha256', "{$ts}\n{$nonce}\nGET\n{$uri}\n", self::SECRET),
            ];
        }
        $this->app['auth']->forgetGuards();

        return $this->call('GET', $uri, [], [], [], $server);
    }

    private function list(array $query = [], ?User $user = null): TestResponse
    {
        return $this->agentGet('/api/agent/v1/mapa', $query + self::TIPOS, $user);
    }

    private function export(array $query = [], ?User $user = null): TestResponse
    {
        return $this->agentGet('/api/agent/v1/mapa/export', $query + self::TIPOS, $user);
    }

    private function sheetRows(TestResponse $response): array
    {
        return IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray(null, false, false);
    }

    // The rows handed to the PDF view, captured while the real PDF is rendered.
    private function capturePdfView(): \ArrayObject
    {
        $captured = new \ArrayObject;
        View::composer('exports.table', function ($view) use ($captured) {
            $captured['data'] = $view->getData();
        });

        return $captured;
    }

    public function test_lists_lideres_and_candidates_of_the_municipio_with_the_contract_fields(): void
    {
        $this->candidate('Elena Electa', 'elected');

        $response = $this->list(['municipio_id' => $this->munA])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.con_datos', 1)
            ->assertJsonPath('meta.secciones', ['Alcaldía' => 1, 'Líderes' => 2]);

        $rows = collect($response->json('data'))->keyBy('nombre');
        $this->assertSame(['Elena Electa', 'Ana Autoriza', 'Bruno Sin Autorizar'], $rows->keys()->all());
        $this->assertSame('elected', $rows['Elena Electa']['outcome']);
        $this->assertSame('Alcalde Electo', $rows['Elena Electa']['cargo']);
        $this->assertFalse($rows['Elena Electa']['datos_autorizados']);

        $ana = $rows['Ana Autoriza'];
        $this->assertSame($this->ana, $ana['id']);
        $this->assertSame($this->munA, $ana['municipio_id']);
        $this->assertSame(['Líderes', 'Coordinadora', 'Poblado', 'Norte', 'Abogada', true], [$ana['tipo_registro'], $ana['cargo'], $ana['barrio'], $ana['zona'], $ana['profesion'], $ana['destacado']]);
        $this->assertSame(['1098765432', '3008765432', '1098765432@correo.co', 'Calle 1098765432', 'Nota de 1098765432'],
            [$ana['cedula'], $ana['telefono'], $ana['email'], $ana['direccion'], $ana['observacion']]);

        $this->list(['tipo' => 'lideres', 'municipio_id' => $this->munA])->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.secciones', ['Líderes' => 2]);
    }

    public function test_a_row_with_several_tipos_counts_in_each_section(): void
    {
        $this->candidate('Dora Doble');
        $this->lider($this->munA, 'Dora Doble', '1033333333', false, ['cargo' => 'Coordinadora']);

        $response = $this->list(['municipio_id' => $this->munA])->assertOk()->assertJsonPath('meta.total', 3);

        $this->assertSame(['Alcaldía' => 1, 'Líderes' => 3], $response->json('meta.secciones'));
        $this->assertSame('Alcaldía, Líderes', collect($response->json('data'))->firstWhere('nombre', 'Dora Doble')['tipo_registro']);
    }

    public function test_unauthorized_lider_has_no_contact_keys_or_values(): void
    {
        $response = $this->list(['tipo' => 'lideres'])->assertOk();

        $bruno = collect($response->json('data'))->firstWhere('id', $this->bruno);
        $this->assertFalse($bruno['datos_autorizados']);
        foreach (['cedula', 'telefono', 'email', 'direccion', 'observacion'] as $field) {
            $this->assertArrayNotHasKey($field, $bruno);
        }
        foreach (['1011223344', '3001223344', '1011223344@correo.co', 'Calle 1011223344', 'Nota de 1011223344'] as $value) {
            $this->assertStringNotContainsString($value, $response->getContent());
        }
    }

    public function test_autoriza_datos_null_is_not_authorized(): void
    {
        $id = $this->lider($this->munA, 'Nulo Sin Respuesta', '1044444444', null);

        $row = collect($this->list(['tipo' => 'lideres'])->assertOk()->json('data'))->firstWhere('id', $id);

        $this->assertFalse($row['datos_autorizados']);
        $this->assertArrayNotHasKey('telefono', $row);
    }

    public function test_candidate_enriched_from_an_unauthorized_lider_shows_no_contact_data(): void
    {
        $this->candidate('Cesar Candidato');
        $this->lider($this->munA, 'Cesar Candidato', '1022222222', false, ['barrio' => 'Lagos']);

        $response = $this->list(['tipo' => 'alcaldia', 'municipio_id' => $this->munA])->assertOk()->assertJsonPath('meta.con_datos', 0);

        $row = $response->json('data.0');
        $this->assertFalse($row['datos_autorizados']);
        $this->assertArrayNotHasKey('telefono', $row);
        foreach (['1022222222', '3002222222', '1022222222@correo.co', 'Calle 1022222222'] as $value) {
            $this->assertStringNotContainsString($value, $response->getContent());
        }
    }

    public function test_candidate_of_an_authorized_lider_takes_the_data_from_that_lider(): void
    {
        $this->candidate('Fabio Firme');
        $this->lider($this->munA, 'FABIO FIRME ', '1066666666', true);

        $response = $this->list(['tipo' => 'alcaldia', 'municipio_id' => $this->munA])->assertOk()->assertJsonPath('meta.con_datos', 1);

        $row = $response->json('data.0');
        $this->assertTrue($row['datos_autorizados']);
        $this->assertSame('1066666666', $row['cedula']);
        $this->assertSame('3006666666', $row['telefono']);
    }

    public function test_two_namesakes_make_the_candidate_ambiguous(): void
    {
        $this->candidate('Hugo Homonimo');
        $this->lider($this->munA, 'Hugo Homonimo', '1077777771', true);
        $this->lider($this->munA, 'Hugo Homonimo', '1077777772', false);

        $response = $this->list(['tipo' => 'alcaldia', 'municipio_id' => $this->munA])->assertOk()->assertJsonPath('meta.con_datos', 0);

        $this->assertFalse($response->json('data.0.datos_autorizados'));
        $this->assertStringNotContainsString('1077777771', $response->getContent());
    }

    public function test_a_namesake_written_with_another_municipio_spelling_also_makes_it_ambiguous(): void
    {
        $this->candidate('Iris Igual');
        $this->lider($this->munA, 'Iris Igual', '1088888881', true);
        $this->row('lideres', ['nombre' => 'Iris Igual', 'municipio' => 'Giron', 'geographic_unit_id' => null, 'cedula' => '1088888882',
            'telefono' => '3008888882', 'autoriza_datos' => false, 'is_active' => true, 'cargo' => 'Líder']);

        $this->list(['tipo' => 'alcaldia', 'municipio_id' => $this->munA])->assertOk()
            ->assertJsonPath('meta.con_datos', 0)
            ->assertJsonPath('data.0.datos_autorizados', false);
    }

    public function test_inactive_lider_gives_no_data_to_a_candidate_row(): void
    {
        $this->candidate('Julia Inactiva');
        $this->lider($this->munA, 'Julia Inactiva', '1099999999', true, ['is_active' => false]);

        $this->list(['tipo' => 'alcaldia', 'municipio_id' => $this->munA])->assertOk()->assertJsonPath('data.0.datos_autorizados', false);
    }

    public function test_another_campaign_is_never_listed_nor_used_for_contact_data(): void
    {
        $otra = $this->row('campaigns', ['name' => 'Otra', 'slug' => 'otra']);
        $this->candidate('Kevin Ajeno');
        $this->lider($this->munA, 'Kevin Ajeno', '1012121212', true, ['campaign_id' => $otra]);
        $this->lider($this->munA, 'Luisa Otra Campana', '1013131313', true, ['campaign_id' => $otra]);

        $response = $this->list(['municipio_id' => $this->munA])->assertOk();

        $this->assertStringNotContainsString('Luisa Otra Campana', $response->getContent());
        $this->assertStringNotContainsString('1012121212', $response->getContent());
        $this->assertFalse(collect($response->json('data'))->firstWhere('nombre', 'Kevin Ajeno')['datos_autorizados']);

        // A filter by a campaign the user cannot see opens nothing.
        $this->assertStringNotContainsString('Luisa Otra Campana', $this->list(['campana' => $otra])->assertOk()->getContent());
    }

    public function test_quota_counts_exactly_the_rows_with_data(): void
    {
        config(['agent.contact_rows_daily_max' => 5]);

        $this->list()->assertOk()->assertJsonPath('meta.con_datos', 1)->assertJsonPath('meta.cupo_restante', 4);
        $this->assertSame(1, app(AgentContactQuota::class)->used($this->campo->id));
    }

    public function test_spent_quota_is_429_with_restantes_and_an_audit_row(): void
    {
        config(['agent.contact_rows_daily_max' => 1]);

        $this->list()->assertOk()->assertJsonPath('meta.cupo_restante', 0);

        $blocked = $this->list()->assertStatus(429)
            ->assertExactJson(['success' => false, 'message' => 'Alcanzaste el límite diario de datos de contacto.', 'restantes' => 0]);
        $this->assertStringNotContainsString('1098765432', $blocked->getContent());
        $this->export()->assertStatus(429)->assertJsonPath('restantes', 0);

        $refused = DB::table('audit_logs')->where('metadata', 'like', '%limite_diario%')->pluck('action');
        $this->assertEqualsCanonicalizing(['agent.mapa.list', 'agent.mapa.export'], $refused->all());
    }

    public function test_quota_is_given_back_if_the_audit_cannot_be_written(): void
    {
        AuditLog::creating(fn () => throw new RuntimeException('audit down'));

        $this->withoutExceptionHandling();
        try {
            $this->list();
            $this->fail('The delivery should have failed.');
        } catch (RuntimeException $e) {
            $this->assertSame('audit down', $e->getMessage());
        }

        $this->assertSame(0, app(AgentContactQuota::class)->used($this->campo->id));
    }

    public function test_list_is_audited_with_filters_and_counts_only(): void
    {
        $this->list(['municipio_id' => $this->munA])->assertOk();

        $log = DB::table('audit_logs')->sole();
        $this->assertSame('agent.mapa.list', $log->action);
        $this->assertSame('mapa_politico', $log->entity_type);
        $this->assertSame($this->campo->id, $log->user_id);
        $meta = json_decode($log->metadata, true);
        $this->assertSame([2, 2, 1], [$meta['total'], $meta['entregadas'], $meta['con_datos']]);
        $this->assertSame($this->munA, $meta['filtros']['municipio_id']);
        foreach (['1098765432', '3008765432', 'Ana Autoriza', 'Nota de'] as $personal) {
            $this->assertStringNotContainsString($personal, $log->metadata);
        }
    }

    public function test_permission_signature_and_token_are_enforced(): void
    {
        $sinPermiso = $this->linkedUser('+573002220000', [$this->munA], ['view.votantes']);

        $this->agentGet('/api/agent/v1/mapa', [], $sinPermiso)->assertForbidden()->assertJson(['success' => false]);
        $this->agentGet('/api/agent/v1/mapa/export', [], $sinPermiso)->assertForbidden();

        $this->agentGet('/api/agent/v1/mapa', [], null, false)->assertUnauthorized()->assertJson(['success' => false]);
        $this->agentGet('/api/agent/v1/mapa/export', [], null, false)->assertUnauthorized();

        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_municipio_or_province_outside_the_territory_is_403(): void
    {
        $this->list(['municipio_id' => $this->munB])->assertForbidden()
            ->assertJson(['success' => false, 'message' => 'Ese municipio está fuera de tu territorio.']);
        $this->export(['municipio_id' => $this->munB])->assertForbidden();
        $this->list(['provincia' => 'Comunera'])->assertForbidden();

        $response = $this->list()->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertStringNotContainsString('Carla Fuera', $response->getContent());
        $this->assertSame(0, DB::table('audit_logs')->where('metadata', 'like', '%'.$this->munB.'%')->count());
    }

    public function test_provincia_is_matched_without_accents_and_an_unknown_one_is_422(): void
    {
        $consulta = $this->linkedUser('+573003330000', null, ['view.mapa']);

        $response = $this->list(['provincia' => 'soto norte'], $consulta)->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertStringNotContainsString('Carla Fuera', $response->getContent());

        $this->list(['provincia' => 'Soto Nort'], $consulta)->assertStatus(422)->assertJsonValidationErrors(['provincia']);
    }

    public function test_filters_estado_destacado_partido_profesion_and_pagination(): void
    {
        DB::table('lideres')->where('id', $this->bruno)->update(['is_active' => false, 'partido' => 'Verde']);

        $this->list(['tipo' => 'lideres'])->assertJsonPath('meta.total', 1);
        $this->list(['tipo' => 'lideres', 'estado' => 'inactivos'])->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->bruno);
        $this->list(['tipo' => 'lideres', 'estado' => 'todos'])->assertJsonPath('meta.total', 2);
        $this->list(['tipo' => 'lideres', 'estado' => 'todos', 'partido' => 'verde'])->assertJsonPath('meta.total', 1);
        $this->list(['tipo' => 'lideres', 'profesion' => 'Abogada'])->assertJsonPath('meta.total', 1);

        $this->list(['tipo' => 'lideres', 'estado' => 'todos', 'destacado' => 'true'])->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->ana);
        $this->list(['tipo' => 'lideres', 'estado' => 'todos', 'destacado' => '0'])->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->bruno);

        $this->list(['tipo' => 'lideres', 'estado' => 'todos', 'per_page' => 1, 'page' => 2])->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.id', $this->bruno);
    }

    public function test_per_page_above_50_is_capped_and_the_total_is_real(): void
    {
        for ($i = 0; $i < 55; $i++) {
            $this->lider($this->munA, 'Relleno '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), '20000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), false);
        }

        $this->list(['tipo' => 'lideres', 'per_page' => 500])->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.total', 57)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_invalid_or_unknown_query_parameters_are_422(): void
    {
        foreach ([['municipio_id' => 'no-uuid'], ['municipio_id' => (string) Str::uuid()], ['tipo' => 'lideres,inventado'],
            ['destacado' => 'quizas'], ['estado' => 'raro'], ['campana' => 'otra'], ['q' => 'a'], ['per_page' => 0], ['cedula' => '1'],
            ['formato' => 'pdf'], ['solo_conteo' => 'quizas']] as $query) {
            $this->agentGet('/api/agent/v1/mapa', $query)->assertStatus(422)->assertJson(['success' => false])->assertJsonValidationErrors(array_keys($query));
        }
        $this->agentGet('/api/agent/v1/mapa/export', ['formato' => 'otro'])->assertStatus(422)->assertJsonValidationErrors(['formato']);
        $this->agentGet('/api/agent/v1/mapa/export', ['solo_conteo' => '1'])->assertStatus(422)->assertJsonValidationErrors(['solo_conteo']);
    }

    public function test_xlsx_export_has_the_columns_headers_and_no_unauthorized_data(): void
    {
        $this->candidate('Cesar Candidato');
        $this->lider($this->munA, 'Cesar Candidato', '1022222222', false);

        $response = $this->export()->assertOk()
            ->assertHeader('X-Total-Count', '3')
            ->assertHeader('X-Export-Truncated', 'false')
            ->assertHeader('X-Quota-Truncated', 'false')
            ->assertHeader('X-Rows-Delivered', '3')
            ->assertHeader('X-Rows-With-Data', '1');
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression('/attachment; filename=mapa_politico_\d{8}_\d{6}\.xlsx/', $response->headers->get('Content-Disposition'));

        $rows = $this->sheetRows($response);
        $this->assertSame(['NOMBRE', 'MUNICIPIO', 'PROVINCIA', 'TIPO', 'CARGO', 'PARTIDO', 'VOTOS', 'BARRIO', 'ZONA', 'PROFESIÓN', 'DESTACADO',
            'AUTORIZA DATOS', 'CÉDULA', 'TELÉFONO', 'EMAIL', 'DIRECCIÓN', 'OBSERVACIONES'], $rows[0]);
        $byName = collect(array_slice($rows, 1))->keyBy(0);
        $this->assertSame(['Sí', '1098765432', '3008765432', '1098765432@correo.co', 'Calle 1098765432', 'Nota de 1098765432'], array_slice($byName['Ana Autoriza'], 11));
        $this->assertSame(['No', '—', '—', '—', '—', '—'], array_slice($byName['Bruno Sin Autorizar'], 11));
        $this->assertSame(['No', '—', '—', '—', '—', '—'], array_slice($byName['Cesar Candidato'], 11));
        $json = json_encode($rows);
        foreach (['1011223344', '3001223344', 'Nota de 1011223344', '1022222222'] as $value) {
            $this->assertStringNotContainsString($value, $json);
        }

        $log = DB::table('audit_logs')->sole();
        $this->assertSame('agent.mapa.export', $log->action);
        $meta = json_decode($log->metadata, true);
        $this->assertSame([3, 3, 1, 'xlsx'], [$meta['total'], $meta['entregadas'], $meta['con_datos'], $meta['formato']]);
    }

    public function test_xlsx_export_respects_the_row_cap(): void
    {
        config(['agent.export_max_rows' => 1]);

        $response = $this->export()->assertOk()
            ->assertHeader('X-Total-Count', '2')
            ->assertHeader('X-Export-Truncated', 'true')
            ->assertHeader('X-Rows-Delivered', '1');
        $this->assertCount(2, $this->sheetRows($response));
    }

    public function test_export_is_cut_to_the_quota_left(): void
    {
        config(['agent.contact_rows_daily_max' => 1]);
        $this->lider($this->munA, 'Bea Autoriza', '1077700077', true);

        // By nombre: Ana (fits), Bea (over the quota: the file stops there), Bruno.
        $response = $this->export(['tipo' => 'lideres'])->assertOk()
            ->assertHeader('X-Total-Count', '3')
            ->assertHeader('X-Quota-Truncated', 'true')
            ->assertHeader('X-Rows-Delivered', '1')
            ->assertHeader('X-Rows-With-Data', '1');

        $rows = $this->sheetRows($response);
        $this->assertCount(2, $rows);
        $this->assertSame('Ana Autoriza', $rows[1][0]);
    }

    public function test_pdf_export_returns_a_pdf_with_headers_and_only_authorized_data_in_the_view(): void
    {
        $captured = $this->capturePdfView();
        $this->candidate('Cesar Candidato');
        $this->lider($this->munA, 'Cesar Candidato', '1022222222', false);

        $response = $this->export(['formato' => 'pdf', 'municipio_id' => $this->munA])->assertOk()
            ->assertHeader('X-Total-Count', '3')
            ->assertHeader('X-Export-Truncated', 'false')
            ->assertHeader('X-Quota-Truncated', 'false')
            ->assertHeader('X-Rows-Delivered', '3')
            ->assertHeader('X-Rows-With-Data', '1');

        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression('/attachment; filename="?mapa_politico_\d{8}_\d{6}\.pdf"?/', $response->headers->get('Content-Disposition'));

        $view = $captured['data'];
        $this->assertSame('Mapa Político - Girón', $view['title']);
        $this->assertSame(['nombre', 'municipio', 'tipo', 'cargo', 'partido', 'votos', 'barrio', 'telefono', 'observacion'], $view['columns']);

        $rows = collect($view['data'])->keyBy('nombre');
        $this->assertSame('3008765432', $rows['Ana Autoriza']['telefono']);
        $this->assertSame('Nota de 1098765432', $rows['Ana Autoriza']['observacion']);
        foreach (['Bruno Sin Autorizar', 'Cesar Candidato'] as $nombre) {
            $this->assertArrayNotHasKey('telefono', $rows[$nombre]);
            $this->assertArrayNotHasKey('observacion', $rows[$nombre]);
        }
        foreach (['3001223344', 'Nota de 1011223344', '3002222222'] as $value) {
            $this->assertStringNotContainsString($value, json_encode($view['data']));
        }

        $meta = json_decode(DB::table('audit_logs')->sole()->metadata, true);
        $this->assertSame([3, 1, 'pdf'], [$meta['entregadas'], $meta['con_datos'], $meta['formato']]);
    }

    // dompdf is stubbed: 500 real rows would take over the memory peak of the whole test run.
    public function test_pdf_is_cut_at_the_pdf_cap_and_says_so(): void
    {
        $seen = [];
        $pdf = Mockery::mock(DomPdf::class);
        $pdf->shouldReceive('setPaper')->with('a4', 'landscape')->once()->andReturnSelf();
        $pdf->shouldReceive('setOption')->with('isRemoteEnabled', false)->once()->andReturnSelf();
        $pdf->shouldReceive('setOption')->with('isPhpEnabled', false)->once()->andReturnSelf();
        $pdf->shouldReceive('download')->once()->andReturn(response('%PDF-stub'));
        Pdf::shouldReceive('loadView')->once()->andReturnUsing(function ($view, $data) use (&$seen, $pdf) {
            $seen = $data;

            return $pdf;
        });
        $now = now();
        foreach (array_chunk(range(1, 505), 100) as $chunk) {
            DB::table('lideres')->insert(array_map(fn ($i) => [
                'id' => (string) Str::uuid(), 'geographic_unit_id' => $this->munA, 'nombre' => 'Relleno '.$i, 'municipio' => 'Girón',
                'cargo' => 'Líder', 'is_active' => true, 'autoriza_datos' => false, 'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }

        $this->export(['tipo' => 'lideres', 'formato' => 'pdf'])->assertOk()
            ->assertHeader('X-Total-Count', '507')
            ->assertHeader('X-Export-Truncated', 'true')
            ->assertHeader('X-Rows-Delivered', '500')
            ->assertHeader('X-Rows-With-Data', '1');

        $this->assertCount(500, $seen['data']);
        $this->assertTrue($seen['truncated']);
        $this->assertSame(507, $seen['totalCount']);
    }

    public function test_namesakes_give_no_barrio_zona_or_profesion_in_any_format(): void
    {
        $captured = $this->capturePdfView();
        $this->candidate('Hugo Homonimo');
        $this->lider($this->munA, 'Hugo Homonimo', '1077777771', true, ['barrio' => 'Lagos', 'zona' => 'Sur', 'profesion' => 'Medico']);
        $this->lider($this->munA, 'Hugo Homonimo', '1077777772', false, ['barrio' => 'Lagos', 'zona' => 'Sur', 'profesion' => 'Medico']);
        $query = ['tipo' => 'alcaldia', 'municipio_id' => $this->munA];

        $response = $this->list($query)->assertOk()->assertJsonPath('data.0.datos_autorizados', false);
        foreach (['barrio', 'zona', 'profesion'] as $field) {
            $this->assertArrayNotHasKey($field, $response->json('data.0'));
        }
        foreach (['Lagos', 'Medico'] as $value) {
            $this->assertStringNotContainsString($value, $response->getContent());
        }

        $sheet = $this->sheetRows($this->export($query)->assertOk());
        $this->assertSame(['—', '—', '—'], array_slice($sheet[1], 7, 3));

        $this->export($query + ['formato' => 'pdf'])->assertOk();
        $this->assertArrayNotHasKey('barrio', $captured['data']['data'][0]);
    }

    public function test_a_resolved_row_takes_barrio_from_the_lider_even_without_authorization(): void
    {
        $this->candidate('Cesar Candidato');
        $this->lider($this->munA, 'Cesar Candidato', '1022222222', false, ['barrio' => 'Lagos', 'zona' => 'Sur', 'profesion' => 'Medico']);

        $row = $this->list(['tipo' => 'alcaldia', 'municipio_id' => $this->munA])->assertOk()->json('data.0');

        $this->assertSame(['Lagos', 'Sur', 'Medico', false], [$row['barrio'], $row['zona'], $row['profesion'], $row['datos_autorizados']]);
        $this->assertArrayNotHasKey('telefono', $row);
    }

    public function test_a_row_merged_from_several_fichas_shows_nothing_personal(): void
    {
        $this->lider($this->munA, 'Gina Gemela', '', true, ['barrio' => 'Lagos', 'telefono' => '3001112222', 'email' => null]);
        $this->lider($this->munA, 'Gina Gemela', '', false, ['barrio' => 'Lagos', 'telefono' => '3003334444', 'email' => null]);

        $response = $this->list(['tipo' => 'lideres', 'municipio_id' => $this->munA, 'per_page' => 50])->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.con_datos', 1);

        $gina = collect($response->json('data'))->firstWhere('nombre', 'Gina Gemela');
        $this->assertFalse($gina['datos_autorizados']);
        foreach (['barrio', 'zona', 'profesion', 'cedula', 'telefono', 'email', 'direccion', 'observacion'] as $field) {
            $this->assertArrayNotHasKey($field, $gina);
        }
        $this->assertStringNotContainsString('3001112222', $response->getContent());
    }

    public function test_solo_conteo_returns_counts_without_data_or_quota(): void
    {
        config(['agent.contact_rows_daily_max' => 5]);

        $response = $this->list(['solo_conteo' => 'true', 'municipio_id' => $this->munA])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.secciones', ['Líderes' => 2])
            ->assertJsonPath('meta.cupo_restante', 5);
        $this->assertEqualsCanonicalizing(['total', 'secciones', 'cupo_restante'], array_keys($response->json('meta')));
        $this->assertSame(0, app(AgentContactQuota::class)->used($this->campo->id));

        $log = DB::table('audit_logs')->sole();
        $this->assertSame('agent.mapa.count', $log->action);
        $this->assertSame(2, json_decode($log->metadata, true)['total']);
        $this->assertStringNotContainsString('Ana Autoriza', $log->metadata);
    }

    public function test_solo_conteo_works_with_the_quota_spent_and_false_is_the_normal_list(): void
    {
        config(['agent.contact_rows_daily_max' => 1]);
        $this->list()->assertOk();
        $this->list()->assertStatus(429);

        $this->list(['solo_conteo' => '1'])->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.cupo_restante', 0);
        $this->list(['solo_conteo' => 'false'])->assertStatus(429);
    }

    public function test_a_lone_attendee_tipo_without_permission_is_a_generic_403(): void
    {
        $this->lider($this->munA, 'Oscar Asistente', '1098700006', false, ['tipo' => ApproveAttendees::TIPO]);

        foreach ([$this->list(['tipo' => 'asistentes_evento']), $this->export(['tipo' => 'asistentes_evento'])] as $response) {
            $response->assertForbidden()->assertJson(['success' => false, 'message' => 'No tienes permiso para esta consulta.']);
            $this->assertDoesNotMatchRegularExpression('/event|asistent/i', $response->getContent());
        }
    }

    public function test_attendee_tipo_is_dropped_when_it_comes_with_others(): void
    {
        $this->lider($this->munA, 'Oscar Asistente', '1098700006', false, ['tipo' => ApproveAttendees::TIPO]);

        $response = $this->list(['tipo' => 'asistentes_evento,lideres', 'per_page' => 50])->assertOk()->assertJsonPath('meta.total', 3);

        $this->assertSame('Líderes', collect($response->json('data'))->firstWhere('nombre', 'Oscar Asistente')['tipo_registro']);
        $this->assertStringNotContainsString('Asistentes', $response->getContent());
    }

    public function test_area_metropolitana_follows_the_territory(): void
    {
        $this->list(['provincia' => 'Área Metropolitana', 'tipo' => 'lideres'])->assertOk()->assertJsonPath('meta.total', 2);

        $fuera = $this->linkedUser('+573004440000', [$this->munB], ['view.mapa']);
        $this->list(['provincia' => 'Área Metropolitana', 'tipo' => 'lideres'], $fuera)->assertForbidden()
            ->assertJson(['success' => false, 'message' => 'Esa provincia está fuera de tu territorio.']);
    }

    public function test_quota_is_given_back_if_the_file_cannot_be_built(): void
    {
        View::composer('exports.table', fn () => throw new RuntimeException('pdf down'));

        $this->export(['formato' => 'pdf'])->assertStatus(500);

        $this->assertSame(0, app(AgentContactQuota::class)->used($this->campo->id));
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'agent.mapa.export')->count());
    }
}
