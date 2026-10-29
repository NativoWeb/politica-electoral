<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

// The real migrations need PostgreSQL/PostGIS, so the tables the territory checks touch are built here on SQLite.
class TerritoryScopeTest extends TestCase
{
    private string $provincia;

    private string $munA;

    private string $munB;

    private string $liderA;

    private string $liderB;

    private User $campo;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->createSchema();
        $this->seedTerritory();
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

        Schema::create('electoral_results', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('candidacy_id')->nullable();
            $t->uuid('geographic_unit_id')->nullable();
            $t->string('metric_type');
            $t->integer('value')->nullable();
        });
    }

    private function seedTerritory(): void
    {
        $this->provincia = $this->geo('province', 'Soto');
        $this->munA = $this->geo('municipality', 'Girón', $this->provincia);
        $this->munB = $this->geo('municipality', 'Lebrija', $this->provincia);

        $this->liderA = $this->lider($this->munA, 'Ana Territorio A', '111');
        $this->liderB = $this->lider($this->munB, 'Beto Territorio B', '222');

        $campoRole = Role::create(['code' => 'R09_CAMPO', 'name' => 'Operador', 'level' => 1]);
        $adminRole = Role::create(['code' => 'R01_SUPERADMIN', 'name' => 'Superadmin', 'level' => 10]);

        $this->campo = User::create([
            'name' => 'Campo A', 'email' => 'campo@test.local', 'password' => 'secret123',
            'role_id' => $campoRole->id, 'territory_scope' => [$this->munA],
        ]);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'secret123',
            'role_id' => $adminRole->id, 'territory_scope' => null,
        ]);
    }

    private function geo(string $type, string $name, ?string $parent = null): string
    {
        $id = (string) Str::uuid();
        DB::table('geographic_units')->insert([
            'id' => $id, 'type' => $type, 'canonical_name' => $name, 'parent_id' => $parent,
        ]);

        return $id;
    }

    private function lider(string $geoId, string $nombre, string $cedula): string
    {
        $id = (string) Str::uuid();
        DB::table('lideres')->insert([
            'id' => $id, 'geographic_unit_id' => $geoId, 'nombre' => $nombre, 'cedula' => $cedula,
            'municipio' => DB::table('geographic_units')->where('id', $geoId)->value('canonical_name'),
            'telefono' => '300'.$cedula, 'is_active' => true, 'nivel_confianza' => 'sin_llamar',
        ]);

        return $id;
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

    public function test_scoped_user_cannot_view_lider_outside_territory(): void
    {
        $this->actingAs($this->campo)->getJson("/votantes/{$this->liderB}")->assertForbidden();
        $this->actingAs($this->campo)->getJson("/votantes/{$this->liderB}/referidos")->assertForbidden();
        $this->actingAs($this->campo)->getJson("/mapa-politico/persona/{$this->liderB}")->assertForbidden();
        $this->actingAs($this->campo)->getJson("/mapa-politico/persona/{$this->liderB}/posibles-duplicados")->assertForbidden();
        $this->actingAs($this->campo)->get("/lideres/{$this->munB}")->assertForbidden();
    }

    public function test_scoped_user_cannot_update_lider_outside_territory(): void
    {
        $this->actingAs($this->campo)
            ->putJson("/votantes/{$this->liderB}", ['nombre' => 'Hackeado', 'telefono' => '999'])
            ->assertForbidden();
        $this->actingAs($this->campo)
            ->putJson("/mapa-politico/persona/{$this->liderB}", ['telefono' => '999'])
            ->assertForbidden();

        $this->assertDatabaseHas('lideres', ['id' => $this->liderB, 'nombre' => 'Beto Territorio B', 'telefono' => '300222']);
    }

    public function test_scoped_user_cannot_deactivate_lider_outside_territory(): void
    {
        $this->actingAs($this->campo)->deleteJson("/votantes/{$this->liderB}")->assertForbidden();
        $this->actingAs($this->campo)->deleteJson("/mapa-politico/persona/{$this->liderB}")->assertForbidden();
        $this->actingAs($this->campo)->postJson("/mapa-politico/persona/{$this->liderB}/toggle")->assertForbidden();

        $this->assertDatabaseHas('lideres', ['id' => $this->liderB, 'is_active' => true]);
    }

    public function test_scoped_user_cannot_merge_a_lider_from_outside_territory(): void
    {
        $this->actingAs($this->campo)
            ->postJson("/mapa-politico/persona/{$this->liderA}/unificar", ['duplicado_id' => $this->liderB])
            ->assertForbidden();
        $this->actingAs($this->campo)
            ->postJson("/mapa-politico/persona/{$this->liderB}/unificar", ['duplicado_id' => $this->liderA])
            ->assertForbidden();

        $this->assertDatabaseHas('lideres', ['id' => $this->liderA]);
        $this->assertDatabaseHas('lideres', ['id' => $this->liderB]);
    }

    public function test_scoped_user_cannot_create_votante_in_other_municipio(): void
    {
        $this->actingAs($this->campo)
            ->postJson('/votantes', ['nombre' => 'Intruso', 'municipio_id' => $this->munB])
            ->assertForbidden();
        $this->actingAs($this->campo)
            ->postJson('/mapa-politico/crear-lider', ['nombre' => 'Intruso 2', 'municipio_id' => $this->munB])
            ->assertForbidden();

        $this->assertDatabaseMissing('lideres', ['nombre' => 'Intruso']);
        $this->assertDatabaseMissing('lideres', ['nombre' => 'Intruso 2']);
    }

    public function test_scoped_user_can_view_update_and_create_inside_territory(): void
    {
        $this->actingAs($this->campo)->getJson("/votantes/{$this->liderA}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->liderA);

        $this->actingAs($this->campo)
            ->putJson("/votantes/{$this->liderA}", ['telefono' => '3115550000'])
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertDatabaseHas('lideres', ['id' => $this->liderA, 'telefono' => '3115550000']);

        $this->actingAs($this->campo)
            ->postJson('/votantes', ['nombre' => 'Nuevo en A', 'municipio_id' => $this->munA])
            ->assertCreated();
        $this->assertDatabaseHas('lideres', ['nombre' => 'Nuevo en A', 'geographic_unit_id' => $this->munA]);
    }

    public function test_admin_without_scope_accesses_every_territory(): void
    {
        $this->actingAs($this->admin)->getJson("/votantes/{$this->liderA}")->assertOk();
        $this->actingAs($this->admin)->getJson("/votantes/{$this->liderB}")->assertOk();

        $this->actingAs($this->admin)
            ->putJson("/votantes/{$this->liderB}", ['telefono' => '3001112233'])
            ->assertOk();
        $this->assertDatabaseHas('lideres', ['id' => $this->liderB, 'telefono' => '3001112233']);
    }

    public function test_superadmin_with_a_scope_is_not_restricted(): void
    {
        $this->admin->update(['territory_scope' => [$this->munA]]);

        $this->actingAs($this->admin)->getJson("/votantes/{$this->liderB}")->assertOk();
    }

    public function test_listing_and_counters_only_show_the_users_territory(): void
    {
        $this->actingAs($this->campo)->get('/votantes')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Votantes')
                ->has('votantes.data', 1)
                ->where('votantes.data.0.id', $this->liderA)
                ->where('counters.total', 1)
                ->has('municipios', 1)
                ->where('municipios.0.id', $this->munA));

        $this->actingAs($this->admin)->get('/votantes')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('votantes.data', 2)
                ->where('counters.total', 2));
    }

    public function test_contact_and_nexo_writes_on_lider_outside_territory_are_forbidden(): void
    {
        $contactoId = (string) Str::uuid();
        DB::table('lider_contactos')->insert(['id' => $contactoId, 'lider_id' => $this->liderB, 'tipo' => 'celular', 'valor' => '1']);
        $nexoId = (string) Str::uuid();
        DB::table('nexos_familiares')->insert(['id' => $nexoId, 'person_id' => $this->liderB, 'nombre' => 'Hermana B']);

        $this->actingAs($this->campo)->postJson("/votantes/{$this->liderB}/contactos", ['tipo' => 'celular', 'valor' => '2'])->assertForbidden();
        $this->actingAs($this->campo)->putJson("/votantes/contactos/{$contactoId}", ['valor' => '3'])->assertForbidden();
        $this->actingAs($this->campo)->deleteJson("/votantes/contactos/{$contactoId}")->assertForbidden();
        $this->actingAs($this->campo)->postJson("/votantes/{$this->liderB}/info-politica", ['cargo_politico' => 'X'])->assertForbidden();
        $this->actingAs($this->campo)->postJson("/mapa-politico/persona/{$this->liderB}/nexos", ['nombre' => 'Otro', 'parentesco' => 'Primo'])->assertForbidden();
        $this->actingAs($this->campo)->putJson("/mapa-politico/nexos/{$nexoId}", ['nombre' => 'Cambiado', 'parentesco' => 'Primo'])->assertForbidden();
        $this->actingAs($this->campo)->deleteJson("/mapa-politico/nexos/{$nexoId}")->assertForbidden();
        $this->actingAs($this->campo)->postJson("/mapa-politico/persona/{$this->liderA}/add-referido", ['lider_id' => $this->liderB])->assertForbidden();

        $this->assertDatabaseHas('lider_contactos', ['id' => $contactoId, 'valor' => '1']);
        $this->assertDatabaseHas('nexos_familiares', ['id' => $nexoId, 'nombre' => 'Hermana B']);
        $this->assertSame(1, DB::table('lider_contactos')->count());
        $this->assertNull(DB::table('lideres')->where('id', $this->liderB)->value('referente_documento'));
    }

    public function test_electoral_person_outside_territory_is_forbidden(): void
    {
        $personB = $this->electoralPersonIn($this->munB);

        $this->actingAs($this->campo)->getJson("/mapa-politico/persona/{$personB}")->assertForbidden();
        $this->actingAs($this->campo)->get("/persona/{$personB}")->assertForbidden();
        $this->actingAs($this->campo)->get("/reporte/persona/{$personB}")->assertForbidden();
    }

    public function test_exports_reports_and_offline_outside_territory_are_forbidden(): void
    {
        $this->actingAs($this->campo)->get("/exportar/excel?source=mapa-politico&municipio={$this->munB}")->assertForbidden();
        $this->actingAs($this->campo)->get("/exportar/pdf?source=mapa-politico&municipio={$this->munB}")->assertForbidden();
        $this->actingAs($this->campo)->get("/reporte/municipio/{$this->munB}")->assertForbidden();
        $this->actingAs($this->campo)->getJson("/api/offline/download/{$this->munB}")->assertForbidden();
    }

    public function test_duplicate_cedula_message_does_not_reveal_names_from_other_territories(): void
    {
        $create = $this->actingAs($this->campo)
            ->postJson('/votantes', ['nombre' => 'Duplicado', 'municipio_id' => $this->munA, 'cedula' => '222'])
            ->assertStatus(422);
        $this->assertStringNotContainsString('Beto', $create->json('message'));

        $update = $this->actingAs($this->campo)
            ->putJson("/votantes/{$this->liderA}", ['cedula' => '222'])
            ->assertStatus(422);
        $this->assertStringNotContainsString('Beto', $update->json('message'));
        $this->assertDatabaseHas('lideres', ['id' => $this->liderA, 'cedula' => '111']);

        $admin = $this->actingAs($this->admin)
            ->postJson('/votantes', ['nombre' => 'Duplicado', 'municipio_id' => $this->munA, 'cedula' => '222'])
            ->assertStatus(422);
        $this->assertStringContainsString('Beto Territorio B', $admin->json('message'));
    }

    public function test_scoped_user_with_wildcard_permission_cannot_escape_scope_through_user_admin(): void
    {
        $this->campo->update(['permissions' => ['*']]);
        $r01 = Role::where('code', 'R01_SUPERADMIN')->value('id');
        $r09 = $this->campo->role_id;
        $base = ['name' => 'Campo A', 'email' => 'campo@test.local', 'role_id' => $r09];

        $this->actingAs($this->campo)
            ->put("/admin/usuarios/{$this->campo->id}", $base + ['territory_scope' => []])
            ->assertForbidden();
        $this->actingAs($this->campo)
            ->put("/admin/usuarios/{$this->campo->id}", ['role_id' => $r01] + $base + ['territory_scope' => [$this->munA]])
            ->assertForbidden();
        $this->actingAs($this->campo)
            ->post('/admin/usuarios', ['name' => 'Libre', 'email' => 'libre@test.local', 'role_id' => $r09])
            ->assertForbidden();
        $this->actingAs($this->campo)->post("/admin/usuarios/{$this->admin->id}/toggle")->assertForbidden();

        $fresh = $this->campo->fresh();
        $this->assertSame([$this->munA], $fresh->territory_scope);
        $this->assertSame($r09, $fresh->role_id);
        $this->assertDatabaseMissing('users', ['email' => 'libre@test.local']);
        $this->assertTrue($this->admin->fresh()->is_active);
    }

    public function test_unrestricted_non_superadmin_cannot_grant_superadmin_or_wildcard(): void
    {
        $operador = User::create([
            'name' => 'Operador admin', 'email' => 'op@test.local', 'password' => 'secret123',
            'role_id' => $this->campo->role_id, 'permissions' => ['view.admin'],
        ]);
        $r01 = Role::where('code', 'R01_SUPERADMIN')->value('id');
        $base = ['name' => 'Operador admin', 'email' => 'op@test.local'];

        $this->actingAs($operador)->put("/admin/usuarios/{$operador->id}", $base + ['role_id' => $r01])->assertForbidden();
        $this->actingAs($operador)->put("/admin/usuarios/{$operador->id}", $base + ['role_id' => $this->campo->role_id, 'permissions' => ['*']])->assertForbidden();
        $this->actingAs($operador)->put("/admin/usuarios/{$this->admin->id}", ['name' => 'X', 'email' => 'admin@test.local', 'password' => 'hijacked1'])->assertForbidden();

        $this->assertSame($this->campo->role_id, $operador->fresh()->role_id);
        $this->assertSame(['view.admin'], $operador->fresh()->permissions);
        $this->assertSame('Admin', $this->admin->fresh()->name);
    }

    public function test_superadmin_can_change_a_users_scope_and_role(): void
    {
        $r01 = Role::where('code', 'R01_SUPERADMIN')->value('id');

        $this->actingAs($this->admin)
            ->put("/admin/usuarios/{$this->campo->id}", [
                'name' => 'Campo A', 'email' => 'campo@test.local', 'role_id' => $this->campo->role_id,
                'territory_scope' => [$this->munA, $this->munB],
            ])
            ->assertRedirect();
        $this->assertSame([$this->munA, $this->munB], $this->campo->fresh()->territory_scope);

        $this->actingAs($this->admin)
            ->put("/admin/usuarios/{$this->campo->id}", ['name' => 'Campo A', 'email' => 'campo@test.local', 'role_id' => $r01])
            ->assertRedirect();
        $this->assertSame($r01, $this->campo->fresh()->role_id);
    }

    public function test_lideres_page_totals_only_count_the_users_territory(): void
    {
        $this->actingAs($this->campo)->get("/lideres/{$this->munA}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Lideres')
                ->where('totalLideres', 1)
                ->where('totalMunicipios', 1));

        $this->actingAs($this->admin)->get("/lideres/{$this->munA}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('totalLideres', 2)
                ->where('totalMunicipios', 2));
    }

    public function test_referidos_count_ignores_referidos_outside_territory(): void
    {
        DB::table('lideres')->where('id', $this->liderB)->update(['referente_documento' => '111']);
        $this->lider($this->munA, 'Zoe Referida A', '333');
        DB::table('lideres')->where('cedula', '333')->update(['referente_documento' => '111']);

        $this->actingAs($this->campo)->get('/votantes')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('votantes.data.0.id', $this->liderA)
                ->where('votantes.data.0.referidos_count', 1));

        $this->actingAs($this->admin)->get('/votantes')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('votantes.data.0.id', $this->liderA)
                ->where('votantes.data.0.referidos_count', 2));
    }

    // A candidate who also has a lider record in their municipio (same name).
    private function candidateLinkedToLiderA(): string
    {
        if (! Schema::hasTable('candidacy_endorsements')) {
            Schema::create('candidacy_endorsements', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('candidacy_id');
                $t->uuid('organization_id');
                $t->boolean('is_primary')->default(true);
                $t->string('endorsement_type')->nullable();
                $t->timestamps();
            });
        }
        $personId = (string) Str::uuid();
        $contestId = (string) Str::uuid();
        $candidacyId = (string) Str::uuid();
        $orgId = (string) Str::uuid();
        DB::table('persons')->insert(['id' => $personId, 'full_name' => 'Ana Territorio A']);
        DB::table('contests')->insert(['id' => $contestId, 'geographic_unit_id' => $this->munA]);
        DB::table('candidacies')->insert(['id' => $candidacyId, 'person_id' => $personId, 'contest_id' => $contestId]);
        DB::table('electoral_results')->insert(['id' => (string) Str::uuid(), 'candidacy_id' => $candidacyId, 'geographic_unit_id' => $this->munA, 'metric_type' => 'votes', 'value' => 120]);
        DB::table('political_organizations')->insert([['id' => $orgId, 'canonical_name' => 'Partido 2023'], ['id' => (string) Str::uuid(), 'canonical_name' => 'Partido Actual']]);
        DB::table('candidacy_endorsements')->insert(['id' => (string) Str::uuid(), 'candidacy_id' => $candidacyId, 'organization_id' => $orgId, 'is_primary' => true]);

        return $personId;
    }

    public function test_a_candidate_with_a_lider_record_opens_as_that_lider_keeping_votes_and_party(): void
    {
        $personId = $this->candidateLinkedToLiderA();

        $this->actingAs($this->admin)->getJson("/mapa-politico/persona/{$personId}")
            ->assertOk()
            ->assertJsonPath('id', $this->liderA)
            ->assertJsonPath('source', 'lider')
            ->assertJsonPath('votos_candidatura', 120)
            ->assertJsonPath('partido_candidatura', 'Partido 2023')
            ->assertJsonPath('partido', null)
            ->assertJsonStructure(['info_politica', 'contactos']);
    }

    public function test_an_ambiguous_candidate_keeps_opening_as_the_electoral_person(): void
    {
        $personId = $this->candidateLinkedToLiderA();
        $this->lider($this->munA, 'Ana Territorio A', '333');

        // The persona-mode response itself uses ILIKE (PostgreSQL only), so the decision is checked directly.
        $this->actingAs($this->admin);
        $controller = app(\App\Http\Controllers\MapaPoliticoController::class);
        $linked = (new \ReflectionMethod($controller, 'linkedLiderId'))->invoke($controller, DB::table('persons')->find($personId));

        $this->assertNull($linked);
    }

    public function test_nexos_saved_under_the_candidate_stay_visible_from_the_lider(): void
    {
        $personId = $this->candidateLinkedToLiderA();
        DB::table('nexos_familiares')->insert(['id' => (string) Str::uuid(), 'person_id' => $personId, 'nombre' => 'Hija de Ana']);

        $nexos = $this->actingAs($this->admin)->getJson("/mapa-politico/persona/{$this->liderA}")->assertOk()->json('nexos');

        $this->assertContains('Hija de Ana', array_column($nexos, 'nombre'));
    }

    public function test_saving_the_party_from_the_lider_never_rewrites_the_past_candidacy_endorsement(): void
    {
        $this->candidateLinkedToLiderA();

        $this->actingAs($this->admin)->putJson("/mapa-politico/persona/{$this->liderA}", ['partido' => 'Partido Actual'])->assertOk();

        $this->assertSame('Partido Actual', DB::table('lideres')->where('id', $this->liderA)->value('partido'));
        $endorsed = DB::table('candidacy_endorsements as ce')->join('political_organizations as po', 'po.id', '=', 'ce.organization_id')->value('po.canonical_name');
        $this->assertSame('Partido 2023', $endorsed);
    }

    public function test_saving_the_panel_form_does_not_copy_the_candidacy_party_or_votes_into_the_lider(): void
    {
        $personId = $this->candidateLinkedToLiderA();
        $data = $this->actingAs($this->admin)->getJson("/mapa-politico/persona/{$personId}")->assertOk()->json();

        // The panel sends its whole form back, built from these same fields.
        $this->putJson("/mapa-politico/persona/{$data['id']}", [
            'telefono' => '3001112233', 'partido' => $data['partido'] ?? '', 'votos' => $data['votos'] ?? 0,
        ])->assertOk();

        $lider = DB::table('lideres')->where('id', $this->liderA)->first(['partido', 'votos', 'telefono']);
        $this->assertNull($lider->partido);
        $this->assertSame(0, (int) $lider->votos);
        $this->assertSame('3001112233', $lider->telefono);
    }
}

