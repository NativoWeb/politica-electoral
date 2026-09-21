<?php

namespace App\Services\Import;

use App\Models\Candidacy;
use App\Models\CandidacyEndorsement;
use App\Models\Contest;
use App\Models\ElectoralEvent;
use App\Models\ElectoralResult;
use App\Models\GeographicAlias;
use App\Models\GeographicUnit;
use App\Models\Office;
use App\Models\OrganizationAlias;
use App\Models\Person;
use App\Models\PersonAlias;
use App\Models\PoliticalOrganization;
use App\Models\SourceFile;
use App\Models\TurnoutMetric;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

class GobernacionAsambleaImporter
{
    private array $stats = [
        'gobernacion_potencial' => 0,
        'asamblea_results' => 0,
        'persons_created' => 0,
        'errors' => [],
    ];

    private ?ElectoralEvent $event = null;
    private array $municipioCache = [];
    private array $orgCache = [];
    private array $personCache = [];

    public function import(string $filePath, ?SourceFile $sourceFile = null): array
    {
        $this->event = ElectoralEvent::where('name', 'Elecciones territoriales 2023')->first();
        if (!$this->event) {
            throw new \RuntimeException('Electoral event "Elecciones territoriales 2023" not found.');
        }

        $this->buildCaches();

        $this->importGobernacion($filePath, $sourceFile);
        $this->importAsamblea($filePath, $sourceFile);

        return $this->stats;
    }

    private function importGobernacion(string $filePath, ?SourceFile $sourceFile): void
    {
        $reader = new Xlsx();
        $reader->setLoadSheetsOnly(['GOBERNACION']);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);

        $office = Office::where('name', 'Gobernación')->first();
        $santander = GeographicUnit::where('type', 'department')->where('canonical_name', 'Santander')->first();

        // Create gobernacion contest
        $contest = Contest::firstOrCreate(
            ['electoral_event_id' => $this->event->id, 'office_id' => $office->id, 'geographic_unit_id' => $santander?->id],
            ['name' => 'Gobernación Santander 2023', 'seats' => 1, 'list_type' => 'na', 'status' => 'official']
        );

        // Create candidates from header (cols 3-12)
        $headers = $rows[0];
        $candidateNames = [];
        for ($col = 3; $col <= 12; $col++) {
            $name = TextNormalizer::cleanString($headers[$col] ?? '');
            if (!empty($name) && $name !== 'VOTO BLANCO') {
                $candidateNames[$col] = $name;
            }
        }

        // Create persons and candidacies for gobernacion candidates
        foreach ($candidateNames as $col => $name) {
            $person = $this->resolveOrCreatePerson($name, $sourceFile);
            Candidacy::firstOrCreate(
                ['person_id' => $person->id, 'contest_id' => $contest->id],
                ['outcome' => 'pending']
            );
        }

        // Import potencial by municipality
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $mpioRaw = TextNormalizer::cleanString($row[1] ?? '');
            if (empty($mpioRaw)) continue;

            $municipio = $this->resolveMunicipio($mpioRaw);
            if (!$municipio) continue;

            $potencial = TextNormalizer::parseVotes($row[2] ?? null);
            if ($potencial !== null && $potencial > 0) {
                TurnoutMetric::updateOrCreate(
                    ['contest_id' => $contest->id, 'geographic_unit_id' => $municipio->id],
                    [
                        'electoral_event_id' => $this->event->id,
                        'grain' => 'municipality',
                        'electoral_potential' => $potencial,
                        'raw_potential' => (string) ($row[2] ?? ''),
                        'source_id' => $sourceFile?->id,
                    ]
                );
                $this->stats['gobernacion_potencial']++;
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }

    private function importAsamblea(string $filePath, ?SourceFile $sourceFile): void
    {
        $reader = new Xlsx();
        $reader->setLoadSheetsOnly(['ASAMBLEA']);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);

        $corp = \App\Models\Corporation::where('name', 'Asamblea')->first();
        $santander = GeographicUnit::where('type', 'department')->where('canonical_name', 'Santander')->first();

        $contest = Contest::firstOrCreate(
            ['electoral_event_id' => $this->event->id, 'corporation_id' => $corp->id, 'geographic_unit_id' => $santander?->id],
            ['name' => 'Asamblea Santander 2023', 'seats' => 16, 'list_type' => 'preferential', 'status' => 'official']
        );

        $headers = $rows[0];

