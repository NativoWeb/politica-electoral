<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

// Check party-level votes for Malaga
$sample = DB::table('electoral_results as er')
    ->join('political_organizations as po', 'er.organization_id', '=', 'po.id')
    ->join('contests as c', 'er.contest_id', '=', 'c.id')
    ->join('geographic_units as g', 'er.geographic_unit_id', '=', 'g.id')
    ->leftJoin('corporations as corp', 'c.corporation_id', '=', 'corp.id')
    ->leftJoin('offices as o', 'c.office_id', '=', 'o.id')
    ->whereNull('er.candidacy_id')
    ->whereNotNull('er.organization_id')
    ->where('g.canonical_name', 'ilike', '%malaga%')
    ->select('po.canonical_name as partido', 'g.canonical_name as municipio', 'er.value as votos', DB::raw('COALESCE(corp.name, o.name) as corporacion'))
    ->orderBy('corporacion')
    ->orderByDesc('er.value')
    ->get();

echo "Party votes in Malaga:\n";
foreach ($sample as $r) {
    echo "  {$r->corporacion}: {$r->partido} = {$r->votos}\n";
}
echo "\nTotal records: " . $sample->count() . "\n";

// Check what corporations have party votes
$corps = DB::table('electoral_results as er')
    ->join('contests as c', 'er.contest_id', '=', 'c.id')
    ->leftJoin('corporations as corp', 'c.corporation_id', '=', 'corp.id')
    ->leftJoin('offices as o', 'c.office_id', '=', 'o.id')
    ->whereNull('er.candidacy_id')
    ->whereNotNull('er.organization_id')
    ->select(DB::raw('COALESCE(corp.name, o.name) as corporacion'), DB::raw('count(*) as t'))
    ->groupBy('corporacion')
    ->get();

echo "\nParty votes by corporation:\n";
foreach ($corps as $c) {
    echo "  {$c->corporacion}: {$c->t} records\n";
}
