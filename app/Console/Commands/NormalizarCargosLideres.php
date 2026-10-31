<?php

namespace App\Console\Commands;

use App\Console\Concerns\TargetsCampaign;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class NormalizarCargosLideres extends Command
{
    use TargetsCampaign;

    protected $signature = 'lideres:normalizar-cargos {--dry-run : Muestra los cambios sin guardarlos} {--campaign= : Campaña (slug o id); por defecto Centro Democrático}';

    protected $description = 'Normaliza los cargos de líderes a las categorías del catálogo, guardando el texto original en la observación';

    // Legacy free-text cargo (unaccented, lowercase) => catalog cargo.
    private const MAP = [
        'lider centro' => 'Líder',
        'lider el morro' => 'Líder',
        'lider las palmas' => 'Líder',
        'lider san jose' => 'Líder',
        'lider gallegos' => 'Líder',
        'lider bolqueteros' => 'Líder',
        'lider fedetabaco' => 'Líder',
        'lider-coomineros de landazuri' => 'Líder',
        'lider de cd casco urbano' => 'Líder',
        'ingeniero lider' => 'Líder',
        'madre del doctor carlos murillo' => 'Líder',
        'papa cristiano' => 'Líder',
        'presento a hector efrain' => 'Líder',
        'concejal electo' => 'Concejal',
        'concejal cd' => 'Concejal',
        'cconcejo por cd 180v' => 'Concejal',
        'c. alcaldia' => 'Candidato Alcaldía',
        'alcalde' => 'Alcalde Electo',
        'presidente jac' => 'JAC',
        'secretario de gobierno' => 'Funcionario',
        'secretario de planeacion' => 'Funcionario',
        'secretario de salud' => 'Funcionario',
        'registraduria' => 'Funcionario',
        'igac' => 'Funcionario',
        'aunap' => 'Funcionario',
        'sena floridablanca' => 'Funcionario',
        'alcaldia barrancabermeja' => 'Funcionario',
        'ex alcalde' => 'Exalcalde',
    ];

    // Place names embedded in "LIDER <lugar>" go to barrio when it is empty.
    private const BARRIO_FROM = [
        'lider centro' => 'Centro',
        'lider el morro' => 'El Morro',
        'lider las palmas' => 'Las Palmas',
        'lider san jose' => 'San José',
        'lider gallegos' => 'Gallegos',
    ];

    // Valid cargos that are not catalog categories but are used by the corporation filters.
    private const KEEP = ['Diputado', 'Representante', 'Senador', 'Alcalde Electo'];

    private const NEW_CATEGORIES = ['Exconcejal', 'Exalcalde'];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        if (! $this->resolveCampaign()) {
            return self::FAILURE;
        }

        $catalog = DB::table('cargo_categories')->pluck('name')->all();
        $canonical = [];
        foreach (array_merge($catalog, self::KEEP, self::NEW_CATEGORIES) as $name) {
            $canonical[$this->key($name)] = $name;
        }

        $changes = [];
        $unmapped = [];
        foreach ($this->lideres()->select('id', 'nombre', 'cargo', 'observacion', 'barrio')->orderBy('nombre')->get() as $l) {
            $tokens = array_values(array_filter(array_map('trim', explode(',', (string) $l->cargo)), fn ($t) => $t !== ''));
            if (empty($tokens)) {
                continue;
            }

            $new = [];
            $legacy = [];
            $barrio = null;
            foreach ($tokens as $t) {
                $k = $this->key($t);
                if (isset(self::MAP[$k])) {
                    $new[] = self::MAP[$k];
                    // Only a real rename is legacy; a spelling variant (case, accents, spaces) is not.
                    if ($k !== $this->key(self::MAP[$k])) {
                        $legacy[] = $t;
                    }
                    $barrio ??= self::BARRIO_FROM[$k] ?? null;
                } elseif (isset($canonical[$k])) {
                    $new[] = $canonical[$k];
                } else {
                    $new[] = $t;
                    $unmapped[$t] = ($unmapped[$t] ?? 0) + 1;
                }
            }
            $new = array_values(array_unique($new));
            $newCargo = implode(', ', $new);

            if ($newCargo === $l->cargo) {
                continue;
            }

            $update = ['cargo' => $newCargo, 'updated_at' => now()];
            $note = $legacy ? 'Cargo original: '.implode(', ', $legacy) : '';
            if ($note !== '') {
                $update['observacion'] = trim(($l->observacion ? $l->observacion.' | ' : '').$note);
            }
            if ($barrio && blank($l->barrio)) {
                $update['barrio'] = $barrio;
            }
            $changes[] = [$l, $update, $note];
        }

        $this->table(['Nombre', 'Cargo actual', 'Cargo nuevo', 'Barrio nuevo', 'Nota añadida'], array_map(
            fn ($c) => [Str::limit($c[0]->nombre, 30), Str::limit($c[0]->cargo, 45), $c[1]['cargo'], $c[1]['barrio'] ?? '', $c[2]],
            $changes
        ));
        $this->info(count($changes).' líderes a actualizar.');
        if ($unmapped) {
            $this->warn('Cargos sin mapeo (se dejan igual): '.json_encode($unmapped, JSON_UNESCAPED_UNICODE));
        }

        if ($dry) {
            $this->comment('Dry run: no se guardó nada.');

            return self::SUCCESS;
        }

        $backup = 'lideres_cargo_backup_'.now()->format('Ymd_His');
        DB::transaction(function () use ($changes, $backup) {
            DB::statement("CREATE TABLE {$backup} AS SELECT id, cargo, observacion, barrio FROM lideres WHERE campaign_id = '{$this->campaignId}'");

            $order = (int) DB::table('cargo_categories')->max('display_order');
            foreach (self::NEW_CATEGORIES as $name) {
                if (! DB::table('cargo_categories')->where('name', $name)->exists()) {
                    DB::table('cargo_categories')->insert([
                        'name' => $name, 'display_order' => ++$order, 'is_highlighted' => false,
                        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }

            foreach ($changes as [$l, $update]) {
                $this->lideres()->where('id', $l->id)->update($update);
            }
        });

        Cache::forget('cargos_por_tipo');
        $this->info("Listo. Respaldo en la tabla {$backup}.");

        return self::SUCCESS;
    }

    private function key(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', Str::ascii($value))));
    }
}
