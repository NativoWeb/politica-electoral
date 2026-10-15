<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

class ImportNuevosLideres extends Command
{
    protected $signature = 'import:nuevos-lideres {--dry-run : Show what would be imported without writing}';
    protected $description = 'Import líderes from LANDAZURY, MUNICIPIO LANDAZURI 2026 RESUMEN, and DIRECTORIOS YARIGUIES CD';

    private array $municipios = [];
    private int $created = 0;
    private int $enriched = 0;
    private int $skipped = 0;
    private bool $dryRun = false;

    public function handle(): int
    {
        $this->dryRun = $this->option('dry-run');
        if ($this->dryRun) {
            $this->warn('DRY RUN — no data will be written');
        }

        $this->municipios = DB::table('geographic_units')
            ->where('type', 'municipality')
            ->get(['id', 'canonical_name', 'parent_id'])
            ->mapWithKeys(fn ($m) => [Str::upper(Str::ascii($m->canonical_name)) => $m])
            ->toArray();

        $this->importLandazuryLideres();
        $this->importLandazuryDirectorio();
        $this->importLandazuriResumen();
        $this->importDirectoriosYariguies();

        $this->newLine();
        $this->info("=== RESUMEN ===");
        $this->info("Creados:     {$this->created}");
        $this->info("Enriquecidos: {$this->enriched}");
        $this->info("Omitidos:    {$this->skipped}");

        return 0;
    }

    private function findMunicipio(string $name): ?object
    {
        $key = Str::upper(Str::ascii(trim($name)));
        $key = str_replace(['LANDAZURY'], ['LANDAZURI'], $key);

        if (isset($this->municipios[$key])) {
            return $this->municipios[$key];
        }

        foreach ($this->municipios as $k => $m) {
            if (str_contains($k, $key) || str_contains($key, $k)) {
                return $m;
            }
        }

        return null;
    }

    private function findProvinciaName(?string $geoId): ?string
    {
        if (!$geoId) return null;

        $mun = collect($this->municipios)->first(fn ($m) => $m->id === $geoId);
        if (!$mun || !$mun->parent_id) return null;

        return DB::table('geographic_units')->where('id', $mun->parent_id)->value('canonical_name');
    }

    private function normalizeCargo(string $raw): string
    {
        $cargo = trim($raw);
        $upper = Str::upper(Str::ascii($cargo));

        if (str_contains($upper, 'CONCEJAL') && str_contains($upper, 'ELECTO')) return 'Concejal Electo';
        if (str_contains($upper, 'CONCEJAL') && str_contains($upper, 'CD')) return 'Concejal CD';
        if (str_contains($upper, 'CONCEJAL')) return 'Concejal';
        if (str_contains($upper, 'MIEMBRO DIRECTORIO')) return 'Líder';
        if (str_contains($upper, 'REPRESENTANTE')) return 'Líder';
        if (str_contains($upper, 'COORDINADOR')) return 'Líder';
        if (str_contains($upper, 'LIDER') || str_contains($upper, 'LÍDER')) return 'Líder';
        if (str_contains($upper, 'VEEDOR')) return 'Líder';

        return Str::title(Str::lower($cargo));
    }

    private function normalizeTipo(string $cargo): string
    {
        $upper = Str::upper(Str::ascii($cargo));

        if (str_contains($upper, 'CONCEJAL')) return 'concejal';
        if (str_contains($upper, 'DIRECTORIO') || str_contains($upper, 'MIEMBRO')) return 'directorio';
        if (str_contains($upper, 'VEEDOR')) return 'veeduria';

        return 'directorio';
    }

    private function cleanPhone(?string $phone): ?string
    {
        if (!$phone) return null;
        $clean = preg_replace('/[^0-9]/', '', trim($phone));
        return $clean ?: null;
    }

