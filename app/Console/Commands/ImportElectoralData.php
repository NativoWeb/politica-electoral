<?php

namespace App\Console\Commands;

use App\Models\SourceFile;
use App\Services\Import\AlcaldiaImporter;
use App\Services\Import\ConcejoImporter;
use App\Services\Import\GobernacionAsambleaImporter;
use App\Services\Import\SenadoCamaraImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportElectoralData extends Command
{
    protected $signature = 'import:electoral
        {--source-dir= : Directory containing Excel files (default: Downloads/Panel Electoral)}
        {--only= : Import only specific dataset: alcaldia,concejo,gobernacion-asamblea,senado-camara}
        {--fresh : Clear all electoral data before importing}';

    protected $description = 'Import electoral data from Excel files into the canonical schema';

    public function handle(): int
    {
        ini_set('memory_limit', '512M');

        $sourceDir = $this->option('source-dir')
            ?: 'C:\\Users\\Marly Rangel\\Downloads\\Panel Electoral';

        $file1 = $sourceDir . DIRECTORY_SEPARATOR . '1-resultados alcaldias, concejos, ok.xlsx';
        $file2 = $sourceDir . DIRECTORY_SEPARATOR . '1-CAMARA - SENADO 2026 POR MPIO.xlsx';

        if (!file_exists($file1)) {
            $this->error("Archivo no encontrado: {$file1}");
            return self::FAILURE;
        }
        if (!file_exists($file2)) {
            $this->error("Archivo no encontrado: {$file2}");
            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            if ($this->confirm('Esto eliminará TODOS los datos electorales. ¿Continuar?')) {
                $this->clearElectoralData();
                $this->info('Datos electorales limpiados.');
            }
        }

        $only = $this->option('only');
        $datasets = $only ? explode(',', $only) : ['alcaldia', 'concejo', 'gobernacion-asamblea', 'senado-camara'];

        // Register source files
        $sourceFile1 = $this->registerSourceFile($file1);
        $sourceFile2 = $this->registerSourceFile($file2);

        $totalStart = microtime(true);

        // 1. Alcaldías
        if (in_array('alcaldia', $datasets)) {
            $this->info('');
            $this->info('=== Importando Alcaldías ===');
            $start = microtime(true);

            $importer = new AlcaldiaImporter();
            $stats = $importer->import($file1, $sourceFile1);

            $elapsed = round(microtime(true) - $start, 1);
            $this->printStats('Alcaldías', $stats, $elapsed);
        }

        // 2. Concejos
        if (in_array('concejo', $datasets)) {
            $this->info('');
            $this->info('=== Importando Concejos ===');
            $start = microtime(true);

            $importer = new ConcejoImporter();
            $stats = $importer->import($file1, $sourceFile1);

            $elapsed = round(microtime(true) - $start, 1);
            $this->printStats('Concejos', $stats, $elapsed);
        }

        // 3. Gobernación/Asamblea
        if (in_array('gobernacion-asamblea', $datasets)) {
            $this->info('');
            $this->info('=== Importando Gobernación y Asamblea ===');
            $start = microtime(true);

            $importer = new GobernacionAsambleaImporter();
            $stats = $importer->import($file1, $sourceFile1);

            $elapsed = round(microtime(true) - $start, 1);
            $this->printStats('Gobernación/Asamblea', $stats, $elapsed);
        }

        // 4. Senado/Cámara/Consultas
        if (in_array('senado-camara', $datasets)) {
            $this->info('');
            $this->info('=== Importando Senado, Cámara y Consultas 2026 ===');
            $start = microtime(true);

            $importer = new SenadoCamaraImporter();
            $stats = $importer->import($file2, $sourceFile2);

            $elapsed = round(microtime(true) - $start, 1);
            $this->printStats('Senado/Cámara/Consultas', $stats, $elapsed);
        }

        $totalElapsed = round(microtime(true) - $totalStart, 1);
        $this->info('');
        $this->info("=== Importación completada en {$totalElapsed}s ===");
        $this->printSummary();

        return self::SUCCESS;
    }

    private function registerSourceFile(string $filePath): SourceFile
    {
        $hash = hash_file('sha256', $filePath);

        return SourceFile::firstOrCreate(
            ['sha256_hash' => $hash],
            [
                'original_name' => basename($filePath),
                'storage_path' => $filePath,
                'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'size_bytes' => filesize($filePath),
                'file_type' => 'xlsx',
            ]
        );
    }

    private function printStats(string $label, array $stats, float $elapsed): void
    {
        $this->info("  Completado en {$elapsed}s");

        foreach ($stats as $key => $value) {
            if ($key === 'errors') {
                if (!empty($value)) {
                    $this->warn("  Errores: " . count($value));
                    foreach (array_slice($value, 0, 10) as $err) {
                        $this->warn("    - {$err}");
                    }
                    if (count($value) > 10) {
                        $this->warn("    ... y " . (count($value) - 10) . " más");
                    }
                }
            } else {
                $this->line("  {$key}: {$value}");
            }
        }
    }

    private function printSummary(): void
    {
        $this->info('--- Resumen de la base de datos ---');
        $this->table(
            ['Entidad', 'Total'],
            [
                ['Personas', DB::table('persons')->count()],
                ['Candidaturas', DB::table('candidacies')->count()],
                ['Resultados electorales', DB::table('electoral_results')->count()],
                ['Eventos electorales', DB::table('electoral_events')->count()],
                ['Contiendas', DB::table('contests')->count()],
                ['Organizaciones políticas', DB::table('political_organizations')->count()],
                ['Avales/endorsements', DB::table('candidacy_endorsements')->count()],
                ['Municipios', DB::table('geographic_units')->where('type', 'municipality')->count()],
                ['Cargos ejecutivos', DB::table('office_tenures')->count()],
                ['Contactos', DB::table('person_contact_points')->count()],
            ]
        );
    }

    private function clearElectoralData(): void
    {
        // Order matters due to foreign keys
        DB::table('electoral_results')->truncate();
        DB::table('turnout_metrics')->truncate();
        DB::table('candidacy_endorsements')->truncate();
        DB::table('office_tenures')->truncate();
        DB::table('organization_memberships')->truncate();
        DB::table('candidacies')->truncate();
        DB::table('electoral_lists')->truncate();
        DB::table('contests')->truncate();
        DB::table('electoral_events')->truncate();
        DB::table('coalition_members')->truncate();
        DB::table('coalitions')->truncate();
        DB::table('person_contact_points')->truncate();
        DB::table('person_facts')->truncate();
        DB::table('person_aliases')->truncate();
        DB::table('relationships')->truncate();
        DB::table('person_merge_transactions')->truncate();
        DB::table('organization_aliases')->truncate();
        DB::table('source_records')->truncate();
        DB::table('import_staging_rows')->truncate();
        DB::table('import_jobs')->truncate();
        DB::table('source_files')->truncate();

        // Delete persons and orgs (but keep geographic data and catalogs)
        DB::table('persons')->delete();
        DB::table('political_organizations')->delete();
    }
}
