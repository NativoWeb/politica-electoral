<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

class ImportLandazuriYariguies extends Command
{
    protected $signature = 'import:landazuri-yariguies {--path= : Base path for Excel files}';
    protected $description = 'Import líderes from LANDAZURY, LANDAZURI RESUMEN & DIRECTORIOS YARIGUIES CD';

    private array $municipios = [];
    private int $inserted = 0;
    private int $updated = 0;
    private int $skipped = 0;
    private string $basePath;

    public function handle(): int
    {
        $this->basePath = $this->option('path')
            ?: (PHP_OS_FAMILY === 'Windows'
                ? 'C:\\Users\\Marly Rangel\\Downloads\\'
                : '/tmp/');

        $this->municipios = DB::table('geographic_units')
            ->where('type', 'municipality')
            ->pluck('id', 'canonical_name')
            ->mapWithKeys(fn ($id, $name) => [Str::upper(Str::ascii($name)) => $id])
            ->toArray();

        $this->importYariguiesCD();
        $this->importLandazuriResumen();
        $this->importLandazuryExcel();

        $total = $this->inserted + $this->updated + $this->skipped;
        $this->newLine();
        $this->info("=== RESUMEN FINAL ===");
        $this->info("Procesados: {$total} | Insertados: {$this->inserted} | Actualizados: {$this->updated} | Saltados: {$this->skipped}");

        return 0;
    }

    private function importYariguiesCD(): void
    {
        $file = $this->basePath . (PHP_OS_FAMILY === 'Windows' ? 'DIRECTORIOS YARIGUIES CD.xlsx' : 'DIRECTORIOS_YARIGUIES_CD.xlsx');
        if (! file_exists($file)) {
            $this->error("No encontrado: {$file}");
            return;
        }

        $this->info('=== DIRECTORIOS YARIGUIES CD ===');
        $reader = IOFactory::createReaderForFile($file);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($file);
        $ws = $spreadsheet->getSheetByName('Hoja1');
        if (! $ws) {
            $this->error('Hoja "Hoja1" no encontrada');
            return;
        }

        $data = $ws->toArray(null, true, true, true);
        $counts = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($data as $rowNum => $row) {
            if ($rowNum <= 2) continue;
            $nombre = trim($row['D'] ?? '');
            $municipio = trim($row['B'] ?? '');
            if (! $nombre || ! $municipio) continue;

            $cargo = $this->normalizeCargo(trim($row['E'] ?? 'MIEMBRO DIRECTORIO MUNICIPAL'));
            $cedula = $this->cleanCedula($row['C'] ?? '');

            $result = $this->upsertLider([
                'nombre' => $nombre,
                'provincia' => trim($row['A'] ?? '') ?: 'YARIGUIES',
                'municipio' => $municipio,
                'cedula' => $cedula,
                'cargo' => $cargo,
                'tipo' => $this->inferTipo($cargo),
                'telefono' => $this->cleanPhone($row['G'] ?? ''),
                'email' => trim($row['H'] ?? '') ?: null,
                'partido' => 'CENTRO DEMOCRATICO',
                'geographic_unit_id' => $this->findMunicipio($municipio),
            ]);
            $counts[$result]++;
        }

        $this->printCounts('Yariguíes CD', $counts);
        $spreadsheet->disconnectWorksheets();
    }

