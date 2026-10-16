<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportLandazuri extends Command
{
    protected $signature = 'import:landazuri {file? : Path to LANDAZURY.xlsx}';

    protected $description = 'Import Landazuri electoral data from Excel (líderes, directorio, alcaldía, concejos)';

    private int $created = 0;

    private int $updated = 0;

    private int $skipped = 0;

    private ?string $geoId = null;

    private string $provincia = '';

    private array $existingNames = [];

    private array $partyMap = [];

    public function handle(): int
    {
        $file = $this->argument('file') ?: 'C:/Users/Marly Rangel/Downloads/LANDAZURY.xlsx';

        if (! file_exists($file)) {
            $this->error("File not found: {$file}");

            return 1;
        }

        $geo = DB::table('geographic_units')
            ->where('type', 'municipality')
            ->whereRaw("unaccent(canonical_name) ilike unaccent(?)", ['%landaz%'])
            ->first();

        if (! $geo) {
            $this->error('Municipality Landázuri not found in geographic_units');

            return 1;
        }

        $this->geoId = $geo->id;
        $provName = DB::table('geographic_units')->where('id', $geo->parent_id)->value('canonical_name');
        $this->provincia = $provName ?? 'Carare';

        $this->info("Municipality: {$geo->canonical_name} (ID: {$geo->id}, Provincia: {$this->provincia})");

        $this->existingNames = DB::table('lideres')
            ->whereRaw("unaccent(municipio) ilike unaccent(?)", ['%landaz%'])
            ->get(['id', 'nombre', 'telefono', 'votos', 'partido', 'cargo', 'observacion', 'cedula', 'email'])
            ->keyBy(fn ($r) => $this->normalize($r->nombre))
            ->toArray();

        $this->info('Existing records: '.count($this->existingNames));

        $this->partyMap = DB::table('political_organizations')
            ->pluck('canonical_name', 'id')
            ->toArray();

        $spreadsheet = IOFactory::load($file);

        $this->importLideres($spreadsheet);
        $this->importDirectorio($spreadsheet);
        $this->importAlcaldia($spreadsheet);
        $this->importConcejos($spreadsheet);

        $this->newLine();
        $this->info("Done. Created: {$this->created} | Updated: {$this->updated} | Skipped: {$this->skipped}");

        return 0;
    }

    private function normalize(string $name): string
    {
        $name = mb_strtoupper(trim($name));
        $name = preg_replace('/\s+/', ' ', $name);

        return $name;
    }

    private function cleanPhone($phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }
        $phone = preg_replace('/[^0-9]/', '', (string) $phone);

        return $phone ?: null;
    }

    private function resolvePartido(?string $raw): ?string
    {
        if (! $raw) {
            return null;
        }
        $raw = trim($raw);
        $map = [
            'CD' => 'Centro Democrático',
            'CENTRO DEMOCRATICO' => 'Centro Democrático',
            'VERDE' => 'Alianza Verde',
            'ALIANZA VERDE' => 'Alianza Verde',
            'LIBERAL' => 'Partido Liberal Colombiano',
            'PARTIDO LIBERAL' => 'Partido Liberal Colombiano',
            'CONSERVADOR' => 'Partido Conservador Colombiano',
            'PARTIDO CONSERVADOR' => 'Partido Conservador Colombiano',
            'ADA' => 'Alianza Democrática Amplia',
            'COAVAL' => 'Colombia Avanza',
            'PACTO HISTORICO' => 'Pacto Histórico',
            'EN MARCHA' => 'En Marcha',
            'NUEVO LIBERALISMO' => 'Nuevo Liberalismo',
            'ASI' => 'Alianza Social Independiente',
            'MAIS' => 'Movimiento Alternativo Indígena y Social',
        ];

        return $map[mb_strtoupper($raw)] ?? $raw;
    }

    private function upsert(string $nombre, array $data): void
    {
        $key = $this->normalize($nombre);

        if (isset($this->existingNames[$key])) {
            $existing = $this->existingNames[$key];
            $updates = [];

            if (empty($existing->telefono) && ! empty($data['telefono'])) {
                $updates['telefono'] = $data['telefono'];
            }
            if (empty($existing->votos) && ! empty($data['votos'])) {
                $updates['votos'] = $data['votos'];
            }
            if (empty($existing->partido) && ! empty($data['partido'])) {
                $updates['partido'] = $data['partido'];
            }
            if (empty($existing->observacion) && ! empty($data['observacion'])) {
                $updates['observacion'] = $data['observacion'];
            }
            if (empty($existing->email) && ! empty($data['email'])) {
                $updates['email'] = $data['email'];
            }

            if (! empty($updates)) {
                $updates['updated_at'] = now();
                DB::table('lideres')->where('id', $existing->id)->update($updates);
                $this->updated++;
                $this->line("  Updated: {$nombre} (".implode(', ', array_keys($updates)).')');
            } else {
                $this->skipped++;
            }

            return;
        }

        $cargo = $data['cargo'] ?? 'Líder';
        $tipo = $this->resolveType($cargo);

        $id = Str::uuid()->toString();
        DB::table('lideres')->insert([
            'id' => $id,
            'nombre' => mb_strtoupper(trim($nombre)),
            'municipio' => 'Landázuri',
            'provincia' => $this->provincia,
            'geographic_unit_id' => $this->geoId,
            'cargo' => $cargo,
            'tipo' => $tipo,
            'telefono' => $data['telefono'] ?? null,
            'votos' => $data['votos'] ?? 0,
            'partido' => $data['partido'] ?? null,
            'observacion' => $data['observacion'] ?? null,
            'email' => $data['email'] ?? null,
            'nivel_confianza' => 'sin_llamar',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->existingNames[$key] = (object) ['id' => $id, 'nombre' => $nombre, 'telefono' => $data['telefono'] ?? null, 'votos' => $data['votos'] ?? 0, 'partido' => $data['partido'] ?? null, 'cargo' => $cargo, 'observacion' => $data['observacion'] ?? null, 'cedula' => null, 'email' => null];
        $this->created++;
        $this->line("  Created: {$nombre} ({$cargo})");

        if (! empty($data['partido'])) {
            $this->ensureEndorsement($id, $data['partido']);
        }
    }

    private function resolveType(string $cargo): string
    {
        $upper = mb_strtoupper($cargo);
        if (str_contains($upper, 'LIDER') || str_contains($upper, 'LÍDER')) {
            return 'nuevo_lider';
        }

        return 'directorio';
    }

    private function ensureEndorsement(string $liderId, string $partido): void
    {
        $orgId = DB::table('political_organizations')
            ->where('canonical_name', 'ilike', '%'.$partido.'%')
            ->value('id');

        if (! $orgId) {
            return;
        }

        $candidacyId = DB::table('candidacies')
            ->whereIn('person_id', function ($q) use ($liderId) {
                $q->select('id')->from('persons')
                    ->whereIn('full_name', function ($q2) use ($liderId) {
                        $q2->select('nombre')->from('lideres')->where('id', $liderId);
                    });
            })
            ->value('id');

        if ($candidacyId) {
            $existing = DB::table('candidacy_endorsements')
                ->where('candidacy_id', $candidacyId)
                ->where('is_primary', true)
                ->first();

            if (! $existing) {
                DB::table('candidacy_endorsements')->insert([
                    'id' => Str::uuid(),
                    'candidacy_id' => $candidacyId,
                    'organization_id' => $orgId,
                    'is_primary' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function normalizeCargo(string $raw): string
    {
        $map = [
            'CONCEJAL CD' => 'Concejal',
            'CONCEJAL ELECTO' => 'Concejal',
            'CONCEJAL' => 'Concejal',
            'CCONCEJO POR CD' => 'Concejal',
            'CONCEJAL ADA' => 'Concejal',
            'EXCONCEJAL' => 'Exconcejal',
            'EX-CONCEJAL' => 'Exconcejal',
            'EX -CONCEJAL' => 'Exconcejal',
            'EXCONCEJAL-LIDER' => 'Exconcejal, Líder',
            'EXCONCEJAL AMIGA DE LA DOC RUBIELA VARGAS' => 'Exconcejal',
            'LIDER' => 'Líder',
            'LIDER/ CONCEJAL' => 'Líder, Concejal',
            'LIDER DE CD CASCO URBANO' => 'Líder',
            'DIRECTORIOS MUNICIPALES' => 'Líder',
            'ELECTO' => 'Alcalde Electo',
            'CANDIDATO' => 'Candidato',
            'EX ALCALDE' => 'Exalcalde',
        ];

        $upper = mb_strtoupper(trim($raw));
        foreach ($map as $key => $val) {
            if (str_starts_with($upper, $key)) {
                return $val;
            }
        }

        return trim($raw);
    }

    private function importLideres($spreadsheet): void
    {
        $this->info('');
        $this->info('=== Importing LIDERES sheet ===');
        $sheet = $spreadsheet->getSheetByName('LIDERES');
        if (! $sheet) {
            $this->warn('Sheet LIDERES not found');

            return;
        }

        $skipNames = ['VOTOS POR LA LISTA', 'VOTOS POR EL PARTIDO', 'TOTAL DE VOTOS', 'TOTAL'];

        for ($r = 2; $r <= $sheet->getHighestRow(); $r++) {
            $nombre = trim($sheet->getCell('D'.$r)->getValue() ?? '');
            if (! $nombre || in_array(mb_strtoupper($nombre), $skipNames)) {
                continue;
            }

            $cargo = trim($sheet->getCell('E'.$r)->getValue() ?? 'Líder');
            $this->upsert($nombre, [
                'cargo' => $this->normalizeCargo($cargo),
                'telefono' => $this->cleanPhone($sheet->getCell('F'.$r)->getValue()),
                'votos' => (int) ($sheet->getCell('G'.$r)->getValue() ?? 0),
                'partido' => $this->resolvePartido($sheet->getCell('H'.$r)->getValue()),
                'observacion' => trim($sheet->getCell('I'.$r)->getValue() ?? '') ?: null,
            ]);
        }
    }

    private function importDirectorio($spreadsheet): void
    {
        $this->info('');
        $this->info('=== Importing directorio sheet ===');
        $sheet = $spreadsheet->getSheetByName('directorio');
        if (! $sheet) {
            $this->warn('Sheet directorio not found');

            return;
        }

        for ($r = 2; $r <= $sheet->getHighestRow(); $r++) {
            $nombre = trim($sheet->getCell('B'.$r)->getValue() ?? '');
            if (! $nombre) {
                continue;
            }

            $cargo = trim($sheet->getCell('C'.$r)->getValue() ?? 'Líder');
            $this->upsert($nombre, [
                'cargo' => $this->normalizeCargo($cargo),
                'telefono' => $this->cleanPhone($sheet->getCell('D'.$r)->getValue()),
            ]);
        }
    }

    private function importAlcaldia($spreadsheet): void
    {
        $this->info('');
        $this->info('=== Importing ALCALDIA sheet ===');
        $sheet = $spreadsheet->getSheetByName('ALCALDIA');
        if (! $sheet) {
            $this->warn('Sheet ALCALDIA not found');

            return;
        }

        for ($r = 2; $r <= $sheet->getHighestRow(); $r++) {
            $nombre = trim($sheet->getCell('A'.$r)->getValue() ?? '');
            if (! $nombre) {
                continue;
            }

            $cargo = trim($sheet->getCell('C'.$r)->getValue() ?? 'Candidato Alcaldía');
            if (mb_strtoupper($cargo) === 'GESTOR SOCIAL') {
                continue;
            }
            $cargoFinal = mb_strtoupper($cargo) === 'ELECTO' ? 'Alcalde Electo' : 'Candidato Alcaldía';
            $partido = trim($sheet->getCell('E'.$r)->getValue() ?? '');

            $this->upsert($nombre, [
                'cargo' => $cargoFinal,
                'votos' => (int) ($sheet->getCell('B'.$r)->getValue() ?? 0),
                'partido' => $partido ?: null,
                'telefono' => $this->cleanPhone($sheet->getCell('D'.$r)->getValue()),
            ]);
        }
    }

    private function importConcejos($spreadsheet): void
    {
        $this->info('');
        $this->info('=== Importing CONCEJOS 2024 sheet ===');
        $sheet = $spreadsheet->getSheetByName('CONCEJOS 2024');
        if (! $sheet) {
            $this->warn('Sheet CONCEJOS 2024 not found');

            return;
        }

        $skipNames = ['VOTOS POR LA LISTA', 'VOTOS POR EL PARTIDO', 'TOTAL DE VOTOS', 'TOTAL'];

        for ($r = 2; $r <= $sheet->getHighestRow(); $r++) {
            $nombre = trim($sheet->getCell('E'.$r)->getValue() ?? '');
            if (! $nombre || in_array(mb_strtoupper($nombre), $skipNames)) {
                continue;
            }

            $partido = $this->resolvePartido($sheet->getCell('C'.$r)->getValue());
            $concejal = trim($sheet->getCell('F'.$r)->getValue() ?? '');
            $cargo = mb_strtoupper($concejal) === 'ELECTO' ? 'Concejal' : 'Candidato Concejo';

            $this->upsert($nombre, [
                'cargo' => $cargo,
                'votos' => (int) ($sheet->getCell('D'.$r)->getValue() ?? 0),
                'partido' => $partido,
                'telefono' => $this->cleanPhone($sheet->getCell('G'.$r)->getValue()),
            ]);
        }
    }
}
