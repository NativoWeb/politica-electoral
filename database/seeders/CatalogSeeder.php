<?php

namespace Database\Seeders;

use App\Models\Corporation;
use App\Models\Office;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        // Offices (executive positions)
        $offices = [
            ['name' => 'Alcaldía', 'type' => 'executive', 'is_uninominal' => true],
            ['name' => 'Gobernación', 'type' => 'executive', 'is_uninominal' => true],
            ['name' => 'Presidencia', 'type' => 'executive', 'is_uninominal' => true],
        ];

        foreach ($offices as $office) {
            Office::create($office);
        }

        // Corporations (legislative bodies)
        $corporations = [
            ['name' => 'Concejo', 'scope' => 'municipal', 'default_seats' => null],
            ['name' => 'Asamblea', 'scope' => 'departmental', 'default_seats' => null],
            ['name' => 'Cámara de Representantes', 'scope' => 'national', 'default_seats' => null],
            ['name' => 'Senado', 'scope' => 'national', 'default_seats' => 108],
        ];

        foreach ($corporations as $corp) {
            Corporation::create($corp);
        }
    }
}
