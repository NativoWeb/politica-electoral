<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

// Coaliciones → partido principal
$merges = [
    'La U' => ['La U-Cd', 'La U - Liberal - Cambio Radical'],
    'Liberal' => ['Liberal - Cambio Radical - Aico', 'Liberal. En Marcha', 'Cr- Liberal'],
    'Alianza Verde' => ['Coalicion Verde', 'Verde / En Marcha', 'Verde, En Marcha, Mira', 'Verde Polo'],
    'Cambio Radical' => ['Cambio Radical - Colombia Justa'],
    'Conservador' => ['Conservador - Mira'],
    'Centro Democrático' => ['La Belleza Centro Democratico'],
    'Fuerza De La Paz' => ['La Fuerza De La Paz'],
    'En Marcha' => ['Marcha'],
    'Nuevo Liberalismo' => ['Mira / Nuevo Liberal', 'Salvacion Nacional. Nuevo Liberalismo'],
    'Mira' => [],
    'Liga' => ['La Liga'],
    'Salvacion Nacional' => [],
    'Pacto Historico' => ['Pacto'],
    'Colombia Justa - Salvacion Nal' => [],
    'Aico' => ['Aico, En Marcha'],
    'Nueva Fuerza Democratica' => ['Nueva Fueza'],
];

// No son partidos reales → desactivar
$notParties = [
    'Dirigente', 'Registrador', 'Exalcalde', 'Ex Alcaldesa',
    'Es Concejal', 'Candidato Asamblea', 'Ex Candidato Asamblea',
    'Familiar Cesar Cediel', 'Amigo De Cesar Cediel',
    'Sec Planeacion',
];

$total = 0;

// Merge coaliciones
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

// Deactivate non-parties
foreach ($notParties as $name) {
    $id = DB::table('political_organizations')->where('canonical_name', $name)->where('status', 'active')->value('id');
    if (!$id) continue;
    DB::table('political_organizations')->where('id', $id)->update(['status' => 'inactive']);
    echo "DEACTIVATED: $name\n";
    $total++;
}

echo "\nTotal changes: $total\n";

// Show remaining active
$remaining = DB::table('political_organizations')->where('status', 'active')->orderBy('canonical_name')->pluck('canonical_name')->toArray();
echo "\nActive parties (" . count($remaining) . "):\n";
foreach ($remaining as $p) echo "  - $p\n";
