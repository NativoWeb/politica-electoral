<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

// Campañas electorales stays hidden behind `feature:campanas` (config/features.php): 404 for everyone, the
// superadmin included, until it is switched on; then only for its beta users (all, when the list is empty).
class CampanasFeatureFlagTest extends TestCase
{
    private array $roles = [];

    private User $superadmin;

    private User $beta;

    private User $sinPermiso;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->createSchema();

        DB::table('campaigns')->insert(['id' => Campaign::DEFAULT_ID, 'name' => 'Centro Democrático', 'slug' => 'centro-democratico']);
        foreach ([['R01_SUPERADMIN', 5], ['R09_CAMPO', 1]] as [$code, $level]) {
            $this->roles[$code] = Role::create(['code' => $code, 'name' => $code, 'level' => $level])->id;
        }
        $this->superadmin = $this->user('super', 'R01_SUPERADMIN', null);
        $this->beta = $this->user('beta', 'R09_CAMPO', ['view.mapa', 'view.campanas']);
        $this->sinPermiso = $this->user('sin', 'R09_CAMPO', ['view.mapa']);
    }

    private function createSchema(): void
    {
        Schema::create('campaigns', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('slug')->unique();
            $t->uuid('parent_id')->nullable();
            $t->uuid('candidate_person_id')->nullable();
            $t->boolean('whatsapp_enabled')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
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
            $t->uuid('campaign_id')->default(Campaign::DEFAULT_ID);
            $t->boolean('is_active')->default(true);
            $t->boolean('must_change_password')->default(false);
            $t->json('permissions')->nullable();
            $t->json('territory_scope')->nullable();
            $t->string('preferred_density')->nullable();
            $t->boolean('executive_mode')->default(false);
            $t->string('whatsapp_phone')->nullable();
            $t->timestamp('whatsapp_verified_at')->nullable();
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
        Schema::create('geographic_units', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->string('canonical_name');
            $t->uuid('parent_id')->nullable();
            $t->timestamps();
        });
    }

    private function user(string $key, string $role, ?array $permissions): User
    {
        return User::create([
            'name' => "Usuario {$key}", 'email' => "{$key}@test.local", 'password' => 'secret123',
            'role_id' => $this->roles[$role], 'permissions' => $permissions,
        ])->fresh();
    }

    private function flag(bool $enabled, array $beta = []): void
    {
        config(['features.campanas_electorales' => ['enabled' => $enabled, 'beta_users' => $beta]]);
    }

    private function offeredKeys(): array
    {
        $keys = [];
        $this->actingAs($this->superadmin)->get('/admin/usuarios')->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$keys) {
                $props = $page->toArray()['props'];
                $keys = collect($props['availablePermissions'])->pluck('key')->all();
                $keys = array_merge($keys, array_keys($props['permissionLabels']));
            });

        return $keys;
    }

    public function test_the_flag_is_off_by_default(): void
    {
        $this->assertFalse(config('features.campanas_electorales.enabled'));
        $this->assertSame([], config('features.campanas_electorales.beta_users'));
    }

    public function test_flag_off_answers_404_to_everyone_also_the_superadmin(): void
    {
        $this->flag(false);

        $this->actingAs($this->superadmin)->get('/campanas-electorales')->assertNotFound();
        $this->actingAs($this->superadmin)->getJson('/campanas-electorales')->assertNotFound();
        $this->actingAs($this->beta)->get('/campanas-electorales')->assertNotFound();
        $this->actingAs($this->sinPermiso)->get('/campanas-electorales')->assertNotFound();
    }

    public function test_flag_off_even_with_beta_users_listed(): void
    {
        $this->flag(false, [$this->beta->id, $this->superadmin->id]);

        $this->actingAs($this->beta)->get('/campanas-electorales')->assertNotFound();
        $this->actingAs($this->superadmin)->get('/campanas-electorales')->assertNotFound();
    }

    public function test_flag_on_lets_a_beta_user_with_permission_in(): void
    {
        $this->flag(true, [$this->beta->id]);

        $this->actingAs($this->beta)->getJson('/campanas-electorales')->assertOk()->assertJsonPath('success', true);
    }

    public function test_flag_on_hides_it_from_users_outside_the_beta_list_also_the_superadmin(): void
    {
        $this->flag(true, [$this->beta->id]);

        $this->actingAs($this->superadmin)->get('/campanas-electorales')->assertNotFound();
        $this->actingAs($this->sinPermiso)->get('/campanas-electorales')->assertNotFound();
    }

    public function test_flag_on_without_permission_is_denied(): void
    {
        $this->flag(true, [$this->sinPermiso->id]);

        $this->actingAs($this->sinPermiso)->getJson('/campanas-electorales')->assertForbidden();
        $this->actingAs($this->sinPermiso)->get('/campanas-electorales')->assertRedirect('/');
    }

    public function test_flag_on_with_empty_beta_list_opens_it_to_everyone_with_permission(): void
    {
        $this->flag(true);

        $this->actingAs($this->beta)->getJson('/campanas-electorales')->assertOk();
        $this->actingAs($this->superadmin)->getJson('/campanas-electorales')->assertOk();
        $this->actingAs($this->sinPermiso)->getJson('/campanas-electorales')->assertForbidden();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->flag(true);

        $this->get('/campanas-electorales')->assertRedirect('/login');
    }

    public function test_the_users_catalog_hides_the_campanas_permissions_while_the_flag_is_off(): void
    {
        $this->flag(false);
        $keys = $this->offeredKeys();
        $this->assertNotContains('view.campanas', $keys);
        $this->assertNotContains('manage.campanas', $keys);
        $this->assertContains('view.mapa', $keys);

        $this->flag(true, [$this->beta->id]);
        $keys = $this->offeredKeys();
        $this->assertContains('view.campanas', $keys);
        $this->assertContains('manage.campanas', $keys);
    }

    public function test_flag_off_rejects_granting_the_campanas_permissions_on_create_also_to_the_superadmin(): void
    {
        Mail::fake();
        $this->flag(false);

        foreach (['view.campanas', 'manage.campanas'] as $perm) {
            $this->actingAs($this->superadmin)->postJson('/admin/usuarios', [
                'name' => 'Nuevo', 'email' => "nuevo-{$perm}@test.local", 'role_id' => $this->roles['R09_CAMPO'],
                'permissions' => ['view.mapa', $perm],
            ])->assertStatus(422)->assertJsonPath('errors.permissions', ['Ese permiso no está disponible.']);
        }
        $this->assertSame(0, User::where('email', 'like', 'nuevo-%')->count());

        $this->flag(true);
        $this->actingAs($this->superadmin)->post('/admin/usuarios', [
            'name' => 'Nuevo', 'email' => 'nuevo-on@test.local', 'role_id' => $this->roles['R09_CAMPO'],
            'permissions' => ['view.mapa', 'view.campanas'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['view.mapa', 'view.campanas'], User::where('email', 'nuevo-on@test.local')->sole()->permissions);
    }

    public function test_flag_off_rejects_granting_the_campanas_permissions_on_update(): void
    {
        $this->flag(false);
        $payload = fn (array $perms) => [
            'name' => $this->sinPermiso->name, 'email' => $this->sinPermiso->email,
            'role_id' => $this->roles['R09_CAMPO'], 'permissions' => $perms,
        ];

        $this->actingAs($this->superadmin)->putJson("/admin/usuarios/{$this->sinPermiso->id}", $payload(['view.mapa', 'manage.campanas']))
            ->assertStatus(422)->assertJsonPath('errors.permissions', ['Ese permiso no está disponible.']);
        $this->assertSame(['view.mapa'], $this->sinPermiso->fresh()->permissions);

        // A user who already holds it (granted while the flag was on) can still be edited.
        $this->actingAs($this->superadmin)->put("/admin/usuarios/{$this->beta->id}", [
            'name' => 'Beta editado', 'email' => $this->beta->email, 'role_id' => $this->roles['R09_CAMPO'],
            'permissions' => ['view.mapa', 'view.campanas'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Beta editado', $this->beta->fresh()->name);
    }

    public function test_shared_prop_features_campanas(): void
    {
        $prop = function (User $user) {
            $value = null;
            $this->actingAs($user)->get('/admin/usuarios')->assertInertia(function (AssertableInertia $page) use (&$value) {
                $value = $page->toArray()['props']['features']['campanas'];
            });

            return $value;
        };

        $this->flag(false);
        $this->assertFalse($prop($this->superadmin));

        $this->flag(true, [$this->superadmin->id]);
        $this->assertTrue($prop($this->superadmin));

        $this->flag(true);
        $this->sinPermiso->forceFill(['permissions' => ['view.mapa', 'view.admin', 'manage.users']])->save();
        $this->assertFalse($prop($this->sinPermiso->fresh()), 'Without view/manage.campanas the menu stays hidden');
    }
}
