<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportGarciaRovira extends Command
{
    protected $signature = 'import:garcia-rovira';
    protected $description = 'Import Garcia Rovira data from the 2 complementary Excel files';

    private array $municipios = [];
    private array $orgs = [];

    public function handle(): int
    {
        $this->municipios = DB::table('geographic_units')
            ->where('type', 'municipality')
            ->pluck('id', 'canonical_name')
            ->mapWithKeys(fn ($id, $name) => [Str::upper(Str::ascii($name)) => $id])
            ->toArray();

        $this->orgs = DB::table('political_organizations')
            ->pluck('id', 'canonical_name')
            ->mapWithKeys(fn ($id, $name) => [Str::upper(Str::ascii($name)) => $id])
            ->toArray();

        $this->importCamaraSenado();
        $this->importAlcaldiasConcejos();

        return 0;
    }

    private function findMunicipio(string $name): ?string
    {
        $key = Str::upper(Str::ascii(trim($name)));
        if (isset($this->municipios[$key])) return $this->municipios[$key];
        // Fuzzy
        foreach ($this->municipios as $k => $id) {
            if (str_contains($k, $key) || str_contains($key, $k)) return $id;
        }
        return null;
    }

    private function findOrCreateOrg(string $name): string
    {
        $key = Str::upper(Str::ascii(trim($name)));
        if (isset($this->orgs[$key])) return $this->orgs[$key];

        // Try partial match
        foreach ($this->orgs as $k => $id) {
            if (str_contains($k, $key) || str_contains($key, $k)) return $id;
        }

        // Create new
        $id = Str::uuid()->toString();
        DB::table('political_organizations')->insert([
            'id' => $id,
            'canonical_name' => Str::title(Str::lower($name)),
            'type' => 'party',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->orgs[$key] = $id;
        $this->info("  Created org: {$name}");
        return $id;
    }

    private function findOrCreatePerson(string $fullName): string
    {
        $normalized = Str::upper(Str::ascii(trim($fullName)));
        $existing = DB::table('persons')->where('normalized_name', $normalized)->value('id');
        if ($existing) return $existing;

        $id = Str::uuid()->toString();
        $parts = explode(' ', trim($fullName));
        $firstName = $parts[0] ?? '';
        $lastName = implode(' ', array_slice($parts, 1)) ?: $firstName;

        DB::table('persons')->insert([
            'id' => $id,
            'first_name' => Str::title(Str::lower($firstName)),
            'last_name' => Str::title(Str::lower($lastName)),
            'full_name' => Str::title(Str::lower($fullName)),
            'normalized_name' => $normalized,
            'identity_status' => 'unverified',
            'data_classification' => 'internal',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $id;
    }

    private function importCamaraSenado(): void
    {
        $file = 'C:\\Users\\Marly Rangel\\Downloads\\Panel Electoral\\Resultados Camara y Senado Garcia Rovira.xlsx';
        if (!file_exists($file)) { $this->error("Not found: {$file}"); return; }

        $this->info('=== Importing Camara y Senado Garcia Rovira ===');

        $reader = IOFactory::createReaderForFile($file);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($file);
        $ws = $spreadsheet->getActiveSheet();
        $data = $ws->toArray(null, true, true, true);

        $header = $data[1]; // Row 1 is header
        $this->info('Columns: ' . count(array_filter($header)));

        // Find Senado event/contest
        $senadoCorp = DB::table('corporations')->where('name', 'Senado')->value('id');
        $camaraCorp = DB::table('corporations')->where('name', 'Cámara de Representantes')->value('id');

        $senadoContest = DB::table('contests')->where('corporation_id', $senadoCorp)->value('id');
        $camaraContest = DB::table('contests')->where('corporation_id', $camaraCorp)->value('id');

        if (!$senadoContest || !$camaraContest) {
            $this->error('Senado or Camara contest not found');
            return;
        }

        // Parse header to identify candidates and their party blocks
        // Structure: PROVINCIA | MUNICIPIO | [candidates...] | LISTA CD | TOTAL PARTIDO CD CAMARA | TOTAL PARTIDO CD SENADO | [next party candidates...]
        // We'll import the TOTAL PARTIDO columns as list votes per municipality

        $imported = 0;
        foreach ($data as $rowNum => $row) {
            if ($rowNum <= 1) continue;
            $municipio = trim($row['B'] ?? '');
            if (!$municipio || $municipio === 'TOTAL') continue;

            $munId = $this->findMunicipio($municipio);
            if (!$munId) {
                $this->warn("  Municipio not found: {$municipio}");
                continue;
            }

            // Column J = TOTAL PARTIDO CD CAMARA
            $totalCdCamara = (int) ($row['J'] ?? 0);
            // Column K = TOTAL PARTIDO CD SENADO
            $totalCdSenado = (int) ($row['K'] ?? 0);

            // Import CD Senado vote for this municipio if not exists
            if ($totalCdSenado > 0) {
                $cdId = $this->findOrCreateOrg('Centro Democrático');
                $this->upsertListVote($senadoContest, $munId, $cdId, $totalCdSenado);
                $imported++;
            }

            if ($totalCdCamara > 0) {
                $cdId = $this->findOrCreateOrg('Centro Democrático');
                $this->upsertListVote($camaraContest, $munId, $cdId, $totalCdCamara);
                $imported++;
            }
        }

        $this->info("Camara/Senado Garcia Rovira: {$imported} list votes imported");
    }

    private function upsertListVote(string $contestId, string $munId, string $orgId, int $value): void
    {
        $existing = DB::table('electoral_results')
            ->where('contest_id', $contestId)
            ->where('geographic_unit_id', $munId)
            ->where('organization_id', $orgId)
            ->where('metric_type', 'votes')
            ->whereNull('candidacy_id')
            ->first();

        if ($existing) return; // Already have this data

        DB::table('electoral_results')->insert([
            'id' => Str::uuid(),
            'contest_id' => $contestId,
            'geographic_unit_id' => $munId,
            'organization_id' => $orgId,
            'metric_type' => 'votes',
            'grain' => 'municipality',
            'value' => $value,
            'status' => 'validated',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function importAlcaldiasConcejos(): void
    {
        $file = 'C:\\Users\\Marly Rangel\\Downloads\\Panel Electoral\\1-resultados alcaldias, concejos, Garcia Rovira.xlsx';
        if (!file_exists($file)) { $this->error("Not found: {$file}"); return; }

        $this->info('=== Importing Alcaldias/Concejos Garcia Rovira ===');

        $reader = IOFactory::createReaderForFile($file);
        $reader->setReadDataOnly(true);

        // Only load specific sheets to avoid memory issues
        $reader->setLoadSheetsOnly(['ALCALDIA ', 'CONCEJO CD SDER']);
        $spreadsheet = $reader->load($file);

        // ALCALDIA sheet — add missing candidates
        $ws = $spreadsheet->getSheetByName('ALCALDIA ');
        if ($ws) {
            $this->importAlcaldiaSheet($ws);
        }

        // CONCEJO CD SDER — concejales CD with contacts → add to lideres
        $ws2 = $spreadsheet->getSheetByName('CONCEJO CD SDER');
        if ($ws2) {
            $this->importConcejalesCDSheet($ws2);
        }
    }

    private function importAlcaldiaSheet($ws): void
    {
        $data = $ws->toArray(null, true, true, true);
        $alcaldiaOffice = DB::table('offices')->where('name', 'Alcaldía')->value('id');
        $event = DB::table('electoral_events')->where('event_type', 'territorial')->first();
        if (!$alcaldiaOffice || !$event) return;

        $imported = 0;
        foreach ($data as $rowNum => $row) {
            if ($rowNum <= 2) continue;
            $municipio = trim($row['A'] ?? '');
            $nombre = trim($row['B'] ?? '');
            $votos = (int) ($row['D'] ?? 0);
            $cargo = trim($row['E'] ?? '');
            $aval = trim($row['G'] ?? '');

            if (!$municipio || !$nombre) continue;

            $munId = $this->findMunicipio($municipio);
            if (!$munId) continue;

            // Check if contest exists
            $contest = DB::table('contests')
                ->where('office_id', $alcaldiaOffice)
                ->where('geographic_unit_id', $munId)
                ->first();

            if (!$contest) {
                // Create contest
                $contestId = Str::uuid()->toString();
                DB::table('contests')->insert([
                    'id' => $contestId,
                    'electoral_event_id' => $event->id,
                    'office_id' => $alcaldiaOffice,
                    'geographic_unit_id' => $munId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $contest = (object) ['id' => $contestId];
            }

            // Find or create person
            $personId = $this->findOrCreatePerson($nombre);

            // Check if candidacy exists
            $existing = DB::table('candidacies')
                ->where('contest_id', $contest->id)
                ->where('person_id', $personId)
                ->first();

            if (!$existing) {
                $outcome = strtoupper($cargo) === 'ELECTO' ? 'elected' : 'not_elected';
                $candId = Str::uuid()->toString();
                DB::table('candidacies')->insert([
                    'id' => $candId,
                    'contest_id' => $contest->id,
                    'person_id' => $personId,
                    'outcome' => $outcome,
                    'status' => 'confirmed',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Add endorsement if aval known
                if ($aval) {
                    $orgId = $this->findOrCreateOrg($aval);
                    DB::table('candidacy_endorsements')->insert([
                        'id' => Str::uuid(),
                        'candidacy_id' => $candId,
                        'organization_id' => $orgId,
                        'is_primary' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // Add votes
                if ($votos > 0) {
                    DB::table('electoral_results')->insert([
                        'id' => Str::uuid(),
                        'candidacy_id' => $candId,
                        'contest_id' => $contest->id,
                        'geographic_unit_id' => $munId,
                        'metric_type' => 'votes',
                        'grain' => 'municipality',
                        'value' => $votos,
                        'status' => 'validated',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $imported++;
            }
        }
        $this->info("ALCALDIA Garcia Rovira: {$imported} candidatos importados");
    }

    private function importConcejalesCDSheet($ws): void
    {
        $data = $ws->toArray(null, true, true, true);
        $imported = 0;

        foreach ($data as $rowNum => $row) {
            if ($rowNum <= 1) continue;
            $provincia = trim($row['A'] ?? '');
            $municipio = trim($row['B'] ?? '');
            $nombre = trim($row['C'] ?? '');
            $votos = (int) ($row['D'] ?? 0);
            $contacto = trim($row['E'] ?? '');

            if (!$nombre || !$municipio) continue;

            $munId = $this->findMunicipio($municipio);

            // Add to lideres if not already there
            $exists = DB::table('lideres')
                ->where('nombre', 'ilike', '%' . explode(' ', $nombre)[0] . '%')
                ->where('municipio', 'ilike', '%' . $municipio . '%')
                ->where('cargo', 'CONCEJAL CD')
                ->exists();

            if (!$exists) {
                DB::table('lideres')->insert([
                    'id' => Str::uuid(),
                    'nombre' => Str::title(Str::lower($nombre)),
                    'provincia' => $provincia ?: null,
                    'municipio' => Str::title(Str::lower($municipio)),
                    'cargo' => 'CONCEJAL CD',
                    'tipo' => 'directorio',
                    'telefono' => $contacto ?: null,
                    'geographic_unit_id' => $munId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $imported++;
            }
        }
        $this->info("CONCEJO CD SDER: {$imported} concejales CD agregados a lideres");
    }
}
