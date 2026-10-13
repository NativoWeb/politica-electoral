<?php

namespace Database\Seeders;

use App\Models\CargoCategory;
use Illuminate\Database\Seeder;

class CargoCategorySeeder extends Seeder
{
    public function run(): void
    {
        $cargos = [
            ['name' => 'Candidato Alcaldía 2027', 'display_order' => 1, 'is_highlighted' => true],
            ['name' => 'Candidato Concejo 2027', 'display_order' => 2, 'is_highlighted' => true],
            ['name' => 'Candidato Asamblea 2027', 'display_order' => 3, 'is_highlighted' => true],
            ['name' => 'Candidato Edil 2027', 'display_order' => 4, 'is_highlighted' => true],
            ['name' => 'Candidato Directorio 2027', 'display_order' => 5, 'is_highlighted' => true],
            ['name' => 'Líder', 'display_order' => 6, 'is_highlighted' => false],
            ['name' => 'Concejal', 'display_order' => 7, 'is_highlighted' => false],
            ['name' => 'Directorio Municipal', 'display_order' => 8, 'is_highlighted' => false],
            ['name' => 'Frentes de Seguridad', 'display_order' => 9, 'is_highlighted' => false],
            ['name' => 'Reservistas', 'display_order' => 10, 'is_highlighted' => false],
            ['name' => 'JAC', 'display_order' => 11, 'is_highlighted' => false],
            ['name' => 'Ediles', 'display_order' => 12, 'is_highlighted' => false],
            ['name' => 'Comunidades Religiosas', 'display_order' => 13, 'is_highlighted' => false],
            ['name' => 'Candidato Concejo', 'display_order' => 14, 'is_highlighted' => false],
            ['name' => 'Candidato Asamblea', 'display_order' => 15, 'is_highlighted' => false],
            ['name' => 'Candidato Alcaldía', 'display_order' => 16, 'is_highlighted' => false],
            ['name' => 'Empresarios', 'display_order' => 17, 'is_highlighted' => false],
            ['name' => 'Juventudes', 'display_order' => 18, 'is_highlighted' => false],
            ['name' => 'Mujeres', 'display_order' => 19, 'is_highlighted' => false],
            ['name' => 'Coordinador Municipal', 'display_order' => 20, 'is_highlighted' => false],
            ['name' => 'Coordinador Provincia', 'display_order' => 21, 'is_highlighted' => false],
            ['name' => 'Funcionario', 'display_order' => 22, 'is_highlighted' => false],
        ];

        foreach ($cargos as $cargo) {
            CargoCategory::updateOrCreate(
                ['name' => $cargo['name']],
                $cargo
            );
        }
    }
}
