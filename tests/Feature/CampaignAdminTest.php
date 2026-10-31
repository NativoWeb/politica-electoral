<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

// Multi-campaign step 3: CD (root) with subcampaign X, and an independent root campaign Y.
// Users, audit and bulk WhatsApp per campaign; R02 is the "Administrador de campaña".
class CampaignAdminTest extends TestCase
{
    private const ADMIN_PERMS = ['view.gobernador', 'view.mapa', 'view.votantes', 'view.admin', 'write.data',
        'manage.users', 'manage.territory', 'manage.partidos', 'audit.view'];

    private const CAMPAIGN_ADMIN_PERMS = ['view.mapa', 'view.votantes', 'view.admin', 'write.data', 'manage.users', 'audit.view'];

    private string $campX;

    private string $campY;

    private string $mun;

    private array $roles = [];

    private User $superadmin;

    private User $adminCd;

    private User $adminX;

    private User $adminY;

    private User $campoCd;

    private User $campoX;

    private User $campoY;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
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
            $t->uuid('geographic_unit_id')->nullable();
            foreach (['nombre', 'telefono', 'municipio', 'partido', 'cargo', 'provincia', 'tipo', 'profesion', 'barrio'] as $col) {
                $t->string($col)->nullable();
            }
            $t->date('fecha_nacimiento')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('lider_contactos', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('lider_id');
            $t->string('valor')->nullable();
            $t->string('estado')->nullable();
            $t->boolean('principal')->default(false);
            $t->timestamps();
        });
        Schema::create('persons', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('full_name');
            $t->uuid('merged_into_id')->nullable();
            $t->date('birth_date')->nullable();
            $t->string('identity_status')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('person_contact_points', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('person_id');
            $t->string('type');
            $t->string('value_raw')->nullable();
            $t->string('value_normalized')->nullable();
            $t->boolean('is_current')->default(true);
            $t->boolean('allow_export')->default(true);
        });
        Schema::create('candidacies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('person_id');
            $t->uuid('contest_id')->nullable();
            $t->date('outcome_date')->nullable();
        });
        Schema::create('whatsapp_templates', function (Blueprint $t) {
            $t->id();
            foreach (['name', 'meta_name', 'body_text', 'header_type', 'header_text', 'language', 'category', 'description'] as $col) {
                $t->string($col)->nullable();
            }
            $t->json('variables')->nullable();
            $t->json('filters')->nullable();
            $t->boolean('is_active')->default(true);
            $t->uuid('campaign_id')->default(Campaign::DEFAULT_ID);
            $t->timestamps();
        });
        Schema::create('whatsapp_sends', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('template_id');
            $t->string('campaign_type')->nullable();
            $t->date('campaign_date')->nullable();
            $t->uuid('campaign_id')->default(Campaign::DEFAULT_ID);
            $t->timestamps();
        });
        Schema::create('whatsapp_send_recipients', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('whatsapp_send_id');
            $t->uuid('person_id')->nullable();
            $t->string('name')->nullable();
            $t->string('phone')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
    }

    private function seedScenario(): void
    {
        DB::table('campaigns')->insert([
            ['id' => Campaign::DEFAULT_ID, 'name' => 'Centro Democrático', 'slug' => 'centro-democratico', 'parent_id' => null, 'whatsapp_enabled' => true],
            ['id' => $this->campX = (string) Str::uuid(), 'name' => 'Alejandro', 'slug' => 'alejandro', 'parent_id' => Campaign::DEFAULT_ID, 'whatsapp_enabled' => false],
            ['id' => $this->campY = (string) Str::uuid(), 'name' => 'Otra', 'slug' => 'otra', 'parent_id' => null, 'whatsapp_enabled' => true],
        ]);

        foreach ([['R01_SUPERADMIN', 5], ['R02_ADMIN_FUNCIONAL', 4], ['R03_ADMIN_DATOS', 4], ['R09_CAMPO', 1],
            ['R10_CONSULTA', 1], ['R12_REPORTES', 1]] as [$code, $level]) {
            $this->roles[$code] = Role::create(['code' => $code, 'name' => $code, 'level' => $level])->id;
        }

        $this->mun = (string) Str::uuid();
        DB::table('geographic_units')->insert(['id' => $this->mun, 'type' => 'municipality', 'canonical_name' => 'Girón']);

        $this->superadmin = $this->user('super', Campaign::DEFAULT_ID, 'R01_SUPERADMIN', null);
        // The two production users that manage users today: R09 of the CD with the whole admin panel.
        $this->adminCd = $this->user('admin-cd', Campaign::DEFAULT_ID, 'R09_CAMPO', self::ADMIN_PERMS);
        $this->adminX = $this->user('admin-x', $this->campX, 'R02_ADMIN_FUNCIONAL', self::CAMPAIGN_ADMIN_PERMS);
        $this->adminY = $this->user('admin-y', $this->campY, 'R02_ADMIN_FUNCIONAL', self::CAMPAIGN_ADMIN_PERMS);
        $this->campoCd = $this->user('campo-cd', Campaign::DEFAULT_ID, 'R09_CAMPO', ['view.mapa', 'write.data']);
        $this->campoX = $this->user('campo-x', $this->campX, 'R09_CAMPO', ['view.mapa', 'write.data']);
        $this->campoY = $this->user('campo-y', $this->campY, 'R09_CAMPO', ['view.mapa', 'write.data']);
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

    private function lider(string $campaignId, string $nombre): string
    {
        $id = (string) Str::uuid();
        DB::table('lideres')->insert([
            'id' => $id, 'campaign_id' => $campaignId, 'nombre' => $nombre, 'telefono' => '3001112233',
            'geographic_unit_id' => $this->mun, 'municipio' => 'Girón', 'is_active' => true,
        ]);

        return $id;
    }

    private function template(string $campaignId, string $name): int
    {
        return DB::table('whatsapp_templates')->insertGetId([
            'name' => $name, 'meta_name' => Str::slug($name, '_'), 'body_text' => 'Hola', 'language' => 'es',
            'is_active' => true, 'campaign_id' => $campaignId, 'filters' => '[]',
        ]);
    }

    private function enableWhatsapp(string $campaignId): void
    {
        DB::table('campaigns')->where('id', $campaignId)->update(['whatsapp_enabled' => true]);
    }

    private function listedUserIds(User $actor): array
    {
        $ids = [];
        $this->actingAs($actor)->get('/admin/usuarios')->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$ids) {
                $ids = collect($page->toArray()['props']['users']['data'])->pluck('id')->sort()->values()->all();
            });

        return $ids;
    }

    private function newUserPayload(array $extra = []): array
    {
        return array_merge([
            'name' => 'Nuevo', 'email' => 'nuevo@test.local', 'role_id' => $this->roles['R09_CAMPO'],
            'permissions' => ['view.mapa', 'write.data'],
        ], $extra);
    }

    // --- Users ---

    public function test_campaign_admin_lists_only_users_of_its_own_campaign(): void
    {
        $this->assertSame(collect([$this->adminX->id, $this->campoX->id])->sort()->values()->all(), $this->listedUserIds($this->adminX));

        $this->actingAs($this->adminX)->get('/admin/usuarios')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('roles', fn ($roles) => collect($roles)->pluck('code')->sort()->values()->all() === ['R02_ADMIN_FUNCIONAL', 'R09_CAMPO'])
            ->where('campaigns', []));
    }

    public function test_campaign_admin_creates_users_in_its_own_campaign_whatever_the_request_says(): void
    {
        $this->actingAs($this->adminX)->post('/admin/usuarios', $this->newUserPayload([
            'campaign_id' => Campaign::DEFAULT_ID, 'territory_scope' => [$this->mun],
        ]))->assertRedirect();

        $created = User::where('email', 'nuevo@test.local')->firstOrFail();
        $this->assertSame($this->campX, $created->campaign_id);
        $this->assertSame([$this->mun], $created->territory_scope);
        $this->assertSame($this->roles['R09_CAMPO'], $created->role_id);
    }

    public function test_campaign_admin_cannot_hand_out_platform_roles_or_permissions_it_lacks(): void
    {
        foreach ([
            ['role_id' => $this->roles['R01_SUPERADMIN']],
            ['role_id' => $this->roles['R03_ADMIN_DATOS']],
            ['permissions' => ['*']],
            ['permissions' => ['view.mapa', 'manage.partidos']],
        ] as $extra) {
            $this->actingAs($this->adminX)->post('/admin/usuarios', $this->newUserPayload($extra))->assertForbidden();
        }
        $this->assertDatabaseMissing('users', ['email' => 'nuevo@test.local']);

        // Read-only campaign roles and another Administrador de campaña are allowed.
        $this->actingAs($this->adminX)->post('/admin/usuarios', $this->newUserPayload(['role_id' => $this->roles['R10_CONSULTA']]))->assertRedirect();
        $this->actingAs($this->adminX)->post('/admin/usuarios', $this->newUserPayload([
            'email' => 'otro-admin@test.local', 'role_id' => $this->roles['R02_ADMIN_FUNCIONAL'],
        ]))->assertRedirect();
        $this->assertSame(2, User::whereIn('email', ['nuevo@test.local', 'otro-admin@test.local'])->where('campaign_id', $this->campX)->count());

        // Nor promote itself.
        $this->actingAs($this->adminX)->put("/admin/usuarios/{$this->adminX->id}", [
            'name' => 'Yo', 'email' => 'admin-x@test.local', 'role_id' => $this->roles['R01_SUPERADMIN'],
            'permissions' => self::CAMPAIGN_ADMIN_PERMS,
        ])->assertForbidden();
        $this->assertSame($this->roles['R02_ADMIN_FUNCIONAL'], $this->adminX->fresh()->role_id);
    }

    public function test_users_of_another_campaign_do_not_exist_for_a_campaign_admin(): void
    {
        $before = DB::table('users')->orderBy('id')->get()->toArray();

        foreach ([$this->campoCd, $this->campoY] as $target) {
            $this->actingAs($this->adminX)->put("/admin/usuarios/{$target->id}", [
                'name' => 'Hackeado', 'email' => $target->email, 'role_id' => $this->roles['R09_CAMPO'],
            ])->assertNotFound();
            $this->actingAs($this->adminX)->post("/admin/usuarios/{$target->id}/toggle")->assertNotFound();
            $this->actingAs($this->adminX)->delete("/admin/usuarios/{$target->id}")->assertNotFound();
        }

        // The root campaign manages its own users only, not its subcampaigns'.
        $this->actingAs($this->adminCd)->post("/admin/usuarios/{$this->campoX->id}/toggle")->assertNotFound();

        $this->assertEquals($before, DB::table('users')->orderBy('id')->get()->toArray());
    }

    public function test_a_user_with_permissions_the_actor_lacks_cannot_be_touched(): void
    {
        $stronger = $this->user('fuerte-x', $this->campX, 'R02_ADMIN_FUNCIONAL', [...self::CAMPAIGN_ADMIN_PERMS, 'manage.partidos']);
        $before = (array) DB::table('users')->where('id', $stronger->id)->first();

        $this->actingAs($this->adminX)->put("/admin/usuarios/{$stronger->id}", [
            'name' => 'Usuario fuerte-x', 'email' => 'fuerte-x@test.local', 'role_id' => $this->roles['R02_ADMIN_FUNCIONAL'],
            'permissions' => self::CAMPAIGN_ADMIN_PERMS, 'password' => 'NuevaClave123',
        ])->assertForbidden();
        $this->actingAs($this->adminX)->post("/admin/usuarios/{$stronger->id}/toggle")->assertForbidden();
        $this->actingAs($this->adminX)->delete("/admin/usuarios/{$stronger->id}")->assertForbidden();

        $this->assertSame($before, (array) DB::table('users')->where('id', $stronger->id)->first());
    }

    public function test_the_users_page_needs_manage_users(): void
    {
        $viewer = $this->user('viewer-x', $this->campX, 'R02_ADMIN_FUNCIONAL', ['view.admin', 'view.mapa', 'write.data']);

        $this->actingAs($viewer)->getJson('/admin/usuarios')->assertForbidden();
        $this->actingAs($viewer)->postJson('/admin/usuarios', $this->newUserPayload(['permissions' => ['view.mapa']]))->assertForbidden();
        $this->actingAs($viewer)->putJson("/admin/usuarios/{$this->campoX->id}", ['name' => 'X', 'email' => 'campo-x@test.local'])->assertForbidden();
        $this->actingAs($viewer)->postJson("/admin/usuarios/{$this->campoX->id}/toggle")->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/admin/usuarios/{$this->campoX->id}")->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'nuevo@test.local']);
        $this->assertTrue($this->campoX->fresh()->is_active);
    }

    public function test_nobody_deactivates_their_own_account(): void
    {
        foreach ([$this->adminX, $this->superadmin] as $actor) {
            $this->actingAs($actor)->post("/admin/usuarios/{$actor->id}/toggle")->assertSessionHasErrors('toggle');
            $this->assertTrue($actor->fresh()->is_active);
        }
    }

    public function test_campaign_admin_edits_its_users_and_their_territory_but_cannot_move_them(): void
    {
        $this->actingAs($this->adminX)->put("/admin/usuarios/{$this->campoX->id}", [
            'name' => 'Campo X editado', 'email' => 'campo-x@test.local', 'role_id' => $this->roles['R09_CAMPO'],
            'permissions' => ['view.mapa', 'write.data'], 'territory_scope' => [$this->mun], 'campaign_id' => $this->campY,
        ])->assertRedirect();

        $user = $this->campoX->fresh();
        $this->assertSame('Campo X editado', $user->name);
        $this->assertSame([$this->mun], $user->territory_scope);
        $this->assertSame($this->campX, $user->campaign_id);

        $this->actingAs($this->adminX)->post("/admin/usuarios/{$this->campoX->id}/toggle")->assertRedirect();
        $this->assertFalse($this->campoX->fresh()->is_active);
    }

    public function test_cd_user_managers_keep_todays_behaviour(): void
    {
        // Every production user is in the CD: they keep seeing all of them and editing their operators.
        $cdUsers = collect([$this->superadmin, $this->adminCd, $this->campoCd])->pluck('id')->sort()->values()->all();
        $this->assertSame($cdUsers, $this->listedUserIds($this->adminCd));

        $this->actingAs($this->adminCd)->put("/admin/usuarios/{$this->campoCd->id}", [
            'name' => 'Campo CD', 'email' => 'campo-cd@test.local', 'role_id' => $this->roles['R09_CAMPO'],
            'permissions' => ['view.mapa', 'view.votantes', 'write.data'], 'territory_scope' => [$this->mun],
        ])->assertRedirect();
        $this->assertSame(['view.mapa', 'view.votantes', 'write.data'], $this->campoCd->fresh()->permissions);

        $this->actingAs($this->adminCd)->post('/admin/usuarios', $this->newUserPayload())->assertRedirect();
        $this->assertSame(Campaign::DEFAULT_ID, User::where('email', 'nuevo@test.local')->value('campaign_id'));

        // An operator does not create Administradores de campaña nor touch a superadmin.
        $this->actingAs($this->adminCd)->post('/admin/usuarios', $this->newUserPayload([
            'email' => 'escala@test.local', 'role_id' => $this->roles['R02_ADMIN_FUNCIONAL'],
        ]))->assertForbidden();
        $this->actingAs($this->adminCd)->post("/admin/usuarios/{$this->superadmin->id}/toggle")->assertForbidden();
    }

    public function test_superadmin_sees_everyone_and_chooses_the_campaign(): void
    {
        $this->assertCount(7, $this->listedUserIds($this->superadmin));

        $this->actingAs($this->superadmin)->post('/admin/usuarios', $this->newUserPayload([
            'role_id' => $this->roles['R02_ADMIN_FUNCIONAL'], 'campaign_id' => $this->campX,
        ]))->assertRedirect();
        $this->assertSame($this->campX, User::where('email', 'nuevo@test.local')->value('campaign_id'));

        $this->actingAs($this->superadmin)->post('/admin/usuarios', $this->newUserPayload([
            'email' => 'sin-campana@test.local', 'campaign_id' => (string) Str::uuid(),
        ]))->assertSessionHasErrors('campaign_id');

        $this->actingAs($this->superadmin)->post('/admin/usuarios', $this->newUserPayload(['email' => 'por-defecto@test.local']))->assertRedirect();
        $this->assertSame(Campaign::DEFAULT_ID, User::where('email', 'por-defecto@test.local')->value('campaign_id'));

        $this->actingAs($this->superadmin)->put("/admin/usuarios/{$this->campoCd->id}", [
            'name' => 'Movido', 'email' => 'campo-cd@test.local', 'role_id' => $this->roles['R09_CAMPO'], 'campaign_id' => $this->campY,
        ])->assertRedirect();
        $this->assertSame($this->campY, $this->campoCd->fresh()->campaign_id);
    }

    // --- Audit ---

    public function test_audit_shows_only_events_of_users_of_visible_campaigns(): void
    {
        foreach ([$this->superadmin, $this->adminCd, $this->campoCd, $this->adminX, $this->campoX, $this->adminY, $this->campoY] as $user) {
            DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'action' => 'TEST_EVENT', 'created_at' => now()]);
        }

        $seen = function (User $actor): array {
            $ids = [];
            $this->actingAs($actor)->get('/admin/auditoria')->assertOk()
                ->assertInertia(function (AssertableInertia $page) use (&$ids) {
                    $ids = collect($page->toArray()['props']['logs']['data'])->where('action', 'TEST_EVENT')
                        ->pluck('user_id')->sort()->values()->all();
                });

            return $ids;
        };
        $ids = fn (User ...$users) => collect($users)->pluck('id')->sort()->values()->all();

        // The superadmin's actions are not shown to campaign users, not even to the CD's.
        $this->assertSame($ids($this->adminX, $this->campoX), $seen($this->adminX));
        $this->assertSame($ids($this->adminCd, $this->campoCd, $this->adminX, $this->campoX), $seen($this->adminCd));
        $this->assertSame($ids($this->adminY, $this->campoY), $seen($this->adminY));
        $this->assertCount(7, $seen($this->superadmin));

        $this->actingAs($this->adminX)->get('/admin/auditoria')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('users', fn ($users) => collect($users)->pluck('id')->sort()->values()->all() === $ids($this->adminX, $this->campoX)));
        $this->actingAs($this->adminCd)->get('/admin/auditoria')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('users', fn ($users) => ! collect($users)->pluck('id')->contains($this->superadmin->id)));
    }

    public function test_a_superadmin_event_about_another_campaign_is_hidden_from_the_cd_admin(): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $this->superadmin->id, 'action' => 'PUT admin.users.update',
            'entity_type' => 'user', 'entity_id' => $this->campoX->id, 'created_at' => now(),
        ]);

        $actions = fn (User $actor) => collect($this->actingAs($actor)->get('/admin/auditoria')->assertOk()
            ->viewData('page')['props']['logs']['data'])->pluck('action')->all();

        $this->assertNotContains('PUT admin.users.update', $actions($this->adminCd));
        $this->assertNotContains('PUT admin.users.update', $actions($this->adminX));
        $this->assertContains('PUT admin.users.update', $actions($this->superadmin));
    }

    // --- Bulk WhatsApp ---

    public function test_campaign_without_whatsapp_gets_403_on_every_bulk_whatsapp_route(): void
    {
        $template = $this->template($this->campX, 'Plantilla X');
        $send = DB::table('whatsapp_sends')->insertGetId(['template_id' => $template, 'campaign_id' => $this->campX]);

        foreach ([
            ['get', '/admin/whatsapp', []],
            ['get', '/admin/whatsapp/templates', []],
            ['post', '/admin/whatsapp/templates', ['name' => 'Nueva', 'meta_name' => 'nueva', 'body_text' => 'x', 'language' => 'es']],
            ['put', "/admin/whatsapp/templates/{$template}", ['name' => 'Editada', 'meta_name' => 'editada', 'body_text' => 'x', 'language' => 'es']],
            ['delete', "/admin/whatsapp/templates/{$template}", []],
            ['postJson', '/admin/whatsapp/prepare', ['template_id' => $template, 'select_all_matching' => true]],
            ['getJson', "/admin/whatsapp/sends/{$send}/status", []],
            ['getJson', '/admin/whatsapp/birthdays/history', []],
        ] as [$method, $uri, $payload]) {
            $this->actingAs($this->adminX)->{$method}($uri, $payload)->assertForbidden();
        }

        $this->assertSame(1, DB::table('whatsapp_templates')->count());
        $this->assertSame('Plantilla X', DB::table('whatsapp_templates')->value('name'));
    }

    public function test_templates_belong_to_the_own_campaign(): void
    {
        $this->enableWhatsapp($this->campX);
        $cd = $this->template(Campaign::DEFAULT_ID, 'Plantilla CD');
        $this->template($this->campX, 'Plantilla X');

        $names = fn (User $actor) => $this->actingAs($actor)->get('/admin/whatsapp/templates')->assertOk()
            ->viewData('page')['props']['templates'];
        $this->assertSame(['Plantilla X'], collect($names($this->adminX))->pluck('name')->all());
        $this->assertSame(['Plantilla CD'], collect($names($this->adminCd))->pluck('name')->all());

        $this->actingAs($this->adminX)->post('/admin/whatsapp/templates', [
            'name' => 'Nueva X', 'meta_name' => 'nueva_x', 'body_text' => 'Hola', 'language' => 'es', 'campaign_id' => Campaign::DEFAULT_ID,
        ])->assertRedirect();
        $this->assertSame($this->campX, DB::table('whatsapp_templates')->where('meta_name', 'nueva_x')->value('campaign_id'));

        $this->actingAs($this->adminX)->put("/admin/whatsapp/templates/{$cd}", [
            'name' => 'Robada', 'meta_name' => 'robada', 'body_text' => 'x', 'language' => 'es',
        ])->assertNotFound();
        $this->actingAs($this->adminX)->delete("/admin/whatsapp/templates/{$cd}")->assertNotFound();
        $this->assertSame('Plantilla CD', DB::table('whatsapp_templates')->where('id', $cd)->value('name'));
    }

    public function test_recipients_and_sends_are_only_the_own_campaigns_lideres(): void
    {
        config(['services.n8n.whatsapp_webhook' => 'https://n8n.test/webhook']);
        Http::fake(['n8n.test/*' => Http::response(['ok' => true])]);
        $this->enableWhatsapp($this->campX);

        $liderCd = $this->lider(Campaign::DEFAULT_ID, 'Ana CD');
        $liderX = $this->lider($this->campX, 'Beto X');
        $this->lider($this->campY, 'Ceci Y');
        $templateCd = $this->template(Campaign::DEFAULT_ID, 'Plantilla CD');
        $templateX = $this->template($this->campX, 'Plantilla X');

        $contacts = fn (User $actor, string $source) => collect($this->actingAs($actor)->get("/admin/whatsapp?source={$source}")->assertOk()
            ->viewData('page')['props']['contacts']['data'])->pluck('name')->all();

        // The CD does not reach the lideres of its subcampaign X, nor of Y.
        $this->assertSame(['Ana CD'], $contacts($this->adminCd, 'lideres'));
        $this->assertSame(['Beto X'], $contacts($this->adminX, 'lideres'));

        $this->actingAs($this->adminCd)->postJson('/admin/whatsapp/prepare', [
            'template_id' => $templateCd, 'source' => 'lideres', 'select_all_matching' => true,
        ])->assertOk()->assertJson(['recipients_count' => 1]);

        $send = DB::table('whatsapp_sends')->latest('id')->first();
        $this->assertSame(Campaign::DEFAULT_ID, $send->campaign_id);
        $this->assertSame([$liderCd], DB::table('whatsapp_send_recipients')->where('whatsapp_send_id', $send->id)->pluck('person_id')->all());
        Http::assertSent(fn ($request) => collect($request['recipients'])->pluck('id')->all() === [$liderCd]);

        // Picking another campaign's lider by id finds no one; neither does another campaign's template.
        $this->actingAs($this->adminCd)->postJson('/admin/whatsapp/prepare', [
            'template_id' => $templateCd, 'source' => 'lideres', 'contact_ids' => [$liderX],
        ])->assertStatus(422);
        $this->actingAs($this->adminCd)->postJson('/admin/whatsapp/prepare', [
            'template_id' => $templateX, 'source' => 'lideres', 'select_all_matching' => true,
        ])->assertNotFound();

        // The send status belongs to the campaign that sent it.
        $this->actingAs($this->adminX)->getJson("/admin/whatsapp/sends/{$send->id}/status")->assertNotFound();
        $this->actingAs($this->adminCd)->getJson("/admin/whatsapp/sends/{$send->id}/status")->assertOk();
    }

    public function test_a_subcampaign_does_not_message_the_shared_electoral_persons(): void
    {
        $this->enableWhatsapp($this->campX);
        $person = (string) Str::uuid();
        DB::table('persons')->insert(['id' => $person, 'full_name' => 'Candidato Público']);
        DB::table('person_contact_points')->insert(['id' => (string) Str::uuid(), 'person_id' => $person, 'type' => 'mobile', 'value_raw' => '3009998877']);
        $this->lider($this->campX, 'Beto X');

        $contacts = fn (User $actor) => collect($this->actingAs($actor)->get('/admin/whatsapp?source=todos')->assertOk()
            ->viewData('page')['props']['contacts']['data'])->pluck('name')->all();

        $this->assertSame(['Beto X'], $contacts($this->adminX));
        $this->assertSame(['Candidato Público'], $contacts($this->adminCd));
    }

    public function test_birthday_history_shows_only_the_own_campaigns_sends(): void
    {
        $this->enableWhatsapp($this->campY);
        $date = now('America/Bogota')->toDateString();
        foreach ([Campaign::DEFAULT_ID => 'Ana CD', $this->campY => 'Ceci Y'] as $campaignId => $name) {
            $send = DB::table('whatsapp_sends')->insertGetId([
                'template_id' => $this->template($campaignId, "Cumple {$name}"), 'campaign_type' => 'birthday',
                'campaign_date' => $date, 'campaign_id' => $campaignId,
            ]);
            DB::table('whatsapp_send_recipients')->insert(['whatsapp_send_id' => $send, 'person_id' => (string) Str::uuid(), 'name' => $name, 'phone' => '300', 'status' => 'enviado']);
        }

        $this->actingAs($this->adminCd)->getJson("/admin/whatsapp/birthdays/history?date={$date}")
            ->assertOk()->assertJsonPath('recipients.*.name', ['Ana CD']);
        $this->actingAs($this->adminY)->getJson("/admin/whatsapp/birthdays/history?date={$date}")
            ->assertOk()->assertJsonPath('recipients.*.name', ['Ceci Y']);
    }

    // --- Shared props ---

    public function test_pages_share_the_users_campaign_and_whether_it_edits_electoral_data(): void
    {
        $this->actingAs($this->adminX)->get('/admin/usuarios')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.user.campaign', ['id' => $this->campX, 'name' => 'Alejandro', 'whatsapp_enabled' => false, 'is_subcampaign' => true])
            ->where('auth.user.canEditElectoral', false));

        $this->actingAs($this->adminCd)->get('/admin/usuarios')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.user.campaign', ['id' => Campaign::DEFAULT_ID, 'name' => 'Centro Democrático', 'whatsapp_enabled' => true, 'is_subcampaign' => false])
            ->where('auth.user.canEditElectoral', true));
    }

    // --- Users form: only grantable permissions ---

    private function offeredPermissions(User $actor): array
    {
        $keys = [];
        $this->actingAs($actor)->get('/admin/usuarios')->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$keys) {
                $props = $page->toArray()['props'];
                $keys = collect($props['availablePermissions'])->pluck('key')->sort()->values()->all();
                $this->assertCount(9, $props['permissionLabels']);
            });

        return $keys;
    }

    public function test_the_users_form_offers_only_the_permissions_the_actor_can_grant(): void
    {
        $sorted = fn (array $perms) => collect($perms)->sort()->values()->all();

        $this->assertSame($sorted(self::CAMPAIGN_ADMIN_PERMS), $this->offeredPermissions($this->adminX));
        $this->assertSame($sorted(self::ADMIN_PERMS), $this->offeredPermissions($this->adminCd));
        $this->assertSame($sorted(self::ADMIN_PERMS), $this->offeredPermissions($this->superadmin));

        // Everything offered is accepted; the hidden ones are still refused.
        $this->actingAs($this->adminX)->post('/admin/usuarios', $this->newUserPayload(['permissions' => self::CAMPAIGN_ADMIN_PERMS]))
            ->assertRedirect();
        $this->actingAs($this->adminX)->post('/admin/usuarios', $this->newUserPayload([
            'email' => 'otro@test.local', 'permissions' => ['view.mapa', 'manage.territory'],
        ]))->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'otro@test.local']);
    }

    public function test_saving_a_user_keeps_the_permissions_the_actor_cannot_see(): void
    {
        $cdUser = $this->user('con-partidos', Campaign::DEFAULT_ID, 'R09_CAMPO', ['view.mapa', 'manage.partidos']);
        $limitedCdAdmin = $this->user('admin-cd-limitado', Campaign::DEFAULT_ID, 'R09_CAMPO', ['view.admin', 'manage.users', 'view.mapa']);

        // The form resends the hidden permission untouched; an actor without it cannot edit that user at all.
        $this->actingAs($limitedCdAdmin)->put("/admin/usuarios/{$cdUser->id}", [
            'name' => 'Cambiado', 'email' => 'con-partidos@test.local', 'role_id' => $this->roles['R09_CAMPO'],
            'permissions' => ['view.mapa', 'manage.partidos'],
        ])->assertForbidden();
        $this->assertSame(['view.mapa', 'manage.partidos'], $cdUser->fresh()->permissions);

        // Whoever holds it edits the user and the permission stays.
        $this->actingAs($this->adminCd)->put("/admin/usuarios/{$cdUser->id}", [
            'name' => 'Cambiado', 'email' => 'con-partidos@test.local', 'role_id' => $this->roles['R09_CAMPO'],
            'permissions' => ['view.mapa', 'manage.partidos'],
        ])->assertRedirect();
        $this->assertSame('Cambiado', $cdUser->fresh()->name);
        $this->assertSame(['view.mapa', 'manage.partidos'], $cdUser->fresh()->permissions);
    }

    public function test_deactivating_the_own_account_is_refused_with_a_message(): void
    {
        $this->actingAs($this->adminX)->from('/admin/usuarios')->post("/admin/usuarios/{$this->adminX->id}/toggle")
            ->assertRedirect('/admin/usuarios')
            ->assertSessionHasErrors(['toggle' => 'No puedes desactivar tu propia cuenta']);
        $this->assertTrue($this->adminX->fresh()->is_active);
    }

    // --- Platform admin screens ---

    private const PLATFORM_SCREENS = ['/admin/territorio', '/admin/partidos', '/admin/importaciones', '/admin/catalogos',
        '/admin/personas', '/admin/cargos', '/admin/elecciones'];

    private function createPlatformScreenTables(): void
    {
        Schema::table('geographic_units', function (Blueprint $t) {
            $t->string('official_code')->nullable();
            $t->boolean('is_active')->default(true);
        });
        Schema::table('persons', function (Blueprint $t) {
            foreach (['first_name', 'last_name', 'normalized_name', 'gender', 'data_classification'] as $col) {
                $t->string($col)->nullable();
            }
        });
        Schema::create('person_aliases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('person_id');
        });
        Schema::create('political_organizations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('canonical_name');
            foreach (['acronym', 'type', 'status', 'color_hex'] as $col) {
                $t->string($col)->nullable();
            }
            $t->timestamps();
        });
        foreach (['offices' => 'type', 'corporations' => 'scope'] as $table => $extra) {
            Schema::create($table, function (Blueprint $t) use ($extra) {
                $t->uuid('id')->primary();
                $t->string('name');
                $t->string($extra)->nullable();
            });
        }
        Schema::create('source_files', function (Blueprint $t) {
            $t->uuid('id')->primary();
            foreach (['original_name', 'file_type', 'sha256_hash'] as $col) {
                $t->string($col)->nullable();
            }
            $t->integer('size_bytes')->nullable();
            $t->timestamps();
        });
        Schema::create('cargo_categories', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->integer('display_order')->default(0);
            $t->boolean('is_highlighted')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('electoral_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            foreach (['name', 'event_type', 'political_period', 'status'] as $col) {
                $t->string($col)->nullable();
            }
            $t->date('election_date')->nullable();
            $t->timestamps();
        });
        Schema::create('contests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('electoral_event_id');
        });
    }

    public function test_platform_admin_screens_are_forbidden_to_campaigns_that_do_not_edit_electoral_data(): void
    {
        $id = (string) Str::uuid();
        $urls = [...self::PLATFORM_SCREENS, "/admin/personas/{$id}/json", "/admin/elecciones/{$id}/json", "/admin/elecciones/contest/{$id}/json"];

        foreach ([$this->adminX, $this->adminY] as $actor) {
            foreach ($urls as $url) {
                $this->actingAs($actor)->get($url)->assertForbidden();
            }
        }
    }

    public function test_platform_admin_screens_stay_open_for_the_cd_and_the_superadmin(): void
    {
        $this->createPlatformScreenTables();

        foreach ([$this->adminCd, $this->superadmin] as $actor) {
            foreach (self::PLATFORM_SCREENS as $url) {
                $this->actingAs($actor)->get($url)->assertOk();
            }
        }
    }

    public function test_the_admin_home_sends_other_campaigns_to_screens_they_can_open(): void
    {
        $this->actingAs($this->adminX)->get('/admin')->assertRedirect('/admin/usuarios');

        $viewer = $this->user('viewer-admin-x', $this->campX, 'R02_ADMIN_FUNCIONAL', ['view.admin', 'view.mapa']);
        $this->actingAs($viewer)->get('/admin')->assertRedirect('/mapa-politico');

        $this->createPlatformScreenTables();
        Schema::create('import_jobs', fn (Blueprint $t) => $t->uuid('id')->primary());
        foreach ([$this->adminCd, $this->superadmin] as $actor) {
            $this->actingAs($actor)->get('/admin')->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Index'));
        }
    }

    public function test_the_forbidden_page_is_in_spanish_and_gives_the_reason(): void
    {
        $this->actingAs($this->adminX)->get('/admin/territorio')->assertForbidden()
            ->assertSee('No tienes acceso a esta sección')
            ->assertSee('Esta sección es solo para el equipo de la plataforma.')
            ->assertSee('Volver al inicio')
            ->assertDontSee('Forbidden');
    }

    public function test_screens_every_campaign_uses_are_not_platform_only(): void
    {
        foreach (['/admin/usuarios', '/admin/auditoria', '/api/search?q=a', '/perfil'] as $url) {
            $this->actingAs($this->adminX)->get($url)->assertOk();
        }
    }

    // --- Commands ---

    public function test_campaign_create_makes_a_subcampaign_and_rejects_duplicates(): void
    {
        $this->artisan('campaign:create', ['name' => 'Alejandro Cárdenas', '--parent' => 'centro-democratico', '--whatsapp' => true])
            ->assertSuccessful();
        $campaign = DB::table('campaigns')->where('slug', 'alejandro-cardenas')->first();
        $this->assertSame(Campaign::DEFAULT_ID, $campaign->parent_id);
        $this->assertTrue((bool) $campaign->whatsapp_enabled);

        $this->artisan('campaign:create', ['name' => 'Raíz', '--slug' => 'raiz'])->assertSuccessful();
        $this->assertNull(DB::table('campaigns')->where('slug', 'raiz')->value('parent_id'));
        $this->assertFalse((bool) DB::table('campaigns')->where('slug', 'raiz')->value('whatsapp_enabled'));

        $total = DB::table('campaigns')->count();
        $this->artisan('campaign:create', ['name' => 'Otra vez', '--slug' => 'alejandro-cardenas'])->assertFailed();
        $this->artisan('campaign:create', ['name' => 'Huérfana', '--parent' => 'no-existe'])->assertFailed();
        $this->artisan('campaign:create', ['name' => 'Huérfana', '--parent' => ''])->assertFailed();
        $this->assertSame($total, DB::table('campaigns')->count());

        $this->artisan('campaign:list')->assertSuccessful()->expectsOutputToContain('alejandro-cardenas');
    }
}
