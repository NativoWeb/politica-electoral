<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

// Coaliciones legítimas que deben volver a estar activas
$restore = [
    'Aico, En Marcha',
    'Cambio Radical - Colombia Justa',
    'Coalicion Verde',
    'Conservador - Mira',
    'Cr- Liberal',
    'La Belleza Centro Democratico',
    'La U-Cd',
    'La U - Liberal - Cambio Radical',
    'Liberal - Cambio Radical - Aico',
    'Liberal. En Marcha',
    'Mira / Nuevo Liberal',
    'Salvacion Nacional. Nuevo Liberalismo',
    'Verde / En Marcha',
    'Verde, En Marcha, Mira',
    'Verde Polo',
];

// Errores de tipeo que eran duplicados reales y deben seguir inactivos:
// Cambio Ardical, Colombia Renanciente, Concervador, Conservador- Mira,
// De La U, Ex Alcalde, Independientes, La Fuerza Del Paz, La Liga,
// Liberalismo, Marcha, Nueva Fueza, Nuevo Libralismo, Nuevo Lineralismo,
// Pacto, Partido Conservador, Partido De La U, Partido Democrata,
// Partido Liberal, Vuevo Liberalismo, Nueva Fuerza Democratico

// No-partidos que deben seguir inactivos:
// Dirigente, Es Concejal, Exalcalde, Ex Alcaldesa, Ex Candidato Asamblea,
// Familiar Cesar Cediel, Amigo De Cesar Cediel, Registrador, Sec Planeacion,
// Candidato Asamblea

$total = 0;
foreach ($restore as $name) {
    $affected = DB::table('political_organizations')
        ->where('canonical_name', $name)
        ->where('status', 'inactive')
        ->update(['status' => 'active']);
    if ($affected) {
        echo "RESTORED: $name\n";

        // Re-link endorsements: find the "correct" party they were merged into
        // and move them back
        $id = DB::table('political_organizations')->where('canonical_name', $name)->value('id');

        // Re-assign lideres that had this partido
        // (they were changed to the parent, but we can't undo that automatically)
        // Only restore endorsements

        $total++;
    } else {
        echo "skip: $name (not found or already active)\n";
    }
}

echo "\nRestored: $total coalitions\n";

// Show final count
$active = DB::table('political_organizations')->where('status', 'active')->count();
$inactive = DB::table('political_organizations')->where('status', 'inactive')->count();
echo "Active: $active, Inactive: $inactive\n";
