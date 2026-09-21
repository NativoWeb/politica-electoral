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
use App\Models\OfficeTenure;
use App\Models\OrganizationAlias;
use App\Models\Person;
use App\Models\PersonAlias;
use App\Models\PersonContactPoint;
use App\Models\PoliticalOrganization;
use App\Models\SourceFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

class AlcaldiaImporter
{
    private array $stats = [
        'persons_created' => 0,
        'persons_matched' => 0,
        'candidacies_created' => 0,
        'results_created' => 0,
        'endorsements_created' => 0,
        'contacts_created' => 0,
        'skipped_rows' => 0,
        'errors' => [],
    ];

    private ?ElectoralEvent $event = null;
    private ?Office $alcaldiaOffice = null;
    private array $municipioCache = [];
    private array $personCache = [];
    private array $orgCache = [];

    public function import(string $filePath, ?SourceFile $sourceFile = null): array
    {
        $this->alcaldiaOffice = Office::where('name', 'Alcaldía')->first();
        if (!$this->alcaldiaOffice) {
            throw new \RuntimeException('Office "Alcaldía" not found. Run seeders first.');
        }

        $this->buildMunicipioCache();
        $this->buildOrgCache();

        // Find the ALCALDIA sheet name first (lightweight - only reads sheet names)
        $reader = new Xlsx();
        $sheetNames = $reader->listWorksheetNames($filePath);
        $alcaldiaSheet = null;
        foreach ($sheetNames as $name) {
            if (str_starts_with(trim($name), 'ALCALDIA') && !str_contains($name, '2027')) {
                $alcaldiaSheet = $name;
                break;
            }
        }

        if (!$alcaldiaSheet) {
            $this->stats['errors'][] = 'No se encontró hoja ALCALDIA';
            return $this->stats;
        }

        // Load ONLY the ALCALDIA sheet to save memory
        $reader->setLoadSheetsOnly([$alcaldiaSheet]);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);

