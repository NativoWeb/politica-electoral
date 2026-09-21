<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportBarrancabermeja extends Command
{
    protected $signature = 'import:barrancabermeja {--dry-run : Show what would be imported without inserting}';
    protected $description = 'Import leaders from Barrancabermeja Excel files (unified, no duplicates)';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $file1 = 'C:/Users/Marly Rangel/Downloads/Mapa Electoral - Barrancabermeja.xlsx';
        $file2 = 'C:/Users/Marly Rangel/Downloads/BARRANCABERMEJA.xlsx';

        $geoId = DB::table('geographic_units')->where('canonical_name', 'ilike', 'Barrancabermeja')->value('id');
        if (!$geoId) {
            $this->error('No se encontró Barrancabermeja en geographic_units');
            return 1;
        }

        // Get existing names to avoid duplicates
        $existing = DB::table('lideres')
            ->where('municipio', 'ilike', '%Barrancabermeja%')
            ->pluck('nombre')
            ->map(fn ($n) => mb_strtoupper(trim($n)))
            ->toArray();

        // Also check persons table
        $existingPersons = DB::table('persons')
            ->pluck('full_name')
            ->map(fn ($n) => mb_strtoupper(trim($n)))
            ->toArray();

        $allExisting = array_merge($existing, $existingPersons);

        $unified = []; // key = UPPERCASE name, value = data array

        // === FILE 1: Mapa Electoral ===
        $this->info('Leyendo: Mapa Electoral - Barrancabermeja.xlsx...');
        $sheet1 = IOFactory::load($file1)->getActiveSheet();
        // Headers at row 4: A=MUNICIPIO, B=NOMBRES, C=APELLIDOS, D=DOCUMENTO, E=ID, F=DIRECCIÓN,
        // G=FUNCIONARIO, H=EMPRESARIO, I=GRANELECTOR, J=VOTOS, K=DETALLE, L=CARGO_POLÍTICO,
        // M=MOVIL, N=FIJO, O=OBSERVACIÓN
        for ($r = 5; $r <= $sheet1->getHighestRow(); $r++) {
            $mun = trim($sheet1->getCell('A' . $r)->getValue() ?? '');
            if (mb_strtoupper($mun) !== 'BARRANCABERMEJA') continue;

            $nombres = trim($sheet1->getCell('B' . $r)->getValue() ?? '');
            $apellidos = trim($sheet1->getCell('C' . $r)->getValue() ?? '');
            $nombre = trim("$nombres $apellidos");
            if (!$nombre || mb_strlen($nombre) < 3) continue;

            $direccion = trim($sheet1->getCell('F' . $r)->getValue() ?? '');
            $cargo = trim($sheet1->getCell('M' . $r)->getValue() ?? '');  // M = CARGO_POLÍTICO
            $movil = trim($sheet1->getCell('N' . $r)->getValue() ?? '');  // N = MOVIL
            $fijo = trim($sheet1->getCell('O' . $r)->getValue() ?? '');   // O = FIJO
            $obs = trim($sheet1->getCell('P' . $r)->getValue() ?? '');    // P = OBSERVACIÓN
            $votos = (int) ($sheet1->getCell('K' . $r)->getValue() ?? 0); // K = VOTOS

            // Normalize cargo
            $cargo = $this->normalizeCargo($cargo);

            $key = mb_strtoupper($nombre);
            $telefono = $movil ?: $fijo;
            // Clean phone: remove spaces
            $telefono = preg_replace('/\s+/', '', $telefono);

            $unified[$key] = [
                'nombre' => mb_convert_case(mb_strtolower($nombre), MB_CASE_TITLE, 'UTF-8'),
                'cargo' => $cargo ?: 'Líder',
                'telefono' => $telefono ?: null,
                'direccion' => $direccion ?: null,
                'observacion' => $obs ?: null,
            ];
        }
        $this->info('  File 1: ' . count($unified) . ' personas de Barrancabermeja');

        // === FILE 2: LIDERES sheet ===
        $this->info('Leyendo: BARRANCABERMEJA.xlsx → Hoja LIDERES...');
        $sheet2 = IOFactory::load($file2)->getSheetByName('LIDERES');
        // Row 2 headers: A=MUNICIPIO, D=NOMBRE, E=CARGO, F=CELULAR, G=VOTOS, H=PARTIDO, I=OBSERVACION
        $added2 = 0;
        for ($r = 3; $r <= $sheet2->getHighestRow(); $r++) {
            $nombre = trim($sheet2->getCell('D' . $r)->getValue() ?? '');
            if (!$nombre || mb_strlen($nombre) < 3) continue;

            $cargo = trim($sheet2->getCell('E' . $r)->getValue() ?? '');
            $telefono = trim($sheet2->getCell('F' . $r)->getValue() ?? '');
            $partido = trim($sheet2->getCell('H' . $r)->getValue() ?? '');
            $obs = trim($sheet2->getCell('I' . $r)->getValue() ?? '');

            $cargo = $this->normalizeCargo($cargo);
            $telefono = preg_replace('/\s+/', '', $telefono);

            $key = mb_strtoupper($nombre);

            if (isset($unified[$key])) {
                // Enrich existing record
                if (!$unified[$key]['telefono'] && $telefono) $unified[$key]['telefono'] = $telefono;
                if ($partido) $unified[$key]['partido_nota'] = $partido;
                if ($obs && !$unified[$key]['observacion']) $unified[$key]['observacion'] = $obs;
                if ($cargo && $unified[$key]['cargo'] === 'Líder') $unified[$key]['cargo'] = $cargo;
            } else {
                $unified[$key] = [
                    'nombre' => mb_convert_case(mb_strtolower($nombre), MB_CASE_TITLE, 'UTF-8'),
                    'cargo' => $cargo ?: 'Líder',
                    'telefono' => $telefono ?: null,
                    'direccion' => null,
                    'observacion' => $obs ?: ($partido ? "Partido: $partido" : null),
                ];
                $added2++;
            }
        }
        $this->info("  File 2 LIDERES: $added2 personas nuevas");

        // === FILE 2: DIRECTORIO sheet ===
        $this->info('Leyendo: BARRANCABERMEJA.xlsx → Hoja DIRECTORIO...');
        $sheet3 = IOFactory::load($file2)->getSheetByName('DIRECTORIO');
        // Row 1: A=MUNICIPIO, B=CALIDAD, C=NOMBRE, D=TELEFONO, E=CORREO, F=OBSERVACION
        $added3 = 0;
        for ($r = 2; $r <= $sheet3->getHighestRow(); $r++) {
            $nombre = trim($sheet3->getCell('C' . $r)->getValue() ?? '');
            if (!$nombre) continue;

            $calidad = trim($sheet3->getCell('B' . $r)->getValue() ?? '');
            $telefono = trim($sheet3->getCell('D' . $r)->getValue() ?? '');
            $email = trim($sheet3->getCell('E' . $r)->getValue() ?? '');
            $obs = trim($sheet3->getCell('F' . $r)->getValue() ?? '');
            $telefono = preg_replace('/\s+/', '', $telefono);

            $key = mb_strtoupper($nombre);
            if (!isset($unified[$key])) {
                $unified[$key] = [
                    'nombre' => mb_convert_case(mb_strtolower($nombre), MB_CASE_TITLE, 'UTF-8'),
                    'cargo' => $calidad ?: 'Líder',
                    'telefono' => $telefono ?: null,
                    'email' => $email ?: null,
                    'direccion' => null,
                    'observacion' => $obs ?: null,
                ];
                $added3++;
            } else {
                if (!($unified[$key]['telefono'] ?? null) && $telefono) $unified[$key]['telefono'] = $telefono;
                if ($email) $unified[$key]['email'] = $email;
            }
        }
        $this->info("  File 2 DIRECTORIO: $added3 personas nuevas");

        // === Filter out duplicates already in system ===
        $toInsert = [];
        $skipped = 0;
        foreach ($unified as $key => $row) {
            if (in_array($key, $allExisting)) {
                $skipped++;
                continue;
            }
            $toInsert[] = $row;
        }

        $this->info('');
        $this->info("RESUMEN:");
        $this->info("  Total unificado: " . count($unified));
        $this->info("  Ya en el sistema: $skipped");
        $this->info("  Nuevos a importar: " . count($toInsert));

        if ($dryRun) {
            $this->warn('Modo dry-run: no se insertaron datos.');
            // Show sample
            $this->table(['Nombre', 'Cargo', 'Teléfono', 'Dirección'], array_map(fn ($r) => [
                $r['nombre'], $r['cargo'], $r['telefono'] ?? '—', $r['direccion'] ?? '—',
            ], array_slice($toInsert, 0, 15)));
            return 0;
        }

        if (!$this->confirm("¿Insertar " . count($toInsert) . " líderes de Barrancabermeja?")) {
            return 0;
        }

        $inserted = 0;
        foreach ($toInsert as $row) {
            DB::table('lideres')->insert([
                'id' => Str::uuid(),
                'nombre' => $row['nombre'],
                'municipio' => 'Barrancabermeja',
                'provincia' => 'Mares',
                'cargo' => $row['cargo'],
                'tipo' => 'directorio',
                'telefono' => $row['telefono'] ?? null,
                'email' => $row['email'] ?? null,
                'direccion' => $row['direccion'] ?? null,
                'barrio' => null,
                'zona' => null,
                'observacion' => $row['observacion'] ? mb_substr($row['observacion'], 0, 490) : null,
                'geographic_unit_id' => $geoId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $inserted++;
        }

        $this->info("Importados: $inserted líderes de Barrancabermeja.");
        return 0;
    }

    private function normalizeCargo(string $cargo): string
    {
        $cargo = trim($cargo);
        $upper = mb_strtoupper($cargo);

        // Map common variations
        $map = [
            'CONCEJAL' => 'Concejal',
            'CANDIDATO CONCEJO' => 'Concejal',
            'ALCALDE' => 'Candidato Alcaldía',
            'EDIL' => 'Líder',
            'PRESIDENTE' => 'Líder',
            'SECRETARIA' => 'Líder',
            'TESORERA' => 'Líder',
            'FISCAL JAC' => 'Líder',
            'FISCAL' => 'Líder',
            'JUNTA ACCIÓN COMUNAL' => 'Líder',
            'JUNTA ACCION COMUNAL' => 'Líder',
            'JAC' => 'Líder',
            'DIRECTORIO MUNICIPAL' => 'Líder',
            'DIRECTORIO' => 'Líder',
            'EDIL' => 'Líder',
            'CANDIDATO ALCALD' => 'Candidato Alcaldía',
            'NOS PIDIO' => 'Líder',
            'COOR P' => 'COORDINADOR MUNICIPAL',
            'COORDINADOR' => 'COORDINADOR MUNICIPAL',
            'MIEMBRO DE LA SOCIEDAD CIVIL' => 'Líder',
            'REPRESENTANTE DE JOVES' => 'REPRESENTANTE JOVENES',
            'REPRESENTANTES SOCIEDAD CIVIL' => 'Líder',
            'DELEGADA DE JUVENTUDES' => 'REPRESENTANTE JOVENES',
        ];

        foreach ($map as $pattern => $replacement) {
            if (str_contains($upper, $pattern)) {
                return $replacement;
            }
        }

        // If cargo looks like an observation (long text), default to Líder
        if (mb_strlen($cargo) > 30 || is_numeric($cargo)) {
            return 'Líder';
        }

        return $cargo ?: 'Líder';
    }
}
