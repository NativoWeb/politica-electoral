<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// The base migrations need PostgreSQL, so users/roles are built here.
class LogoutClearSiteDataTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

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

        $role = Role::create(['code' => 'R09_CAMPO', 'name' => 'Operador', 'level' => 1]);
        $this->user = User::create([
            'name' => 'Campo A', 'email' => 'campo@test.local', 'password' => 'secret123',
            'role_id' => $role->id, 'permissions' => ['view.mapa'],
        ]);
    }

    public function test_logout_tells_the_browser_to_drop_cached_pages(): void
    {
        $response = $this->actingAs($this->user)->post('/logout');

        $response->assertRedirect('/login');
        $response->assertHeader('Clear-Site-Data', '"cache"');
        $this->assertGuest();
    }

    public function test_guest_logout_is_rejected_before_reaching_the_controller(): void
    {
        $response = $this->post('/logout');

        $response->assertRedirect('/login');
        $response->assertHeaderMissing('Clear-Site-Data');
    }
}