        // Parse header to identify candidates and party groups
        // Structure: PROVINCIA, MUNICIPIO, POTENCIAL, VOTO EN BLANCO, then party groups
        // Each group: VOTOS LISTA X, candidate1, candidate2, ..., TOTAL PARTIDO
        // We need to identify candidate columns (not VOTOS LISTA, TOTAL, POTENCIAL, etc.)
        $candidateColumns = [];
        for ($col = 4; $col < count($headers); $col++) {
            $h = TextNormalizer::cleanString($headers[$col] ?? '');
            $hUpper = mb_strtoupper($h);
            if (empty($h)) continue;
            if (str_starts_with($hUpper, 'VOTOS LISTA') || str_starts_with($hUpper, 'VOTO LISTA')
                || str_starts_with($hUpper, 'TOTAL') || $hUpper === 'VOTO EN BLANCO'
                || $hUpper === 'POTENCIAL VOTACION') {
                continue;
            }
            $candidateColumns[$col] = $h;
        }

        // Create persons and candidacies
        $candidacyMap = [];
        foreach ($candidateColumns as $col => $name) {
            $person = $this->resolveOrCreatePerson($name, $sourceFile);
            $candidacy = Candidacy::firstOrCreate(
                ['person_id' => $person->id, 'contest_id' => $contest->id],
                ['outcome' => 'pending']
            );
            $candidacyMap[$col] = $candidacy;
        }

        // Import votes per municipality
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $mpioRaw = TextNormalizer::cleanString($row[1] ?? '');
            if (empty($mpioRaw)) continue;

            $municipio = $this->resolveMunicipio($mpioRaw);
            if (!$municipio) {
                $this->stats['errors'][] = "Asamblea fila {$i}: Municipio no encontrado: {$mpioRaw}";
                continue;
            }

            foreach ($candidacyMap as $col => $candidacy) {
                $votes = TextNormalizer::parseVotes($row[$col] ?? null);
                if ($votes === null || $votes === 0) continue;

                ElectoralResult::updateOrCreate(
                    [
                        'contest_id' => $contest->id,
                        'candidacy_id' => $candidacy->id,
                        'geographic_unit_id' => $municipio->id,
                        'metric_type' => 'nominal_votes',
                    ],
                    [
                        'grain' => 'municipality',
                        'value' => $votes,
                        'source_id' => $sourceFile?->id,
                        'status' => 'validated',
                    ]
                );
                $this->stats['asamblea_results']++;
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }

    private function resolveOrCreatePerson(string $fullName, ?SourceFile $sourceFile): Person
    {
        $normalized = TextNormalizer::normalizeName($fullName);
        if (isset($this->personCache[$normalized])) return $this->personCache[$normalized];

        $person = Person::where('normalized_name', $normalized)->first();
        if ($person) { $this->personCache[$normalized] = $person; return $person; }

        $names = TextNormalizer::splitName($fullName);
        $person = Person::create([
            'first_name' => $names['first_name'],
            'last_name' => $names['last_name'],
            'full_name' => mb_convert_case(trim($fullName), MB_CASE_TITLE, 'UTF-8'),
            'normalized_name' => $normalized,
            'identity_status' => 'unverified',
            'data_classification' => 'public',
        ]);
        PersonAlias::create(['person_id' => $person->id, 'alias_name' => $fullName, 'normalized_alias' => $normalized, 'alias_type' => 'electoral', 'source_id' => $sourceFile?->id]);
        $this->personCache[$normalized] = $person;
        $this->stats['persons_created']++;
        return $person;
    }

    private function resolveMunicipio(string $raw): ?GeographicUnit
    {
        $normalized = TextNormalizer::normalizeName($raw);
        if (isset($this->municipioCache[$normalized])) return $this->municipioCache[$normalized];

        $alias = GeographicAlias::where('normalized_alias', $normalized)->first();
        if ($alias) { $this->municipioCache[$normalized] = $alias->geographicUnit; return $alias->geographicUnit; }

        $unit = GeographicUnit::where('type', 'municipality')
            ->whereRaw("similarity(UPPER(canonical_name), ?) > 0.35", [$normalized])
            ->orderByRaw("similarity(UPPER(canonical_name), ?) DESC", [$normalized])
            ->first();
        if ($unit) {
            GeographicAlias::firstOrCreate(['geographic_unit_id' => $unit->id, 'normalized_alias' => $normalized], ['raw_alias' => $raw]);
            $this->municipioCache[$normalized] = $unit;
        }
        return $unit;
    }

    private function buildCaches(): void
    {
        foreach (GeographicAlias::with('geographicUnit')->whereHas('geographicUnit', fn ($q) => $q->where('type', 'municipality'))->get() as $a) {
            $this->municipioCache[$a->normalized_alias] = $a->geographicUnit;
        }
        foreach (Person::all() as $p) {
            $this->personCache[$p->normalized_name] = $p;
        }
    }
}
