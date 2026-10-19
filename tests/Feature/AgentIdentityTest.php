<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppLinkCode;
use App\Services\Agent\AgentIdentityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// The base migrations need PostgreSQL, so users/roles are built here; the new tables come from their real migrations.
class AgentIdentityTest extends TestCase
{
    private const SECRET = 'test-agent-secret-0123456789abcdef';

    private const PHONE = '+573001234567';

    private string $munA;

    private User $campo;

    private User $otro;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent.hmac_secret' => self::SECRET]);
        $this->createSchema();

        $this->munA = (string) Str::uuid();
        DB::table('geographic_units')->insert(['id' => $this->munA, 'type' => 'municipality', 'canonical_name' => 'Girón']);

        $campoRole = Role::create(['code' => 'R09_CAMPO', 'name' => 'Operador', 'level' => 1]);
        $consultaRole = Role::create(['code' => 'R10_CONSULTA', 'name' => 'Consulta', 'level' => 1]);

        $this->campo = User::create([
            'name' => 'Campo A', 'email' => 'campo@test.local', 'password' => 'secret123',
            'role_id' => $campoRole->id, 'territory_scope' => [$this->munA], 'permissions' => ['view.mapa'],
        ]);
        $this->otro = User::create([
            'name' => 'Otro', 'email' => 'otro@test.local', 'password' => 'secret123',
            'role_id' => $consultaRole->id, 'territory_scope' => null,
        ]);
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

