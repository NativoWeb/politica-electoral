<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use App\Services\Geo\MunicipiosGeoJson;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

// geo:import-municipios rejects bad files before touching the database and needs PostGIS (the load itself is covered
// by GeoMunicipiosPostgresTest). GET /api/geo/municipios.geojson needs a session with view.mapa and honours the ETag.
class GeoMunicipiosTest extends TestCase
{
    private const FIXTURE = 'tests/Fixtures/geo/municipios_minimo.geojson';

    private string $role;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('geographic_units', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('parent_id')->nullable();
            $t->string('type', 30);
            $t->string('canonical_name', 150);
            $t->string('official_code', 20)->nullable();
            $t->timestamps();
        });
        Schema::create('campaigns', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('slug')->unique();
            $t->uuid('parent_id')->nullable();
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

        DB::table('campaigns')->insert(['id' => Campaign::DEFAULT_ID, 'name' => 'Centro Democrático', 'slug' => 'centro-democratico']);
        $this->role = Role::create(['code' => 'R09_CAMPO', 'name' => 'Campo', 'level' => 1])->id;
        DB::table('geographic_units')->insert(['id' => (string) Str::uuid(), 'type' => 'department', 'canonical_name' => 'Santander', 'official_code' => '68']);
    }

    private function user(array $permissions): User
    {
        return User::create([
            'name' => 'Geo', 'email' => 'geo-'.Str::random(6).'@test.local', 'password' => 'secret123',
            'role_id' => $this->role, 'permissions' => $permissions,
        ])->fresh();
    }

    private function snapshot(): array
    {
        return DB::table('geographic_units')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function test_a_missing_file_fails_without_writing(): void
    {
        $before = $this->snapshot();

        $this->artisan('geo:import-municipios', ['archivo' => base_path('tests/Fixtures/geo/no-existe.geojson')])
            ->expectsOutputToContain('No se puede leer el archivo')
            ->assertFailed();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_file_that_is_not_a_feature_collection_fails(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'geo');
        file_put_contents($path, json_encode(['type' => 'Feature', 'geometry' => null]));

        try {
            $this->artisan('geo:import-municipios', ['archivo' => $path])
                ->expectsOutputToContain('no es un GeoJSON FeatureCollection')
                ->assertFailed();
        } finally {
            unlink($path);
        }
    }

    public function test_a_feature_without_a_dane_code_fails(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'geo');
        file_put_contents($path, json_encode(['type' => 'FeatureCollection', 'features' => [[
            'type' => 'Feature', 'properties' => ['MPIO_CNMBRE' => 'SIN CODIGO'],
            'geometry' => ['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 0], [1, 1], [0, 0]]]],
        ]]]));

        try {
            $this->artisan('geo:import-municipios', ['archivo' => $path])
                ->expectsOutputToContain('no tiene código DANE de 5 dígitos')
                ->assertFailed();
        } finally {
            unlink($path);
        }
    }

    public function test_a_repeated_code_fails(): void
    {
        $data = json_decode(file_get_contents(base_path(self::FIXTURE)), true);
        $data['features'][] = $data['features'][0];
        $path = tempnam(sys_get_temp_dir(), 'geo');
        file_put_contents($path, json_encode($data));

        try {
            $this->artisan('geo:import-municipios', ['archivo' => $path])
                ->expectsOutputToContain('El código 68001 está repetido')
                ->assertFailed();
        } finally {
            unlink($path);
        }
    }

    public function test_a_valid_file_needs_postgis_and_writes_nothing_elsewhere(): void
    {
        $before = $this->snapshot();

        $this->artisan('geo:import-municipios', ['archivo' => base_path(self::FIXTURE), '--dry-run' => true])
            ->expectsOutputToContain('necesita PostgreSQL con PostGIS')
            ->assertFailed();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_the_endpoint_needs_a_session(): void
    {
        $this->getJson('/api/geo/municipios.geojson?simplified=1')->assertUnauthorized();
    }

    public function test_the_endpoint_needs_view_mapa(): void
    {
        $this->actingAs($this->user(['view.votantes']))
            ->getJson('/api/geo/municipios.geojson?simplified=1')
            ->assertForbidden();
    }

    public function test_the_endpoint_serves_the_cached_collection_with_its_etag(): void
    {
        $body = json_encode(['type' => 'FeatureCollection', 'features' => [['type' => 'Feature', 'id' => 'x', 'properties' => ['nombre' => 'Bucaramanga'], 'geometry' => null]]]);
        Cache::put(MunicipiosGeoJson::key(true), ['body' => $body, 'etag' => '"abc123"']);
        $user = $this->user(['view.mapa']);

        $response = $this->actingAs($user)->get('/api/geo/municipios.geojson?simplified=1');
        $response->assertOk()
            ->assertHeader('Content-Type', 'application/geo+json')
            ->assertHeader('ETag', '"abc123"');
        $this->assertSame($body, $response->getContent());

        $this->actingAs($user)->get('/api/geo/municipios.geojson?simplified=1', ['If-None-Match' => '"abc123"'])
            ->assertStatus(304);
    }
}
