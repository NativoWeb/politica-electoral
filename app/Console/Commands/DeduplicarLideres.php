<?php

namespace App\Console\Commands;

use App\Console\Concerns\TargetsCampaign;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeduplicarLideres extends Command
{
    use TargetsCampaign;

    protected $signature = 'lideres:deduplicar {--dry-run} {--campaign= : Campaña (slug o id); por defecto Centro Democrático}';

    protected $description = 'Eliminar registros de prueba y deduplicar líderes por cédula y nombre+municipio';

    private array $fieldsToCount = [
        'nombre', 'provincia', 'municipio', 'cargo', 'tipo', 'telefono', 'cedula',
        'email', 'observacion', 'geographic_unit_id', 'direccion', 'barrio', 'zona',
        'partido', 'profesion', 'votos', 'tipo_documento', 'fecha_expedicion_doc',
        'genero', 'fecha_nacimiento', 'foto', 'estado_civil', 'referente_documento',
        'referente_nombre', 'referente_apellido', 'departamento_votacion',
        'municipio_votacion', 'puesto_votacion', 'direccion_puesto', 'mesa_votacion',
        'nivel_confianza', 'convenio', 'escolaridad', 'tipo_hoja_vida',
        'fecha_registro_hv', 'facebook', 'twitter', 'instagram',
    ];

    private array $booleanFields = [
        'destacado', 'militante', 'autoriza_datos', 'verificado', 'fallecido',
        'empresario', 'reservista', 'funcionario', 'exfuncionario', 'gran_elector',
    ];

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        if (! $this->resolveCampaign()) {
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('=== DRY RUN — no se harán cambios ===');
        }

        $pruebasEliminadas = $this->eliminarPruebas($dryRun);
        [$gruposCedula, $eliminadosCedula, $fusionadosCedula] = $this->deduplicarPorCedula($dryRun);
        [$gruposNombre, $eliminadosNombre, $fusionadosNombre] = $this->deduplicarPorNombreMunicipio($dryRun);

        $this->newLine();
        $this->info('=== RESUMEN ===');
        $this->info("Pruebas eliminadas: {$pruebasEliminadas}");
        $this->info("Grupos por cédula procesados: {$gruposCedula} — eliminados: {$eliminadosCedula} — campos rescatados: {$fusionadosCedula}");
        $this->info("Grupos por nombre+municipio procesados: {$gruposNombre} — eliminados: {$eliminadosNombre} — campos rescatados: {$fusionadosNombre}");
        $this->info('Total eliminados: '.($pruebasEliminadas + $eliminadosCedula + $eliminadosNombre));

        return self::SUCCESS;
    }

    private function eliminarPruebas(bool $dryRun): int
    {
        $pruebas = $this->lideres()->whereRaw("nombre ILIKE '%prueba%'")->get();
        $count = $pruebas->count();

        if ($count === 0) {
            $this->info('No se encontraron registros de prueba.');

            return 0;
        }

        foreach ($pruebas as $p) {
            $this->warn("Prueba: [{$p->id}] {$p->nombre} — {$p->municipio}");
        }

        if (! $dryRun) {
            $this->lideres()->whereIn('id', $pruebas->pluck('id'))->delete();
        }

        $this->info(($dryRun ? 'Se eliminarían' : 'Eliminados').": {$count} registros de prueba.");

        return $count;
    }

    private function deduplicarPorCedula(bool $dryRun): array
    {
        $this->newLine();
        $this->info('--- Deduplicando por cédula ---');

        $duplicados = $this->lideres()
            ->select('cedula', DB::raw('count(*) as total'))
            ->whereNotNull('cedula')
            ->where('cedula', '!=', '')
            ->groupBy('cedula')
            ->havingRaw('count(*) > 1')
            ->get();

        if ($duplicados->isEmpty()) {
            $this->info('No hay duplicados por cédula.');

            return [0, 0, 0];
        }

        $grupos = 0;
        $eliminados = 0;
        $fusionados = 0;

        foreach ($duplicados as $dup) {
            $registros = $this->lideres()->where('cedula', $dup->cedula)->get();

            try {
                [$e, $f] = $this->procesarGrupo($registros, $dryRun, "cédula={$dup->cedula}");
                $grupos++;
                $eliminados += $e;
                $fusionados += $f;
            } catch (\Throwable $ex) {
                $this->error("Error procesando cédula {$dup->cedula}: {$ex->getMessage()}");
            }
        }

        return [$grupos, $eliminados, $fusionados];
    }

    private function deduplicarPorNombreMunicipio(bool $dryRun): array
    {
        $this->newLine();
        $this->info('--- Deduplicando por nombre+municipio (sin cédula) ---');

        $duplicados = $this->lideres()
            ->select(
                DB::raw('UPPER(TRIM(nombre)) as norm_nombre'),
                DB::raw('UPPER(TRIM(municipio)) as norm_municipio'),
                DB::raw('count(*) as total')
            )
            ->where(function ($q) {
                $q->whereNull('cedula')->orWhere('cedula', '');
            })
            ->groupByRaw('UPPER(TRIM(nombre)), UPPER(TRIM(municipio))')
            ->havingRaw('count(*) > 1')
            ->get();

        if ($duplicados->isEmpty()) {
            $this->info('No hay duplicados por nombre+municipio.');

            return [0, 0, 0];
        }

        $grupos = 0;
        $eliminados = 0;
        $fusionados = 0;

        foreach ($duplicados as $dup) {
            $registros = $this->lideres()
                ->where(function ($q) {
                    $q->whereNull('cedula')->orWhere('cedula', '');
                })
                ->whereRaw('UPPER(TRIM(nombre)) = ?', [$dup->norm_nombre])
                ->whereRaw('UPPER(TRIM(municipio)) = ?', [$dup->norm_municipio])
                ->get();

            try {
                [$e, $f] = $this->procesarGrupo($registros, $dryRun, "nombre={$dup->norm_nombre}, municipio={$dup->norm_municipio}");
                $grupos++;
                $eliminados += $e;
                $fusionados += $f;
            } catch (\Throwable $ex) {
                $this->error("Error procesando {$dup->norm_nombre}/{$dup->norm_municipio}: {$ex->getMessage()}");
            }
        }

        return [$grupos, $eliminados, $fusionados];
    }

    private function procesarGrupo($registros, bool $dryRun, string $label): array
    {
        $scored = $registros->map(fn ($r) => ['record' => $r, 'score' => $this->contarCampos($r)]);
        $sorted = $scored->sortByDesc('score');

        $ganador = $sorted->first()['record'];
        $perdedores = $sorted->skip(1)->pluck('record');
        $perdedorIds = $perdedores->pluck('id')->toArray();

        $this->info("  Grupo ({$label}): ganador=[{$ganador->id}] {$ganador->nombre}, perdedores=".count($perdedorIds));

        $fusionados = 0;

        $ganadorData = (array) $ganador;
        foreach ($perdedores as $perdedor) {
            foreach ([...$this->fieldsToCount, ...$this->booleanFields] as $field) {
                if (! $this->tieneValor($ganadorData[$field] ?? null, in_array($field, $this->booleanFields))
                    && $this->tieneValor($perdedor->$field ?? null, in_array($field, $this->booleanFields))) {
                    $ganadorData[$field] = $perdedor->$field;
                    $fusionados++;
                    $this->info("    Rescatado: {$field} = {$perdedor->$field} (de [{$perdedor->id}])");
                }
            }
        }

        if (! $dryRun) {
            DB::transaction(function () use ($ganador, $ganadorData, $perdedorIds) {
                $updateFields = collect($ganadorData)
                    ->except(['id', 'campaign_id', 'created_at', 'updated_at'])
                    ->put('updated_at', now())
                    ->toArray();

                $this->lideres()->where('id', $ganador->id)->update($updateFields);
                DB::table('nexos_familiares')->where('campaign_id', $this->campaignId)->whereIn('person_id', $perdedorIds)->update(['person_id' => $ganador->id]);
                $this->lideres()->whereIn('id', $perdedorIds)->delete();
            });
        }

        return [count($perdedorIds), $fusionados];
    }

    private function contarCampos(object $record): int
    {
        $score = 0;

        foreach ($this->fieldsToCount as $field) {
            if ($this->tieneValor($record->$field ?? null, false)) {
                $score++;
            }
        }

        foreach ($this->booleanFields as $field) {
            if ($this->tieneValor($record->$field ?? null, true)) {
                $score++;
            }
        }

        return $score;
    }

    private function tieneValor(mixed $value, bool $isBoolean): bool
    {
        if ($isBoolean) {
            return $value === true || $value === 1 || $value === '1';
        }

        return $value !== null && $value !== '';
    }
}
