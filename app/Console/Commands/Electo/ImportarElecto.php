<?php

namespace App\Console\Commands\Electo;

use App\Models\AuditLog;
use App\Models\Campaign;
use App\Services\Electo\ElectoImporter;
use App\Services\Electo\ImportContext;
use App\Services\Electo\ImportReport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

// Migrates the CSV export of the previous system "Electo" (docs/migracion-electo.md). Everything goes to the campaign
// given (by default the CD). Idempotent and resumable through electo_import_rows. The report (counts, field names,
// DIVIPOLA codes and Electo ids only, no personal data) is written as JSON under storage/app/electo.
class ImportarElecto extends Command
{
    protected $signature = 'electo:importar
        {carpeta : Carpeta con los CSV exportados de Electo}
        {--dry-run : Hace todo dentro de una transacción que se revierte al final}
        {--solo= : Partes separadas por coma: personas,contactos,mapa,eventos,funcionarios (por defecto todas)}
        {--lote=2000 : Registros por lote}
        {--campana= : Grupo político destino (por defecto el CD)}
        {--reporte= : Ruta del reporte JSON (por defecto storage/app/electo/reporte_<fecha>_<run>.json)}';

    protected $description = 'Importa los CSV del sistema anterior Electo (personas, contactos, mapa político, eventos y funcionarios)';

    public function handle(): int
    {
        $dir = rtrim((string) $this->argument('carpeta'), '/\\');
        if (! is_dir($dir)) {
            $this->error('La carpeta no existe.');

            return self::FAILURE;
        }
        $partes = $this->option('solo') ? array_map('trim', explode(',', (string) $this->option('solo'))) : ElectoImporter::PARTES;
        if ($unknown = array_diff($partes, ElectoImporter::PARTES)) {
            $this->error('Partes desconocidas: '.implode(', ', $unknown).'. Válidas: '.implode(', ', ElectoImporter::PARTES).'.');

            return self::FAILURE;
        }
        $lote = (int) $this->option('lote');
        if ($lote < 100 || $lote > 10000) {
            $this->error('--lote debe estar entre 100 y 10000.');

            return self::FAILURE;
        }
        $campaign = (string) ($this->option('campana') ?: Campaign::DEFAULT_ID);
        if (! Str::isUuid($campaign)) {
            $this->error('--campana debe ser un UUID.');

            return self::FAILURE;
        }

        // One-off CLI import of ~130.000 records kept as small lookup maps; PHP-FPM keeps its own limit.
        if ($this->memoryLimitBytes() < 1024 * 1024 * 1024) {
            ini_set('memory_limit', '1024M');
        }

        $dryRun = (bool) $this->option('dry-run');
        $report = new ImportReport((string) Str::uuid(), $dryRun, $partes);
        $ctx = new ImportContext($dir, $campaign, $lote, $dryRun, $report, CarbonImmutable::today(), fn (string $m) => $this->line($m));
        $importer = new ElectoImporter($ctx);

        if ($errors = $importer->preflight($partes)) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $start = microtime(true);
        try {
            $importer->run($partes);
        } catch (\Throwable $e) {
            $report->aviso('Interrumpido: '.class_basename($e).'. Lo ya confirmado se salta al volver a correr.');
            $this->writeReport($report, $start);
            // Database messages carry the row values (personal data): only the SQLSTATE is shown.
            $this->error('La importación se detuvo: '.($e instanceof QueryException ? 'error de base de datos SQLSTATE '.$e->getCode() : $e->getMessage()));

            return self::FAILURE;
        }
        $path = $this->writeReport($report, $start);

        if (! $dryRun) {
            AuditLog::create([
                'user_id' => null, 'action' => 'electo.importar', 'entity_type' => 'campaign', 'entity_id' => $campaign,
                'metadata' => ['command' => 'electo:importar', 'run_id' => $report->runId, 'partes' => $partes, 'reporte' => basename($path)],
            ]);
        }

        $this->summary($report);
        $this->info(($dryRun ? 'DRY-RUN (nada guardado). ' : '')."Reporte: {$path}");

        return self::SUCCESS;
    }

    private function memoryLimitBytes(): int
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }
        $value = (int) $limit;

        return match (strtolower(substr($limit, -1))) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => $value,
        };
    }

    private function writeReport(ImportReport $report, float $start): string
    {
        $report->tiempos['total'] = round(microtime(true) - $start, 1);
        $report->tiempos['memoria_pico_mb'] = round(memory_get_peak_usage(true) / 1024 / 1024);
        $path = $this->option('reporte')
            ?: storage_path('app/electo/reporte_'.now()->format('Ymd_His').'_'.substr($report->runId, 0, 8).($report->dryRun ? '_dryrun' : '').'.json');
        $report->write($path);

        return $path;
    }

    private function summary(ImportReport $report): void
    {
        $rows = [];
        foreach ($report->counts as $entidad => $counts) {
            ksort($counts);
            foreach ($counts as $accion => $n) {
                $rows[] = [$entidad, $accion, $n];
            }
        }
        $this->table(['Entidad', 'Acción', 'Cantidad'], $rows);
        if ($report->contradicciones) {
            $this->table(['Campo en contradicción', 'Fichas'], collect($report->contradicciones)->map(fn ($n, $k) => [$k, $n])->values()->all());
        }
        foreach ($report->avisos as $aviso) {
            $this->warn($aviso);
        }
    }
}
