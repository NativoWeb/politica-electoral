<?php

namespace Database\Seeders;

use App\Models\GeographicAlias;
use App\Models\GeographicUnit;
use Illuminate\Database\Seeder;

class GeographySeeder extends Seeder
{
    public function run(): void
    {
        // Colombia
        $colombia = GeographicUnit::create([
            'type' => 'country',
            'canonical_name' => 'Colombia',
            'official_code' => 'CO',
            'level' => 0,
        ]);

        // Santander
        $santander = GeographicUnit::create([
            'parent_id' => $colombia->id,
            'type' => 'department',
            'canonical_name' => 'Santander',
            'official_code' => '68',
            'level' => 1,
        ]);

        // Provinces with their municipalities
        $provinces = [
            'Comunera' => [
                'Chima', 'Confines', 'Contratación', 'El Guacamayo', 'Galán',
                'Gámbita', 'Guadalupe', 'Guapotá', 'Hato', 'Oiba',
                'Palmar', 'Palmas del Socorro', 'Santa Helena del Opón',
                'Simacota', 'Socorro', 'Suaita',
            ],
            'García Rovira' => [
                'Capitanejo', 'Carcasí', 'Cerrito', 'Concepción', 'Enciso',
                'Guaca', 'Macaravita', 'Málaga', 'Molagavita', 'San Andrés',
                'San José de Miranda', 'San Miguel',
            ],
            'Guanentá' => [
                'Aratoca', 'Barichara', 'Cabrera', 'Cepitá', 'Coromoro',
                'Curití', 'Encino', 'Jordán', 'Mogotes', 'Ocamonte',
                'Onzaga', 'Páramo', 'Pinchote', 'San Gil', 'San Joaquín',
                'Valle de San José', 'Villanueva',
            ],
            'Mares' => [
                'Barrancabermeja', 'Betulia', 'El Carmen de Chucurí',
                'Puerto Wilches', 'Sabana de Torres', 'San Vicente de Chucurí',
            ],
            'Soto' => [
                'Bucaramanga', 'California', 'Charta', 'El Playón',
                'Floridablanca', 'Girón', 'Lebrija', 'Los Santos',
                'Matanza', 'Piedecuesta', 'Rionegro', 'Santa Bárbara',
                'Suratá', 'Tona', 'Vetas',
            ],
            'Soto Norte' => [
                'California', 'Matanza', 'Suratá', 'Vetas',
            ],
            'Vélez' => [
                'Aguada', 'Albania', 'Barbosa', 'Bolívar', 'Chipatá',
                'Cimitarra', 'El Peñón', 'Florián', 'Guavatá', 'Güepsa',
                'Jesús María', 'La Belleza', 'La Paz', 'Landázuri',
                'Puente Nacional', 'Puerto Parra', 'San Benito',
                'Sucre', 'Vélez',
            ],
        ];

        // Track created municipalities to avoid duplicates (some appear in multiple provinces)
        $createdMunicipios = [];

        foreach ($provinces as $provName => $municipios) {
            $province = GeographicUnit::create([
                'parent_id' => $santander->id,
                'type' => 'province',
                'canonical_name' => $provName,
                'level' => 2,
            ]);

            foreach ($municipios as $mpioName) {
                if (isset($createdMunicipios[$mpioName])) {
                    continue;
                }

                $mpio = GeographicUnit::create([
                    'parent_id' => $province->id,
                    'type' => 'municipality',
                    'canonical_name' => $mpioName,
                    'level' => 3,
                ]);

                // Create normalized alias for matching
                $normalized = mb_strtoupper($mpioName);
                $normalized = str_replace(
                    ['Á', 'É', 'Í', 'Ó', 'Ú', 'Ñ'],
                    ['A', 'E', 'I', 'O', 'U', 'N'],
                    $normalized
                );

                GeographicAlias::create([
                    'geographic_unit_id' => $mpio->id,
                    'raw_alias' => $mpioName,
                    'normalized_alias' => $normalized,
                ]);

                $createdMunicipios[$mpioName] = $mpio->id;
            }
        }
    }
}
