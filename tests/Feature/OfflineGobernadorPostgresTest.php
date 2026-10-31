<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// The offline package only carries the gobernador candidates for users who may open /gobernador.
// Opt-in (the download uses ilike): RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral php artisan test --filter=OfflineGobernadorPostgresTest
// Every change is rolled back by DatabaseTransactions.
class OfflineGobernadorPostgresTest extends TestCase
{
    use DatabaseTransactions;

    private string $municipio;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }

        $this->municipio = DB::table('geographic_units')->where('type', 'municipality')->value('id');
        $gobernacion = DB::table('offices')->where('name', 'Gobernación')->value('id');
        $this->assertNotNull($this->municipio);
        $this->assertTrue(DB::table('contests')->where('office_id', $gobernacion)->exists(), 'The local database has no gobernador contest.');
    }

    private function user(array $permissions): User
    {
        return User::create([
            'name' => 'Offline PG', 'email' => 'offline-pg-'.Str::random(6).'@test.local', 'password' => 'secret123',
            'role_id' => Role::where('code', 'R09_CAMPO')->value('id'), 'permissions' => $permissions,
        ]);
    }

    public function test_offline_download_has_no_gobernador_without_the_permission(): void
    {
        $this->actingAs($this->user(['view.mapa', 'write.data']))
            ->getJson("/api/offline/download/{$this->municipio}")
            ->assertOk()
            ->assertJsonPath('gobernador', [])
            ->assertJsonStructure(['personas', 'lideres', 'nexos']);
    }

    public function test_offline_download_keeps_gobernador_with_the_permission(): void
    {
        $response = $this->actingAs($this->user(['view.mapa', 'view.gobernador']))
            ->getJson("/api/offline/download/{$this->municipio}")
            ->assertOk();

        $this->assertNotEmpty($response->json('gobernador'));
        $this->assertArrayHasKey('nombre', $response->json('gobernador.0'));
    }
}