    private function upsertLider(array $data, string $source): void
    {
        $nombre = trim($data['nombre'] ?? '');
        if (!$nombre) return;

        $cedula = trim($data['cedula'] ?? '');
        $municipio = trim($data['municipio'] ?? '');

        $existing = null;

        // Dedup 1: by cédula
        if ($cedula) {
            $existing = DB::table('lideres')
                ->where('cedula', $cedula)
                ->first();
        }

        // Dedup 2: by normalized name + municipio
        if (!$existing) {
            $normalizedName = Str::upper(Str::ascii($nombre));
            $query = DB::table('lideres')
                ->whereRaw("UPPER(unaccent(nombre)) = ?", [$normalizedName]);

            if ($municipio) {
                $query->where('municipio', 'ilike', '%' . $municipio . '%');
            }

            $existing = $query->first();
        }

        if ($existing) {
            $updates = [];
            if (!$existing->cedula && $cedula) $updates['cedula'] = $cedula;
            if (!$existing->telefono && ($data['telefono'] ?? null)) $updates['telefono'] = $data['telefono'];
            if (!$existing->email && ($data['email'] ?? null)) $updates['email'] = $data['email'];
            if (!$existing->votos && ($data['votos'] ?? null)) $updates['votos'] = (int) $data['votos'];
            if (!$existing->partido && ($data['partido'] ?? null)) $updates['partido'] = $data['partido'];
            if (!$existing->observacion && ($data['observacion'] ?? null)) $updates['observacion'] = $data['observacion'];
            if (!$existing->geographic_unit_id && ($data['geographic_unit_id'] ?? null)) $updates['geographic_unit_id'] = $data['geographic_unit_id'];
            if (!$existing->provincia && ($data['provincia'] ?? null)) $updates['provincia'] = $data['provincia'];

            if (count($updates) > 0) {
                $updates['updated_at'] = now();
                if (!$this->dryRun) {
                    DB::table('lideres')->where('id', $existing->id)->update($updates);
                }
                $fields = implode(', ', array_keys($updates));
                $this->line("  <comment>ENRIQUECIDO</comment> [{$source}] {$nombre} — campos: {$fields}");
                $this->enriched++;
            } else {
                $this->skipped++;
            }
            return;
        }

        // Create new
        $cargo = $this->normalizeCargo($data['cargo'] ?? 'Líder');
        $tipo = $this->normalizeTipo($data['cargo'] ?? '');
        $mun = $this->findMunicipio($municipio);
        $provincia = $data['provincia'] ?? ($mun ? $this->findProvinciaName($mun->id) : null);

        $record = [
            'id' => Str::uuid()->toString(),
            'nombre' => Str::title(Str::lower($nombre)),
            'cedula' => $cedula ?: null,
            'municipio' => $mun ? $mun->canonical_name : Str::title(Str::lower($municipio)),
            'provincia' => $provincia,
            'cargo' => $cargo,
            'tipo' => $tipo,
            'telefono' => $data['telefono'] ?? null,
            'email' => $data['email'] ?? null,
            'votos' => ($data['votos'] ?? null) ? (int) $data['votos'] : null,
            'partido' => $data['partido'] ?? null,
            'observacion' => $data['observacion'] ?? null,
            'geographic_unit_id' => $mun?->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (!$this->dryRun) {
            DB::table('lideres')->insert($record);
        }
        $this->line("  <info>CREADO</info> [{$source}] {$nombre} — {$cargo} ({$record['municipio']})");
        $this->created++;
    }

    private function importLandazuryLideres(): void
    {
        $file = 'C:\\Users\\Marly Rangel\\Downloads\\LANDAZURY.xlsx';
        if (!file_exists($file)) {
            $this->error("No encontrado: {$file}");
            return;
        }

        $this->info('=== LANDAZURY.xlsx → LIDERES ===');
        $reader = IOFactory::createReaderForFile($file);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly(['LIDERES']);
        $ss = $reader->load($file);
        $ws = $ss->getActiveSheet();
        $data = $ws->toArray(null, true, true, true);

        foreach ($data as $rn => $row) {
            if ($rn <= 1) continue;
            $nombre = trim($row['D'] ?? '');
            if (!$nombre) continue;

            $this->upsertLider([
                'nombre' => $nombre,
                'municipio' => trim($row['B'] ?? 'LANDAZURI'),
                'cedula' => trim($row['C'] ?? ''),
                'cargo' => trim($row['E'] ?? 'Líder'),
                'telefono' => $this->cleanPhone($row['F'] ?? ''),
                'votos' => $row['G'] ?? null,
                'partido' => trim($row['H'] ?? ''),
                'observacion' => trim($row['I'] ?? ''),
            ], 'LANDAZURY/LIDERES');
        }
    }

    private function importLandazuryDirectorio(): void
    {
        $file = 'C:\\Users\\Marly Rangel\\Downloads\\LANDAZURY.xlsx';
        if (!file_exists($file)) return;

        $this->newLine();
        $this->info('=== LANDAZURY.xlsx → directorio ===');
        $reader = IOFactory::createReaderForFile($file);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly(['directorio']);
        $ss = $reader->load($file);
        $ws = $ss->getActiveSheet();
        $data = $ws->toArray(null, true, true, true);

        foreach ($data as $rn => $row) {
            if ($rn <= 1) continue;
            $nombre = trim($row['B'] ?? '');
            if (!$nombre) continue;

            $this->upsertLider([
                'nombre' => $nombre,
                'municipio' => trim($row['A'] ?? 'LANDAZURI'),
                'cargo' => trim($row['C'] ?? 'Líder'),
                'telefono' => $this->cleanPhone($row['D'] ?? ''),
            ], 'LANDAZURY/directorio');
        }
    }

    private function importLandazuriResumen(): void
    {
        $file = 'C:\\Users\\Marly Rangel\\Downloads\\MUNICIPIO LANDAZURI 2026 RESUMEN.xlsx';
        if (!file_exists($file)) {
            $this->error("No encontrado: {$file}");
            return;
        }

        $this->newLine();
        $this->info('=== MUNICIPIO LANDAZURI 2026 RESUMEN.xlsx ===');

        $reader = IOFactory::createReaderForFile($file);
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new class implements IReadFilter {
            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                return $row <= 100;
            }
        });
        $ss = $reader->load($file);
        $ws = $ss->getActiveSheet();
        $data = $ws->toArray(null, true, true, true);

