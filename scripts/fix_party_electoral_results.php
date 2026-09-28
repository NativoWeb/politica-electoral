<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
use Illuminate\Support\Facades\DB;

$merges = [
    'Partido De La U' => 'La U',
    'Partido Conservador' => 'Conservador',
    'Partido Liberal' => 'Liberal',
];

$total = 0;
foreach ($merges as $old => $new) {
    $oldId = DB::table('political_organizations')->where('canonical_name', $old)->value('id');
    $newId = DB::table('political_organizations')->where('canonical_name', $new)->value('id');
    if (!$oldId || !$newId) { echo "SKIP: $old -> $new (not found)\n"; continue; }
    $affected = DB::table('electoral_results')->where('organization_id', $oldId)->update(['organization_id' => $newId]);
    echo "REMAPPED: $old -> $new ($affected results)\n";
    $total += $affected;
}
echo "\nTotal remapped: $total\n";

// Show updated party totals for Bucaramanga Camara
$bucId = DB::table('geographic_units')->where('canonical_name', 'Bucaramanga')->value('id');
$totals = DB::table('electoral_results as er')
    ->join('political_organizations as po', 'er.organization_id', '=', 'po.id')
    ->join('contests as c', 'er.contest_id', '=', 'c.id')
    ->join('corporations as corp', 'c.corporation_id', '=', 'corp.id')
    ->whereNull('er.candidacy_id')
    ->where('er.geographic_unit_id', $bucId)
    ->where('corp.name', 'Cámara de Representantes')
    ->select('po.canonical_name as partido', 'er.value as votos')
    ->orderByDesc('er.value')
    ->get();
echo "\nBucaramanga Cámara party totals (updated):\n";
foreach ($totals as $t) echo "  {$t->partido}: {$t->votos}\n";
