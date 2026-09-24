<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$r = DB::table('lideres')->where('destacado', true)->select('nombre', 'destacado')->first();
echo "type=" . gettype($r->destacado) . " val=" . var_export($r->destacado, true) . "\n";

// Simulate what the controller does
$row = (array) $r;
echo "array type=" . gettype($row['destacado']) . " val=" . var_export($row['destacado'], true) . "\n";
echo "json=" . json_encode(['destacado' => $row['destacado']]) . "\n";