        foreach ($data as $rn => $row) {
            $nombre = trim($row['E'] ?? '');
            if (!$nombre) continue;
            // Skip header rows
            $upper = Str::upper($nombre);
            if (in_array($upper, ['COORDINADOR', 'OBSERVACION', 'PROYECCION MILITANTES LANDAZURI', 'LANDAZURI'])) continue;

            $cedula = trim($row['D'] ?? '');
            if ($cedula && !is_numeric($cedula)) continue;

            $this->upsertLider([
                'nombre' => $nombre,
                'municipio' => 'LANDAZURI',
                'provincia' => 'Yariguíes',
                'cedula' => $cedula,
                'cargo' => trim($row['F'] ?? 'Líder'),
                'telefono' => $this->cleanPhone($row['H'] ?? ''),
                'email' => trim($row['I'] ?? ''),
            ], 'RESUMEN');
        }
    }

    private function importDirectoriosYariguies(): void
    {
        $file = 'C:\\Users\\Marly Rangel\\Downloads\\DIRECTORIOS YARIGUIES CD.xlsx';
        if (!file_exists($file)) {
            $this->error("No encontrado: {$file}");
            return;
        }

        $this->newLine();
        $this->info('=== DIRECTORIOS YARIGUIES CD.xlsx ===');
        $reader = IOFactory::createReaderForFile($file);
        $reader->setReadDataOnly(true);
        $ss = $reader->load($file);
        $ws = $ss->getActiveSheet();
        $data = $ws->toArray(null, true, true, true);

        foreach ($data as $rn => $row) {
            if ($rn <= 2) continue; // Row 1 = title, Row 2 = header
            $nombre = trim($row['D'] ?? '');
            if (!$nombre) continue;

            $cedula = trim($row['C'] ?? '');
            if ($cedula && !is_numeric($cedula)) continue;

            $this->upsertLider([
                'nombre' => $nombre,
                'municipio' => trim($row['B'] ?? ''),
                'provincia' => trim($row['A'] ?? ''),
                'cedula' => $cedula,
                'cargo' => trim($row['E'] ?? 'Líder'),
                'telefono' => $this->cleanPhone($row['G'] ?? ''),
                'email' => trim($row['H'] ?? ''),
                'partido' => 'Centro Democrático',
            ], 'YARIGUIES');
        }
    }
}
