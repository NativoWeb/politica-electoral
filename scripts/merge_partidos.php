<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$merges = [
    'Independiente' => ['Independientes'],
    'Colombia Renaciente' => ['Colombia Renanciente'],
    'Cambio Radical' => ['Cambio Ardical'],
    'Conservador' => ['Concervador', 'Partido Conservador'],
    'Conservador - Mira' => ['Conservador- Mira'],
    'Nuevo Liberalismo' => ['Nuevo Libralismo', 'Nuevo Lineralismo', 'Vuevo Liberalismo'],
    'Nueva Fuerza Democratica' => ['Nueva Fuerza Democratico'],
    'La Fuerza De La Paz' => ['La Fuerza Del Paz'],
    'Liberal' => ['Liberalismo', 'Partido Liberal'],
    'La U' => ['De La U', 'Partido De La U'],
    'Exalcalde' => ['Ex Alcalde'],
    'Democrata' => ['Partido Democrata', 'Democrata Colombiano'],
    'Nueva Fuerza' => ['Nueva Fueza'],
];

$total = 0;
foreach ($merges as $correct => $wrongs) {
    $correctId = DB::table('political_organizations')->where('canonical_name', $correct)->value('id');
    if (!$correctId) { echo "SKIP: $correct not found\n"; continue; }

    foreach ($wrongs as $wrong) {
        $wrongId = DB::table('political_organizations')->where('canonical_name', $wrong)->value('id');
        if (!$wrongId) { echo "  skip: $wrong not found\n"; continue; }

        $e = DB::table('candidacy_endorsements')->where('organization_id', $wrongId)->update(['organization_id' => $correctId]);
        $l = DB::table('lideres')->where('partido', $wrong)->update(['partido' => $correct]);
        DB::table('political_organizations')->where('id', $wrongId)->update(['status' => 'inactive']);
        echo "MERGED: $wrong -> $correct (endorsements:$e, lideres:$l)\n";
        $total++;
    }
}
echo "\nTotal merged: $total\n";
