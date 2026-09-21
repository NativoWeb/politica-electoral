<?php

namespace App\Services\Import;

use App\Models\Candidacy;
use App\Models\CandidacyEndorsement;
use App\Models\Contest;
use App\Models\Corporation;
use App\Models\ElectoralEvent;
use App\Models\ElectoralResult;
use App\Models\GeographicAlias;
use App\Models\GeographicUnit;
use App\Models\Person;
use App\Models\PersonAlias;
use App\Models\PersonContactPoint;
use App\Models\OfficeTenure;
use App\Models\PoliticalOrganization;
use App\Models\OrganizationAlias;
use App\Models\SourceFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

class ConcejoImporter
{
    private array $stats = [
        'sheets_processed' => 0,
        'concejales_created' => 0,
        'candidacies_created' => 0,
        'results_created' => 0,
        'skipped_sheets' => 0,
        'errors' => [],
    ];

    private ?ElectoralEvent $event = null;
    private ?Corporation $concejo = null;
    private array $municipioCache = [];
    private array $personCache = [];
    private array $orgCache = [];

    public function import(string $filePath, ?SourceFile $sourceFile = null): array
    {
        $this->concejo = Corporation::where('name', 'Concejo')->first();
        if (!$this->concejo) {
            throw new \RuntimeException('Corporation "Concejo" not found. Run seeders first.');
        }

        $this->event = ElectoralEvent::where('name', 'Elecciones territoriales 2023')->first();
        if (!$this->event) {
            throw new \RuntimeException('Electoral event not found. Run AlcaldiaImporter first.');
        }

        $this->buildCaches();

        // List sheet names without loading data
        $reader = new Xlsx();
        $sheetNames = $reader->listWorksheetNames($filePath);
        $concejoSheets = array_filter($sheetNames, fn ($n) => str_starts_with($n, 'C.'));

        // Load each sheet one at a time to avoid memory exhaustion
        foreach ($concejoSheets as $sheetName) {
            $sheetReader = new Xlsx();
            $sheetReader->setLoadSheetsOnly([$sheetName]);
            $sheetReader->setReadDataOnly(true);
            $spreadsheet = $sheetReader->load($filePath);

            $this->importConcejoSheet($spreadsheet->getActiveSheet(), $sheetName, $sourceFile);

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet, $sheetReader);
        }

