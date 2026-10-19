<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\Agent\AgentIdentityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

// The base migrations need PostgreSQL, so the tables touched here are built on SQLite;
// the agent tables (users WhatsApp columns, tokens, lider_seguimientos) come from their real migrations.
class AgentWriteApiTest extends TestCase
{
    private const SECRET = 'test-agent-secret-0123456789abcdef';

    private string $munA;

    private string $munB;

    private string $liderA;

    private string $liderB;

    private User $campo;

    private User $consulta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['agent.hmac_secret' => self::SECRET]);
        $this->createSchema();

        $provincia = $this->geo('province', 'Soto');
        $this->munA = $this->geo('municipality', 'Girón', $provincia);
        $this->munB = $this->geo('municipality', 'Lebrija', $provincia);
        $this->liderA = $this->lider($this->munA, 'Ana Territorio A', '111');
        $this->liderB = $this->lider($this->munB, 'Beto Territorio B', '222');

        $this->campo = $this->linkedUser('R09_CAMPO', '+573001110000', [$this->munA], ['view.votantes', 'write.data']);
        $this->consulta = $this->linkedUser('R10_CONSULTA', '+573002220000', null, ['view.votantes', 'write.data']);
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
            foreach (['nombre', 'provincia', 'municipio', 'cargo', 'tipo', 'telefono', 'email',
                'observacion', 'partido', 'direccion', 'barrio', 'zona', 'profesion', 'tipo_documento',
                'fecha_expedicion_doc', 'genero', 'fecha_nacimiento', 'foto', 'estado_civil',
                'referente_documento', 'referente_nombre', 'referente_apellido', 'departamento_votacion',
                'municipio_votacion', 'puesto_votacion', 'direccion_puesto', 'mesa_votacion',
                'convenio', 'escolaridad', 'tipo_hoja_vida', 'fecha_registro_hv',
                'facebook', 'twitter', 'instagram'] as $col) {
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

        Schema::create('nexos_familiares', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('person_id');
            foreach (['nombre', 'parentesco', 'cargo', 'gustos', 'observaciones', 'cedula', 'telefono'] as $col) {
                $t->string($col)->nullable();
            }
            $t->integer('edad')->nullable();
            $t->timestamps();
        });

        Schema::create('persons', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('full_name');
            $t->date('birth_date')->nullable();
            $t->string('gender')->nullable();
            $t->timestamps();
        });
        Schema::create('electoral_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->date('election_date')->nullable();
        });
        Schema::create('offices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
        });
        Schema::create('corporations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
        });
        Schema::create('contests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('electoral_event_id')->nullable();
            $t->uuid('geographic_unit_id')->nullable();
            $t->uuid('office_id')->nullable();
            $t->uuid('corporation_id')->nullable();
        });
        Schema::create('candidacies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('person_id');
            $t->uuid('contest_id');
            $t->string('outcome')->nullable();
        });
        Schema::create('candidacy_endorsements', function (Blueprint $t) {
            $t->uuid('candidacy_id');
            $t->uuid('organization_id');
            $t->boolean('is_primary')->default(true);
        });
        Schema::create('political_organizations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('canonical_name');
        });
        Schema::create('electoral_results', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('candidacy_id')->nullable();
            $t->uuid('geographic_unit_id')->nullable();
            $t->string('metric_type');
            $t->integer('value')->nullable();
        });
        Schema::create('office_tenures', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('person_id');
            $t->uuid('office_id')->nullable();
            $t->uuid('corporation_id')->nullable();
            $t->uuid('geographic_unit_id')->nullable();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->string('status')->nullable();
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

        foreach ([...glob(database_path('migrations/2026_10_19_*.php')), ...glob(database_path('migrations/2026_10_20_*.php'))] as $file) {
            (require $file)->up();
        }
    }

    private function geo(string $type, string $name, ?string $parent = null): string
    {
        $id = (string) Str::uuid();
        DB::table('geographic_units')->insert(['id' => $id, 'type' => $type, 'canonical_name' => $name, 'parent_id' => $parent]);

        return $id;
    }

    private function lider(string $geoId, string $nombre, ?string $cedula): string
    {
        $id = (string) Str::uuid();
        DB::table('lideres')->insert([
            'id' => $id, 'geographic_unit_id' => $geoId, 'nombre' => $nombre, 'cedula' => $cedula,
            'municipio' => DB::table('geographic_units')->where('id', $geoId)->value('canonical_name'),
            'telefono' => '300'.$cedula, 'is_active' => true, 'nivel_confianza' => 'sin_llamar',
        ]);

        return $id;
    }

    private function linkedUser(string $roleCode, string $phone, ?array $scope, array $permissions): User
    {
        $role = Role::firstOrCreate(['code' => $roleCode], ['name' => $roleCode, 'level' => 1]);
        $user = User::create([
            'name' => $roleCode.' user', 'email' => Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => $role->id, 'territory_scope' => $scope, 'permissions' => $permissions,
        ]);
        $user->forceFill(['whatsapp_phone' => $phone, 'whatsapp_verified_at' => now()])->save();

        return $user;
    }

    private function tokenFor(User $user, array $abilities = ['agent'], int $minutes = 15): string
    {
        return $user->createToken(AgentIdentityService::TOKEN_NAME, $abilities, now()->addMinutes($minutes))->plainTextToken;
    }

    private function agent(string $method, string $uri, ?array $body, ?string $token, array $headers = [], bool $sign = true): TestResponse
    {
        $raw = $body === null ? '' : json_encode($body);
        $ts = (string) now()->getTimestamp();
        $nonce = Str::random(32);

        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($sign) {
            $server += [
                'HTTP_X_AGENT_TIMESTAMP' => $ts,
                'HTTP_X_AGENT_NONCE' => $nonce,
                'HTTP_X_AGENT_SIGNATURE' => hash_hmac('sha256', "{$ts}\n{$nonce}\n{$method}\n{$uri}\n{$raw}", self::SECRET),
            ];
        }
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $this->app['auth']->forgetGuards();

        return $this->call($method, $uri, [], [], [], $server, $raw);
    }

    private function electoralPersonIn(string $geoId): string
    {
        $personId = (string) Str::uuid();
        $contestId = (string) Str::uuid();
        DB::table('persons')->insert(['id' => $personId, 'full_name' => 'Candidato '.$geoId]);
        DB::table('contests')->insert(['id' => $contestId, 'geographic_unit_id' => $geoId]);
        DB::table('candidacies')->insert(['id' => (string) Str::uuid(), 'person_id' => $personId, 'contest_id' => $contestId]);

        return $personId;
    }

    public function test_user_without_write_role_gets_403_and_nothing_is_created(): void
    {
        $token = $this->tokenFor($this->consulta);

        $this->agent('POST', '/api/agent/v1/votantes', ['nombre' => 'No debe', 'municipio_id' => $this->munA], $token)
            ->assertForbidden()
            ->assertJson(['success' => false]);
        $this->agent('PATCH', "/api/agent/v1/votantes/{$this->liderA}", ['telefono' => '1'], $token)->assertForbidden();
        $this->agent('POST', "/api/agent/v1/votantes/{$this->liderA}/desactivar", null, $token)->assertForbidden();

        $this->assertDatabaseMissing('lideres', ['nombre' => 'No debe']);
        $this->assertDatabaseHas('lideres', ['id' => $this->liderA, 'telefono' => '300111', 'is_active' => true]);
        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->agent('GET', '/api/agent/v1/me', null, $token)->assertOk()->assertJson(['can_write' => false]);
    }

    public function test_write_role_also_needs_write_data_permission(): void
    {
        $this->campo->forceFill(['permissions' => ['view.votantes']])->save();
        $token = $this->tokenFor($this->campo);

        $this->agent('GET', '/api/agent/v1/me', null, $token)->assertOk()->assertJson(['can_write' => false]);
        $this->agent('POST', '/api/agent/v1/votantes', ['nombre' => 'Sin permiso', 'municipio_id' => $this->munA], $token)
            ->assertForbidden();
        $this->assertDatabaseMissing('lideres', ['nombre' => 'Sin permiso']);

        $this->campo->forceFill(['permissions' => ['view.votantes', 'write.data']])->save();
        $this->agent('POST', '/api/agent/v1/votantes', ['nombre' => 'Con permiso', 'municipio_id' => $this->munA], $token)
            ->assertCreated();
        $this->assertDatabaseHas('lideres', ['nombre' => 'Con permiso', 'geographic_unit_id' => $this->munA]);
    }

    public function test_superadmin_writes_without_explicit_write_data(): void
    {
        $admin = $this->linkedUser('R01_SUPERADMIN', '+573003330000', null, []);

        $this->agent('POST', '/api/agent/v1/votantes', ['nombre' => 'Por admin', 'municipio_id' => $this->munB], $this->tokenFor($admin))
            ->assertCreated();
    }

    public function test_scoped_user_creates_in_territory_and_it_shows_on_the_web_list(): void
    {
        $correlation = (string) Str::uuid();

        $response = $this->agent('POST', '/api/agent/v1/votantes', [
            'nombre' => 'Nueva por WhatsApp', 'municipio_id' => $this->munA, 'cedula' => '333',
            'telefono' => '3001234567', 'nivel_confianza' => 'contactado', 'gran_elector' => true,
        ], $this->tokenFor($this->campo), ['X-Correlation-Id' => $correlation])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombre', 'Nueva por WhatsApp')
            ->assertJsonPath('data.municipio', 'Girón')
            ->assertJsonPath('data.is_active', true);

        $id = $response->json('data.id');
        $this->assertDatabaseHas('lideres', [
            'id' => $id, 'geographic_unit_id' => $this->munA, 'cedula' => '333', 'nivel_confianza' => 'contactado', 'gran_elector' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->campo->id, 'entity_type' => 'votante', 'correlation_id' => $correlation,
            'action' => 'POST agent.votantes.store',
        ]);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->campo)->get('/votantes')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('votantes.data', 2)
                ->where('votantes.data.1.id', $id)
                ->where('counters.total', 2));
    }

    public function test_scoped_user_cannot_touch_another_territory(): void
    {
        $token = $this->tokenFor($this->campo);

        $this->agent('POST', '/api/agent/v1/votantes', ['nombre' => 'Intruso', 'municipio_id' => $this->munB], $token)
            ->assertForbidden()
            ->assertJson(['success' => false]);
        $this->agent('PATCH', "/api/agent/v1/votantes/{$this->liderB}", ['telefono' => '999', 'verificado' => true], $token)
            ->assertForbidden();
        $this->agent('POST', "/api/agent/v1/votantes/{$this->liderB}/desactivar", null, $token)->assertForbidden();
        $this->agent('POST', "/api/agent/v1/votantes/{$this->liderB}/contactos", ['tipo' => 'celular', 'valor' => '1'], $token)
            ->assertForbidden();
        $this->agent('POST', "/api/agent/v1/votantes/{$this->liderB}/nexos", ['nombre' => 'Primo'], $token)->assertForbidden();
        $this->agent('POST', "/api/agent/v1/votantes/{$this->liderB}/seguimientos", ['tipo' => 'llamada', 'nota' => 'x'], $token)
            ->assertForbidden();
        $this->agent('GET', "/api/agent/v1/votantes/{$this->liderB}/seguimientos", null, $token)->assertForbidden();
        $this->agent('POST', "/api/agent/v1/votantes/{$this->liderA}/referidos", ['lider_id' => $this->liderB], $token)
            ->assertForbidden();
        $this->agent('POST', "/api/agent/v1/votantes/{$this->liderB}/referidos", ['lider_id' => $this->liderA], $token)
            ->assertForbidden()
            ->assertJsonMissing(['nombre' => 'Beto Territorio B']);

        $this->assertDatabaseMissing('lideres', ['nombre' => 'Intruso']);
        $this->assertDatabaseHas('lideres', [
            'id' => $this->liderB, 'telefono' => '300222', 'verificado' => false, 'is_active' => true,
        ]);
        $this->assertNull(DB::table('lideres')->where('id', $this->liderB)->value('referente_documento'));
        $this->assertNull(DB::table('lideres')->where('id', $this->liderA)->value('referente_documento'));
        $this->assertSame(0, DB::table('lider_contactos')->count());
        $this->assertSame(0, DB::table('nexos_familiares')->count());
        $this->assertSame(0, DB::table('lider_seguimientos')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_patch_only_accepts_whitelisted_fields(): void
    {
        $token = $this->tokenFor($this->campo);

        foreach ([['cedula' => '999'], ['municipio_id' => $this->munB], ['nombre' => 'Otro nombre'],
            ['geographic_unit_id' => $this->munB], ['is_active' => false], ['telefono' => '1', 'cedula' => '999']] as $body) {
            $this->agent('PATCH', "/api/agent/v1/votantes/{$this->liderA}", $body, $token)
                ->assertStatus(422)
                ->assertJson(['success' => false])
                ->assertJsonValidationErrors(array_keys(array_diff_key($body, ['telefono' => 1])));
        }
        $this->agent('PATCH', "/api/agent/v1/votantes/{$this->liderA}", [], $token)->assertStatus(422);
        $this->agent('PATCH', "/api/agent/v1/votantes/{$this->liderA}", ['nivel_confianza' => 'inventado'], $token)
            ->assertStatus(422)
            ->assertJsonValidationErrors('nivel_confianza');

        $this->assertDatabaseHas('lideres', [
            'id' => $this->liderA, 'cedula' => '111', 'nombre' => 'Ana Territorio A',
            'geographic_unit_id' => $this->munA, 'telefono' => '300111', 'is_active' => true,
        ]);
        $this->assertSame(0, DB::table('audit_logs')->count());

        $this->agent('PATCH', "/api/agent/v1/votantes/{$this->liderA}", [
            'nivel_confianza' => 'comprometido', 'verificado' => true, 'telefono' => '3115550000',
            'puesto_votacion' => 'Colegio Central', 'mesa_votacion' => '12', 'militante' => true, 'autoriza_datos' => true,
        ], $token, ['X-Correlation-Id' => 'no-es-uuid'])
            ->assertOk()
            ->assertJsonPath('data.nivel_confianza', 'comprometido')
            ->assertJsonPath('data.verificado', true);

        $this->assertDatabaseHas('lideres', [
            'id' => $this->liderA, 'nivel_confianza' => 'comprometido', 'verificado' => true, 'telefono' => '3115550000',
            'puesto_votacion' => 'Colegio Central', 'mesa_votacion' => '12', 'militante' => true, 'cedula' => '111',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->campo->id, 'entity_id' => $this->liderA, 'correlation_id' => null,
        ]);
    }

    public function test_duplicate_cedula_is_422_without_revealing_other_territory(): void
    {
        $response = $this->agent('POST', '/api/agent/v1/votantes', [
            'nombre' => 'Duplicada', 'municipio_id' => $this->munA, 'cedula' => '222',
        ], $this->tokenFor($this->campo))->assertStatus(422)->assertJson(['success' => false]);

        $this->assertStringContainsString('otro territorio', $response->json('message'));
        $this->assertStringNotContainsString('Beto', $response->json('message'));
        $this->assertDatabaseMissing('lideres', ['nombre' => 'Duplicada']);
    }

    public function test_unknown_or_invalid_ids_are_404(): void
    {
        $token = $this->tokenFor($this->campo);
        $missing = (string) Str::uuid();

        $this->agent('PATCH', "/api/agent/v1/votantes/{$missing}", ['telefono' => '1'], $token)
            ->assertNotFound()
            ->assertJson(['success' => false]);
        $this->agent('POST', "/api/agent/v1/votantes/{$missing}/desactivar", null, $token)->assertNotFound();
        $this->agent('POST', "/api/agent/v1/votantes/{$missing}/seguimientos", ['tipo' => 'llamada', 'nota' => 'x'], $token)
            ->assertNotFound();
        $this->agent('POST', '/api/agent/v1/votantes/not-a-uuid/desactivar', null, $token)
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'No encontrado.']);
    }

    public function test_contacto_referido_and_nexo_in_territory(): void
    {
        $token = $this->tokenFor($this->campo);
        $correlation = (string) Str::uuid();
        $liderA2 = $this->lider($this->munA, 'Carla Territorio A', null);

        $this->agent('POST', "/api/agent/v1/votantes/{$this->liderA}/contactos", ['tipo' => 'WhatsApp', 'valor' => '3009998877'], $token, ['X-Correlation-Id' => $correlation])
            ->assertCreated()
            ->assertJsonPath('data.tipo', 'whatsapp');
        $this->assertDatabaseHas('lider_contactos', ['lider_id' => $this->liderA, 'tipo' => 'whatsapp', 'valor' => '3009998877', 'estado' => 'activo']);
        $this->agent('POST', "/api/agent/v1/votantes/{$this->liderA}/contactos", ['tipo' => 'fax', 'valor' => '1'], $token)
            ->assertStatus(422)
            ->assertJsonValidationErrors('tipo');

        $this->agent('POST', "/api/agent/v1/votantes/{$this->liderA}/referidos", ['lider_id' => $liderA2], $token, ['X-Correlation-Id' => $correlation])
            ->assertCreated();
        $this->assertDatabaseHas('lideres', ['id' => $liderA2, 'referente_documento' => '111', 'referente_nombre' => 'Ana']);
        $this->agent('POST', "/api/agent/v1/votantes/{$this->liderA}/referidos", ['lider_id' => $this->liderA], $token)
            ->assertStatus(422)
            ->assertJsonValidationErrors('lider_id');
        $this->agent('POST', "/api/agent/v1/votantes/{$liderA2}/referidos", ['lider_id' => $this->liderA], $token)
            ->assertStatus(422)
            ->assertJson(['success' => false, 'message' => 'El referente debe tener cédula registrada.']);

        $nexo = $this->agent('POST', "/api/agent/v1/votantes/{$this->liderA}/nexos", ['nombre' => 'Hermana', 'parentesco' => 'Hermana', 'edad' => 30], $token, ['X-Correlation-Id' => $correlation])
            ->assertCreated();
        $this->assertDatabaseHas('nexos_familiares', ['id' => $nexo->json('data.id'), 'person_id' => $this->liderA, 'nombre' => 'Hermana']);
        $this->agent('POST', "/api/agent/v1/votantes/{$this->liderA}/nexos", ['nombre' => 'X', 'person_id' => $this->liderB], $token)
            ->assertStatus(422)
            ->assertJsonValidationErrors('person_id');

        $this->assertSame(3, DB::table('audit_logs')->where('correlation_id', $correlation)->where('user_id', $this->campo->id)->count());
        $this->assertEqualsCanonicalizing(['contacto', 'referido', 'nexo'],
            DB::table('audit_logs')->where('correlation_id', $correlation)->pluck('entity_type')->all());
    }

    public function test_seguimiento_is_created_and_listed(): void
    {
        $token = $this->tokenFor($this->campo);
        $correlation = (string) Str::uuid();

        $created = $this->agent('POST', "/api/agent/v1/votantes/{$this->liderA}/seguimientos", [
            'tipo' => 'visita', 'nota' => 'Visita en la casa, confirma apoyo.', 'fecha' => '2026-10-18 10:30:00',
        ], $token, ['X-Correlation-Id' => $correlation])
            ->assertCreated()
            ->assertJsonPath('data.tipo', 'visita')
            ->assertJsonPath('data.user.id', $this->campo->id);
        $this->agent('POST', "/api/agent/v1/votantes/{$this->liderA}/seguimientos", ['tipo' => 'llamada', 'nota' => 'Llamada hoy'], $token)
            ->assertCreated();

        $this->assertDatabaseHas('lider_seguimientos', [
            'id' => $created->json('data.id'), 'lider_id' => $this->liderA, 'user_id' => $this->campo->id, 'tipo' => 'visita',
        ]);
        $this->assertDatabaseHas('audit_logs', ['correlation_id' => $correlation, 'entity_type' => 'seguimiento', 'entity_id' => $this->liderA]);

        $this->agent('GET', "/api/agent/v1/votantes/{$this->liderA}/seguimientos", null, $token)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.tipo', 'llamada')
            ->assertJsonPath('data.1.nota', 'Visita en la casa, confirma apoyo.');

        $this->agent('POST', "/api/agent/v1/votantes/{$this->liderA}/seguimientos", ['tipo' => 'fiesta', 'nota' => str_repeat('a', 2001)], $token)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tipo', 'nota']);
    }

    public function test_listing_returns_only_the_last_20_seguimientos(): void
    {
        foreach (range(1, 25) as $i) {
            DB::table('lider_seguimientos')->insert([
                'id' => (string) Str::uuid(), 'lider_id' => $this->liderA, 'user_id' => $this->campo->id,
                'tipo' => 'llamada', 'nota' => "n{$i}", 'fecha' => now()->subDays(30 - $i), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->agent('GET', "/api/agent/v1/votantes/{$this->liderA}/seguimientos", null, $this->tokenFor($this->campo))
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('data.0.nota', 'n25')
            ->assertJsonPath('data.19.nota', 'n6');
    }

    public function test_desactivar_inside_territory(): void
    {
        $correlation = (string) Str::uuid();

        $this->agent('POST', "/api/agent/v1/votantes/{$this->liderA}/desactivar", null, $this->tokenFor($this->campo), ['X-Correlation-Id' => $correlation])
            ->assertOk()
            ->assertJson(['success' => true, 'data' => ['id' => $this->liderA, 'is_active' => false]]);

        $this->assertDatabaseHas('lideres', ['id' => $this->liderA, 'is_active' => false]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->campo->id, 'entity_id' => $this->liderA, 'correlation_id' => $correlation,
            'action' => 'POST agent.votantes.desactivar',
        ]);
    }

    public function test_pdf_reports_respect_territory(): void
    {
        $token = $this->tokenFor($this->consulta);
        $this->consulta->forceFill(['territory_scope' => [$this->munA]])->save();

        $this->agent('GET', "/api/agent/v1/reportes/municipio/{$this->munA}", null, $token)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->agent('GET', "/api/agent/v1/reportes/municipio/{$this->munB}", null, $token)
            ->assertForbidden()
            ->assertJson(['success' => false]);

        $this->agent('GET', '/api/agent/v1/reportes/persona/'.$this->electoralPersonIn($this->munA), null, $token)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->agent('GET', '/api/agent/v1/reportes/persona/'.$this->electoralPersonIn($this->munB), null, $token)
            ->assertForbidden();
        $this->agent('GET', '/api/agent/v1/reportes/persona/'.Str::uuid(), null, $token)->assertNotFound();
    }

    public function test_signature_token_and_user_state_are_enforced(): void
    {
        $body = ['nombre' => 'Sin firma', 'municipio_id' => $this->munA];

        $this->agent('POST', '/api/agent/v1/votantes', $body, $this->tokenFor($this->campo), [], false)
            ->assertUnauthorized()
            ->assertJson(['success' => false]);
        $this->agent('POST', '/api/agent/v1/votantes', $body, null)
            ->assertUnauthorized()
            ->assertJson(['success' => false, 'message' => 'Unauthenticated.']);

        $expired = $this->tokenFor($this->campo);
        $this->travel(16)->minutes();
        $this->agent('POST', '/api/agent/v1/votantes', $body, $expired)->assertUnauthorized();
        $this->travelBack();

        $token = $this->tokenFor($this->campo);
        $this->campo->forceFill(['is_active' => false])->save();
        $this->agent('POST', '/api/agent/v1/votantes', $body, $token)->assertUnauthorized();

        $this->campo->forceFill(['is_active' => true])->save();
        $this->agent('POST', '/api/agent/v1/votantes', $body, $this->tokenFor($this->campo, ['reports']))
            ->assertForbidden()
            ->assertJson(['success' => false]);

        $this->assertDatabaseMissing('lideres', ['nombre' => 'Sin firma']);
    }

    public function test_writes_are_throttled_per_user(): void
    {
        $token = $this->tokenFor($this->campo);

        for ($i = 0; $i < 60; $i++) {
            $this->agent('GET', "/api/agent/v1/votantes/{$this->liderA}/seguimientos", null, $token)->assertOk();
        }
        $this->agent('POST', "/api/agent/v1/votantes/{$this->liderA}/seguimientos", ['tipo' => 'otro', 'nota' => 'x'], $token)
            ->assertStatus(429)
            ->assertJson(['success' => false]);
        $this->assertSame(0, DB::table('lider_seguimientos')->count());

        $this->agent('GET', "/api/agent/v1/votantes/{$this->liderA}/seguimientos", null, $this->tokenFor($this->consulta))->assertOk();
    }
}
