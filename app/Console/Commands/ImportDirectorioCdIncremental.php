<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportDirectorioCdIncremental extends Command
{
    protected $signature = 'import:directorio-cd {file : Ruta al Excel DIRECTORIOS MUNICIPALES CD} {--dry-run : Muestra los cambios sin guardarlos}';

    protected $description = 'Importa sin borrar nada: crea las personas del directorio CD que falten y agrega las observaciones de llamadas';

    public function handle(): int
    {
        $file = $this->argument('file');
        if (! file_exists($file)) {
            $this->error("No existe el archivo: {$file}");

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');

        $reader = IOFactory::createReaderForFile($file);
        $reader->setReadDataOnly(true);
        $book = $reader->load($file);

        $geo = DB::table('geographic_units as g')
            ->leftJoin('geographic_units as p', 'g.parent_id', '=', 'p.id')
            ->where('g.type', 'municipality')
            ->get(['g.id', 'g.canonical_name', 'p.canonical_name as provincia'])
            ->keyBy(fn ($g) => $this->key($g->canonical_name));

        [$byCed, $byNameMun] = $this->indexLideres();

        $people = $this->readPeople($book);
        $toCreate = [];
        foreach ($people as $p) {
            if ($this->find($p, $byCed, $byNameMun)) {
                continue;
            }
            $ck = $p['ced'] ?: $this->key($p['nom']).'|'.$this->key($p['mun']);
            if (isset($toCreate[$ck])) {
                $toCreate[$ck]['extra'][] = $p['cargo_raw'];

                continue;
            }
            $p['extra'] = [];
            $toCreate[$ck] = $p;
        }

        $this->info('Personas a crear: '.count($toCreate));
        $this->table(['Municipio', 'Nombre', 'Cargo', 'Tipo', 'Cédula', 'Teléfono'], array_map(
            fn ($p) => [$p['mun'], $p['nom'], $p['cargo'], $p['tipo'], $p['ced'], $p['tel']], array_values($toCreate)
        ));

        $calls = $this->readCalls($book);
        $obsUpdates = [];
        foreach ($calls as $c) {
            $l = $this->find($c, $byCed, $byNameMun);
            if (! $l) {
                continue;
            }
            $current = $obsUpdates[$l->id] ?? (string) $l->observacion;
            $note = 'Llamada: '.$c['obs'];
            if (stripos($current, $c['obs']) !== false) {
                continue;
            }
            $obsUpdates[$l->id] = trim($current === '' ? $note : $current.' | '.$note);
        }
        $this->info('Observaciones de llamadas a agregar: '.count($obsUpdates));

        if ($dry) {
            $this->comment('Dry run: no se guardó nada.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($toCreate, $obsUpdates, $geo) {
            foreach ($toCreate as $p) {
                $g = $geo[$this->key($p['mun'])] ?? null;
                $obs = $p['extra'] ? 'También en el Excel como: '.implode(', ', array_unique($p['extra'])) : null;
                DB::table('lideres')->insert([
                    'id' => Str::uuid(),
                    'nombre' => Str::title(Str::lower($p['nom'])),
                    'municipio' => $g->canonical_name ?? Str::title(Str::lower($p['mun'])),
                    'provincia' => $g->provincia ?? ($p['prov'] ?: null),
                    'geographic_unit_id' => $g->id ?? null,
                    'cargo' => $p['cargo'],
                    'tipo' => $p['tipo'],
                    'telefono' => $p['tel'] ?: null,
                    'cedula' => $p['ced'] ?: null,
                    'email' => $p['email'] ?: null,
                    'observacion' => $obs,
                    'is_active' => true,
                    'nivel_confianza' => 'sin_llamar',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            foreach ($obsUpdates as $id => $obs) {
                DB::table('lideres')->where('id', $id)->update(['observacion' => $obs, 'updated_at' => now()]);
            }
        });

        $this->info('Listo: '.count($toCreate).' personas creadas, '.count($obsUpdates).' observaciones agregadas.');

        return self::SUCCESS;
    }

    private function indexLideres(): array
    {
        $byCed = [];
        $byNameMun = [];
        foreach (DB::table('lideres')->get(['id', 'nombre', 'municipio', 'cedula', 'observacion']) as $l) {
            $ced = preg_replace('/\D/', '', (string) $l->cedula);
            if ($ced !== '') {
                $byCed[$ced] = $l;
            }
            $byNameMun[$this->key($l->nombre).'|'.$this->key($l->municipio)] = $l;
        }

        return [$byCed, $byNameMun];
    }

    private function find(array $p, array $byCed, array $byNameMun): ?object
    {
        if ($p['ced'] !== '' && isset($byCed[$p['ced']])) {
            return $byCed[$p['ced']];
        }

        return $byNameMun[$this->key($p['nom']).'|'.$this->key($p['mun'])] ?? null;
    }

    private function readPeople($book): array
    {
        $out = [];
        $specs = [
            'COORD Y DIRECTORIO' => ['skip' => 2, 'ced' => 'G', 'tel' => 'F', 'cargo' => 'E', 'email' => 'H'],
            'VEEDURIA' => ['skip' => 1, 'ced' => 'E', 'tel' => 'F', 'cargo' => null, 'email' => null],
        ];
        foreach ($specs as $sheet => $c) {
            $ws = $book->getSheetByName($sheet);
            if (! $ws) {
                continue;
            }
            foreach ($ws->toArray(null, true, true, true) as $i => $row) {
                if ($i <= $c['skip']) {
                    continue;
                }
                $nom = trim((string) ($row['D'] ?? ''));
                $mun = trim((string) ($row['C'] ?? ''));
                if ($nom === '' || $mun === '') {
                    continue;
                }
                $raw = $c['cargo'] ? trim((string) ($row[$c['cargo']] ?? '')) : 'VEEDOR';
                [$cargo, $tipo] = $this->mapCargo($raw);
                $out[] = [
                    'prov' => trim((string) ($row['B'] ?? '')),
                    'mun' => $mun,
                    'nom' => $nom,
                    'cargo_raw' => $raw,
                    'cargo' => $cargo,
                    'tipo' => $tipo,
                    'tel' => preg_replace('/\D/', '', (string) ($row[$c['tel']] ?? '')),
                    'ced' => preg_replace('/\D/', '', (string) ($row[$c['ced']] ?? '')),
                    'email' => $c['email'] ? trim((string) ($row[$c['email']] ?? '')) : '',
                ];
            }
        }

        return $out;
    }

    private function readCalls($book): array
    {
        $ws = $book->getSheetByName('LLAMADAS  DIRECTORIO') ?? $book->getSheetByName('LLAMADAS DIRECTORIO');
        if (! $ws) {
            return [];
        }
        $out = [];
        foreach ($ws->toArray(null, true, true, true) as $i => $row) {
            if ($i <= 2) {
                continue;
            }
            $nom = trim((string) ($row['D'] ?? ''));
            $obs = trim((string) ($row['H'] ?? ''));
            if ($nom === '' || $obs === '') {
                continue;
            }
            $out[] = [
                'nom' => $nom,
                'mun' => trim((string) ($row['C'] ?? '')),
                'ced' => preg_replace('/\D/', '', (string) ($row['G'] ?? '')),
                'obs' => $obs,
            ];
        }

        return $out;
    }

    // Same convention the existing directorio rows already use in the database.
    private function mapCargo(string $raw): array
    {
        $u = Str::upper(Str::ascii($raw));

        return match (true) {
            $u === 'VEEDOR' => ['Líder', 'veeduria'],
            str_contains($u, 'CONCEJAL') => ['Concejal', 'directorio'],
            str_contains($u, 'JOVEN') => ['Juventudes', 'directorio'],
            str_contains($u, 'RESERVA') => ['Reservistas', 'directorio'],
            str_contains($u, 'COORDINADOR') => ['Coordinador Municipal', 'directorio'],
            str_contains($u, 'ALCALDIA') => ['Candidato Alcaldía', 'directorio'],
            default => ['Líder', 'directorio'],
        };
    }

    private function key(?string $v): string
    {
        return Str::upper(Str::ascii(trim(preg_replace('/\s+/', ' ', (string) $v))));
    }
}
