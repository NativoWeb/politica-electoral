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
}
