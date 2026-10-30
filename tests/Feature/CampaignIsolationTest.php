<?php

namespace Tests\Feature;

use App\Exports\GenericExport;
use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Maatwebsite\Excel\Facades\Excel;
use Mockery;
use Tests\TestCase;

// Multi-campaign step 2: campaign CD (root) with subcampaign X, and an independent root campaign Y.
// A user sees their campaign and its subcampaigns, edits only their own, and gets a 404 for the rest.
// Built on SQLite like TerritoryScopeTest; the ILIKE paths live in CampaignIsolationPostgresTest.
class CampaignIsolationTest extends TestCase
{
    private string $mun;

    private string $campX;

    private string $campY;

    private string $liderA;

    private string $liderB;

    private string $liderC;

    private User $userCd;

    private User $userX;

    private User $userY;

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
            $t->uuid('campaign_id')->default(Campaign::DEFAULT_ID);
            $t->uuid('geographic_unit_id')->nullable();
            $t->integer('votos')->default(0);
            foreach ($booleans as $col) {
                $t->boolean($col)->default($col === 'is_active');
            }
            foreach (['nombre', 'provincia', 'municipio', 'cargo', 'tipo', 'telefono', 'cedula', 'email',
                'observacion', 'partido', 'direccion', 'barrio', 'zona', 'profesion', 'tipo_documento',
                'fecha_expedicion_doc', 'genero', 'fecha_nacimiento', 'foto', 'estado_civil',
                'referente_documento', 'referente_nombre', 'referente_apellido', 'departamento_votacion',
                'municipio_votacion', 'puesto_votacion', 'direccion_puesto', 'mesa_votacion',
                'nivel_confianza', 'convenio', 'escolaridad', 'tipo_hoja_vida', 'fecha_registro_hv',
                'facebook', 'twitter', 'instagram'] as $col) {
                $t->string($col)->nullable();
            }
            $t->timestamps();
        });
        DB::statement("CREATE UNIQUE INDEX lideres_campaign_id_cedula_unique ON lideres (campaign_id, cedula) WHERE cedula IS NOT NULL AND cedula <> ''");

        Schema::create('lider_contactos', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('lider_id');
            $t->string('tipo')->nullable();
            $t->string('valor')->nullable();
            $t->boolean('principal')->default(false);
            $t->string('estado')->nullable();
            $t->timestamps();
        });

        Schema::create('lider_info_politica', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('lider_id');
            foreach (['cargo_politico', 'departamento', 'municipio', 'partido', 'aliado', 'observacion', 'estado'] as $col) {
                $t->string($col)->nullable();
            }
            $t->integer('votos')->nullable();
            $t->timestamps();
        });

        Schema::create('nexos_familiares', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('campaign_id')->default(Campaign::DEFAULT_ID);
            $t->uuid('person_id');
            foreach (['nombre', 'parentesco', 'cargo', 'gustos', 'observaciones', 'cedula', 'telefono'] as $col) {
                $t->string($col)->nullable();
            }
            $t->integer('edad')->nullable();
            $t->timestamps();
        });

        Schema::create('lider_municipios_influencia', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('lider_id');
            $t->uuid('geographic_unit_id');
            $t->string('municipio_name')->nullable();
            $t->string('provincia_name')->nullable();
            $t->string('nota')->nullable();
            $t->timestamps();
        });

        Schema::create('political_organizations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('canonical_name');
        });

        Schema::create('persons', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('full_name');
            $t->string('cedula')->nullable();
            $t->timestamps();
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
            $t->uuid('id')->primary();
            $t->uuid('candidacy_id');
            $t->uuid('organization_id');
            $t->boolean('is_primary')->default(true);
            $t->string('endorsement_type')->nullable();
            $t->timestamps();
        });

        Schema::create('electoral_results', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('candidacy_id')->nullable();
            $t->uuid('contest_id')->nullable();
            $t->uuid('organization_id')->nullable();
            $t->uuid('geographic_unit_id')->nullable();
            $t->string('metric_type');
            $t->integer('value')->nullable();
        });

        Schema::create('cargo_categories', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->integer('display_order')->default(0);
            $t->boolean('is_highlighted')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
    }

    private function seedScenario(): void
    {
        DB::table('campaigns')->insert([
            ['id' => Campaign::DEFAULT_ID, 'name' => 'Centro Democrático', 'slug' => 'centro-democratico', 'parent_id' => null],
            ['id' => $this->campX = (string) Str::uuid(), 'name' => 'Alejandro', 'slug' => 'alejandro', 'parent_id' => Campaign::DEFAULT_ID],
            ['id' => $this->campY = (string) Str::uuid(), 'name' => 'Otra', 'slug' => 'otra', 'parent_id' => null],
        ]);

        $provincia = $this->geo('province', 'Soto');
        $this->mun = $this->geo('municipality', 'Girón', $provincia);

        $this->liderA = $this->lider(Campaign::DEFAULT_ID, 'Ana Campaña CD', '111');
        $this->liderB = $this->lider($this->campX, 'Beto Campaña X', '222');
        $this->liderC = $this->lider($this->campY, 'Ceci Campaña Y', '333');

        $role = Role::create(['code' => 'R02_ADMIN_FUNCIONAL', 'name' => 'Admin funcional', 'level' => 9]);
        $this->userCd = $this->user('cd', Campaign::DEFAULT_ID, $role->id);
        $this->userX = $this->user('x', $this->campX, $role->id);
        $this->userY = $this->user('y', $this->campY, $role->id);
    }

    private function user(string $key, string $campaignId, string $roleId): User
    {
        $user = User::create([
            'name' => "Usuario {$key}", 'email' => "{$key}@test.local", 'password' => 'secret123', 'role_id' => $roleId,
        ]);
        // campaign_id is never mass-assignable.
        $user->forceFill(['campaign_id' => $campaignId])->save();

        return $user;
    }

    private function geo(string $type, string $name, ?string $parent = null): string
    {
        $id = (string) Str::uuid();
        DB::table('geographic_units')->insert(['id' => $id, 'type' => $type, 'canonical_name' => $name, 'parent_id' => $parent]);

        return $id;
    }

    private function lider(string $campaignId, string $nombre, ?string $cedula, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('lideres')->insert(array_merge([
            'id' => $id, 'campaign_id' => $campaignId, 'geographic_unit_id' => $this->mun, 'nombre' => $nombre,
            'cedula' => $cedula, 'municipio' => 'Girón', 'telefono' => '300'.$cedula, 'cargo' => 'Líder',
            'tipo' => 'directorio', 'is_active' => true, 'nivel_confianza' => 'sin_llamar', 'observacion' => "Nota de {$nombre}",
            'barrio' => "Barrio {$nombre}",
        ], $extra));

        return $id;
    }

    private function nexo(string $campaignId, string $personId, string $nombre): string
    {
        $id = (string) Str::uuid();
        DB::table('nexos_familiares')->insert(['id' => $id, 'campaign_id' => $campaignId, 'person_id' => $personId, 'nombre' => $nombre]);

        return $id;
    }

    private function snapshot(string $liderId): array
    {
        return (array) DB::table('lideres')->where('id', $liderId)->first();
    }

    // Every endpoint that touches a single lider, as [method, uri, payload].
    private function writeEndpoints(string $id, string $otherId): array
    {
        return [
            ['putJson', "/votantes/{$id}", ['nombre' => 'Hackeado', 'telefono' => '999']],
            ['deleteJson', "/votantes/{$id}", []],
            ['postJson', "/mapa-politico/persona/{$id}/unificar", ['duplicado_id' => $otherId]],
            ['postJson', "/mapa-politico/persona/{$id}/nexos", ['nombre' => 'Intruso', 'parentesco' => 'Primo']],
            ['postJson', "/mapa-politico/persona/{$id}/add-referido", ['lider_id' => $otherId]],
            ['postJson', "/mapa-politico/persona/{$id}/toggle", []],
            ['putJson', "/mapa-politico/persona/{$id}", ['telefono' => '999', 'observacion' => 'Hackeado']],
            ['deleteJson', "/mapa-politico/persona/{$id}", []],
            ['postJson', "/votantes/{$id}/contactos", ['tipo' => 'celular', 'valor' => '1']],
            ['postJson', "/votantes/{$id}/info-politica", ['cargo_politico' => 'X']],
            ['postJson', "/mapa-politico/persona/{$id}/municipios-influencia", ['geographic_unit_id' => $this->mun]],
        ];
    }

    private function assertWritesRejected(User $user, string $id, string $otherId, int $status): void
    {
        $before = $this->snapshot($id);
        $otherBefore = $this->snapshot($otherId);
        $contactos = DB::table('lider_contactos')->count();
        $nexos = DB::table('nexos_familiares')->count();
        $info = DB::table('lider_info_politica')->count();
        $influencia = DB::table('lider_municipios_influencia')->count();

        foreach ($this->writeEndpoints($id, $otherId) as [$method, $uri, $payload]) {
            $this->actingAs($user)->{$method}($uri, $payload)->assertStatus($status);
        }

        $this->assertSame($before, $this->snapshot($id));
        $this->assertSame($otherBefore, $this->snapshot($otherId));
        $this->assertSame($contactos, DB::table('lider_contactos')->count());
        $this->assertSame($nexos, DB::table('nexos_familiares')->count());
        $this->assertSame($info, DB::table('lider_info_politica')->count());
        $this->assertSame($influencia, DB::table('lider_municipios_influencia')->count());
    }

    // Child records of a lider, reached by their own id.
    private function assertChildWritesRejected(User $user, string $liderId, int $status): void
    {
        $contacto = (string) Str::uuid();
        DB::table('lider_contactos')->insert(['id' => $contacto, 'lider_id' => $liderId, 'tipo' => 'celular', 'valor' => 'original']);
        $info = (string) Str::uuid();
        DB::table('lider_info_politica')->insert(['id' => $info, 'lider_id' => $liderId, 'cargo_politico' => 'original']);
        $campaign = DB::table('lideres')->where('id', $liderId)->value('campaign_id');
        $nexo = $this->nexo($campaign, $liderId, 'original');
        $influencia = (string) Str::uuid();
        DB::table('lider_municipios_influencia')->insert(['id' => $influencia, 'lider_id' => $liderId, 'geographic_unit_id' => $this->mun]);

        $this->actingAs($user)->putJson("/votantes/contactos/{$contacto}", ['valor' => 'cambiado'])->assertStatus($status);
        $this->actingAs($user)->deleteJson("/votantes/contactos/{$contacto}")->assertStatus($status);
        $this->actingAs($user)->putJson("/votantes/info-politica/{$info}", ['cargo_politico' => 'cambiado'])->assertStatus($status);
        $this->actingAs($user)->deleteJson("/votantes/info-politica/{$info}")->assertStatus($status);
        $this->actingAs($user)->putJson("/mapa-politico/nexos/{$nexo}", ['nombre' => 'cambiado'])->assertStatus($status);
        $this->actingAs($user)->deleteJson("/mapa-politico/nexos/{$nexo}")->assertStatus($status);
        $this->actingAs($user)->deleteJson("/mapa-politico/persona/{$liderId}/municipios-influencia/{$influencia}")->assertStatus($status);

        $this->assertDatabaseHas('lider_contactos', ['id' => $contacto, 'valor' => 'original']);
        $this->assertDatabaseHas('lider_info_politica', ['id' => $info, 'cargo_politico' => 'original']);
        $this->assertDatabaseHas('nexos_familiares', ['id' => $nexo, 'nombre' => 'original']);
        $this->assertDatabaseHas('lider_municipios_influencia', ['id' => $influencia]);
    }

    // --- 1. A campaign the user cannot see: 404 everywhere, nothing changes ---

    public function test_subcampaign_user_gets_404_reading_a_lider_of_the_parent_campaign(): void
    {
        $this->actingAs($this->userX)->getJson("/votantes/{$this->liderA}")->assertNotFound();
        $this->actingAs($this->userX)->getJson("/mapa-politico/persona/{$this->liderA}")->assertNotFound();
        $this->actingAs($this->userX)->getJson("/votantes/{$this->liderA}/referidos")->assertNotFound();
        $this->actingAs($this->userX)->getJson("/mapa-politico/persona/{$this->liderA}/posibles-duplicados")->assertNotFound();
    }

    public function test_subcampaign_user_gets_404_writing_a_lider_of_the_parent_campaign(): void
    {
        $this->assertWritesRejected($this->userX, $this->liderA, $this->liderB, 404);
        $this->assertChildWritesRejected($this->userX, $this->liderA, 404);
    }

    public function test_a_missing_lider_and_an_invisible_one_answer_the_same(): void
    {
        $missing = (string) Str::uuid();

        $this->actingAs($this->userX)->getJson("/votantes/{$missing}/referidos")->assertNotFound();
        $this->actingAs($this->userX)->getJson("/mapa-politico/persona/{$missing}/posibles-duplicados")->assertNotFound();
        $this->actingAs($this->userX)->getJson("/votantes/{$missing}")->assertNotFound();
    }

    public function test_removing_a_referido_across_campaigns_is_404(): void
    {
        DB::table('lideres')->where('id', $this->liderB)->update(['referente_documento' => '111']);

        $this->actingAs($this->userX)->deleteJson("/mapa-politico/persona/{$this->liderA}/referido/{$this->liderB}")->assertNotFound();

        $this->assertSame('111', DB::table('lideres')->where('id', $this->liderB)->value('referente_documento'));
    }

    // --- 2. A subcampaign is visible to the parent's users, but read-only ---

    public function test_parent_campaign_user_can_read_a_subcampaign_lider(): void
    {
        $this->actingAs($this->userCd)->getJson("/votantes/{$this->liderB}")
            ->assertOk()->assertJsonPath('data.id', $this->liderB);
        $this->actingAs($this->userCd)->getJson("/mapa-politico/persona/{$this->liderB}")
            ->assertOk()->assertJsonPath('id', $this->liderB);
        $this->actingAs($this->userCd)->getJson("/votantes/{$this->liderB}/referidos")->assertOk();
    }

    public function test_parent_campaign_user_cannot_write_a_subcampaign_lider(): void
    {
        $other = $this->lider($this->campX, 'Beto Duplicado X', null);

        $this->assertWritesRejected($this->userCd, $this->liderB, $other, 403);
        $this->assertChildWritesRejected($this->userCd, $this->liderB, 403);
    }

    public function test_parent_campaign_user_gets_404_on_an_independent_campaign(): void
    {
        $this->actingAs($this->userCd)->getJson("/votantes/{$this->liderC}")->assertNotFound();
        $this->actingAs($this->userCd)->getJson("/mapa-politico/persona/{$this->liderC}")->assertNotFound();
        $this->assertWritesRejected($this->userCd, $this->liderC, $this->liderA, 404);
        $this->assertChildWritesRejected($this->userCd, $this->liderC, 404);
    }

    public function test_own_campaign_writes_keep_working(): void
    {
        $this->actingAs($this->userX)->putJson("/votantes/{$this->liderB}", ['telefono' => '3115550000'])->assertOk();
        $this->actingAs($this->userX)->postJson("/votantes/{$this->liderB}/contactos", ['tipo' => 'celular', 'valor' => '1'])->assertCreated();
        $this->actingAs($this->userX)->postJson("/mapa-politico/persona/{$this->liderB}/nexos", ['nombre' => 'Hermana de Beto'])->assertOk();
        $this->actingAs($this->userCd)->putJson("/votantes/{$this->liderA}", ['telefono' => '3001112233'])->assertOk();

        $this->assertDatabaseHas('lideres', ['id' => $this->liderB, 'telefono' => '3115550000']);
        $this->assertDatabaseHas('lideres', ['id' => $this->liderA, 'telefono' => '3001112233']);
        $this->assertDatabaseHas('nexos_familiares', ['person_id' => $this->liderB, 'nombre' => 'Hermana de Beto', 'campaign_id' => $this->campX]);
    }

    // --- 3. Listings and counters ---

    public function test_votantes_listing_and_counters_follow_campaign_visibility(): void
    {
        $this->actingAs($this->userX)->get('/votantes')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Votantes')
                ->has('votantes.data', 1)
                ->where('votantes.data.0.id', $this->liderB)
                ->where('counters.total', 1));

        $this->actingAs($this->userCd)->get('/votantes')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('votantes.data', 2)
                ->where('votantes.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all()
                    === collect([$this->liderA, $this->liderB])->sort()->values()->all())
                ->where('counters.total', 2));

        $this->actingAs($this->userY)->get('/votantes')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('votantes.data', 1)->where('votantes.data.0.id', $this->liderC));
    }

    public function test_referidos_never_cross_campaigns(): void
    {
        // Same referente cédula in two campaigns: each lider only counts its own campaign's referidos.
        $this->lider($this->campX, 'Referida en X', '444', ['referente_documento' => '111']);
        $this->lider(Campaign::DEFAULT_ID, 'Referida en CD', '555', ['referente_documento' => '111']);
        $clonA = $this->lider($this->campX, 'Ana en X', '111');

        $this->actingAs($this->userCd)->getJson("/votantes/{$this->liderA}/referidos")
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.nombre', 'Referida en CD');
        $this->actingAs($this->userX)->getJson("/votantes/{$clonA}/referidos")
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.nombre', 'Referida en X');

        $this->actingAs($this->userCd)->get('/votantes')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('counters.con_referidos', 2)
                ->where('votantes.data', fn ($rows) => collect($rows)->firstWhere('id', $this->liderA)['referidos_count'] === 1
                    && collect($rows)->firstWhere('id', $clonA)['referidos_count'] === 1));
    }

    public function test_mapa_politico_lideres_follow_campaign_visibility(): void
    {
        $names = fn (User $user) => $this->actingAs($user)->get('/mapa-politico?tipo=lideres')->assertOk()
            ->viewData('page')['props']['data'];

        $x = collect($names($this->userX))->pluck('nombre')->all();
        $cd = collect($names($this->userCd))->pluck('nombre')->sort()->values()->all();

        $this->assertSame(['Beto Campaña X'], $x);
        $this->assertSame(['Ana Campaña CD', 'Beto Campaña X'], $cd);

        $this->actingAs($this->userX)->get('/mapa-politico?tipo=lideres')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('sectionCounts', ['Líderes' => 1])
                ->where('barrios', ['Barrio Beto Campaña X']));
    }

    public function test_lideres_page_follows_campaign_visibility(): void
    {
        $this->actingAs($this->userX)->get("/lideres/{$this->mun}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Lideres')
                ->has('lideres', 1)
                ->where('lideres.0.id', $this->liderB)
                ->where('totalLideres', 1)
                ->where('statsByCargo.0.total', 1));

        $this->actingAs($this->userCd)->get("/lideres/{$this->mun}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('lideres', 2)
                ->where('totalLideres', 2));
    }

    // --- 3b. Campaign labels and filter for the frontend (step 4) ---

    public function test_visible_campaigns_are_shared_only_when_there_is_more_than_one(): void
    {
        $this->actingAs($this->userCd)->get('/votantes')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('visibleCampaigns', [
                    ['id' => Campaign::DEFAULT_ID, 'name' => 'Centro Democrático'],
                    ['id' => $this->campX, 'name' => 'Alejandro'],
                ])
                ->where('auth.user.campaign.id', Campaign::DEFAULT_ID)
                ->where('auth.user.campaign.is_subcampaign', false));

        $this->actingAs($this->userX)->get('/votantes')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('visibleCampaigns', [])
                ->where('auth.user.campaign.name', 'Alejandro')
                ->where('auth.user.campaign.is_subcampaign', true)
                ->where('auth.user.canEditElectoral', false));

        $this->actingAs($this->userY)->get('/votantes')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('visibleCampaigns', []));
    }

    public function test_listings_and_the_ficha_carry_the_campaign_of_each_lider(): void
    {
        $rows = $this->actingAs($this->userCd)->get('/mapa-politico?tipo=lideres')->assertOk()
            ->viewData('page')['props']['data'];
        $this->assertEqualsCanonicalizing(
            [$this->liderA => Campaign::DEFAULT_ID, $this->liderB => $this->campX],
            collect($rows)->mapWithKeys(fn ($r) => [$r['id'] => $r['campaign_id']])->all(),
        );

        $this->actingAs($this->userCd)->get('/votantes')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('votantes.data', fn ($data) => collect($data)->firstWhere('id', $this->liderB)['campaign_id'] === $this->campX));

        $this->actingAs($this->userCd)->getJson("/mapa-politico/persona/{$this->liderB}")
            ->assertOk()->assertJsonPath('campaign_id', $this->campX);
        $this->actingAs($this->userCd)->getJson("/votantes/{$this->liderB}")
            ->assertOk()->assertJsonPath('data.campaign_id', $this->campX);
    }

    public function test_mapa_politico_campaign_filter_works_inside_the_visible_campaigns(): void
    {
        $this->candidateNamedLikeC();
        $names = fn (User $user, string $query) => collect($this->actingAs($user)->get("/mapa-politico?tipo=alcaldia,lideres&municipio={$this->mun}{$query}")
            ->assertOk()->viewData('page')['props']['data'])->pluck('nombre')->sort()->values()->all();

        // Without the filter the shared electoral candidate is listed with the visible lideres.
        $this->assertSame(['Ana Campaña CD', 'Beto Campaña X', 'Ceci Campaña Y'], $names($this->userCd, ''));

        // A visible campaign: only its lideres, no shared electoral rows.
        $this->assertSame(['Beto Campaña X'], $names($this->userCd, "&campana={$this->campX}"));
        $this->assertSame(['Ana Campaña CD'], $names($this->userCd, '&campana='.Campaign::DEFAULT_ID));
        $this->actingAs($this->userCd)->get("/mapa-politico?tipo=lideres&campana={$this->campX}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.campana', $this->campX));

        // A campaign the user cannot see (or garbage) is ignored, never a way in.
        $this->assertSame(['Ana Campaña CD', 'Beto Campaña X', 'Ceci Campaña Y'], $names($this->userCd, "&campana={$this->campY}"));
        $this->assertSame(['Ana Campaña CD', 'Beto Campaña X', 'Ceci Campaña Y'], $names($this->userCd, '&campana=no-es-uuid'));
        $this->assertSame(['Beto Campaña X', 'Ceci Campaña Y'], $names($this->userX, '&campana='.Campaign::DEFAULT_ID));
        $this->actingAs($this->userCd)->get("/mapa-politico?tipo=lideres&campana={$this->campY}")
            ->assertInertia(fn (AssertableInertia $page) => $page->missing('filters.campana'));
    }

    public function test_votantes_campaign_filter_works_inside_the_visible_campaigns(): void
    {
        $this->actingAs($this->userCd)->get("/votantes?campana={$this->campX}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('votantes.data', 1)
                ->where('votantes.data.0.id', $this->liderB)
                ->where('counters.total', 1)
                ->where('filters.campana', $this->campX));

        $this->actingAs($this->userCd)->get("/votantes?campana={$this->campY}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('votantes.data', 2)
                ->where('counters.total', 2)
                ->missing('filters.campana'));

        $this->actingAs($this->userX)->get('/votantes?campana='.Campaign::DEFAULT_ID)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('votantes.data', 1)
                ->where('votantes.data.0.id', $this->liderB));
    }

    public function test_a_leftover_campaign_filter_is_ignored_with_a_single_visible_campaign(): void
    {
        // X sees only its own campaign: there is no filter on screen, so ?campana= must not narrow anything.
        $this->actingAs($this->userX)->get("/votantes?campana={$this->campX}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('votantes.data', 1)
                ->missing('filters.campana'));
        $this->actingAs($this->userX)->get("/mapa-politico?tipo=lideres&campana={$this->campX}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('data', 1)
                ->missing('filters.campana'));
        $this->actingAs($this->userY)->get("/votantes?campana={$this->campY}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->missing('filters.campana'));
    }

    public function test_namesake_lideres_of_different_campaigns_stay_in_separate_rows(): void
    {
        $twin = $this->lider($this->campX, 'Ana Campaña CD', '777');

        $rows = collect($this->actingAs($this->userCd)->get('/mapa-politico?tipo=lideres')->assertOk()
            ->viewData('page')['props']['data'])->where('nombre', 'Ana Campaña CD');

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            [$this->liderA => Campaign::DEFAULT_ID, $twin => $this->campX],
            $rows->mapWithKeys(fn ($r) => [$r['id'] => $r['campaign_id']])->all(),
        );
    }

    public function test_a_shared_electoral_row_still_merges_with_the_own_campaign_lider(): void
    {
        $this->candidateNamedLike('Ana Campaña CD');
        $this->lider($this->campX, 'Ana Campaña CD', '777');

        $rows = collect($this->actingAs($this->userCd)->get("/mapa-politico?tipo=alcaldia,lideres&municipio={$this->mun}")->assertOk()
            ->viewData('page')['props']['data'])->where('nombre', 'Ana Campaña CD')->values();

        $this->assertCount(2, $rows);
        $merged = $rows->firstWhere('campaign_id', Campaign::DEFAULT_ID);
        $this->assertStringContainsString('Alcaldía', $merged['tipo_registro']);
        $this->assertStringContainsString('Líderes', $merged['tipo_registro']);
        $this->assertSame('Líderes', $rows->firstWhere('campaign_id', $this->campX)['tipo_registro']);
    }

    public function test_exports_apply_the_campaign_filter_inside_the_visible_campaigns(): void
    {
        $this->candidateNamedLikeC();
        $rows = function (string $query) {
            Excel::fake();
            $this->actingAs($this->userCd)->get("/exportar/excel?source=mapa-politico&tipo=alcaldia,lideres{$query}")->assertOk();
            $exported = null;
            Excel::assertDownloaded('Exportacion.xlsx', function (GenericExport $export) use (&$exported) {
                $exported = $export->array();

                return true;
            });

            return collect($exported)->map(fn ($r) => implode('|', array_map('strval', $r)))->implode("\n");
        };

        $x = $rows("&campana={$this->campX}");
        $this->assertStringContainsString('Beto Campaña X', $x);
        $this->assertStringNotContainsString('Ana Campaña CD', $x);
        $this->assertStringNotContainsString('Ceci Campaña Y', $x);

        // Not visible: ignored, the export is what the unfiltered listing shows.
        $y = $rows("&campana={$this->campY}");
        $this->assertStringContainsString('Ana Campaña CD', $y);
        $this->assertStringContainsString('Beto Campaña X', $y);
        $this->assertStringContainsString('Ceci Campaña Y', $y);

        $captured = null;
        $pdf = Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $pdf->shouldReceive('setPaper')->andReturnSelf();
        $pdf->shouldReceive('setOption')->andReturnSelf();
        $pdf->shouldReceive('download')->andReturn(response('pdf'));
        Pdf::shouldReceive('loadView')->andReturnUsing(function ($view, $data) use (&$captured, $pdf) {
            $captured = $data['data'];

            return $pdf;
        });
        $this->actingAs($this->userCd)->get('/exportar/pdf?source=mapa-politico&tipo=alcaldia,lideres&campana='.Campaign::DEFAULT_ID)->assertOk();
        $this->assertSame(['Ana Campaña CD'], array_column($captured, 'nombre'));
    }

    // --- 4. Exports and offline packages ---

    private function candidateNamedLikeC(): void
    {
        $this->candidateNamedLike('Ceci Campaña Y');
    }

    private function candidateNamedLike(string $name): void
    {
        $office = DB::table('offices')->where('name', 'Alcaldía')->value('id');
        if (! $office) {
            $office = (string) Str::uuid();
            DB::table('offices')->insert(['id' => $office, 'name' => 'Alcaldía']);
        }
        $contest = (string) Str::uuid();
        DB::table('contests')->insert(['id' => $contest, 'geographic_unit_id' => $this->mun, 'office_id' => $office]);
        $person = (string) Str::uuid();
        DB::table('persons')->insert(['id' => $person, 'full_name' => $name]);
        DB::table('candidacies')->insert(['id' => (string) Str::uuid(), 'person_id' => $person, 'contest_id' => $contest, 'outcome' => 'elected']);
    }

    public function test_excel_export_follows_campaign_visibility_including_observaciones(): void
    {
        $this->candidateNamedLikeC();
        $rows = function (User $user) {
            Excel::fake();
            $this->actingAs($user)->get('/exportar/excel?source=mapa-politico&tipo=alcaldia,lideres')->assertOk();
            $exported = null;
            Excel::assertDownloaded('Exportacion.xlsx', function (GenericExport $export) use (&$exported) {
                $exported = $export->array();

                return true;
            });

            return collect($exported)->map(fn ($r) => implode('|', array_map('strval', $r)))->implode("\n");
        };

        $x = $rows($this->userX);
        $this->assertStringContainsString('Beto Campaña X', $x);
        $this->assertStringNotContainsString('Ana Campaña CD', $x);
        $this->assertStringNotContainsString('Nota de Ceci', $x);

        $cd = $rows($this->userCd);
        $this->assertStringContainsString('Ana Campaña CD', $cd);
        $this->assertStringContainsString('Beto Campaña X', $cd);
        $this->assertStringNotContainsString('Nota de Ceci', $cd);

        // The electoral candidate is public: Y sees it with the note of its own lider record.
        $this->assertStringContainsString('Nota de Ceci', $rows($this->userY));
    }

    public function test_pdf_export_follows_campaign_visibility(): void
    {
        $captured = null;
        $pdf = Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $pdf->shouldReceive('setPaper')->andReturnSelf();
        $pdf->shouldReceive('setOption')->andReturnSelf();
        $pdf->shouldReceive('download')->andReturn(response('pdf'));
        Pdf::shouldReceive('loadView')->andReturnUsing(function ($view, $data) use (&$captured, $pdf) {
            $captured = $data['data'];

            return $pdf;
        });

        $this->actingAs($this->userX)->get('/exportar/pdf?source=mapa-politico&tipo=lideres')->assertOk();
        $this->assertSame(['Beto Campaña X'], array_column($captured, 'nombre'));

        $this->actingAs($this->userCd)->get('/exportar/pdf?source=mapa-politico&tipo=lideres')->assertOk();
        $this->assertSame(['Ana Campaña CD', 'Beto Campaña X'], array_column($captured, 'nombre'));
    }

    // --- 5. Creating: own campaign, cédula unique per campaign, no leaks ---

    public function test_creating_with_a_cedula_of_another_campaign_succeeds_in_the_own_campaign(): void
    {
        $response = $this->actingAs($this->userX)
            ->postJson('/votantes', ['nombre' => 'Ana en X', 'municipio_id' => $this->mun, 'cedula' => '111'])
            ->assertCreated();
        $this->assertStringNotContainsString('Ana Campaña CD', $response->json('message'));
        $this->assertDatabaseHas('lideres', ['id' => $response->json('id'), 'campaign_id' => $this->campX, 'cedula' => '111']);

        $this->actingAs($this->userY)
            ->postJson('/mapa-politico/crear-lider', ['nombre' => 'Ana en Y', 'municipio_id' => $this->mun, 'cedula' => '111'])
            ->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('lideres', ['nombre' => 'Ana en Y', 'campaign_id' => $this->campY]);
    }

    public function test_a_duplicate_cedula_inside_the_own_campaign_is_rejected(): void
    {
        $this->actingAs($this->userX)
            ->postJson('/votantes', ['nombre' => 'Beto otra vez', 'municipio_id' => $this->mun, 'cedula' => '222'])
            ->assertStatus(422);
        $this->actingAs($this->userX)
            ->postJson('/mapa-politico/crear-lider', ['nombre' => 'Beto otra vez', 'municipio_id' => $this->mun, 'cedula' => '222'])
            ->assertStatus(422);
        $this->assertDatabaseMissing('lideres', ['nombre' => 'Beto otra vez']);
    }

    public function test_the_parent_user_never_hits_or_reveals_a_subcampaign_cedula(): void
    {
        // The cédula exists only in X: the CD user creates it in CD without a duplicate warning.
        $this->actingAs($this->userCd)
            ->postJson('/votantes', ['nombre' => 'Beto en CD', 'municipio_id' => $this->mun, 'cedula' => '222'])
            ->assertCreated();

        $update = $this->actingAs($this->userX)->putJson("/votantes/{$this->liderB}", ['cedula' => '111'])->assertOk();
        $this->assertStringNotContainsString('Ana', (string) $update->json('message'));
        $this->assertSame('111', DB::table('lideres')->where('id', $this->liderB)->value('cedula'));
    }

    public function test_campaign_id_in_the_request_is_ignored(): void
    {
        $id = $this->actingAs($this->userX)
            ->postJson('/votantes', ['nombre' => 'Colado', 'municipio_id' => $this->mun, 'campaign_id' => Campaign::DEFAULT_ID])
            ->assertCreated()->json('id');
        $this->actingAs($this->userX)->putJson("/votantes/{$id}", ['campaign_id' => $this->campY])->assertOk();

        $this->assertSame($this->campX, DB::table('lideres')->where('id', $id)->value('campaign_id'));
    }

    // --- 7. Duplicates and merges stay inside one campaign ---

    public function test_possible_duplicates_and_merge_never_cross_campaigns(): void
    {
        $clonA = $this->lider($this->campX, 'Ana Campaña CD', '111');

        $this->actingAs($this->userCd)->getJson("/mapa-politico/persona/{$this->liderA}/posibles-duplicados")
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->userX)->getJson("/mapa-politico/persona/{$clonA}/posibles-duplicados")
            ->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($this->userCd)
            ->postJson("/mapa-politico/persona/{$this->liderA}/unificar", ['duplicado_id' => $clonA])
            ->assertStatus(422);
        $this->actingAs($this->userX)
            ->postJson("/mapa-politico/persona/{$clonA}/unificar", ['duplicado_id' => $this->liderA])
            ->assertNotFound();

        $this->assertDatabaseHas('lideres', ['id' => $this->liderA]);
        $this->assertDatabaseHas('lideres', ['id' => $clonA]);
    }

    public function test_possible_duplicates_inside_the_campaign_are_still_found(): void
    {
        DB::table('lideres')->where('id', $this->liderB)->update(['telefono' => '3001234567']);
        $dup = $this->lider($this->campX, 'Beto Campaña X', null, ['telefono' => '3001234567']);
        $this->lider(Campaign::DEFAULT_ID, 'Beto Campaña X', null, ['telefono' => '3001234567']);

        $this->actingAs($this->userX)->getJson("/mapa-politico/persona/{$this->liderB}/posibles-duplicados")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $dup);
    }

    public function test_possible_duplicates_of_a_read_only_lider_are_not_offered(): void
    {
        DB::table('lideres')->where('id', $this->liderB)->update(['telefono' => '3001234567']);
        $this->lider($this->campX, 'Beto Campaña X', null, ['telefono' => '3001234567']);

        $this->actingAs($this->userCd)->getJson("/mapa-politico/persona/{$this->liderB}/posibles-duplicados")
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->userX)->getJson("/mapa-politico/persona/{$this->liderB}/posibles-duplicados")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    // --- Admin writes on data shared by every campaign belong to the platform ---

    public function test_admin_writes_on_shared_electoral_data_are_platform_only(): void
    {
        Schema::table('persons', function (Blueprint $t) {
            foreach (['first_name', 'last_name', 'normalized_name', 'gender', 'birth_date', 'identity_status', 'data_classification'] as $col) {
                $t->string($col)->nullable();
            }
            $t->softDeletes();
        });
        $person = (string) Str::uuid();
        DB::table('persons')->insert(['id' => $person, 'full_name' => 'Persona Compartida', 'first_name' => 'Persona', 'last_name' => 'Compartida']);
        $party = (string) Str::uuid();
        DB::table('political_organizations')->insert(['id' => $party, 'canonical_name' => 'Partido Compartido']);
        DB::table('lideres')->where('id', $this->liderC)->update(['partido' => 'Partido Compartido']);
        foreach ([$this->userCd, $this->userX] as $user) {
            $user->update(['permissions' => ['view.admin']]);
        }
        $rename = ['first_name' => 'Renombrada', 'last_name' => 'Por X'];

        $this->actingAs($this->userX)->put("/admin/personas/{$person}", $rename)->assertForbidden();
        $this->actingAs($this->userX)->delete("/admin/partidos/{$party}/force")->assertForbidden();
        $this->actingAs($this->userX)->post('/admin/cargos/graduar', [])->assertForbidden();
        $this->actingAs($this->userX)->post('/admin/catalogos/partidos', ['canonical_name' => 'Nuevo'])->assertForbidden();
        $this->assertSame('Persona Compartida', DB::table('persons')->where('id', $person)->value('full_name'));
        $this->assertDatabaseHas('political_organizations', ['id' => $party]);
        $this->assertSame('Partido Compartido', DB::table('lideres')->where('id', $this->liderC)->value('partido'));

        // Reads stay open.
        $this->actingAs($this->userX)->get('/admin/cargos')->assertOk();

        $this->actingAs($this->userCd)->put("/admin/personas/{$person}", ['first_name' => 'Renombrada', 'last_name' => 'Por CD'])->assertRedirect();
        $this->actingAs($this->userCd)->delete("/admin/partidos/{$party}/force")->assertRedirect();
        $this->assertSame('Renombrada Por CD', DB::table('persons')->where('id', $person)->value('full_name'));
        $this->assertDatabaseMissing('political_organizations', ['id' => $party]);
    }

    // --- Nexos of a shared electoral person belong to the campaign that wrote them ---

    public function test_nexos_of_an_electoral_person_are_private_to_each_campaign(): void
    {
        $person = (string) Str::uuid();
        DB::table('persons')->insert(['id' => $person, 'full_name' => 'Candidato Compartido']);
        $nexoCd = $this->nexo(Campaign::DEFAULT_ID, $person, 'Nexo del CD');

        $this->actingAs($this->userY)->postJson("/mapa-politico/persona/{$person}/nexos", ['nombre' => 'Nexo de Y'])->assertOk();
        $this->assertDatabaseHas('nexos_familiares', ['person_id' => $person, 'nombre' => 'Nexo de Y', 'campaign_id' => $this->campY]);

        $nexoX = $this->nexo($this->campX, $person, 'Nexo de X');

        $this->actingAs($this->userY)->putJson("/mapa-politico/nexos/{$nexoCd}", ['nombre' => 'Cambiado'])->assertNotFound();
        $this->actingAs($this->userX)->deleteJson("/mapa-politico/nexos/{$nexoCd}")->assertNotFound();
        $this->actingAs($this->userCd)->deleteJson("/mapa-politico/nexos/{$nexoX}")->assertForbidden();
        $this->assertDatabaseHas('nexos_familiares', ['id' => $nexoCd, 'nombre' => 'Nexo del CD']);
        $this->assertDatabaseHas('nexos_familiares', ['id' => $nexoX]);
    }
}
