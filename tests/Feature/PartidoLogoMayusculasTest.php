<?php

namespace Tests\Feature;

use App\Models\Lider;
use App\Models\PoliticalOrganization;
use App\Models\Role;
use App\Models\User;
use App\Support\PartyLogos;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\WithCampaignColumns;
use Tests\TestCase;

// Admin → Partidos: logo upload (real content, 1 MB, replace deletes the old file) and party names in capitals,
// plus data:partidos-mayusculas for the existing rows.
class PartidoLogoMayusculasTest extends TestCase
{
    use WithCampaignColumns;

    private User $admin;

    private User $sinPermiso;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');

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
        Schema::create('political_organizations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type')->nullable();
            $t->string('canonical_name');
            $t->string('acronym')->nullable();
            $t->string('color_hex')->nullable();
            $t->string('logo_path')->nullable();
            $t->string('status')->nullable();
            $t->uuid('merged_into_id')->nullable();
            $t->timestamps();
        });
        Schema::create('lideres', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('nombre');
            $t->string('municipio')->nullable();
            $t->string('partido')->nullable();
            $t->timestamps();
        });
        Schema::create('lider_info_politica', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('lider_id');
            $t->string('partido')->nullable();
            $t->timestamps();
        });
        Schema::create('candidacy_endorsements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('organization_id')->nullable();
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
        $this->addCampaignColumns();

        $role = Role::create(['code' => 'R02_ADMIN_FUNCIONAL', 'name' => 'Admin', 'level' => 5]);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'secret123',
            'role_id' => $role->id, 'permissions' => ['view.admin'],
        ]);
        $this->sinPermiso = User::create([
            'name' => 'Consulta', 'email' => 'consulta@test.local', 'password' => 'secret123',
            'role_id' => $role->id, 'permissions' => ['view.mapa'],
        ]);
    }

    private function partido(string $name, ?string $logo = null): string
    {
        $id = (string) Str::uuid();
        DB::table('political_organizations')->insert([
            'id' => $id, 'canonical_name' => $name, 'type' => 'party', 'status' => 'active', 'logo_path' => $logo,
        ]);

        return $id;
    }

    // --- Logo ---

    public function test_a_valid_logo_is_stored_with_a_generated_name_and_reaches_the_shared_props(): void
    {
        $id = $this->partido('CENTRO DEMOCRÁTICO');
        DB::table('political_organizations')->where('id', $id)->update(['acronym' => 'CD']);

        $this->actingAs($this->admin)->post("/admin/partidos/{$id}/logo", [
            'logo' => UploadedFile::fake()->image('mi logo.png', 120, 120),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $path = DB::table('political_organizations')->where('id', $id)->value('logo_path');
        $this->assertStringStartsWith('partidos/', $path);
        $this->assertStringNotContainsString('mi logo', $path);
        Storage::disk('public')->assertExists($path);

        $this->get('/admin/partidos')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('partyLogos.CENTRO DEMOCRÁTICO', "/storage/{$path}")
            ->where('partyLogos.CD', "/storage/{$path}")
            ->where('partidos.0.logo', "/storage/{$path}"));
    }

    public function test_a_fake_image_an_svg_or_a_heavy_file_is_refused(): void
    {
        $id = $this->partido('PARTIDO X');

        // A real file (not a testing double), so the type comes from its content: PHP named .png.
        $fakePng = tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($fakePng, '<?php echo "hola"; ?>');

        foreach ([
            new UploadedFile($fakePng, 'logo.png', 'image/png', null, true),
            UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->image('logo.png', 50, 50)->size(1500),
        ] as $file) {
            $this->actingAs($this->admin)->post("/admin/partidos/{$id}/logo", ['logo' => $file])
                ->assertSessionHasErrors('logo');
        }

        $this->assertNull(DB::table('political_organizations')->where('id', $id)->value('logo_path'));
        $this->assertSame([], Storage::disk('public')->allFiles());
        if (is_file($fakePng)) {
            unlink($fakePng);
        }
    }

    public function test_replacing_the_logo_deletes_the_old_file_and_removing_it_clears_it(): void
    {
        $id = $this->partido('PARTIDO X');
        $this->actingAs($this->admin)->post("/admin/partidos/{$id}/logo", ['logo' => UploadedFile::fake()->image('a.png')]);
        $first = DB::table('political_organizations')->where('id', $id)->value('logo_path');

        $this->post("/admin/partidos/{$id}/logo", ['logo' => UploadedFile::fake()->image('b.webp')])->assertSessionHasNoErrors();
        $second = DB::table('political_organizations')->where('id', $id)->value('logo_path');

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        $this->delete("/admin/partidos/{$id}/logo")->assertRedirect();
        $this->assertNull(DB::table('political_organizations')->where('id', $id)->value('logo_path'));
        Storage::disk('public')->assertMissing($second);
        $this->assertSame([], PartyLogos::map());
    }

    public function test_only_who_edits_parties_can_change_a_logo(): void
    {
        $id = $this->partido('PARTIDO X');

        $this->actingAs($this->sinPermiso)->post("/admin/partidos/{$id}/logo", ['logo' => UploadedFile::fake()->image('a.png')])
            ->assertForbidden();
        $this->actingAs($this->sinPermiso)->delete("/admin/partidos/{$id}/logo")->assertForbidden();
        $this->assertNull(DB::table('political_organizations')->where('id', $id)->value('logo_path'));

        $this->actingAs($this->admin)->post('/admin/partidos/'.Str::uuid().'/logo', ['logo' => UploadedFile::fake()->image('a.png')])
            ->assertNotFound();
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    // --- Names in capitals ---

    public function test_parties_are_created_and_edited_in_capitals_and_a_duplicate_is_refused(): void
    {
        $this->actingAs($this->admin)->post('/admin/partidos', ['name' => '  Partido Señorial Democrático '])
            ->assertRedirect()->assertSessionHasNoErrors();
        $id = DB::table('political_organizations')->value('id');
        $this->assertSame('PARTIDO SEÑORIAL DEMOCRÁTICO', DB::table('political_organizations')->value('canonical_name'));

        $this->put("/admin/partidos/{$id}", ['name' => 'Nueva Fuerza Ñ'])->assertSessionHasNoErrors();
        $this->assertSame('NUEVA FUERZA Ñ', DB::table('political_organizations')->where('id', $id)->value('canonical_name'));

        $this->post('/admin/partidos', ['name' => 'nueva fuerza ñ'])->assertSessionHasErrors('name');
        $this->assertSame(1, DB::table('political_organizations')->count());

        $this->assertSame('MOVIMIENTO ÁGIL', PoliticalOrganization::create(['canonical_name' => 'Movimiento Ágil'])->canonical_name);
    }

    public function test_a_lider_party_is_stored_in_capitals(): void
    {
        $lider = Lider::withoutGlobalScopes()->create(['nombre' => 'Ana', 'municipio' => 'Bucaramanga', 'partido' => 'Centro Democrático']);

        $this->assertSame('CENTRO DEMOCRÁTICO', DB::table('lideres')->where('id', $lider->id)->value('partido'));
    }

    // --- data:partidos-mayusculas ---

    public function test_dry_run_writes_nothing_and_the_command_is_idempotent(): void
    {
        $cd = $this->partido('Centro Democrático');
        $this->partido('LIBERAL');
        $lider = (string) Str::uuid();
        DB::table('lideres')->insert(['id' => $lider, 'nombre' => 'Ana', 'partido' => 'Centro Democrático']);
        DB::table('lideres')->insert(['id' => (string) Str::uuid(), 'nombre' => 'Luis', 'partido' => 'Liberal']);
        DB::table('lider_info_politica')->insert(['id' => (string) Str::uuid(), 'lider_id' => $lider, 'partido' => 'Pacto Histórico']);

        $this->artisan('data:partidos-mayusculas', ['--dry-run' => true])
            ->expectsOutputToContain('Organizaciones a renombrar: 1 de 2')
            ->expectsOutputToContain('lideres.partido: 2 filas')
            ->assertSuccessful();
        $this->assertSame('Centro Democrático', DB::table('political_organizations')->where('id', $cd)->value('canonical_name'));
        $this->assertSame('Centro Democrático', DB::table('lideres')->where('id', $lider)->value('partido'));
        $this->assertSame(0, DB::table('audit_logs')->count());

        $this->artisan('data:partidos-mayusculas')->assertSuccessful();
        $this->assertSame('CENTRO DEMOCRÁTICO', DB::table('political_organizations')->where('id', $cd)->value('canonical_name'));
        $this->assertSame(['CENTRO DEMOCRÁTICO', 'LIBERAL'], DB::table('lideres')->orderBy('nombre')->pluck('partido')->all());
        $this->assertSame('PACTO HISTÓRICO', DB::table('lider_info_politica')->value('partido'));
        $audit = json_decode(DB::table('audit_logs')->where('action', 'DATA_PARTIDOS_MAYUSCULAS')->value('metadata'), true);
        $this->assertSame(1, $audit['organizaciones']);
        $this->assertSame(2, $audit['lideres.partido']);
        $this->assertStringNotContainsString('Ana', json_encode($audit));

        $this->artisan('data:partidos-mayusculas')->expectsOutputToContain('Nada que cambiar.')->assertSuccessful();
        $this->assertSame(1, DB::table('audit_logs')->count());
    }

    public function test_two_parties_that_would_share_a_name_stop_the_command(): void
    {
        $a = $this->partido('Liberal');
        $b = $this->partido('LIBERAL');
        $this->partido('Pacto Historico');
        DB::table('lideres')->insert(['id' => (string) Str::uuid(), 'nombre' => 'Ana', 'partido' => 'Liberal']);

        $this->artisan('data:partidos-mayusculas')
            ->expectsOutputToContain('Hay partidos que quedarían con el mismo nombre')
            ->expectsOutputToContain($a)
            ->expectsOutputToContain($b)
            ->assertFailed();

        $this->assertSame('Pacto Historico', DB::table('political_organizations')->where('canonical_name', 'Pacto Historico')->value('canonical_name'));
        $this->assertSame('Liberal', DB::table('lideres')->value('partido'));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }
}
