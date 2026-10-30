<?php

namespace App\Console\Commands;

use App\Console\Concerns\TargetsCampaign;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

// Full load of one campaign's lideres. It never touches other campaigns, and it only deletes the campaign's
// current lideres with --replace after an explicit confirmation (or --force).
class ImportLideres extends Command
{
    use TargetsCampaign;

    protected $signature = 'import:lideres
        {file? : Ruta al Excel; por defecto el de Descargas}
        {--campaign= : Campaña (slug o id); por defecto Centro Democrático}
        {--replace : Borra antes los líderes de ESA campaña (pide confirmación)}
        {--force : Con --replace, no pide confirmación}';
    protected $description = 'Import líderes from DIRECTORIOS MUNICIPALES CD Excel';

    public function handle(): int
    {
        $file = $this->argument('file') ?: 'C:\\Users\\Marly Rangel\\Downloads\\Panel Electoral\\DIRECTORIOS MUNICIPALES CD (1).xlsx';

        if (!file_exists($file)) {
            $this->error("File not found: {$file}");
            return 1;
        }

        if (! $this->resolveCampaign()) {
            return 1;
        }

        $existing = $this->lideres()->count();
        if ($existing > 0 && ! $this->option('replace')) {
            $this->error("La campaña ya tiene {$existing} líderes. Usa --replace para reemplazarlos o import:directorio-cd para una carga incremental.");

            return 1;
        }
        if ($existing > 0 && ! $this->option('force')
            && ! $this->confirm("Se borrarán los {$existing} líderes de esta campaña (y solo de esta). ¿Continuar?")) {
            $this->warn('Cancelado: no se borró ni importó nada.');

            return 1;
        }

        return DB::transaction(fn () => $this->import($file));
    }

    private function import(string $file): int
    {
        $deleted = $this->lideres()->delete();
        if ($deleted) {
            $this->warn("Borrados {$deleted} líderes de la campaña.");
        }

        $reader = IOFactory::createReaderForFile($file);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($file);

        $imported = 0;

        // Geographic units for matching
        $municipios = DB::table('geographic_units')
            ->where('type', 'municipality')
            ->pluck('id', 'canonical_name')
            ->mapWithKeys(fn ($id, $name) => [Str::upper(Str::ascii($name)) => $id])
            ->toArray();

        // Sheet: COORD Y DIRECTORIO (most complete — has email)
        $ws = $spreadsheet->getSheetByName('COORD Y DIRECTORIO');
        if ($ws) {
            $rows = $ws->toArray(null, true, true, true);
            foreach ($rows as $i => $row) {
                if ($i <= 2) continue; // skip headers
                $nombre = trim($row['D'] ?? '');
                $municipio = trim($row['C'] ?? '');
                if (!$nombre || !$municipio) continue;

                $provincia = trim($row['B'] ?? '');
                $cargo = trim($row['E'] ?? 'MIEMBRO DIRECTORIO MUNICIPAL');
                $telefono = trim($row['F'] ?? '');
                $cedula = trim($row['G'] ?? '');
                $email = trim($row['H'] ?? '');

                // Normalize cargo
                $cargo = $this->normalizeCargo($cargo);

                // Match municipio
                $munKey = Str::upper(Str::ascii($municipio));
                $geoId = $municipios[$munKey] ?? null;

                DB::table('lideres')->insert([
                    'id' => Str::uuid(),
                    'campaign_id' => $this->campaignId,
                    'nombre' => Str::title(Str::lower($nombre)),
                    'provincia' => $provincia ?: null,
                    'municipio' => Str::title(Str::lower($municipio)),
                    'cargo' => $cargo,
                    'tipo' => 'directorio',
                    'telefono' => $telefono ?: null,
                    'cedula' => $cedula ?: null,
                    'email' => $email ?: null,
                    'geographic_unit_id' => $geoId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $imported++;
            }
            $this->info("COORD Y DIRECTORIO: {$imported} líderes importados");
        }

        // Sheet: VEEDURIA
        $ws2 = $spreadsheet->getSheetByName('VEEDURIA');
        $veedores = 0;
        if ($ws2) {
            $rows = $ws2->toArray(null, true, true, true);
            foreach ($rows as $i => $row) {
                if ($i <= 1) continue;
                $nombre = trim($row['D'] ?? '');
                $municipio = trim($row['C'] ?? '');
                if (!$nombre || !$municipio) continue;

                $provincia = trim($row['B'] ?? '');
                $cedula = trim($row['E'] ?? '');
                $telefono = trim($row['F'] ?? '');
                $observacion = trim($row['G'] ?? '');

                $munKey = Str::upper(Str::ascii($municipio));
                $geoId = $municipios[$munKey] ?? null;

                DB::table('lideres')->insert([
                    'id' => Str::uuid(),
                    'campaign_id' => $this->campaignId,
                    'nombre' => Str::title(Str::lower($nombre)),
                    'provincia' => $provincia ?: null,
                    'municipio' => Str::title(Str::lower($municipio)),
                    'cargo' => 'VEEDOR',
                    'tipo' => 'veeduria',
                    'telefono' => $telefono ?: null,
                    'cedula' => $cedula ?: null,
                    'observacion' => $observacion ?: null,
                    'geographic_unit_id' => $geoId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $veedores++;
            }
            $this->info("VEEDURIA: {$veedores} veedores importados");
        }

        $this->info("Total: " . ($imported + $veedores) . " registros");
        return 0;
    }

    private function normalizeCargo(string $cargo): string
    {
        $cargo = Str::upper(trim($cargo));
        return match (true) {
            str_contains($cargo, 'CONCEJAL') => 'CONCEJAL CD',
            str_contains($cargo, 'DIRECTORIO') => 'MIEMBRO DIRECTORIO',
            str_contains($cargo, 'JOVEN') => 'REPRESENTANTE JOVENES',
            str_contains($cargo, 'RESERVA') => 'REPRESENTANTE RESERVA',
            str_contains($cargo, 'COORDINADOR') => 'COORDINADOR MUNICIPAL',
            str_contains($cargo, 'ALCALDIA') => 'CANDIDATO ALCALDIA',
            default => $cargo,
        };
    }
}
