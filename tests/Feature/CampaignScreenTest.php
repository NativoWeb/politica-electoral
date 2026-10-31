<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

// Campañas screen (/admin/campanas, superadmin only) and what deactivating a campaign does to its users.
// Scenario: CD (root) with subcampaign X, and an independent root campaign Y.
class CampaignScreenTest extends TestCase
{
    private const CAMPAIGN_ADMIN_PERMS = ['view.mapa', 'view.votantes', 'view.admin', 'write.data', 'manage.users', 'audit.view'];

    private const ALL_ADMIN_PERMS = ['view.gobernador', 'view.mapa', 'view.votantes', 'view.admin', 'write.data',
        'manage.users', 'manage.territory', 'manage.partidos', 'audit.view'];

    private string $campX;

    private string $campY;

    private array $roles = [];

    private User $superadmin;

    private User $adminCd;

    private User $adminX;

    private User $campoX;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->createSchema();
        $this->seedScenario();
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
        Schema::create('lideres', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('campaign_id');
            $t->string('nombre')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('persons', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('full_name');
            $t->string('normalized_name')->nullable();
            $t->uuid('merged_into_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('candidacies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('person_id');
        });
    }

    private function seedScenario(): void
    {
        DB::table('campaigns')->insert([
            ['id' => Campaign::DEFAULT_ID, 'name' => 'Centro Democrático', 'slug' => 'centro-democratico', 'parent_id' => null],
            ['id' => $this->campX = (string) Str::uuid(), 'name' => 'Alejandro', 'slug' => 'alejandro', 'parent_id' => Campaign::DEFAULT_ID],
            ['id' => $this->campY = (string) Str::uuid(), 'name' => 'Otra', 'slug' => 'otra', 'parent_id' => null],
        ]);

        foreach ([['R01_SUPERADMIN', 5], ['R02_ADMIN_FUNCIONAL', 4], ['R09_CAMPO', 1]] as [$code, $level]) {
            $this->roles[$code] = Role::create(['code' => $code, 'name' => $code, 'level' => $level])->id;
        }

        $this->superadmin = $this->user('super', Campaign::DEFAULT_ID, 'R01_SUPERADMIN', null);
        $this->adminCd = $this->user('admin-cd', Campaign::DEFAULT_ID, 'R09_CAMPO', self::ALL_ADMIN_PERMS);
        $this->adminX = $this->user('admin-x', $this->campX, 'R02_ADMIN_FUNCIONAL', self::CAMPAIGN_ADMIN_PERMS);
        $this->campoX = $this->user('campo-x', $this->campX, 'R09_CAMPO', ['view.mapa']);
    }

    private function user(string $key, string $campaignId, string $role, ?array $permissions): User
    {
        $user = User::create([
            'name' => "Usuario {$key}", 'email' => "{$key}@test.local", 'password' => 'secret123',
            'role_id' => $this->roles[$role], 'permissions' => $permissions,
        ]);
        $user->forceFill(['campaign_id' => $campaignId])->save();

        return $user->fresh();
    }

    private function person(string $name, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('persons')->insert(array_merge([
            'id' => $id, 'full_name' => $name, 'normalized_name' => Str::upper(Str::ascii($name)),
        ], $extra));

        return $id;
    }

    private function campaign(string $slug): object
    {
        return DB::table('campaigns')->where('slug', $slug)->first();
    }

    private function login(User $user, string $password = 'secret123')
    {
        return $this->from('/login')->post('/login', ['email' => $user->email, 'password' => $password]);
    }

    // --- Create / edit ---

