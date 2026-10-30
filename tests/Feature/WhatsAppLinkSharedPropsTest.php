<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\WithCampaignColumns;
use Tests\TestCase;

// The base migrations need PostgreSQL, so users/roles are built here; the WhatsApp columns come from their real migration.
class WhatsAppLinkSharedPropsTest extends TestCase
{
    use WithCampaignColumns;

    private const PHONE = '+573001234567';

    private const AGENT_NUMBER = '+573134459145';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent.whatsapp_number' => self::AGENT_NUMBER]);

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
        (require database_path('migrations/2026_10_19_000001_add_whatsapp_link_to_users.php'))->up();

        $this->addCampaignColumns();

        $role = Role::create(['code' => 'R09_CAMPO', 'name' => 'Operador', 'level' => 1]);
        $this->user = User::create([
            'name' => 'Campo A', 'email' => 'campo@test.local', 'password' => 'secret123',
            'role_id' => $role->id, 'permissions' => ['view.mapa'],
        ]);
    }

    public function test_unlinked_user_gets_linked_false_and_the_agent_number(): void
    {
        $this->actingAs($this->user)->get('/perfil')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Perfil')
                ->where('auth.user.whatsapp.linked', false)
                ->where('auth.user.whatsapp.phoneLast4', null)
                ->where('auth.user.whatsapp.verifiedAt', null)
                ->where('agent.whatsappNumber', self::AGENT_NUMBER));
    }

    public function test_linked_user_gets_only_the_last_four_digits(): void
    {
        $this->user->forceFill(['whatsapp_phone' => self::PHONE, 'whatsapp_verified_at' => now()])->save();

        $response = $this->actingAs($this->user)->get('/perfil');

        $response->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.user.whatsapp.linked', true)
                ->where('auth.user.whatsapp.phoneLast4', '4567')
                ->where('auth.user.whatsapp.verifiedAt', fn ($v) => is_string($v) && $v !== ''));

        $body = $response->getContent();
        $this->assertStringNotContainsString('3001234567', $body);
        $this->assertStringNotContainsString('573001234567', $body);
        $this->assertStringNotContainsString('whatsapp_phone', $body);
    }

    public function test_phone_without_verification_is_not_reported_as_linked(): void
    {
        $this->user->forceFill(['whatsapp_phone' => self::PHONE, 'whatsapp_verified_at' => null])->save();

        $this->actingAs($this->user)->get('/perfil')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.user.whatsapp.linked', false));
    }

    public function test_profile_page_requires_login(): void
    {
        $this->get('/perfil')->assertRedirect('/login');
    }
}
