<?php

namespace Tests\Feature;

use App\Exports\GenericExport;
use App\Http\Controllers\LiderController;
use App\Http\Controllers\MapaPoliticoController;
use App\Http\Controllers\OfflineController;
use App\Http\Controllers\VotanteController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Mockery;
use Tests\TestCase;

// /mapa-politico needs view.mapa and /votantes needs view.votantes on the server, not only in the menu.
// Endpoints both screens call accept either permission. Exports and offline follow the screen they export.
class ViewPermissionsTest extends TestCase
{
    private const MAPA_ACTIONS = [
        'index', 'persona', 'posiblesDuplicados', 'searchLideres', 'storeLider', 'updatePersona', 'storeNexo',
        'updateNexo', 'destroyNexo', 'destroyLider', 'toggleLider', 'mergeLider', 'addReferido', 'removeReferido',
        'storeMunicipioInfluencia', 'destroyMunicipioInfluencia',
    ];

    private const VOTANTE_ACTIONS = [
        'index', 'show', 'referidos', 'store', 'update', 'destroy', 'storeContacto', 'updateContacto',
        'destroyContacto', 'storeInfoPolitica', 'updateInfoPolitica', 'destroyInfoPolitica',
    ];

    private array $roles = [];

    private string $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->createSchema();
        $this->id = (string) Str::uuid();

        DB::table('campaigns')->insert(['id' => Campaign::DEFAULT_ID, 'name' => 'Centro Democrático', 'slug' => 'centro-democratico']);
        foreach ([['R01_SUPERADMIN', 5], ['R09_CAMPO', 1]] as [$code, $level]) {
            $this->roles[$code] = Role::create(['code' => $code, 'name' => $code, 'level' => $level])->id;
        }