        return $this->stats;
    }

    private function importConcejoSheet($sheet, string $sheetName, ?SourceFile $sourceFile): void
    {
        $rows = $sheet->toArray(null, true, true, false);

        // Extract municipality name from sheet name (e.g., "C.BUCARAMANGA" -> "BUCARAMANGA")
        $mpioFromSheet = substr($sheetName, 2); // Remove "C."

        // Find the "CONCEJO DE ... ELECTO" separator to get elected concejales
        $electoSectionStart = null;
        $mpioFromContent = null;

        foreach ($rows as $idx => $row) {
            $firstCell = TextNormalizer::cleanString($row[0] ?? '');
            if (str_contains(mb_strtoupper($firstCell), 'CONCEJO DE') && str_contains(mb_strtoupper($firstCell), 'ELECTO')) {
                $electoSectionStart = $idx;
                // Extract municipality name: "CONCEJO DE BUCARAMANGA ELECTO"
                $mpioFromContent = mb_strtoupper($firstCell);
                $mpioFromContent = str_replace(['CONCEJO DE', 'ELECTO', 'CONCEJO'], '', $mpioFromContent);
                $mpioFromContent = trim($mpioFromContent);
                break;
            }
        }

        // Resolve municipality
        $mpioName = $mpioFromContent ?: $mpioFromSheet;
        $municipio = $this->resolveMunicipio($mpioName);

        if (!$municipio) {
            $this->stats['errors'][] = "Hoja {$sheetName}: Municipio no encontrado: {$mpioName}";
            $this->stats['skipped_sheets']++;
            return;
        }

        // Create contest for Concejo
        $contest = Contest::firstOrCreate(
            [
                'electoral_event_id' => $this->event->id,
                'corporation_id' => $this->concejo->id,
                'geographic_unit_id' => $municipio->id,
            ],
            [
                'name' => "Concejo {$municipio->canonical_name} 2023",
                'seats' => 0, // Will be updated based on elected count
                'list_type' => 'open',
                'status' => 'official',
            ]
        );

        // Import the elected section (after "CONCEJO DE ... ELECTO")
        if ($electoSectionStart !== null) {
            $electedCount = $this->importElectedSection($rows, $electoSectionStart, $contest, $municipio, $sourceFile);
            if ($electedCount > 0) {
                $contest->update(['seats' => $electedCount]);
            }
        }

        // Also import individual list sections (before the elected section)
        // These contain all candidates per party list
        $this->importListSections($rows, $electoSectionStart ?? count($rows), $contest, $municipio, $sourceFile);

        $this->stats['sheets_processed']++;
    }

    private function importElectedSection(array $rows, int $startIdx, Contest $contest, GeographicUnit $municipio, ?SourceFile $sourceFile): int
    {
        // Header is typically at startIdx + 1: CONCEJAL, VOTOS, CONTACTO, PARTIDO
        $headerIdx = $startIdx + 1;
        $electedCount = 0;

        for ($i = $headerIdx + 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $nombre = TextNormalizer::cleanString($row[0] ?? '');

            if (empty($nombre)) {
                break;
            }

            // Skip non-candidate rows
            $nombreUpper = mb_strtoupper($nombre);
            if (in_array($nombreUpper, ['CONCEJAL', 'TOTAL', 'VOTOS POR LA LISTA', ''])
                || str_starts_with($nombreUpper, 'EDIL')
                || str_starts_with($nombreUpper, 'CANDIDATO GOBERNACION')
                || str_starts_with($nombreUpper, 'CANDIDATO ALCALDIA')
                || str_starts_with($nombreUpper, "\t")) {
                break;
            }

            $votos = TextNormalizer::parseVotes($row[1] ?? null);
            $contacto = TextNormalizer::cleanString($row[2] ?? '');
            $partido = TextNormalizer::cleanString($row[3] ?? '');

            if ($votos === null) {
                continue;
            }

            $person = $this->resolveOrCreatePerson($nombre, $sourceFile);

            $candidacy = Candidacy::firstOrCreate(
                ['person_id' => $person->id, 'contest_id' => $contest->id],
                ['outcome' => 'elected']
            );
            $this->stats['candidacies_created']++;

            ElectoralResult::updateOrCreate(
                [
                    'contest_id' => $contest->id,
                    'candidacy_id' => $candidacy->id,
                    'geographic_unit_id' => $municipio->id,
                    'metric_type' => 'votes',
                ],
                [
                    'grain' => 'municipality',
                    'value' => $votos,
                    'raw_value' => (string) ($row[1] ?? ''),
                    'source_id' => $sourceFile?->id,
                    'status' => 'validated',
                ]
            );
            $this->stats['results_created']++;

            // Endorsement from partido
            if (!empty($partido)) {
                $org = $this->resolveOrCreateOrg($partido);
                CandidacyEndorsement::firstOrCreate(
                    ['candidacy_id' => $candidacy->id, 'organization_id' => $org->id],
                    ['endorsement_type' => 'aval', 'is_primary' => true, 'source_id' => $sourceFile?->id]
                );
            }

            // Contact
            if (!empty($contacto) && preg_match('/\d{7,}/', $contacto)) {
                PersonContactPoint::firstOrCreate(
                    ['person_id' => $person->id, 'value_raw' => $contacto],
                    ['type' => 'mobile', 'label' => 'campaign', 'data_classification' => 'confidential', 'source_id' => $sourceFile?->id]
                );
            }

            // Office tenure
            OfficeTenure::firstOrCreate(
                ['person_id' => $person->id, 'corporation_id' => $this->concejo->id, 'geographic_unit_id' => $municipio->id, 'candidacy_id' => $candidacy->id],
                ['start_date' => '2024-01-01', 'end_date' => '2027-12-31', 'status' => 'active', 'source_id' => $sourceFile?->id]
            );

            $electedCount++;
            $this->stats['concejales_created']++;
        }

        return $electedCount;
    }

    private function importListSections(array $rows, int $endBefore, Contest $contest, GeographicUnit $municipio, ?SourceFile $sourceFile): void
    {
        // Each list section starts with "LISTA CONCEJO <PARTIDO>" in row[0]
        // Followed by header: NOMBRE, VOTOS, CARGO, CONTACTO
        $i = 0;
        while ($i < $endBefore) {
            $firstCell = TextNormalizer::cleanString($rows[$i][0] ?? '');

            if (str_starts_with(mb_strtoupper($firstCell), 'LISTA CONCEJO')) {
                // Extract party name from list title
                $listParty = str_ireplace(['LISTA CONCEJO', 'LISTA'], '', $firstCell);
                $listParty = trim($listParty);

                // Next row is header, skip it
                $i += 2;

                // Process candidates until empty row or summary
                while ($i < $endBefore) {
                    $nombre = TextNormalizer::cleanString($rows[$i][0] ?? '');
                    $nombreUpper = mb_strtoupper($nombre);

                    if (empty($nombre) || in_array($nombreUpper, ['VOTOS POR LA LISTA', 'TOTAL'])) {
                        $i++;
                        continue;
                    }

                    // Stop at section boundary
                    if (str_starts_with($nombreUpper, 'LISTA ') || str_starts_with($nombreUpper, 'CONCEJO DE')) {
                        break;
                    }

                    $votos = TextNormalizer::parseVotes($rows[$i][1] ?? null);
                    $cargoRaw = TextNormalizer::cleanString($rows[$i][2] ?? '');

                    if ($votos === null) {
                        $i++;
                        continue;
                    }

                    $person = $this->resolveOrCreatePerson($nombre, $sourceFile);

                    $isElected = str_contains(mb_strtoupper($cargoRaw), 'ELECTO');
                    $candidacy = Candidacy::firstOrCreate(
                        ['person_id' => $person->id, 'contest_id' => $contest->id],
                        ['outcome' => $isElected ? 'elected' : 'not_elected']
                    );

                    // Only create result if we don't already have one from the elected section
                    ElectoralResult::firstOrCreate(
                        [
                            'contest_id' => $contest->id,
                            'candidacy_id' => $candidacy->id,
                            'geographic_unit_id' => $municipio->id,
                            'metric_type' => 'votes',
                        ],
                        [
                            'grain' => 'municipality',
                            'value' => $votos,
                            'raw_value' => (string) ($rows[$i][1] ?? ''),
                            'source_id' => $sourceFile?->id,
                            'status' => 'validated',
                        ]
                    );

                    // Endorsement
                    if (!empty($listParty)) {
                        $org = $this->resolveOrCreateOrg($listParty);
                        CandidacyEndorsement::firstOrCreate(
                            ['candidacy_id' => $candidacy->id, 'organization_id' => $org->id],
                            ['endorsement_type' => 'aval', 'is_primary' => true, 'source_id' => $sourceFile?->id]
                        );
                    }

                    $i++;
                }
            } else {
                $i++;
            }
        }
    }

    private function resolveOrCreatePerson(string $fullName, ?SourceFile $sourceFile): Person
    {
        $normalized = TextNormalizer::normalizeName($fullName);

        if (isset($this->personCache[$normalized])) {
            return $this->personCache[$normalized];
        }

        $person = Person::where('normalized_name', $normalized)->first();
        if ($person) {
            $this->personCache[$normalized] = $person;
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

        return $person;
    }

    private function resolveMunicipio(string $raw): ?GeographicUnit
    {
        $normalized = TextNormalizer::normalizeName($raw);

        if (isset($this->municipioCache[$normalized])) {
            return $this->municipioCache[$normalized];
        }

        $alias = GeographicAlias::where('normalized_alias', $normalized)->first();
        if ($alias) {
            $this->municipioCache[$normalized] = $alias->geographicUnit;
            return $alias->geographicUnit;
        }

        $unit = GeographicUnit::where('type', 'municipality')
            ->whereRaw("UPPER(canonical_name) = ?", [$normalized])
            ->first();

        if (!$unit) {
            $unit = GeographicUnit::where('type', 'municipality')
                ->whereRaw("similarity(UPPER(canonical_name), ?) > 0.35", [$normalized])
                ->orderByRaw("similarity(UPPER(canonical_name), ?) DESC", [$normalized])
                ->first();
        }

        if ($unit) {
            GeographicAlias::firstOrCreate(
                ['geographic_unit_id' => $unit->id, 'normalized_alias' => $normalized],
                ['raw_alias' => $raw]
            );
            $this->municipioCache[$normalized] = $unit;
        }

        return $unit;
    }

    private function resolveOrCreateOrg(string $raw): PoliticalOrganization
    {
        $normalized = TextNormalizer::normalizeName($raw);

        if (isset($this->orgCache[$normalized])) {
            return $this->orgCache[$normalized];
        }

        $alias = OrganizationAlias::where('normalized_alias', $normalized)->first();
        if ($alias) {
            $this->orgCache[$normalized] = $alias->organization;
            return $alias->organization;
        }

        $org = PoliticalOrganization::whereRaw("UPPER(canonical_name) = ?", [$normalized])->first();
        if ($org) {
            $this->orgCache[$normalized] = $org;
            return $org;
        }

        $org = PoliticalOrganization::create([
            'type' => 'party',
            'canonical_name' => mb_convert_case(trim($raw), MB_CASE_TITLE, 'UTF-8'),
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

    private function buildCaches(): void
    {
        $aliases = GeographicAlias::with('geographicUnit')
            ->whereHas('geographicUnit', fn ($q) => $q->where('type', 'municipality'))
            ->get();
        foreach ($aliases as $alias) {
            $this->municipioCache[$alias->normalized_alias] = $alias->geographicUnit;
        }

        $orgs = PoliticalOrganization::all();
        foreach ($orgs as $org) {
            $this->orgCache[TextNormalizer::normalizeName($org->canonical_name)] = $org;
        }
        foreach (OrganizationAlias::with('organization')->get() as $alias) {
            $this->orgCache[$alias->normalized_alias] = $alias->organization;
        }
    }
}
