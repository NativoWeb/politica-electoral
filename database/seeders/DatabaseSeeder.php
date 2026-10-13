<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            CatalogSeeder::class,
            GeographySeeder::class,
            CargoCategorySeeder::class,
        ]);

        // Create admin user with superadmin role
        $superadminRole = Role::where('code', 'R01_SUPERADMIN')->first();

        User::create([
            'name' => 'Administrador',
            'email' => 'admin@inteligenciaelectoral.co',
            'password' => bcrypt('password'),
            'role_id' => $superadminRole?->id,
            'is_active' => true,
            'executive_mode' => false,
        ]);
    }
}