        // Controllers are stubbed (except the Votantes listing used by its export): these tests are about access.
        $this->partialMock(MapaPoliticoController::class, function ($mock) {
            foreach (self::MAPA_ACTIONS as $action) {
                $mock->shouldReceive($action)->andReturn(response()->json(['success' => true, 'action' => "mapa.{$action}"]));
            }
            $mock->shouldReceive('rows')->andReturn(['data' => [
                ['nombre' => 'Ana Mapa', 'municipio' => 'Málaga', 'tipo_registro' => 'Líderes', 'observacion' => null],
            ]]);
        });
        $this->partialMock(VotanteController::class, function ($mock) {
            foreach (self::VOTANTE_ACTIONS as $action) {
                $mock->shouldReceive($action)->andReturn(response()->json(['success' => true, 'action' => "votantes.{$action}"]));
            }
        });
        $this->partialMock(LiderController::class, function ($mock) {
            $mock->shouldReceive('index')->andReturn(response('lideres-ok'));
            $mock->shouldReceive('show')->andReturn(response('lideres-show-ok'));
        });
        $this->partialMock(OfflineController::class, function ($mock) {
            $mock->shouldReceive('download')->andReturn(response()->json(['personas' => []]));
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
        Schema::create('lideres', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('campaign_id')->nullable();
            $t->uuid('geographic_unit_id')->nullable();
            $t->string('nombre');
            $t->string('municipio')->nullable();
            $t->string('cargo')->nullable();
            $t->string('partido')->nullable();
            $t->string('telefono')->nullable();
            $t->string('cedula')->nullable();
            $t->string('referente_documento')->nullable();
            $t->integer('votos')->nullable();
            $t->text('observacion')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
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

    private function inertiaHeaders(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())];
    }

    // [method, uri] of the map's own data and write endpoints.
    private function mapaOnlyEndpoints(): array
    {
        $id = $this->id;

        return [
            ['GET', "/mapa-politico/persona/{$id}"],
            ['GET', "/mapa-politico/persona/{$id}/posibles-duplicados"],
            ['POST', '/mapa-politico/crear-lider'],
            ['PUT', "/mapa-politico/persona/{$id}"],
            ['DELETE', "/mapa-politico/persona/{$id}"],
            ['POST', "/mapa-politico/persona/{$id}/nexos"],
            ['PUT', "/mapa-politico/nexos/{$id}"],
            ['DELETE', "/mapa-politico/nexos/{$id}"],
            ['POST', "/mapa-politico/persona/{$id}/unificar"],
            ['POST', "/mapa-politico/persona/{$id}/municipios-influencia"],
            ['DELETE', "/mapa-politico/persona/{$id}/municipios-influencia/{$id}"],
            ['GET', "/api/offline/download/{$id}"],
        ];
    }

    private function votantesOnlyEndpoints(): array
    {
        $id = $this->id;

        return [
            ['GET', "/votantes/{$id}"],
            ['GET', "/votantes/{$id}/referidos"],
            ['POST', '/votantes'],
            ['PUT', "/votantes/{$id}"],
            ['DELETE', "/votantes/{$id}"],
        ];
    }

    // Called by both screens: VotanteDetail (toggle, referidos) and the map's persona panel (contactos, info politica).
    private function sharedEndpoints(): array
    {
        $id = $this->id;

        return [
            ['GET', '/mapa-politico/search-lideres?q=an'],
            ['POST', "/mapa-politico/persona/{$id}/toggle"],
            ['POST', "/mapa-politico/persona/{$id}/add-referido"],
            ['DELETE', "/mapa-politico/persona/{$id}/referido/{$id}"],
            ['POST', "/votantes/{$id}/contactos"],
            ['PUT', "/votantes/contactos/{$id}"],
            ['DELETE', "/votantes/contactos/{$id}"],
            ['POST', "/votantes/{$id}/info-politica"],
            ['PUT', "/votantes/info-politica/{$id}"],
            ['DELETE', "/votantes/info-politica/{$id}"],
        ];
    }

    private function assertStatuses(User $user, array $endpoints, int $status): void
    {
        foreach ($endpoints as [$method, $uri]) {
            $this->actingAs($user)->json($method, $uri)->assertStatus($status);
        }
    }

    // --- /mapa-politico ---

    public function test_map_page_and_its_endpoints_are_denied_without_view_mapa(): void
    {
        $soloVotantes = $this->user('R09_CAMPO', ['view.votantes', 'write.data']);

        $this->actingAs($soloVotantes)->get('/mapa-politico')->assertRedirect('/');
        $this->actingAs($soloVotantes)->get('/mapa-politico?municipio=x', $this->inertiaHeaders())->assertRedirect('/');
        $this->assertStatuses($soloVotantes, $this->mapaOnlyEndpoints(), 403);

        // Home sends them to the view they have, never back to the map.
        $this->actingAs($soloVotantes)->get('/')->assertRedirect('/votantes');
        $this->actingAs($soloVotantes)->followingRedirects()->get('/mapa-politico')->assertOk()->assertJson(['action' => 'votantes.index']);
    }

    public function test_map_page_and_its_endpoints_open_with_view_mapa(): void
    {
        $soloMapa = $this->user('R09_CAMPO', ['view.mapa', 'write.data']);

        $this->actingAs($soloMapa)->get('/mapa-politico')->assertOk()->assertJson(['action' => 'mapa.index']);
        $this->assertStatuses($soloMapa, $this->mapaOnlyEndpoints(), 200);
        $this->assertStatuses($this->user('R01_SUPERADMIN', null), $this->mapaOnlyEndpoints(), 200);
    }

    // --- /votantes ---

    public function test_votantes_page_and_its_endpoints_are_denied_without_view_votantes(): void
    {
        $soloMapa = $this->user('R09_CAMPO', ['view.mapa', 'write.data']);

        $this->actingAs($soloMapa)->get('/votantes')->assertRedirect('/');
        $this->actingAs($soloMapa)->get('/votantes', $this->inertiaHeaders())->assertRedirect('/');
        $this->assertStatuses($soloMapa, $this->votantesOnlyEndpoints(), 403);
        $this->actingAs($soloMapa)->get('/')->assertRedirect('/mapa-politico');
    }

    public function test_votantes_page_and_its_endpoints_open_with_view_votantes(): void
    {
        $soloVotantes = $this->user('R09_CAMPO', ['view.votantes', 'write.data']);

        $this->actingAs($soloVotantes)->get('/votantes')->assertOk()->assertJson(['action' => 'votantes.index']);
        $this->assertStatuses($soloVotantes, $this->votantesOnlyEndpoints(), 200);
        $this->assertStatuses($this->user('R01_SUPERADMIN', null), $this->votantesOnlyEndpoints(), 200);
    }

    // --- endpoints shared by both screens ---

    public function test_shared_endpoints_accept_either_view_and_deny_users_with_neither(): void
    {
        $this->assertStatuses($this->user('R09_CAMPO', ['view.mapa', 'write.data']), $this->sharedEndpoints(), 200);
        $this->assertStatuses($this->user('R09_CAMPO', ['view.votantes', 'write.data']), $this->sharedEndpoints(), 200);

        $soloGobernador = $this->user('R09_CAMPO', ['view.gobernador', 'write.data']);
        $this->assertStatuses($soloGobernador, $this->sharedEndpoints(), 403);
        $this->actingAs($soloGobernador)->getJson("/votantes/{$this->id}")->assertForbidden();
    }

    public function test_writes_still_need_a_writer_role_on_top_of_the_view(): void
    {
        $consulta = Role::create(['code' => 'R10_CONSULTA_EJECUTIVA', 'name' => 'R10', 'level' => 1]);
        $lector = User::create([
            'name' => 'Lector', 'email' => 'lector@test.local', 'password' => 'secret123',
            'role_id' => $consulta->id, 'permissions' => ['view.mapa', 'view.votantes'],
        ])->fresh();

        $this->actingAs($lector)->postJson("/votantes/{$this->id}/contactos")->assertForbidden();
        $this->actingAs($lector)->getJson('/mapa-politico/search-lideres?q=an')->assertOk();
    }

    public function test_lideres_pages_need_the_map_or_votantes_view(): void
    {
        $soloGobernador = $this->user('R09_CAMPO', ['view.gobernador']);

        $this->actingAs($soloGobernador)->get('/lideres')->assertRedirect('/');
        $this->actingAs($soloGobernador)->get("/lideres/{$this->id}")->assertRedirect('/');
        $this->actingAs($soloGobernador)->getJson("/lideres/{$this->id}")->assertForbidden();

        $this->actingAs($this->user('R09_CAMPO', ['view.votantes']))->get("/lideres/{$this->id}")->assertOk()->assertSee('lideres-show-ok');
        $this->actingAs($this->user('R09_CAMPO', ['view.mapa']))->get('/lideres')->assertOk()->assertSee('lideres-ok');
    }

    // --- exports and offline ---

    public function test_map_export_needs_view_mapa(): void
    {
        Excel::fake();
        $soloVotantes = $this->user('R09_CAMPO', ['view.votantes']);

        $this->actingAs($soloVotantes)->get('/exportar/excel?source=mapa-politico')->assertForbidden();
        // Without a source the export is the map's.
        $this->actingAs($soloVotantes)->get('/exportar/excel')->assertForbidden();
        $this->actingAs($soloVotantes)->get('/exportar/pdf?source=mapa-politico')->assertForbidden();

        $this->actingAs($this->user('R09_CAMPO', ['view.mapa']))
            ->get('/exportar/excel?source=mapa-politico&title=Mapa')->assertOk();
        Excel::assertDownloaded('Mapa.xlsx', fn (GenericExport $export) => $export->array()[0][0] === 'Ana Mapa');
    }

    public function test_votantes_export_needs_view_votantes_and_exports_the_votantes_listing(): void
    {
        Excel::fake();
        DB::table('lideres')->insert([
            ['id' => (string) Str::uuid(), 'campaign_id' => Campaign::DEFAULT_ID, 'nombre' => 'Beatriz Votante', 'municipio' => 'Málaga', 'partido' => 'CD', 'telefono' => '3001234567', 'observacion' => 'Nota', 'is_active' => true],
            ['id' => (string) Str::uuid(), 'campaign_id' => Campaign::DEFAULT_ID, 'nombre' => 'Inactivo', 'municipio' => 'Málaga', 'partido' => 'CD', 'telefono' => null, 'observacion' => null, 'is_active' => false],
        ]);

        $this->actingAs($this->user('R09_CAMPO', ['view.mapa']))->get('/exportar/excel?source=votantes')->assertForbidden();

        $this->actingAs($this->user('R09_CAMPO', ['view.votantes']))
            ->get('/exportar/excel?source=votantes&title=Votantes&municipio=M%C3%A1laga')->assertOk();
        Excel::assertDownloaded('Votantes.xlsx', function (GenericExport $export) {
            $this->assertSame([['Beatriz Votante', 'Málaga', 'Votante', '—', 'CD', '3001234567', '—', 'Nota']], $export->array());

            return true;
        });
    }

    public function test_votantes_pdf_reads_only_one_row_past_the_cap_and_reports_the_real_total(): void
    {
        DB::table('lideres')->insert(collect(range(1, 503))->map(fn ($i) => [
            'id' => (string) Str::uuid(), 'campaign_id' => Campaign::DEFAULT_ID, 'nombre' => sprintf('Votante %03d', $i), 'is_active' => true,
        ])->all());

        $captured = null;
        $pdf = Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $pdf->shouldReceive('setPaper')->andReturnSelf();
        $pdf->shouldReceive('setOption')->andReturnSelf();
        $pdf->shouldReceive('download')->andReturn(response('pdf'));
        Pdf::shouldReceive('loadView')->andReturnUsing(function ($view, $data) use (&$captured, $pdf) {
            $captured = $data;

            return $pdf;
        });

        DB::enableQueryLog();
        $this->actingAs($this->user('R09_CAMPO', ['view.votantes']))->get('/exportar/pdf?source=votantes')->assertOk();
        $listing = collect(DB::getQueryLog())->pluck('query')->first(fn ($sql) => str_contains($sql, 'order by "lideres"."nombre"'));

        $this->assertStringContainsString('limit 501', $listing);
        $this->assertStringNotContainsString('referidos_count', $listing);
        $this->assertCount(500, $captured['data']);
        $this->assertTrue($captured['truncated']);
        $this->assertSame(503, $captured['totalCount']);
        $this->assertSame('Votante 001', $captured['data'][0]['nombre']);
    }

    public function test_offline_download_needs_view_mapa(): void
    {
        $this->actingAs($this->user('R09_CAMPO', ['view.votantes', 'view.gobernador']))
            ->getJson("/api/offline/download/{$this->id}")->assertForbidden();
        $this->actingAs($this->user('R09_CAMPO', ['view.mapa']))
            ->getJson("/api/offline/download/{$this->id}")->assertOk();
    }

    // --- route names (audit trail) ---

    public function test_every_app_route_web_and_api_has_a_readable_name(): void
    {
        $unnamed = collect(Route::getRoutes()->getRoutes())
            ->reject(fn ($route) => str_starts_with($route->uri(), '_')) // package internals (Inertia devtools)
            ->reject(fn ($route) => $route->uri() === 'up') // framework health check
            ->filter(fn ($route) => $route->getName() === null || str_starts_with($route->getName(), 'generated::'))
            ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame([], $unnamed);
    }

    public function test_api_routes_use_the_api_agent_and_api_whatsapp_prefixes(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/agent/') || str_starts_with($route->uri(), 'api/whatsapp/'))
            ->mapWithKeys(fn ($route) => [$route->uri() => $route->getName()]);

        $this->assertNotEmpty($names);
        foreach ($names as $uri => $name) {
            $prefix = str_starts_with($uri, 'api/agent/') ? 'api.agent.' : 'api.whatsapp.';
            $this->assertStringStartsWith($prefix, (string) $name, $uri);
        }
        $this->assertSame('api.agent.link', Route::getRoutes()->getByName('api.agent.link')?->getName());
        $this->assertSame('api/whatsapp/birthdays/today', Route::getRoutes()->getByName('api.whatsapp.birthdays.today')?->uri());
    }
}
