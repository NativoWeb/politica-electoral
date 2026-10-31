<?php

namespace Tests\Feature;

use App\Http\Controllers\LegislativoController;
use App\Http\Controllers\VotanteController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

// QA prod (minor findings): every web route has a readable name for the audit trail, /gobernador needs
// view.gobernador, and "/" lands each user on a view they may open without ever looping.
class MinorQaFixesTest extends TestCase
{
    private array $roles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->createSchema();

        DB::table('campaigns')->insert(['id' => Campaign::DEFAULT_ID, 'name' => 'Centro Democrático', 'slug' => 'centro-democratico']);
        foreach ([['R01_SUPERADMIN', 5], ['R02_ADMIN_FUNCIONAL', 4], ['R09_CAMPO', 1]] as [$code, $level]) {
            $this->roles[$code] = Role::create(['code' => $code, 'name' => $code, 'level' => $level])->id;
        }

        // The electoral pages and votante writes are stubbed: these tests are about routing, permissions and audit.
        $this->partialMock(LegislativoController::class, function ($mock) {
            $mock->shouldReceive('gobernador')->andReturn(response('gobernador-ok'));
            $mock->shouldReceive('gobernadorMunicipio')->andReturn(response('gobernador-municipio-ok'));
        });
        $this->partialMock(VotanteController::class, function ($mock) {
            $mock->shouldReceive('store')->andReturn(response()->json(['success' => true]));
            $mock->shouldReceive('storeContacto')->andReturn(response()->json(['success' => true]));
        });
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
    }

    private function user(string $role, ?array $permissions): User
    {
        $key = Str::lower(Str::random(6));

        return User::create([
            'name' => "Usuario {$key}", 'email' => "{$key}@test.local", 'password' => 'secret123',
            'role_id' => $this->roles[$role], 'permissions' => $permissions,
        ])->fresh();
    }

    // --- BUG-03: readable audit actions ---

    public function test_every_app_web_route_has_a_real_name(): void
    {
        $unnamed = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array('web', $route->gatherMiddleware(), true))
            ->reject(fn ($route) => str_starts_with($route->uri(), '_')) // package internals (Inertia devtools)
            ->filter(fn ($route) => $route->getName() === null || str_starts_with($route->getName(), 'generated::'))
            ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame([], $unnamed);
    }

    public function test_creating_a_votante_and_a_contacto_is_audited_with_the_route_name(): void
    {
        $campo = $this->user('R09_CAMPO', ['view.votantes', 'write.data']);
        $votanteId = (string) Str::uuid();

        $this->actingAs($campo)->postJson('/votantes', ['nombre' => 'Ana'])->assertOk();
        $this->actingAs($campo)->postJson("/votantes/{$votanteId}/contactos", ['tipo' => 'telefono'])->assertOk();

        $logs = DB::table('audit_logs')->orderBy('created_at')->get(['action', 'entity_type', 'entity_id']);
        $this->assertSame(['POST votantes.store', 'POST votantes.contactos.store'], $logs->pluck('action')->all());
        $this->assertSame(['votante', 'contacto'], $logs->pluck('entity_type')->all());
        $this->assertSame($votanteId, $logs[1]->entity_id);
        $this->assertFalse($logs->contains(fn ($l) => str_contains($l->action, 'generated::')));
    }

    // --- /gobernador requires view.gobernador ---

    public function test_gobernador_is_denied_without_view_gobernador(): void
    {
        $adminSub = $this->user('R02_ADMIN_FUNCIONAL', ['view.mapa', 'view.votantes', 'view.admin', 'write.data', 'manage.users', 'audit.view']);
        $municipio = (string) Str::uuid();

        $this->actingAs($adminSub)->get('/gobernador')->assertRedirect('/');
        $this->actingAs($adminSub)->get("/gobernador/{$municipio}")->assertRedirect('/');
        $this->actingAs($adminSub)->get('/gobernador', $this->inertiaHeaders())->assertRedirect('/');
        $this->actingAs($adminSub)->getJson('/gobernador')->assertForbidden();
        $this->actingAs($adminSub)->post('/gobernador')->assertStatus(405);
        $this->actingAs($adminSub)->get('/exportar/excel?source=gobernador')->assertForbidden();

        // The redirect never lands back on /gobernador.
        $this->actingAs($adminSub)->get('/')->assertRedirect('/mapa-politico');
    }

    public function test_gobernador_still_opens_with_the_permission_and_for_superadmin(): void
    {
        $campo = $this->user('R09_CAMPO', ['view.gobernador', 'view.mapa']);
        $super = $this->user('R01_SUPERADMIN', null);
        $municipio = (string) Str::uuid();

        $this->actingAs($campo)->get('/gobernador')->assertOk()->assertSee('gobernador-ok');
        $this->actingAs($campo)->get("/gobernador/{$municipio}")->assertOk()->assertSee('gobernador-municipio-ok');
        $this->actingAs($super)->get('/gobernador')->assertOk();
    }

    private function inertiaHeaders(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())];
    }

    // Reviewer finding: after login to a url.intended of /gobernador, Inertia followed the redirect and
    // painted a 403 modal over the login. Now it lands on the user's home.
    public function test_login_with_intended_gobernador_without_permission_lands_home_without_403(): void
    {
        $campo = $this->user('R09_CAMPO', ['view.mapa', 'write.data']);

        $this->get('/gobernador')->assertRedirect('/login');
        $this->post('/login', ['email' => $campo->email, 'password' => 'secret123'], $this->inertiaHeaders())
            ->assertRedirect('/gobernador');

        $this->get('/gobernador', $this->inertiaHeaders())->assertRedirect('/');
        $this->get('/', $this->inertiaHeaders())->assertRedirect('/mapa-politico');
    }

    public function test_login_with_intended_gobernador_and_no_views_ends_on_profile(): void
    {
        $nada = $this->user('R09_CAMPO', null);

        $this->get('/gobernador')->assertRedirect('/login');
        $this->followingRedirects()
            ->post('/login', ['email' => $nada->email, 'password' => 'secret123'], $this->inertiaHeaders())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Perfil', false));
    }

    public function test_admin_pages_without_permission_redirect_home_on_visits_but_403_on_writes_and_json(): void
    {
        $campo = $this->user('R09_CAMPO', ['view.mapa']);

        $this->actingAs($campo)->get('/admin/auditoria', $this->inertiaHeaders())->assertRedirect('/');
        $this->actingAs($campo)->getJson('/admin/auditoria')->assertForbidden();
        $this->actingAs($campo)->post('/admin/usuarios', ['name' => 'X'])->assertForbidden();
    }

    public function test_guest_is_still_sent_to_login(): void
    {
        $this->get('/gobernador')->assertRedirect('/login');
    }

    // --- "/" lands on a view the user may open, never in a loop ---

    public function test_home_lands_on_the_first_view_the_user_may_open(): void
    {
        $this->actingAs($this->user('R09_CAMPO', ['view.mapa', 'view.gobernador']))->get('/')->assertRedirect('/mapa-politico');
        $this->actingAs($this->user('R09_CAMPO', ['view.votantes']))->get('/')->assertRedirect('/votantes');
        $this->actingAs($this->user('R01_SUPERADMIN', null))->get('/')->assertRedirect('/mapa-politico');
    }

    public function test_user_with_only_gobernador_reaches_it_without_looping(): void
    {
        $soloGob = $this->user('R09_CAMPO', ['view.gobernador']);

        $this->actingAs($soloGob)->followingRedirects()->get('/')->assertOk()->assertSee('gobernador-ok');
    }

    public function test_user_without_any_view_lands_on_profile_without_looping(): void
    {
        $nada = $this->user('R09_CAMPO', null);

        $this->actingAs($nada)->get('/')->assertRedirect('/perfil');
        $this->actingAs($nada)->followingRedirects()->get('/gobernador')->assertOk();
        $this->assertSame('/perfil', parse_url(url()->current(), PHP_URL_PATH));
    }
}
