<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

// Phone numbers and one-time codes of the WhatsApp linking flow must never reach audit_logs in full.
class WhatsAppLinkAuditTest extends TestCase
{
    private const SECRET = 'test-agent-secret-0123456789abcdef';

    private const PHONE = '+573001234567';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent.hmac_secret' => self::SECRET]);

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
        (require database_path('migrations/2026_10_19_000001_add_whatsapp_link_to_users.php'))->up();
        (require database_path('migrations/2026_10_19_183258_create_personal_access_tokens_table.php'))->up();

        $role = Role::create(['code' => 'R09_CAMPO', 'name' => 'Operador', 'level' => 1]);
        $this->user = User::create([
            'name' => 'Campo A', 'email' => 'campo@test.local', 'password' => 'secret123',
            'role_id' => $role->id, 'permissions' => ['view.mapa'],
        ]);
    }

    private function auditMetadata(): array
    {
        return DB::table('audit_logs')->pluck('metadata')->map(fn ($m) => json_decode($m, true))->all();
    }

    public function test_generating_a_code_audits_only_the_last_four_digits(): void
    {
        $code = $this->actingAs($this->user)
            ->postJson('/perfil/whatsapp/codigo', ['phone' => self::PHONE])
            ->assertOk()
            ->json('code');

        $rows = DB::table('audit_logs')->where('action', 'POST perfil.whatsapp.codigo')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(['phone_last4' => '4567'], json_decode($rows[0]->metadata, true));

        $raw = DB::table('audit_logs')->pluck('metadata')->implode(' ');
        $this->assertStringNotContainsString('3001234567', $raw);
        $this->assertStringNotContainsString($code, $raw);
    }

    public function test_code_and_whatsapp_phone_fields_are_never_audited_on_linking_routes(): void
    {
        $this->actingAs($this->user)->postJson('/perfil/whatsapp/codigo', [
            'phone' => self::PHONE, 'code' => '123456', 'whatsapp_phone' => '+573009998877',
        ])->assertOk();

        $this->assertSame(
            [['phone_last4' => '4567', 'whatsapp_phone_last4' => '8877']],
            $this->auditMetadata(),
        );
    }

    public function test_agent_link_leaves_neither_code_nor_phone_in_the_audit_trail(): void
    {
        $code = $this->actingAs($this->user)
            ->postJson('/perfil/whatsapp/codigo', ['phone' => self::PHONE])
            ->json('code');
        $this->app['auth']->forgetGuards();

        $body = json_encode(['phone' => self::PHONE, 'code' => $code]);
        $ts = (string) now()->getTimestamp();
        $nonce = Str::random(32);
        $sig = hash_hmac('sha256', "{$ts}\n{$nonce}\nPOST\n/api/agent/v1/link\n{$body}", self::SECRET);

        $this->call('POST', '/api/agent/v1/link', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_AGENT_TIMESTAMP' => $ts,
            'HTTP_X_AGENT_NONCE' => $nonce,
            'HTTP_X_AGENT_SIGNATURE' => $sig,
        ], $body)->assertOk();

        $this->assertSame(self::PHONE, $this->user->refresh()->whatsapp_phone);
        $raw = DB::table('audit_logs')->pluck('metadata')->implode(' ');
        $this->assertStringNotContainsString($code, $raw);
        $this->assertStringNotContainsString('3001234567', $raw);
    }
}