        $this->event = $this->getOrCreateEvent('Elecciones territoriales 2023', 'territorial', '2023-10-29', '2024-2027');
        $this->importAlcaldiaSheet($spreadsheet, $sourceFile);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $this->stats;
    }

    private function importAlcaldiaSheet($spreadsheet, ?SourceFile $sourceFile): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);

        // Find header row (row with PROVINCIA, MUNICIPIO, etc.)
        $headerRow = null;
        foreach ($rows as $idx => $row) {
            $firstCell = TextNormalizer::normalizeName((string) ($row[0] ?? ''));
            if ($firstCell === 'PROVINCIA') {
                $headerRow = $idx;
                break;
            }
        }

        if ($headerRow === null) {
            $this->stats['errors'][] = 'No se encontró fila de encabezados en ALCALDIA';
            return;
        }

        // Process data rows
        for ($i = $headerRow + 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $this->processAlcaldiaRow($row, $i + 1, $sourceFile);
        }
    }

    private function processAlcaldiaRow(array $row, int $rowNumber, ?SourceFile $sourceFile): void
    {
        $municipioRaw = TextNormalizer::cleanString($row[1] ?? '');
        $nombreRaw = TextNormalizer::cleanString($row[2] ?? '');
        $potencialRaw = $row[3] ?? null;
        $votosRaw = $row[4] ?? null;
        $cargoRaw = TextNormalizer::cleanString($row[5] ?? '');
        $tipoAvalRaw = TextNormalizer::cleanString($row[6] ?? '');
        $avalPrincipalRaw = TextNormalizer::cleanString($row[7] ?? '');
        $contactoRaw = TextNormalizer::cleanString($row[8] ?? '');

        if (empty($municipioRaw) || empty($nombreRaw)) {
            $this->stats['skipped_rows']++;
            return;
        }

        // Resolve municipality
        $municipio = $this->resolveMunicipio($municipioRaw);
        if (!$municipio) {
            $this->stats['errors'][] = "Fila {$rowNumber}: Municipio no encontrado: {$municipioRaw}";
            $this->stats['skipped_rows']++;
            return;
        }

        // Get or create contest for this municipality
        $contest = $this->getOrCreateContest($municipio);

        // Resolve or create person
        $person = $this->resolveOrCreatePerson($nombreRaw, $sourceFile);

        // Parse votes
        $votes = TextNormalizer::parseVotes($votosRaw);

        // Determine outcome
        $cargoNorm = TextNormalizer::normalizeName($cargoRaw);
        $outcome = match (true) {
            str_contains($cargoNorm, 'ELECTO') => 'elected',
            str_contains($cargoNorm, 'CANDIDATO') => 'not_elected',
            default => 'pending',
        };

        // Create candidacy
        $candidacy = DB::transaction(function () use ($person, $contest, $outcome, $sourceFile) {
            return Candidacy::firstOrCreate(
                ['person_id' => $person->id, 'contest_id' => $contest->id],
                ['outcome' => $outcome]
            );
        });
        $this->stats['candidacies_created']++;

        // Create electoral result
        if ($votes !== null) {
            ElectoralResult::updateOrCreate(
                [
                    'contest_id' => $contest->id,
                    'candidacy_id' => $candidacy->id,
                    'geographic_unit_id' => $municipio->id,
                    'metric_type' => 'votes',
                ],
                [
                    'grain' => 'municipality',
                    'value' => $votes,
                    'raw_value' => (string) $votosRaw,
                    'source_id' => $sourceFile?->id,
                    'status' => 'validated',
                ]
            );
            $this->stats['results_created']++;
        }

        // Create endorsements from "Aval Principal"
        if (!empty($avalPrincipalRaw)) {
            $this->createEndorsements($candidacy, $avalPrincipalRaw, $tipoAvalRaw, $sourceFile);
        }

        // Create contact point
        if (!empty($contactoRaw) && preg_match('/\d{7,}/', $contactoRaw)) {
            PersonContactPoint::firstOrCreate(
                ['person_id' => $person->id, 'value_raw' => $contactoRaw],
                [
                    'type' => 'mobile',
                    'label' => 'campaign',
                    'data_classification' => 'confidential',
                    'source_id' => $sourceFile?->id,
                ]
            );
            $this->stats['contacts_created']++;
        }

        // Create office tenure if elected
        if ($outcome === 'elected') {
            OfficeTenure::firstOrCreate(
                ['person_id' => $person->id, 'office_id' => $this->alcaldiaOffice->id, 'geographic_unit_id' => $municipio->id, 'candidacy_id' => $candidacy->id],
                ['start_date' => '2024-01-01', 'end_date' => '2027-12-31', 'status' => 'active', 'source_id' => $sourceFile?->id]
            );
        }
    }

    private function createEndorsements(Candidacy $candidacy, string $avalRaw, string $tipoAval, ?SourceFile $sourceFile): void
    {
        // Split by comma — avales can be "CR, U, NUEVO LIBERALISMO"
        $parts = preg_split('/[,;]+/', $avalRaw);
        $isPrimary = true;

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            // Skip non-organization entries (comments, names, etc.)
            $normalized = TextNormalizer::normalizeName($part);
            if (str_contains($normalized, 'APOYO') || str_contains($normalized, 'HUMBERTO') || strlen($normalized) < 2) {
                continue;
            }

            $org = $this->resolveOrCreateOrg($part);

            $endorseType = match (TextNormalizer::normalizeName($tipoAval)) {
                'COAVAL' => 'coaval',
                'AVAL' => 'aval',
                default => $isPrimary ? 'aval' : 'support',
            };

            CandidacyEndorsement::firstOrCreate(
                ['candidacy_id' => $candidacy->id, 'organization_id' => $org->id],
                [
                    'endorsement_type' => $endorseType,
                    'is_primary' => $isPrimary,
                    'source_id' => $sourceFile?->id,
                ]
            );
            $this->stats['endorsements_created']++;
            $isPrimary = false;
        }
    }

    private function resolveOrCreatePerson(string $fullName, ?SourceFile $sourceFile): Person
    {
        $normalized = TextNormalizer::normalizeName($fullName);

        if (isset($this->personCache[$normalized])) {
            $this->stats['persons_matched']++;
            return $this->personCache[$normalized];
        }

        $person = Person::where('normalized_name', $normalized)->first();
        if ($person) {
            $this->personCache[$normalized] = $person;
            $this->stats['persons_matched']++;
            return $person;
        }

        $names = TextNormalizer::splitName($fullName);
        $person = Person::create([
            'first_name' => $names['first_name'],
            'last_name' => $names['last_name'],
            'full_name' => mb_convert_case(trim($fullName), MB_CASE_TITLE, 'UTF-8'),
            'normalized_name' => $normalized,
            'identity_status' => 'unverified',
            'data_classification' => 'public',
        ]);

        PersonAlias::create([
            'person_id' => $person->id,
            'alias_name' => $fullName,
            'normalized_alias' => $normalized,
            'alias_type' => 'electoral',
            'source_id' => $sourceFile?->id,
        ]);

        $this->personCache[$normalized] = $person;
        $this->stats['persons_created']++;

        return $person;
    }

    private function resolveMunicipio(string $raw): ?GeographicUnit
    {
        $normalized = TextNormalizer::normalizeName($raw);

        if (isset($this->municipioCache[$normalized])) {
            return $this->municipioCache[$normalized];
        }

        // Try alias match
        $alias = GeographicAlias::where('normalized_alias', $normalized)->first();
        if ($alias) {
            $unit = $alias->geographicUnit;
            $this->municipioCache[$normalized] = $unit;
            return $unit;
        }

        // Try direct canonical name match
        $unit = GeographicUnit::where('type', 'municipality')
            ->whereRaw("UPPER(canonical_name) = ?", [$normalized])
            ->first();

        if ($unit) {
            // Create alias for future matching
            GeographicAlias::create([
                'geographic_unit_id' => $unit->id,
                'raw_alias' => $raw,
                'normalized_alias' => $normalized,
            ]);
            $this->municipioCache[$normalized] = $unit;
            return $unit;
        }

        // Fuzzy match via trigram
        $unit = GeographicUnit::where('type', 'municipality')
            ->whereRaw("similarity(UPPER(canonical_name), ?) > 0.4", [$normalized])
            ->orderByRaw("similarity(UPPER(canonical_name), ?) DESC", [$normalized])
            ->first();

        if ($unit) {
            GeographicAlias::create([
                'geographic_unit_id' => $unit->id,
                'raw_alias' => $raw,
                'normalized_alias' => $normalized,
            ]);
            $this->municipioCache[$normalized] = $unit;
            return $unit;
        }

        return null;
    }

    private function resolveOrCreateOrg(string $raw): PoliticalOrganization
    {
        $normalized = TextNormalizer::normalizeName($raw);

        if (isset($this->orgCache[$normalized])) {
            return $this->orgCache[$normalized];
        }

        // Known abbreviations mapping
        $knownAbbrevs = [
            'C' => 'Partido Conservador',
            'L' => 'Partido Liberal',
            'CR' => 'Cambio Radical',
            'U' => 'Partido de la U',
            'CD' => 'Centro Democrático',
            'VERDE' => 'Alianza Verde',
            'MIRA' => 'MIRA',
            'ASI' => 'ASI',
            'ADA' => 'ADA',
            'MAIS' => 'MAIS',
            'FIRMAS' => 'Grupo Significativo de Ciudadanos',
        ];

        if (isset($knownAbbrevs[$normalized])) {
            $canonicalName = $knownAbbrevs[$normalized];
            $org = PoliticalOrganization::where('canonical_name', $canonicalName)->first();
            if ($org) {
                $this->orgCache[$normalized] = $org;
                return $org;
            }
        }

        // Try alias
        $alias = OrganizationAlias::where('normalized_alias', $normalized)->first();
        if ($alias) {
            $org = $alias->organization;
            $this->orgCache[$normalized] = $org;
            return $org;
        }

        // Try canonical name
        $org = PoliticalOrganization::whereRaw("UPPER(canonical_name) = ?", [$normalized])->first();
        if ($org) {
            $this->orgCache[$normalized] = $org;
            return $org;
        }

        // Create new organization
        $displayName = isset($knownAbbrevs[$normalized]) ? $knownAbbrevs[$normalized] : mb_convert_case(trim($raw), MB_CASE_TITLE, 'UTF-8');
        $org = PoliticalOrganization::create([
            'type' => 'party',
            'canonical_name' => $displayName,
            'acronym' => strlen($normalized) <= 5 ? $normalized : null,
            'status' => 'active',
        ]);

        OrganizationAlias::create([
            'organization_id' => $org->id,
            'raw_alias' => $raw,
            'normalized_alias' => $normalized,
        ]);

        $this->orgCache[$normalized] = $org;

        return $org;
    }

    private function getOrCreateEvent(string $name, string $type, string $date, string $period): ElectoralEvent
    {
        return ElectoralEvent::firstOrCreate(
            ['name' => $name],
            [
                'event_type' => $type,
                'election_date' => $date,
                'political_period' => $period,
                'authority' => 'Registraduría Nacional',
                'scope' => 'departmental',
                'status' => 'official',
            ]
        );
    }

    private function getOrCreateContest(GeographicUnit $municipio): Contest
    {
        $key = $municipio->id;
        static $contestCache = [];

        if (isset($contestCache[$key])) {
            return $contestCache[$key];
        }

        $contest = Contest::firstOrCreate(
            [
                'electoral_event_id' => $this->event->id,
                'office_id' => $this->alcaldiaOffice->id,
                'geographic_unit_id' => $municipio->id,
            ],
            [
                'name' => "Alcaldía {$municipio->canonical_name} 2023",
                'seats' => 1,
                'list_type' => 'na',
                'status' => 'official',
            ]
        );

        $contestCache[$key] = $contest;

        return $contest;
    }

    private function buildMunicipioCache(): void
    {
        $aliases = GeographicAlias::with('geographicUnit')
            ->whereHas('geographicUnit', fn ($q) => $q->where('type', 'municipality'))
            ->get();

        foreach ($aliases as $alias) {
            $this->municipioCache[$alias->normalized_alias] = $alias->geographicUnit;
        }
    }

    private function buildOrgCache(): void
    {
        $orgs = PoliticalOrganization::all();
        foreach ($orgs as $org) {
            $this->orgCache[TextNormalizer::normalizeName($org->canonical_name)] = $org;
        }

        $aliases = OrganizationAlias::with('organization')->get();
        foreach ($aliases as $alias) {
            $this->orgCache[$alias->normalized_alias] = $alias->organization;
        }
    }
}
