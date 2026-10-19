<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

// Opt-in integration checks for queries that only run on PostgreSQL (ilike, real schema).
// Run against a LOCAL database: RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral php artisan test --filter=TerritoryScopePostgresTest
// Every change is rolled back by DatabaseTransactions.
class TerritoryScopePostgresTest extends TestCase
{
    use DatabaseTransactions;

    private string $munA;

    private string $munB;

    private User $campo;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }

        $this->withoutVite();
        $this->munA = $this->geo("San José de O'Prueba");
        $this->munB = $this->geo('Municipio Prueba B');
        $this->lider($this->munA, 'Barrio Dentro A');
        $this->lider($this->munB, 'Barrio Fuera B');

        $this->campo = User::create([
            'name' => 'Campo PG', 'email' => 'campo-pg-'.Str::random(6).'@test.local', 'password' => 'secret123',
            'role_id' => Role::where('code', 'R09_CAMPO')->value('id'), 'territory_scope' => [$this->munA],
            'permissions' => ['view.mapa', 'view.votantes'],
        ]);
    }

    private function geo(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('geographic_units')->insert(['id' => $id, 'type' => 'municipality', 'canonical_name' => $name]);

        return $id;
    }

    private function lider(string $geoId, string $barrio): void
    {
        DB::table('lideres')->insert([
            'id' => (string) Str::uuid(), 'geographic_unit_id' => $geoId, 'nombre' => 'Lider '.$barrio,
            'municipio' => DB::table('geographic_units')->where('id', $geoId)->value('canonical_name'),
            'barrio' => $barrio, 'cargo' => 'COORDINADOR MUNICIPAL', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_mapa_politico_barrios_without_municipio_only_come_from_the_territory(): void
    {
        $this->actingAs($this->campo)->get('/mapa-politico')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('barrios', fn ($barrios) => collect($barrios)->contains('Barrio Dentro A')
                    && ! collect($barrios)->contains('Barrio Fuera B')));
    }

    public function test_offline_download_binds_municipio_names_with_quotes(): void
    {
        $officeId = DB::table('offices')->where('name', 'Alcaldía')->value('id');
        $eventId = DB::table('contests')->whereNotNull('electoral_event_id')->value('electoral_event_id');
        $this->assertNotNull($officeId);
        $this->assertNotNull($eventId);

        $contestId = (string) Str::uuid();
        DB::table('contests')->insert([
            'id' => $contestId, 'name' => 'Alcaldía prueba', 'electoral_event_id' => $eventId,
            'office_id' => $officeId, 'geographic_unit_id' => $this->munA,
        ]);
        $personId = (string) Str::uuid();
        DB::table('persons')->insert([
            'id' => $personId, 'first_name' => 'Cand', 'last_name' => 'Prueba',
            'full_name' => 'Cand Prueba', 'normalized_name' => 'cand prueba',
        ]);
        DB::table('candidacies')->insert(['id' => (string) Str::uuid(), 'person_id' => $personId, 'contest_id' => $contestId]);

        $response = $this->actingAs($this->campo)->getJson("/api/offline/download/{$this->munA}")->assertOk();

        $persona = collect($response->json('personas'))->firstWhere('id', $personId);
        $this->assertSame("San José de O'Prueba", $persona['municipio'] ?? null);

        $this->actingAs($this->campo)->getJson("/api/offline/download/{$this->munB}")->assertForbidden();
    }

    public function test_votantes_list_runs_on_postgres_and_only_shows_the_territory(): void
    {
        $this->actingAs($this->campo)->get('/votantes')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('counters.total', 1)
                ->where('votantes.data.0.barrio', 'Barrio Dentro A')
                ->where('votantes.data.0.referidos_count', 0));
    }
}
