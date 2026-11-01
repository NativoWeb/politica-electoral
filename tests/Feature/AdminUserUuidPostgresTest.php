<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

// On PostgreSQL a value that is not a UUID reaching a uuid column is a database error (500). The user form and
// the user routes must answer 422/404 instead, and a missing record answers the generic 404.
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral DB_URL= php artisan test --filter=Postgres
class AdminUserUuidPostgresTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }

        $this->withoutVite();
        Mail::fake();
        $this->admin = User::create([
            'name' => 'Admin PG', 'email' => 'admin-pg-'.Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => Role::where('code', 'R02_ADMIN_FUNCIONAL')->value('id'),
            'permissions' => ['view.admin', 'manage.users', 'view.mapa', 'manage.eventos'],
        ]);
        $this->admin->forceFill(['campaign_id' => Campaign::DEFAULT_ID])->save();
    }

    public function test_a_role_id_that_is_not_a_uuid_is_a_422_not_a_database_error(): void
    {
        $payload = ['name' => 'Nuevo PG', 'email' => 'nuevo-pg-'.Str::random(6).'@test.local', 'role_id' => "abc' OR 1=1"];

        $this->actingAs($this->admin)->postJson('/admin/usuarios', $payload)
            ->assertStatus(422)->assertJsonPath('errors.role_id', ['El campo rol debe ser un UUID válido.']);
        $this->actingAs($this->admin)->putJson("/admin/usuarios/{$this->admin->id}", $payload)
            ->assertStatus(422)->assertJsonPath('errors.role_id', ['El campo rol debe ser un UUID válido.']);
        $this->actingAs($this->admin)->postJson('/admin/usuarios', array_merge($payload, ['role_id' => (string) Str::uuid()]))
            ->assertStatus(422)->assertJsonPath('errors.role_id', ['El rol seleccionado no es válido.']);
    }

    public function test_user_routes_with_an_id_that_is_not_a_uuid_are_404(): void
    {
        $this->actingAs($this->admin)->putJson('/admin/usuarios/no-uuid', ['name' => 'X', 'email' => 'x@test.local'])->assertNotFound();
        $this->actingAs($this->admin)->postJson('/admin/usuarios/no-uuid/toggle')->assertNotFound();
        $this->actingAs($this->admin)->deleteJson('/admin/usuarios/no-uuid')->assertNotFound();
    }

    public function test_a_missing_event_is_the_generic_404(): void
    {
        $this->actingAs($this->admin)->getJson('/eventos/'.Str::uuid())
            ->assertNotFound()->assertExactJson(['message' => 'No se encontró lo que busca.']);
    }
}
