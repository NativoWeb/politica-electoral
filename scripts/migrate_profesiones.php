<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

// Cargos que son realmente PROFESIONES → mover a campo profesion y poner cargo = 'Líder'
$profesiones = [
    'ABOGADA', 'ABOGADO', 'AMA DE CASA', 'DOCENTE', 'EMPRESARIA', 'EMPRESARIO - SENA',
    'EMPLEADO', 'INGENIERO VICIL', 'INGENIERO CIVIL', 'ING. CALIDAD', 'CONTADORA PUBLICA',
    'PERIODISTA A.P.B.', 'PASTOR', 'ESTUDIANTE', 'SALUD', 'ASESOR', 'Communitey Manager',
    'comunicadora, erwin', 'dorectora UNAD', 'lider abogado joven',
];

// Cargos que son líderes de barrio → cargo = 'Líder' (el barrio ya está en el campo barrio o se pierde)
$lideresBarrio = [
    'LIDER 1 DE MAYO', 'LIDER PARNASO', 'LIDER BUENOS AIRES', 'LIDER BRISAS DE LA LIBERTAD',
    'LIDER CORDALES', 'LIDER VILLA MARIA', 'LIDER LA FLORESTA', 'LIDER B. NARIÑO',
    'LIDER B. BOSTON', 'LIDER LA VICTORIA', 'LIDER B. CAPINERO', 'LIDER PISCICOLA ALCALDIA',
    'LIDER SIMON BOLIVAR', 'LIDER LAS PLAYAS', 'LIDER LA PENINSULA', 'LIDER BOSTON',
    'LIDER EL CASTILLO', 'LIDER MIRADOR DEL LAGO', 'LIDER SOCIAL', 'LIDER DANUBIO',
    'LIDER LA LIBERTAD', 'LIDER BARRANCA', 'LIDER LAS GRANJAS', 'LIDER EL PROGRESO',
    'LIDER INSCREDIAL', 'LIDER SAN FRANCISCO', 'LIDER VEREDA QUEMADERO', 'LIDER PUEBLO NUEVO',
    'LIDER LA ESPERANZA', 'LIDER LA UNION', 'LIDER ISLA DEL ZAPATO', 'LIDER BUENAVISTA',
    'LIDER', 'LIDER CCONCEJO',
];

// Cargos que son instituciones → mover a observación y poner cargo = 'Líder'
$instituciones = [
    'AUNAP', 'CAS', 'FUNCIONARIO ICA', 'UNIDAD DE VICTIMAS', 'FUNCACION ENTRERIOS',
    'ALCALDIA BARRANCABERMEJA', 'Probarranca', 'Directora de Probarranca',
    'Equipo Plataforma Rios',
];

// Cargos que son notas → mover a observación
$notas = [
    'se caso hijo de arsecio lopez', 'DIRIGENTE',
];

// Renombrar
$rename = [
    'C.CONCEJO' => 'Concejal',
    'Consejero Juventudes' => 'Juventudes',
    'REPRESENTANTE JOVENES' => 'Juventudes',
    'REPRESENTANTE RESERVA' => 'Mujeres',
    'COORDINADOR MUNICIPAL' => 'Coordinador Municipal',
];

$total = 0;

// 1. Profesiones → mover a campo profesion, cargo = Líder
foreach ($profesiones as $prof) {
    $affected = DB::table('lideres')
        ->where('cargo', $prof)
        ->whereNull('profesion')
        ->update(['profesion' => $prof, 'cargo' => 'Líder']);
    $affected2 = DB::table('lideres')
        ->where('cargo', $prof)
        ->whereNotNull('profesion')
        ->update(['cargo' => 'Líder']);
    if ($affected + $affected2 > 0) {
        echo "PROFESION: $prof -> profesion ($affected+$affected2)\n";
        $total += $affected + $affected2;
    }
}

// 2. Líderes de barrio → cargo = Líder (extraer barrio si vacío)
foreach ($lideresBarrio as $lb) {
    $barrio = str_replace(['LIDER ', 'LIDER'], '', $lb);
    $barrio = trim($barrio);
    if ($barrio && $barrio !== $lb) {
        // Set barrio if empty
        DB::table('lideres')
            ->where('cargo', $lb)
            ->whereNull('barrio')
            ->update(['barrio' => ucwords(strtolower($barrio))]);
    }
    $affected = DB::table('lideres')->where('cargo', $lb)->update(['cargo' => 'Líder']);
    if ($affected > 0) {
        echo "BARRIO: $lb -> Líder" . ($barrio ? " (barrio=$barrio)" : "") . " ($affected)\n";
        $total += $affected;
    }
}

// 3. Instituciones → observacion, cargo = Líder
foreach ($instituciones as $inst) {
    $affected = DB::table('lideres')
        ->where('cargo', $inst)
        ->update([
            'observacion' => DB::raw("CASE WHEN observacion IS NULL OR observacion = '' THEN '$inst' ELSE observacion || ' | $inst' END"),
            'cargo' => 'Líder',
        ]);
    if ($affected > 0) {
        echo "INSTITUCION: $inst -> observacion + Líder ($affected)\n";
        $total += $affected;
    }
}

// 4. Notas → observacion, cargo = Líder
foreach ($notas as $nota) {
    $affected = DB::table('lideres')
        ->where('cargo', $nota)
        ->update([
            'observacion' => DB::raw("CASE WHEN observacion IS NULL OR observacion = '' THEN '$nota' ELSE observacion || ' | $nota' END"),
            'cargo' => 'Líder',
        ]);
    if ($affected > 0) {
        echo "NOTA: $nota -> observacion ($affected)\n";
        $total += $affected;
    }
}

// 5. Renombrar cargos
foreach ($rename as $old => $new) {
    $affected = DB::table('lideres')->where('cargo', $old)->update(['cargo' => $new]);
    if ($affected > 0) {
        echo "RENAME: $old -> $new ($affected)\n";
        $total += $affected;
    }
}

echo "\nTotal changed: $total\n";

// Show final cargo distribution
echo "\nFinal cargo distribution:\n";
$cargos = DB::table('lideres')->select('cargo', DB::raw('count(*) as t'))->groupBy('cargo')->orderByDesc('t')->get();
foreach ($cargos as $c) echo "  {$c->cargo}: {$c->t}\n";
