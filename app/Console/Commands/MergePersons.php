<?php

namespace App\Console\Commands;

use App\Actions\Electoral\MergePersons as MergePersonsAction;
use App\Services\CachedQueries;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Two persons rows that are the same human: see App\Actions\Electoral\MergePersons for what moves and what aborts.
// A re-import of the Gobernación sheet of the Excel (short header names such as "FERLEY PARRA") must be followed by
// data:gobernacion-2023-totales again, as before any merge: that name matches no person and the import creates one.
class MergePersons extends Command
{
    protected $signature = 'data:merge-persons
        {survivor : id de la persona que queda}
        {absorbed : id de la persona que se absorbe}
        {--reason= : Motivo de la fusión (sin datos personales)}
        {--dry-run : Solo muestra el plan, no guarda nada}';

    protected $description = 'Merge two persons that are the same human: every reference moves to the survivor';

    public function handle(MergePersonsAction $merger): int
    {
        $survivorId = (string) $this->argument('survivor');
        $absorbedId = (string) $this->argument('absorbed');
        $dryRun = (bool) $this->option('dry-run');

        if (! Str::isUuid($survivorId) || ! Str::isUuid($absorbedId)) {
            $this->error('Los dos ids deben ser UUID.');

            return self::FAILURE;
        }
        if ($survivorId === $absorbedId) {
            $this->error('El superviviente y el absorbido son la misma persona.');

            return self::FAILURE;
        }

        DB::beginTransaction();
        try {
            if ($dryRun && DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION READ ONLY');
            }

            [$survivor, $absorbed] = $merger->load($survivorId, $absorbedId, ! $dryRun);

            if (! $survivor || ! $absorbed) {
                DB::rollBack();
                $this->error('No existe la persona '.(! $survivor ? "superviviente {$survivorId}" : "absorbida {$absorbedId}").'.');

                return self::FAILURE;
            }
            if ($absorbed->merged_into_id === $survivorId) {
                DB::rollBack();
                $this->info('Ya fusionadas: nada que hacer.');

                return self::SUCCESS;
            }
            $blocked = $merger->blockedState($survivor, 'superviviente') ?? $merger->blockedState($absorbed, 'absorbida');
            if ($blocked) {
                DB::rollBack();
                $this->error($blocked);

                return self::FAILURE;
            }

            $plan = $merger->plan($survivor, $absorbed);
            $this->printPlan($merger, $survivor, $absorbed, $plan);

            if ($plan['conflicts']) {
                DB::rollBack();
                foreach ($plan['conflicts'] as $conflict) {
                    $this->error($conflict);
                }
                $this->error('Fusión abortada: no se guardó nada.');

                return self::FAILURE;
            }

            if ($dryRun) {
                DB::rollBack();
                $this->warn('DRY-RUN: no se guardó nada.');

                return self::SUCCESS;
            }

            $merger->apply($survivor, $absorbed, $plan, null, MergePersonsAction::COMMAND, $this->option('reason') ?: null);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        // Cached rankings (Senado, Cámara, Asamblea, Concejo) carry person ids and names.
        CachedQueries::flush();
        $this->info('Fusión aplicada.');

        return self::SUCCESS;
    }

    private function printPlan(MergePersonsAction $merger, object $survivor, object $absorbed, array $plan): void
    {
        $this->info('Personas:');
        $this->table(['Rol', 'Id', 'Nombre', 'Candidaturas'], [
            ['superviviente', $survivor->id, $survivor->full_name, DB::table('candidacies')->where('person_id', $survivor->id)->count()],
            ['absorbida', $absorbed->id, $absorbed->full_name, DB::table('candidacies')->where('person_id', $absorbed->id)->count()],
        ]);

        $this->info('Referencias de la absorbida:');
        $this->table(['Tabla.columna', 'Mover', 'Descartar', 'Detalle'], collect($plan['refs'])
            ->map(fn ($r) => [$r['label'], count($r['move']), count($r['drop']), $r['note']])->all());

        $this->line('Alias nuevo en el superviviente: '.($plan['alias'] ? $plan['alias']['normalized_alias'] : 'ninguno'));
        $this->line('Campos completados en el superviviente: '.($plan['fields'] ? implode(', ', array_keys($plan['fields'])) : 'ninguno'));
        $this->line('Filas de staging a reapuntar: '.count($plan['staging']));
        $this->line("La absorbida queda con merged_into_id={$survivor->id}, identity_status=merged y deleted_at.");
        foreach ($plan['warnings'] as $warning) {
            $this->warn($warning);
        }
        $this->info('Resumen: '.collect($merger->counts($plan))->map(fn ($v, $k) => "{$k}={$v}")->implode(', '));
    }
}