    public function test_superadmin_creates_a_subcampaign_and_an_independent_campaign(): void
    {
        $candidate = $this->person('Alejandro Cárdenas Ruiz');

        $this->actingAs($this->superadmin)->post('/admin/campanas', [
            'name' => 'Prueba Campaña', 'slug' => '', 'parent_id' => Campaign::DEFAULT_ID,
            'whatsapp_enabled' => false, 'candidate_person_id' => $candidate,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $sub = $this->campaign('prueba-campana');
        $this->assertSame('Prueba Campaña', $sub->name);
        $this->assertSame(Campaign::DEFAULT_ID, $sub->parent_id);
        $this->assertSame($candidate, $sub->candidate_person_id);
        $this->assertFalse((bool) $sub->whatsapp_enabled);
        $this->assertTrue((bool) $sub->is_active);

        $this->actingAs($this->superadmin)->post('/admin/campanas', [
            'name' => 'Independiente', 'slug' => 'Mi Slug Propio', 'parent_id' => '', 'whatsapp_enabled' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $root = $this->campaign('mi-slug-propio');
        $this->assertNull($root->parent_id);
        $this->assertTrue((bool) $root->whatsapp_enabled);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->superadmin->id, 'action' => 'POST admin.campaigns.store', 'entity_type' => 'campana',
        ]);
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'POST admin.campaigns.store')->count());
    }

