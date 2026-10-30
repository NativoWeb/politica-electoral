<?php

namespace App\Console\Commands;

use App\Console\Concerns\TargetsCampaign;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class CorregirCargosDirectorioCd extends Command
{
    use TargetsCampaign;

    protected $signature = 'lideres:corregir-cargos-directorio {file : Ruta al Excel DIRECTORIOS MUNICIPALES CD} {--dry-run : Muestra los cambios sin guardarlos} {--campaign= : Campaña (slug o id); por defecto Centro Democrático}';

    protected $description = 'Según el Excel del directorio CD: Líder -> Directorio Municipal para miembros del directorio y Mujeres -> Reservistas para representantes de la reserva';

    // Excel cargo predicate => [cargo currently stored, cargo it should be].
    private const RULES = [
        'directorio' => ['Líder', 'Directorio Municipal'],
        'reserva' => ['Mujeres', 'Reservistas'],
    ];

    public function handle(): int
    {
        $file = $this->argument('file');
        if (! file_exists($file)) {
            $this->error("No existe el archivo: {$file}");

            return self::FAILURE;
        }
        if (! $this->resolveCampaign()) {
            return self::FAILURE;
        }

        $reader = IOFactory::createReaderForFile($file);
        $reader->setReadDataOnly(true);
        $ws = $reader->load($file)->getSheetByName('COORD Y DIRECTORIO');

        $byCed = [];
        $byNameMun = [];
        foreach ($this->lideres()->get(['id', 'nombre', 'municipio', 'cedula', 'cargo']) as $l) {
            $ced = preg_replace('/\D/', '', (string) $l->cedula);
            if ($ced !== '') {
                $byCed[$ced] = $l;
            }
            $byNameMun[$this->key($l->nombre).'|'.$this->key($l->municipio)] = $l;
        }

        $changes = [];
        $skipped = [];
        foreach ($ws->toArray(null, true, true, true) as $i => $row) {
            if ($i <= 2) {
                continue;
            }
            $nom = trim((string) ($row['D'] ?? ''));
            $mun = trim((string) ($row['C'] ?? ''));
            $raw = Str::upper(Str::ascii(trim((string) ($row['E'] ?? ''))));
            if ($nom === '' || $mun === '') {
                continue;
            }
            $rule = match (true) {
                str_contains($raw, 'DIRECTORIO') => 'directorio',
                str_contains($raw, 'RESERVA') => 'reserva',
                default => null,
            };
            if (! $rule) {
                continue;
            }

            $ced = preg_replace('/\D/', '', (string) ($row['G'] ?? ''));
            $l = ($ced !== '' ? ($byCed[$ced] ?? null) : null) ?? ($byNameMun[$this->key($nom).'|'.$this->key($mun)] ?? null);
            if (! $l || isset($changes[$l->id])) {
                continue;
            }

            [$from, $to] = self::RULES[$rule];
            $tokens = array_values(array_filter(array_map('trim', explode(',', (string) $l->cargo)), fn ($t) => $t !== ''));
            $keys = array_map(fn ($t) => $this->key($t), $tokens);
            if (in_array($this->key($to), $keys, true)) {
                continue;
            }
            $pos = array_search($this->key($from), $keys, true);
            if ($pos === false) {
                $skipped[] = [$l->nombre, $l->cargo, $rule];

                continue;
            }
            $tokens[$pos] = $to;
            $changes[$l->id] = [$l->nombre, $l->municipio, $l->cargo, implode(', ', array_values(array_unique($tokens)))];
        }

        $summary = [];
        foreach ($changes as $c) {
            $summary["{$c[2]}  ->  {$c[3]}"] = ($summary["{$c[2]}  ->  {$c[3]}"] ?? 0) + 1;
        }
        arsort($summary);
        $this->table(['Cambio', 'Personas'], array_map(fn ($k, $v) => [$k, $v], array_keys($summary), $summary));
        $this->info(count($changes).' líderes a actualizar.');
        if ($skipped) {
            $this->warn(count($skipped).' no se tocan porque su cargo actual ya no es el esperado (alguien lo editó):');
            $this->table(['Nombre', 'Cargo actual', 'Regla'], $skipped);
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run: no se guardó nada.');

            return self::SUCCESS;
        }

        $backup = 'lideres_cargo_backup_'.now()->format('Ymd_His');
        DB::transaction(function () use ($changes, $backup) {
            DB::statement("CREATE TABLE {$backup} AS SELECT id, cargo FROM lideres WHERE campaign_id = '{$this->campaignId}'");
            foreach ($changes as $id => $c) {
                $this->lideres()->where('id', $id)->update(['cargo' => $c[3], 'updated_at' => now()]);
            }
        });
        $this->info("Listo. Respaldo en la tabla {$backup}.");

        return self::SUCCESS;
    }

    private function key(?string $v): string
    {
        return Str::upper(Str::ascii(trim(preg_replace('/\s+/', ' ', (string) $v))));
    }
}
