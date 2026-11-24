<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\Geo\ComunaAssigner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Undoes one batch of geo:asignar-comunas (comuna_cambios.lote): each record goes back to the comuna and origin it had
// before, unless it changed again afterwards (by hand or by a later run), which is left as it is and reported.
class RevertirComunas extends Command
{
    protected $signature = 'geo:revertir-comunas
        {--lote= : Lote que imprimió geo:asignar-comunas}
        {--dry-run : Solo muestra lo que haría, no guarda nada}';

    protected $description = 'Revert one batch of comuna assignments';

    public function handle(): int
    {
        $lote = (string) $this->option('lote');
        if (! Str::isUuid($lote)) {
            $this->error('--lote debe ser el UUID que imprimió geo:asignar-comunas.');

            return self::FAILURE;
        }
        if (! DB::table('comuna_cambios')->where('lote', $lote)->exists()) {
            $this->error("No existe el lote {$lote}.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $counts = ['restaurados' => 0, 'omitidos' => 0, 'ya_restaurados' => 0, 'no_existen' => 0];

        DB::transaction(function () use ($lote, $dryRun, &$counts) {
            DB::table('comuna_cambios')->where('lote', $lote)->orderBy('id')
                ->chunkById(AsignarComunas::PAGE, function ($cambios) use ($dryRun, &$counts) {
                    foreach ($cambios->groupBy('tabla') as $table => $rows) {
                        if (! isset(ComunaAssigner::TABLES[$table])) {
                            throw new \RuntimeException("Tabla desconocida en el lote: {$table}");
                        }
                        $current = DB::table($table)->whereIn('id', $rows->pluck('registro_id')->all())
                            ->when(! $dryRun, fn ($q) => $q->lockForUpdate())
                            ->get(['id', 'comuna_id', 'comuna_source'])->keyBy('id');
                        foreach ($rows as $c) {
                            $now = $current->get($c->registro_id);
                            if (! $now) {
                                $counts['no_existen']++;
                            } elseif ($now->comuna_id === $c->antes_comuna_id && $now->comuna_source === $c->antes_source) {
                                $counts['ya_restaurados']++;
                            } elseif ($now->comuna_id !== $c->despues_comuna_id || $now->comuna_source !== $c->despues_source) {
                                $counts['omitidos']++;
                            } else {
                                $counts['restaurados']++;
                                if (! $dryRun) {
                                    DB::table($table)->where('id', $c->registro_id)
                                        ->where('comuna_source', $c->despues_source)
                                        ->update(['comuna_id' => $c->antes_comuna_id, 'comuna_source' => $c->antes_source]);
                                }
                            }
                        }
                    }
                });

            if (! $dryRun) {
                AuditLog::create([
                    'user_id' => null,
                    'action' => 'geo.revertir_comunas',
                    'entity_type' => 'comuna_lote',
                    'entity_id' => $lote,
                    'metadata' => ['command' => 'geo:revertir-comunas', 'lote' => $lote, 'resumen' => $counts],
                ]);
            }
        });

        $this->table(['Restaurados', 'Omitidos (cambiaron después)', 'Ya estaban como antes', 'Ya no existen'],
            [[$counts['restaurados'], $counts['omitidos'], $counts['ya_restaurados'], $counts['no_existen']]]);
        $dryRun ? $this->warn('DRY-RUN: no se guardó nada.') : $this->info("Lote {$lote} revertido.");

        return self::SUCCESS;
    }
}