    private function importLandazuriResumen(): void
    {
        $file = $this->basePath . (PHP_OS_FAMILY === 'Windows' ? 'MUNICIPIO LANDAZURI 2026 RESUMEN.xlsx' : 'MUNICIPIO_LANDAZURI_2026_RESUMEN.xlsx');
        if (! file_exists($file)) {
            $this->error("No encontrado: {$file}");
            return;
        }

        $this->info('=== MUNICIPIO LANDAZURI RESUMEN ===');
        $reader = IOFactory::createReaderForFile($file);
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new class implements IReadFilter {
            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                return $row <= 100;
            }
        });
        $spreadsheet = $reader->load($file);
        $ws = $spreadsheet->getSheetByName('LANDAZURI');
        if (! $ws) {
            $this->error('Hoja "LANDAZURI" no encontrada');
            return;
        }

        $data = $ws->toArray(null, true, true, true);
        $counts = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
        $munId = $this->findMunicipio('LANDAZURI');

        // Row 13: Coordinador
        if (! empty($data[13])) {
            $row = $data[13];
            $nombre = trim($row['E'] ?? '');
            if ($nombre) {
                $result = $this->upsertLider([
                    'nombre' => $nombre,
                    'provincia' => 'YARIGUIES',
                    'municipio' => 'LANDAZURI',
                    'cedula' => $this->cleanCedula($row['D'] ?? ''),
                    'cargo' => 'COORDINADOR MUNICIPAL',
                    'tipo' => 'directorio',
                    'telefono' => $this->cleanPhone($row['H'] ?? ''),
                    'email' => trim($row['I'] ?? '') ?: null,
                    'partido' => 'CENTRO DEMOCRATICO',
                    'observacion' => trim($row['F'] ?? '') ?: null,
                    'geographic_unit_id' => $munId,
                ]);
                $counts[$result]++;
            }
        }

        // Rows 18-24: Directorio Municipal
        for ($rowNum = 18; $rowNum <= 24; $rowNum++) {
            if (empty($data[$rowNum])) continue;
            $row = $data[$rowNum];
            $nombre = trim($row['E'] ?? '');
            if (! $nombre) continue;

            $cargo = $this->normalizeCargo(trim($row['F'] ?? 'MIEMBRO DIRECTORIO MUNICIPAL'));

            $result = $this->upsertLider([
                'nombre' => $nombre,
                'provincia' => 'YARIGUIES',
                'municipio' => 'LANDAZURI',
                'cedula' => $this->cleanCedula($row['D'] ?? ''),
                'cargo' => $cargo,
                'tipo' => $this->inferTipo($cargo),
                'telefono' => $this->cleanPhone($row['H'] ?? ''),
                'email' => trim($row['I'] ?? '') ?: null,
                'partido' => 'CENTRO DEMOCRATICO',
                'geographic_unit_id' => $munId,
            ]);
            $counts[$result]++;
        }

        $this->printCounts('Landázuri Resumen', $counts);
        $spreadsheet->disconnectWorksheets();
    }

    private function importLandazuryExcel(): void
    {
        $file = $this->basePath . 'LANDAZURY.xlsx';
        if (! file_exists($file)) {
            $this->error("No encontrado: {$file}");
            return;
        }

        $this->info('=== LANDAZURY.xlsx ===');
        $reader = IOFactory::createReaderForFile($file);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly(['LIDERES', 'directorio', 'ALCALDIA', 'CONCEJOS 2024']);
        $spreadsheet = $reader->load($file);

        $this->importLideresSheet($spreadsheet);
        $this->importDirectorioSheet($spreadsheet);
        $this->importAlcaldiaSheet($spreadsheet);
        $this->importConcejosSheet($spreadsheet);

        $spreadsheet->disconnectWorksheets();
    }

    private function importLideresSheet($spreadsheet): void
    {
        $ws = $spreadsheet->getSheetByName('LIDERES');
        if (! $ws) return;

        $data = $ws->toArray(null, true, true, true);
        $counts = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
        $munId = $this->findMunicipio('LANDAZURI');

        foreach ($data as $rowNum => $row) {
            if ($rowNum <= 1) continue;
            $nombre = trim($row['D'] ?? '');
            if (! $nombre) continue;

            $cargo = trim($row['E'] ?? 'LIDER');
            $cargoNorm = $this->normalizeCargo($cargo);

            $result = $this->upsertLider([
                'nombre' => $nombre,
                'provincia' => 'YARIGUIES',
                'municipio' => trim($row['B'] ?? 'LANDAZURI') ?: 'LANDAZURI',
                'cedula' => $this->cleanCedula($row['C'] ?? ''),
                'cargo' => $cargoNorm,
                'tipo' => $this->inferTipo($cargoNorm),
                'telefono' => $this->cleanPhone($row['F'] ?? ''),
                'votos' => $this->cleanVotos($row['G'] ?? ''),
                'partido' => trim($row['H'] ?? '') ?: null,
                'observacion' => trim($row['I'] ?? '') ?: null,
                'geographic_unit_id' => $munId,
            ]);
            $counts[$result]++;
        }

        $this->printCounts('LIDERES', $counts);
    }

    private function importDirectorioSheet($spreadsheet): void
    {
        $ws = $spreadsheet->getSheetByName('directorio');
        if (! $ws) return;

        $data = $ws->toArray(null, true, true, true);
        $counts = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
        $munId = $this->findMunicipio('LANDAZURI');

        foreach ($data as $rowNum => $row) {
            if ($rowNum <= 1) continue;
            $nombre = trim($row['B'] ?? '');
            if (! $nombre) continue;

            $cargo = $this->normalizeCargo(trim($row['C'] ?? 'MIEMBRO DIRECTORIO'));

            $result = $this->upsertLider([
                'nombre' => $nombre,
                'provincia' => 'YARIGUIES',
                'municipio' => 'LANDAZURI',
                'cargo' => $cargo,
                'tipo' => 'directorio',
                'telefono' => $this->cleanPhone($row['D'] ?? ''),
                'partido' => 'CENTRO DEMOCRATICO',
                'geographic_unit_id' => $munId,
            ]);
            $counts[$result]++;
        }

        $this->printCounts('directorio', $counts);
    }

    private function importAlcaldiaSheet($spreadsheet): void
    {
        $ws = $spreadsheet->getSheetByName('ALCALDIA');
        if (! $ws) return;

        $data = $ws->toArray(null, true, true, true);
        $counts = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
        $munId = $this->findMunicipio('LANDAZURI');

        foreach ($data as $rowNum => $row) {
            if ($rowNum <= 1) continue;
            $nombre = trim($row['A'] ?? '');
            if (! $nombre) continue;

            $cargoRaw = Str::upper(trim($row['C'] ?? ''));
            $cargo = $cargoRaw === 'ELECTO' ? 'ALCALDE ELECTO' : 'CANDIDATO ALCALDÍA';

            $result = $this->upsertLider([
                'nombre' => $nombre,
                'provincia' => 'YARIGUIES',
                'municipio' => 'LANDAZURI',
                'cargo' => $cargo,
                'tipo' => 'candidato',
                'telefono' => $this->cleanPhone($row['D'] ?? ''),
                'votos' => $this->cleanVotos($row['B'] ?? ''),
                'partido' => trim($row['E'] ?? '') ?: null,
                'geographic_unit_id' => $munId,
            ]);
            $counts[$result]++;
        }

        $this->printCounts('ALCALDIA', $counts);
    }

    private function importConcejosSheet($spreadsheet): void
    {
        $ws = $spreadsheet->getSheetByName('CONCEJOS 2024');
        if (! $ws) return;

        $data = $ws->toArray(null, true, true, true);
        $counts = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($data as $rowNum => $row) {
            if ($rowNum <= 1) continue;
            $nombre = trim($row['E'] ?? '');
            $municipio = trim($row['B'] ?? '');
            if (! $nombre || ! $municipio) continue;

            $cargoRaw = Str::upper(trim($row['F'] ?? ''));
            $cargo = str_contains($cargoRaw, 'ELECTO') ? 'CONCEJAL ELECTO' : 'CONCEJAL';

            $result = $this->upsertLider([
                'nombre' => $nombre,
                'provincia' => trim($row['A'] ?? '') ?: 'YARIGUIES',
                'municipio' => $municipio,
                'cargo' => $cargo,
                'tipo' => 'concejo',
                'telefono' => $this->cleanPhone($row['G'] ?? ''),
                'votos' => $this->cleanVotos($row['D'] ?? ''),
                'partido' => trim($row['C'] ?? '') ?: null,
                'geographic_unit_id' => $this->findMunicipio($municipio),
            ]);
            $counts[$result]++;
        }

        $this->printCounts('CONCEJOS 2024', $counts);
    }

    private function upsertLider(array $data): string
    {
        $cedula = trim($data['cedula'] ?? '');
        $nombre = trim($data['nombre'] ?? '');
        $municipio = trim($data['municipio'] ?? '');

        if (! $nombre) return 'skipped';

        // 1. By cédula
        if ($cedula) {
            $existing = DB::table('lideres')->where('cedula', $cedula)->first();
            if ($existing) {
                $updates = [];
                foreach (['telefono', 'email', 'partido', 'votos', 'observacion', 'provincia', 'geographic_unit_id'] as $field) {
                    if (! empty($data[$field]) && empty($existing->$field)) {
                        $updates[$field] = $data[$field];
                    }
                }
                if ($updates) {
                    $updates['updated_at'] = now();
                    DB::table('lideres')->where('id', $existing->id)->update($updates);
                    $this->updated++;
                    return 'updated';
                }
                $this->skipped++;
                return 'skipped';
            }
        }

        // 2. By name+municipio (normalized)
        $normalizedName = Str::upper(Str::ascii(Str::title(Str::lower($nombre))));
        $normalizedMun = Str::upper(Str::ascii($municipio));
        $existing = DB::table('lideres')
            ->whereRaw("UPPER(UNACCENT(nombre)) = ?", [$normalizedName])
            ->whereRaw("UPPER(UNACCENT(municipio)) = ?", [$normalizedMun])
            ->first();

        if ($existing) {
            $updates = [];
            if ($cedula && ! $existing->cedula) {
                $updates['cedula'] = $cedula;
            }
            foreach (['telefono', 'email', 'partido', 'votos', 'observacion', 'provincia', 'geographic_unit_id'] as $field) {
                if (! empty($data[$field]) && empty($existing->$field)) {
                    $updates[$field] = $data[$field];
                }
            }
            if ($updates) {
                $updates['updated_at'] = now();
                DB::table('lideres')->where('id', $existing->id)->update($updates);
                $this->updated++;
                return 'updated';
            }
            $this->skipped++;
            return 'skipped';
        }

        // 3. Insert
        DB::table('lideres')->insert([
            'id' => Str::uuid(),
            'nombre' => Str::title(Str::lower($nombre)),
            'provincia' => ! empty($data['provincia']) ? Str::upper(trim($data['provincia'])) : null,
            'municipio' => $municipio ? Str::title(Str::lower($municipio)) : null,
            'cargo' => $data['cargo'] ?? null,
            'tipo' => $data['tipo'] ?? 'lider',
            'telefono' => $data['telefono'] ?? null,
            'cedula' => $cedula ?: null,
            'email' => $data['email'] ?? null,
            'observacion' => $data['observacion'] ?? null,
            'votos' => ! empty($data['votos']) ? (int) $data['votos'] : null,
            'partido' => $data['partido'] ?? null,
            'geographic_unit_id' => $data['geographic_unit_id'] ?? null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->inserted++;
        return 'inserted';
    }

    private function normalizeCargo(string $cargo): string
    {
        $c = Str::upper(trim($cargo));
        return match (true) {
            str_contains($c, 'EXCONCEJAL') || str_contains($c, 'EX-CONCEJAL') || str_contains($c, 'EX CONCEJAL') || str_contains($c, 'EX -CONCEJAL') => 'EXCONCEJAL',
            str_contains($c, 'CONCEJAL') && str_contains($c, 'ELECTO') => 'CONCEJAL ELECTO',
            str_contains($c, 'CCONCEJO CD') || str_contains($c, 'CONCEJAL CD') => 'CONCEJAL CD',
            str_contains($c, 'CONCEJAL') => 'CONCEJAL',
            str_contains($c, 'DIRECTORIO') => 'MIEMBRO DIRECTORIO',
            str_contains($c, 'JOVEN') => 'REPRESENTANTE JOVENES',
            str_contains($c, 'RESERVA') => 'REPRESENTANTE RESERVA',
            str_contains($c, 'COORDINADOR') => 'COORDINADOR MUNICIPAL',
            str_contains($c, 'ALCALD') && str_contains($c, 'ELECTO') => 'ALCALDE ELECTO',
            str_contains($c, 'ALCALD') && str_contains($c, 'CANDIDAT') => 'CANDIDATO ALCALDÍA',
            str_contains($c, 'EX ALCALDE') || str_contains($c, 'EXALCALDE') || str_contains($c, 'EX-ALCALDE') => 'EX ALCALDE',
            str_contains($c, 'ALCALDE') => 'ALCALDE',
            str_contains($c, 'VEEDOR') => 'VEEDOR',
            str_contains($c, 'PRESIDENTE JAC') || str_contains($c, 'PTE JAC') => 'PRESIDENTE JAC',
            $c === 'LIDER' || $c === 'LÍDER' => 'LIDER',
            default => $cargo,
        };
    }

    private function inferTipo(string $cargo): string
    {
        $c = Str::upper($cargo);
        return match (true) {
            str_contains($c, 'CONCEJAL') && ! str_contains($c, 'EX') => 'concejo',
            str_contains($c, 'DIRECTORIO') || str_contains($c, 'COORDINADOR') || str_contains($c, 'JOVEN') || str_contains($c, 'RESERVA') => 'directorio',
            str_contains($c, 'ALCALD') || str_contains($c, 'CANDIDAT') => 'candidato',
            default => 'lider',
        };
    }

    private function findMunicipio(string $name): ?string
    {
        $key = Str::upper(Str::ascii(trim($name)));
        if (isset($this->municipios[$key])) return $this->municipios[$key];
        foreach ($this->municipios as $k => $id) {
            if (str_contains($k, $key) || str_contains($key, $k)) return $id;
        }
        return null;
    }

    private function cleanCedula($val): string
    {
        $s = trim((string) ($val ?? ''));
        $s = preg_replace('/[^0-9]/', '', $s);
        return $s ?: '';
    }

    private function cleanPhone($val): ?string
    {
        $s = trim((string) ($val ?? ''));
        $s = preg_replace('/[^0-9+]/', '', $s);
        return $s ?: null;
    }

    private function cleanVotos($val): ?int
    {
        $s = trim((string) ($val ?? ''));
        $n = (int) preg_replace('/[^0-9]/', '', $s);
        return $n > 0 ? $n : null;
    }

    private function printCounts(string $source, array $counts): void
    {
        $total = array_sum($counts);
        $this->info("  {$source}: {$total} procesados → {$counts['inserted']} insertados, {$counts['updated']} actualizados, {$counts['skipped']} saltados");
    }
}