        Schema::create('cache', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->mediumText('value');
            $t->integer('expiration');
        });
        Schema::create('cache_locks', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->string('owner');
            $t->integer('expiration');
        });

        foreach (glob(database_path('migrations/2026_10_19_*.php')) as $file) {
            (require $file)->up();
        }
    }

    private function agent(string $method, string $uri, ?array $body = null, array $opts = []): TestResponse
    {
        $raw = $body === null ? '' : json_encode($body);
        $ts = $opts['ts'] ?? (string) now()->getTimestamp();
        $nonce = $opts['nonce'] ?? Str::random(32);
        $sig = $opts['sig'] ?? hash_hmac('sha256', "{$ts}\n{$nonce}\n{$method}\n{$uri}\n{$raw}", self::SECRET);

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_AGENT_TIMESTAMP' => $ts,
            'HTTP_X_AGENT_NONCE' => $nonce,
            'HTTP_X_AGENT_SIGNATURE' => $sig,
        ];
        if (isset($opts['token'])) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$opts['token'];
        }

        // Each call is a fresh request for the guards, as it would be in production.
        $this->app['auth']->forgetGuards();

        return $this->call($method, $uri, [], [], [], $server, $raw);
    }

    private function whoami(string $phone = self::PHONE): TestResponse
    {
        return $this->agent('GET', '/api/agent/v1/whoami?phone='.rawurlencode($phone));
    }

    private function generateCode(User $user, string $phone = self::PHONE): string
    {
        return $this->actingAs($user)->postJson('/perfil/whatsapp/codigo', ['phone' => $phone])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->json('code');
    }

    private function link(string $code, string $phone = self::PHONE): TestResponse
    {
        return $this->agent('POST', '/api/agent/v1/link', ['phone' => $phone, 'code' => $code]);
    }

    private function linkedCampo(): User
    {
        $this->link($this->generateCode($this->campo))->assertOk();

        return $this->campo->refresh();
    }

    private function wrongCode(string $code): string
    {
        return str_pad((string) (((int) $code + 1) % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public function test_without_secret_every_agent_route_is_401(): void
    {
        config(['agent.hmac_secret' => '']);

        $this->whoami()->assertUnauthorized()->assertJson(['success' => false]);
        $this->link('123456')->assertUnauthorized();
        $this->agent('POST', '/api/agent/v1/token', ['phone' => self::PHONE])->assertUnauthorized();
        $this->agent('GET', '/api/agent/v1/me')->assertUnauthorized();
    }

    public function test_bad_signature_old_timestamp_and_replayed_nonce_are_401(): void
    {
        $this->linkedCampo();

        $this->agent('GET', '/api/agent/v1/whoami?phone=%2B573001234567', null, ['sig' => str_repeat('a', 64)])
            ->assertUnauthorized();

        $this->agent('GET', '/api/agent/v1/whoami?phone=%2B573001234567', null, ['ts' => (string) now()->subMinutes(6)->getTimestamp()])
            ->assertUnauthorized();

        // Signature over a different query must not validate the real one.
        $ts = (string) now()->getTimestamp();
        $nonce = Str::random(24);
        $sig = hash_hmac('sha256', "{$ts}\n{$nonce}\nGET\n/api/agent/v1/whoami?phone=%2B570000000000\n", self::SECRET);
        $this->agent('GET', '/api/agent/v1/whoami?phone=%2B573001234567', null, compact('ts', 'nonce', 'sig'))
            ->assertUnauthorized();

        $nonce = Str::random(24);
        $this->agent('GET', '/api/agent/v1/whoami?phone=%2B573001234567', null, ['nonce' => $nonce])->assertOk();
        $this->agent('GET', '/api/agent/v1/whoami?phone=%2B573001234567', null, ['nonce' => $nonce])->assertUnauthorized();
    }

    public function test_link_with_valid_code_stores_phone_and_code_cannot_be_reused(): void
    {
        $code = $this->generateCode($this->campo);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertDatabaseMissing('whatsapp_link_codes', ['code_hash' => $code]);

        $this->link($code)->assertOk()->assertJson([
            'success' => true, 'user_id' => $this->campo->id, 'name' => 'Campo A',
        ]);

        $this->campo->refresh();
        $this->assertSame(self::PHONE, $this->campo->whatsapp_phone);
        $this->assertNotNull($this->campo->whatsapp_verified_at);

        $this->link($code)->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_new_code_invalidates_the_previous_one(): void
    {
        $first = $this->generateCode($this->campo);
        $second = $this->generateCode($this->campo);

        $this->link($first)->assertStatus(422);
        $this->link($second)->assertOk();
    }

    public function test_expired_code_is_422(): void
    {
        $code = $this->generateCode($this->campo);
        $this->travel(11)->minutes();

        $this->link($code)->assertStatus(422);
        $this->assertNull($this->campo->refresh()->whatsapp_phone);
    }

    public function test_sixth_failed_attempt_invalidates_the_code(): void
    {
        $code = $this->generateCode($this->campo);

        for ($i = 0; $i < 6; $i++) {
            $this->link($this->wrongCode($code))->assertStatus(422);
        }

        $this->link($code)->assertStatus(422);
        $this->assertNull($this->campo->refresh()->whatsapp_phone);
        $this->assertNotNull(WhatsAppLinkCode::where('user_id', $this->campo->id)->value('expires_at'));
        $this->assertSame(0, WhatsAppLinkCode::active()->where('user_id', $this->campo->id)->count());
    }

    public function test_five_failed_attempts_still_allow_the_right_code(): void
    {
        $code = $this->generateCode($this->campo);

        for ($i = 0; $i < 5; $i++) {
            $this->link($this->wrongCode($code))->assertStatus(422);
        }

        $this->link($code)->assertOk();
    }

    public function test_phone_of_another_user_is_409(): void
    {
        $this->linkedCampo();

        $this->link($this->generateCode($this->otro))->assertStatus(409)->assertJson(['success' => false]);
        $this->assertNull($this->otro->refresh()->whatsapp_phone);
        $this->assertSame(1, Cache::get('agent_link_fail:'.hash('sha256', self::PHONE)), '409 counts as a failure of the phone');
    }

    public function test_right_code_from_another_phone_is_422_and_counts_as_failure(): void
    {
        $code = $this->generateCode($this->campo, self::PHONE);
        $intruder = '+573110000000';

        $this->link($code, $intruder)->assertStatus(422);
        $this->assertSame(1, Cache::get('agent_link_fail:'.hash('sha256', $intruder)));
        $this->assertNull($this->campo->refresh()->whatsapp_phone);

        $this->link($code, self::PHONE)->assertOk();
    }

    public function test_link_validates_phone_and_code_format(): void
    {
        $this->link('12345')->assertStatus(422)->assertJsonValidationErrors('code');
        $this->link('123456', '3001234567')->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_generating_a_code_requires_login_and_a_valid_phone(): void
    {
        $this->postJson('/perfil/whatsapp/codigo', ['phone' => self::PHONE])->assertUnauthorized();
        $this->deleteJson('/perfil/whatsapp')->assertUnauthorized();

        $this->actingAs($this->campo)->postJson('/perfil/whatsapp/codigo')->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->actingAs($this->campo)->postJson('/perfil/whatsapp/codigo', ['phone' => '3001234567'])
            ->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->assertSame(0, WhatsAppLinkCode::count());
    }

    public function test_global_failure_threshold_pauses_link_for_everyone(): void
    {
        config(['agent.link_global_max_failures' => 3]);
        Log::spy();
        $code = $this->generateCode($this->campo);

        foreach (['+573110000001', '+573110000002', '+573110000003', '+573110000004'] as $phone) {
            $this->link('000000', $phone)->assertStatus(422);
        }

        $this->link($code)->assertStatus(429)->assertJson(['success' => false]);
        Log::shouldHaveReceived('warning')->once();
        $this->assertNull($this->campo->refresh()->whatsapp_phone);
    }

    public function test_unsigned_bursts_do_not_consume_the_signed_quota(): void
    {
        $code = $this->generateCode($this->campo);

        for ($i = 0; $i < 15; $i++) {
            $this->withServerVariables(['HTTP_X_FORWARDED_FOR' => "10.0.0.{$i}"])
                ->postJson('/api/agent/v1/link', ['phone' => self::PHONE, 'code' => '000000'])
                ->assertUnauthorized();
        }
        $this->link($code)->assertOk();

        for ($i = 0; $i < 301; $i++) {
            $this->getJson('/api/agent/v1/whoami?phone=%2B573001234567')->assertUnauthorized();
        }
        $this->whoami()->assertOk();
    }

    public function test_forwarded_for_is_ignored_without_trusted_proxies(): void
    {
        Route::get('/_ip-probe', fn (Request $request) => $request->ip());

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'])
            ->get('/_ip-probe')->assertSee('127.0.0.1');

        config(['trustedproxy.proxies' => '127.0.0.1']);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'])
            ->get('/_ip-probe')->assertSee('203.0.113.9');
    }

    public function test_agent_token_does_not_open_any_other_route(): void
    {
        $user = $this->linkedCampo();
        $token = $this->agent('POST', '/api/agent/v1/token', ['phone' => self::PHONE])->json('token');
        $bearer = ['Authorization' => 'Bearer '.$token];

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/search?q=ana', $bearer)->assertUnauthorized();
        $this->getJson('/api/comparar', $bearer)->assertUnauthorized();
        $this->getJson('/api/offline/download/'.$this->munA, $bearer)->assertUnauthorized();
        $this->get('/mapa-politico', $bearer)->assertRedirect('/login');
        $this->assertGuest();
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_cache_prune_command_deletes_only_expired_rows(): void
    {
        config(['cache.default' => 'database']);
        DB::table('cache')->insert([
            ['key' => 'old', 'value' => 'x', 'expiration' => now()->subMinute()->getTimestamp()],
            ['key' => 'live', 'value' => 'x', 'expiration' => now()->addMinute()->getTimestamp()],
        ]);

        $this->artisan('cache:prune-expired')->assertSuccessful();

        $this->assertSame(['live'], DB::table('cache')->pluck('key')->all());
    }

    public function test_whoami_returns_identity_for_linked_phone(): void
    {
        $this->linkedCampo();

        $this->whoami()->assertOk()->assertExactJson([
            'success' => true,
            'id' => $this->campo->id,
            'name' => 'Campo A',
            'role_code' => 'R09_CAMPO',
            'permissions' => ['view.mapa'],
            'territory' => ['restricted' => true, 'municipios' => [['id' => $this->munA, 'name' => 'Girón']]],
            'can_write' => true,
        ]);
    }

    public function test_whoami_404_for_unlinked_inactive_or_must_change_password(): void
    {
        $this->whoami()->assertNotFound()->assertJson(['success' => false]);

        $user = $this->linkedCampo();
        $this->whoami()->assertOk();

        $user->forceFill(['is_active' => false])->save();
        $this->whoami()->assertNotFound();

        $user->forceFill(['is_active' => true, 'must_change_password' => true])->save();
        $this->whoami()->assertNotFound();

        $user->forceFill(['must_change_password' => false, 'whatsapp_verified_at' => null])->save();
        $this->whoami()->assertNotFound();

        $this->whoami('not-a-phone')->assertNotFound();
        $this->agent('GET', '/api/agent/v1/whoami?phone%5B%5D=1')->assertNotFound();
        $this->agent('POST', '/api/agent/v1/link', ['phone' => ['x'], 'code' => '123456'])->assertStatus(422);
    }

    public function test_token_lets_the_agent_call_me_until_it_expires(): void
    {
        $this->linkedCampo();

        $response = $this->agent('POST', '/api/agent/v1/token', ['phone' => self::PHONE])->assertOk();
        $token = $response->json('token');
        $expiresAt = Carbon::parse($response->json('expires_at'));
        $this->assertEqualsWithDelta(now()->addMinutes(15)->getTimestamp(), $expiresAt->getTimestamp(), 5);

        $this->agent('GET', '/api/agent/v1/me', null, ['token' => $token])
            ->assertOk()
            ->assertJson(['id' => $this->campo->id, 'role_code' => 'R09_CAMPO', 'can_write' => true]);

        $this->travel(16)->minutes();
        $this->agent('GET', '/api/agent/v1/me', null, ['token' => $token])->assertUnauthorized();
    }

    public function test_me_without_agent_ability_is_403_and_without_token_is_401(): void
    {
        $user = $this->linkedCampo();
        $other = $user->createToken('otro', ['reports'], now()->addMinutes(15))->plainTextToken;

        $this->agent('GET', '/api/agent/v1/me', null, ['token' => $other])->assertForbidden();
        $this->agent('GET', '/api/agent/v1/me')->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
    }

    public function test_token_is_404_for_unknown_or_ineligible_phone(): void
    {
        $this->agent('POST', '/api/agent/v1/token', ['phone' => self::PHONE])->assertNotFound();

        $user = $this->linkedCampo();
        $user->forceFill(['is_active' => false])->save();
        $this->agent('POST', '/api/agent/v1/token', ['phone' => self::PHONE])->assertNotFound();
    }

    public function test_me_rejects_a_user_deactivated_after_the_token_was_issued(): void
    {
        $user = $this->linkedCampo();
        $token = $this->agent('POST', '/api/agent/v1/token', ['phone' => self::PHONE])->json('token');

        $user->forceFill(['is_active' => false])->save();

        $this->agent('GET', '/api/agent/v1/me', null, ['token' => $token])->assertUnauthorized();
        $this->assertSame(0, $user->tokens()->count(), 'agent.user deletes the token of an ineligible user');

        $user->forceFill(['is_active' => true, 'must_change_password' => true])->save();
        $token = $user->createToken(AgentIdentityService::TOKEN_NAME, ['agent'], now()->addMinutes(15))->plainTextToken;
        $this->agent('GET', '/api/agent/v1/me', null, ['token' => $token])->assertUnauthorized();
    }

    public function test_me_checks_the_signature_before_the_token(): void
    {
        $this->linkedCampo();
        $token = $this->agent('POST', '/api/agent/v1/token', ['phone' => self::PHONE])->json('token');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/agent/v1/me', ['Authorization' => 'Bearer '.$token])
            ->assertUnauthorized()
            ->assertExactJson(['success' => false, 'message' => 'Unauthorized.']);

        // With a bogus token the answer still comes from the HMAC check, not from Sanctum.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/agent/v1/me', ['Authorization' => 'Bearer 999|nope'])
            ->assertUnauthorized()
            ->assertExactJson(['success' => false, 'message' => 'Unauthorized.']);
    }

    public function test_unlink_makes_whoami_404_and_revokes_tokens(): void
    {
        $user = $this->linkedCampo();
        $token = $this->agent('POST', '/api/agent/v1/token', ['phone' => self::PHONE])->json('token');
        $user->createToken('otra-integracion', ['reports']);

        $this->actingAs($user)->deleteJson('/perfil/whatsapp')->assertOk()->assertJson(['success' => true]);

        $user->refresh();
        $this->assertNull($user->whatsapp_phone);
        $this->assertNull($user->whatsapp_verified_at);
        $this->whoami()->assertNotFound();
        $this->assertSame(['otra-integracion'], $user->tokens()->pluck('name')->all(), 'only agent tokens are revoked');

        $this->agent('GET', '/api/agent/v1/me', null, ['token' => $token])->assertUnauthorized();
    }
}