    public function test_a_duplicate_slug_or_an_empty_name_is_a_validation_error(): void
    {
        $total = DB::table('campaigns')->count();

        // "Alejandro" slugs to the existing "alejandro".
        $this->actingAs($this->superadmin)->post('/admin/campanas', ['name' => 'Alejandro', 'parent_id' => Campaign::DEFAULT_ID])
            ->assertSessionHasErrors('slug');
        $this->actingAs($this->superadmin)->post('/admin/campanas', ['name' => 'Nueva', 'slug' => 'OTRA'])
            ->assertSessionHasErrors('slug');
        $this->actingAs($this->superadmin)->post('/admin/campanas', ['name' => '   ', 'slug' => ''])
            ->assertSessionHasErrors(['name', 'slug']);
        $this->actingAs($this->superadmin)->post('/admin/campanas', ['name' => 'Sin padre', 'parent_id' => (string) Str::uuid()])
            ->assertSessionHasErrors('parent_id');
        $this->actingAs($this->superadmin)->post('/admin/campanas', ['name' => 'Con candidato', 'candidate_person_id' => (string) Str::uuid()])
            ->assertSessionHasErrors('candidate_person_id');
        $this->assertSame($total, DB::table('campaigns')->count());

        // Keeping its own slug is not a duplicate; taking another campaign's is.
        $this->actingAs($this->superadmin)->put("/admin/campanas/{$this->campX}", ['name' => 'Alejandro C.', 'slug' => 'alejandro', 'parent_id' => Campaign::DEFAULT_ID])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->superadmin)->put("/admin/campanas/{$this->campX}", ['name' => 'Alejandro C.', 'slug' => 'otra', 'parent_id' => Campaign::DEFAULT_ID])
            ->assertSessionHasErrors('slug');
        $this->assertSame('alejandro', DB::table('campaigns')->where('id', $this->campX)->value('slug'));
    }

    public function test_a_campaign_cannot_hang_from_itself_or_from_a_campaign_below_it(): void
    {
        $this->actingAs($this->superadmin)->post('/admin/campanas', ['name' => 'Nieta', 'parent_id' => $this->campX])->assertSessionHasNoErrors();
        $nieta = $this->campaign('nieta')->id;

        foreach ([$this->campX, $nieta] as $parent) {
            $this->actingAs($this->superadmin)->put("/admin/campanas/{$this->campX}", ['name' => 'Alejandro', 'parent_id' => $parent])
                ->assertSessionHasErrors('parent_id');
        }
        $this->assertSame(Campaign::DEFAULT_ID, DB::table('campaigns')->where('id', $this->campX)->value('parent_id'));

        // Moving it under an unrelated campaign is fine, and the edit is audited.
        $this->actingAs($this->superadmin)->put("/admin/campanas/{$this->campX}", [
            'name' => 'Alejandro', 'slug' => 'alejandro', 'parent_id' => $this->campY, 'whatsapp_enabled' => true,
        ])->assertSessionHasNoErrors();
        $this->assertSame($this->campY, DB::table('campaigns')->where('id', $this->campX)->value('parent_id'));
        $this->assertTrue((bool) DB::table('campaigns')->where('id', $this->campX)->value('whatsapp_enabled'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'PUT admin.campaigns.update', 'entity_type' => 'campana', 'entity_id' => $this->campX,
        ]);
    }

    public function test_the_root_campaign_is_never_reparented_nor_deactivated(): void
    {
        $this->actingAs($this->superadmin)->put('/admin/campanas/'.Campaign::DEFAULT_ID, [
            'name' => 'Centro Democrático', 'slug' => 'centro-democratico', 'parent_id' => $this->campY,
        ])->assertSessionHasErrors('parent_id');

        $this->actingAs($this->superadmin)->post('/admin/campanas/'.Campaign::DEFAULT_ID.'/toggle')
            ->assertSessionHasErrors('toggle');

        $cd = DB::table('campaigns')->where('id', Campaign::DEFAULT_ID)->first();
        $this->assertNull($cd->parent_id);
        $this->assertTrue((bool) $cd->is_active);

        // Its name and WhatsApp can still be edited.
        $this->actingAs($this->superadmin)->put('/admin/campanas/'.Campaign::DEFAULT_ID, [
            'name' => 'Centro Democrático Santander', 'slug' => 'centro-democratico', 'parent_id' => '', 'whatsapp_enabled' => true,
        ])->assertSessionHasNoErrors();
        $this->assertSame('Centro Democrático Santander', DB::table('campaigns')->where('id', Campaign::DEFAULT_ID)->value('name'));
    }

    // --- Listing and candidate search ---

    public function test_the_listing_shows_parent_flags_and_counts(): void
    {
        DB::table('lideres')->insert([
            ['id' => (string) Str::uuid(), 'campaign_id' => $this->campX, 'nombre' => 'A', 'is_active' => true],
            ['id' => (string) Str::uuid(), 'campaign_id' => $this->campX, 'nombre' => 'B', 'is_active' => true],
            ['id' => (string) Str::uuid(), 'campaign_id' => $this->campX, 'nombre' => 'Baja', 'is_active' => false],
            ['id' => (string) Str::uuid(), 'campaign_id' => $this->campY, 'nombre' => 'C', 'is_active' => true],
        ]);

        $this->actingAs($this->superadmin)->get('/admin/campanas')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Campaigns')
                ->where('defaultId', Campaign::DEFAULT_ID)
                ->where('campaigns.0.id', Campaign::DEFAULT_ID)
                ->where('campaigns.0.isDefault', true)
                ->where('campaigns.0.parentName', null)
                ->where('campaigns.0.usersCount', 2)
                ->where('campaigns', function ($campaigns) {
                    $x = collect($campaigns)->firstWhere('id', $this->campX);
                    $y = collect($campaigns)->firstWhere('id', $this->campY);

                    return $x['parentName'] === 'Centro Democrático' && $x['usersCount'] === 2 && $x['lideresCount'] === 2
                        && $x['isActive'] === true && $x['whatsappEnabled'] === false
                        && $y['parentName'] === null && $y['usersCount'] === 0 && $y['lideresCount'] === 1;
                }));
    }

    public function test_candidate_search_finds_electoral_persons_by_name(): void
    {
        $alejandro = $this->person('Alejandro Cárdenas Ruiz');
        $this->person('Alejandra Cárdenas Borrada', ['deleted_at' => now()]);
        $this->person('Alejandro Cardenas Fusionado', ['merged_into_id' => $alejandro]);
        $this->person('Pedro Pérez');
        DB::table('candidacies')->insert(['id' => (string) Str::uuid(), 'person_id' => $alejandro]);

        $this->actingAs($this->superadmin)->getJson('/admin/campanas/candidatos?q=cárdenas')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', [['id' => $alejandro, 'name' => 'Alejandro Cárdenas Ruiz', 'candidacies' => 1]]);

        $this->actingAs($this->superadmin)->getJson('/admin/campanas/candidatos?q=al')->assertOk()->assertJsonPath('data', []);
    }

    // --- Access ---

    public function test_only_a_superadmin_reaches_the_campaign_routes(): void
    {
        $before = DB::table('campaigns')->orderBy('id')->get()->toArray();

        foreach ([$this->adminX, $this->adminCd, $this->campoX] as $actor) {
            $this->actingAs($actor)->get('/admin/campanas')->assertForbidden();
            $this->actingAs($actor)->getJson('/admin/campanas/candidatos?q=alejandro')->assertForbidden();
            $this->actingAs($actor)->post('/admin/campanas', ['name' => 'Intrusa'])->assertForbidden();
            $this->actingAs($actor)->put("/admin/campanas/{$this->campX}", ['name' => 'Hackeada', 'parent_id' => ''])->assertForbidden();
            $this->actingAs($actor)->post("/admin/campanas/{$this->campX}/toggle")->assertForbidden();
        }

        $this->assertEquals($before, DB::table('campaigns')->orderBy('id')->get()->toArray());
        $this->assertSame(0, DB::table('audit_logs')->where('entity_type', 'campana')->count());
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->get('/admin/campanas')->assertRedirect('/login');
    }

    // --- Deactivated campaign ---

    public function test_users_of_a_deactivated_campaign_cannot_log_in_and_lose_their_open_session(): void
    {
        $this->actingAs($this->superadmin)->post("/admin/campanas/{$this->campX}/toggle")->assertSessionHasNoErrors();
        $this->assertFalse((bool) DB::table('campaigns')->where('id', $this->campX)->value('is_active'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'POST admin.campaigns.toggle', 'entity_id' => $this->campX]);
        $this->app['auth']->forgetGuards();

        // Login refused with a clear reason, without counting as a failed attempt.
        $this->login($this->campoX)->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'Tu campaña está desactivada. Comunícate con el administrador de la plataforma.']);
        $this->assertGuest();

        // A wrong password still gets the generic message: the campaign state is not revealed.
        $this->login($this->campoX, 'otra-clave')->assertSessionHasErrors('email');
        $this->assertStringContainsString('credenciales', session('errors')->first('email'));

        // An open session ends on the next request: pages go to the login, JSON calls get a 401, and the login
        // page shows the reason once, even when the request that closed the session was a background one.
        $reason = 'Tu campaña está desactivada. Comunícate con el administrador de la plataforma.';
        $this->actingAs($this->adminX)->get('/admin/usuarios')->assertRedirect('/login')
            ->assertHeader('Clear-Site-Data', '"cache"');
        $this->assertGuest();
        $forced = DB::table('audit_logs')->where('action', 'FORCED_LOGOUT')->where('user_id', $this->adminX->id)->first();
        $this->assertSame('session', $forced->entity_type);
        $this->assertSame($reason, json_decode($forced->metadata, true)['reason']);
        $this->get('/login')->assertInertia(fn (AssertableInertia $page) => $page->where('blockedReason', $reason));
        $this->get('/login')->assertInertia(fn (AssertableInertia $page) => $page->where('blockedReason', null));

        $this->actingAs($this->adminX)->getJson('/admin/usuarios')->assertUnauthorized()
            ->assertJsonPath('success', false)->assertJsonPath('message', $reason)
            ->assertHeader('Clear-Site-Data', '"cache"');
        $this->assertGuest();
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'FORCED_LOGOUT')->where('user_id', $this->adminX->id)->count());
        $this->get('/admin/usuarios')->assertRedirect('/login');
        $this->get('/login')->assertInertia(fn (AssertableInertia $page) => $page->where('blockedReason', $reason));

        // Reactivated, they log in again.
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->superadmin)->post("/admin/campanas/{$this->campX}/toggle")->assertSessionHasNoErrors();
        $this->app['auth']->forgetGuards();
        $this->login($this->campoX)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->campoX);
    }

    public function test_a_superadmin_is_never_locked_out_by_a_campaign(): void
    {
        DB::table('campaigns')->where('id', $this->campX)->update(['is_active' => false]);
        $this->superadmin->forceFill(['campaign_id' => $this->campX])->save();

        $this->login($this->superadmin)->assertRedirect('/')->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->superadmin);
        $this->get('/admin/campanas')->assertOk();
    }

    public function test_a_deactivated_user_cannot_log_in_either(): void
    {
        $this->campoX->forceFill(['is_active' => false])->save();

        $this->login($this->campoX)
            ->assertSessionHasErrors(['email' => 'Tu cuenta está desactivada. Comunícate con el administrador.']);
        $this->assertGuest();
    }
}
