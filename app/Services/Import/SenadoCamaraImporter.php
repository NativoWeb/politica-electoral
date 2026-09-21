<?php

namespace App\Services\Import;

use App\Models\Candidacy;
use App\Models\CandidacyEndorsement;
use App\Models\Contest;
use App\Models\Corporation;
use App\Models\ElectoralEvent;
use App\Models\ElectoralList;
use App\Models\ElectoralResult;
use App\Models\GeographicAlias;
use App\Models\GeographicUnit;
use App\Models\OrganizationAlias;
use App\Models\Person;
use App\Models\PersonAlias;
use App\Models\PoliticalOrganization;
use App\Models\SourceFile;
use App\Models\TurnoutMetric;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

class SenadoCamaraImporter
{
    private array $stats = [
        'senado_results' => 0,
        'camara_results' => 0,
        'consulta_results' => 0,
        'persons_created' => 0,
        'orgs_created' => 0,
        'errors' => [],
    ];

    private ?ElectoralEvent $eventLegislativo = null;
    private ?ElectoralEvent $eventConsulta = null;
    private ?Corporation $senado = null;
    private ?Corporation $camara = null;
    private array $municipioCache = [];
    private array $orgCache = [];
    private array $personCache = [];

    public function import(string $filePath, ?SourceFile $sourceFile = null): array
    {
        $this->senado = Corporation::where('name', 'Senado')->first();
        $this->camara = Corporation::where('name', 'Cámara de Representantes')->first();

        $this->eventLegislativo = ElectoralEvent::firstOrCreate(
            ['name' => 'Elecciones legislativas 2026'],
            [
                'event_type' => 'legislative',
                'election_date' => '2026-03-08',
                'political_period' => '2026-2030',
                'authority' => 'Registraduría Nacional',
                'scope' => 'national',
                'status' => 'official',
            ]
        );

        $this->eventConsulta = ElectoralEvent::firstOrCreate(
            ['name' => 'Consultas interpartidistas 2026'],
            [
                'event_type' => 'consultation',
                'election_date' => '2026-03-08',
                'authority' => 'Registraduría Nacional',
                'scope' => 'national',
                'status' => 'official',
            ]
        );

        $this->buildCaches();

        $reader = new Xlsx();
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);

        $this->importSenado($spreadsheet, $sourceFile);
        $this->importCamara($spreadsheet, $sourceFile);
        $this->importConsulta($spreadsheet, $sourceFile);

        return $this->stats;
    }

    private function importSenado($spreadsheet, ?SourceFile $sourceFile): void
    {
        $sheet = $spreadsheet->getSheetByName('SENADO x MPIO 2026');
        if (!$sheet) {
            $this->stats['errors'][] = 'Hoja SENADO x MPIO 2026 no encontrada';
            return;
        }

        $rows = $sheet->toArray(null, true, true, false);
        $headers = $rows[0];

        // Get Santander geographic unit for the contest
        $santander = GeographicUnit::where('type', 'department')->where('canonical_name', 'Santander')->first();

        // Create contest for Senado
        $contest = Contest::firstOrCreate(
            ['electoral_event_id' => $this->eventLegislativo->id, 'corporation_id' => $this->senado->id, 'name' => 'Senado 2026'],
            ['seats' => 108, 'list_type' => 'preferential', 'status' => 'official', 'geographic_unit_id' => $santander?->id]
        );

        // Column mapping based on headers:
        // 0:PROVINCIA, 1:MUNICIPIO, 2:SENADO CENTRO, 3:LUIS EDUARDO DIAZ, 4:TOTAL CONSERVADOR,
        // 5:JAIME DURAN, 6:RICHARD AGUILAR, 7:TOTAL LIBERAL, 8:NELSON LOPEZ,
        // 9:TOTAL CAMBIO RADICAL, 10:GUSTAVO MORENO, 11:PARTIDO ALIANZA POR COLOMBIA, 12:PACTO HISTORICO
        $columnMap = [
            ['col' => 2, 'type' => 'party_total', 'party' => 'Centro Democrático'],
            ['col' => 3, 'type' => 'candidate', 'name' => 'Luis Eduardo Díaz', 'party' => 'Partido Conservador'],
            ['col' => 4, 'type' => 'party_total', 'party' => 'Partido Conservador'],
            ['col' => 5, 'type' => 'candidate', 'name' => 'Jaime Durán', 'party' => 'Partido Liberal'],
            ['col' => 6, 'type' => 'candidate', 'name' => 'Richard Aguilar', 'party' => 'Partido Liberal'],
            ['col' => 7, 'type' => 'party_total', 'party' => 'Partido Liberal'],
            ['col' => 8, 'type' => 'candidate', 'name' => 'Nelson López', 'party' => 'Cambio Radical'],
            ['col' => 9, 'type' => 'party_total', 'party' => 'Cambio Radical'],
            ['col' => 10, 'type' => 'candidate', 'name' => 'Gustavo Moreno', 'party' => 'Alianza por Colombia'],
            ['col' => 11, 'type' => 'party_total', 'party' => 'Alianza por Colombia'],
            ['col' => 12, 'type' => 'party_total', 'party' => 'Pacto Histórico'],
        ];

        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $mpioRaw = TextNormalizer::cleanString($row[1] ?? '');
            if (empty($mpioRaw)) {
                continue;
            }

            $municipio = $this->resolveMunicipio($mpioRaw);
            if (!$municipio) {
                $this->stats['errors'][] = "Senado fila {$i}: Municipio no encontrado: {$mpioRaw}";
                continue;
            }

            foreach ($columnMap as $mapping) {
                $value = TextNormalizer::parseVotes($row[$mapping['col']] ?? null);
                if ($value === null || $value === 0) {
                    continue;
                }

                if ($mapping['type'] === 'candidate') {
                    $person = $this->resolveOrCreatePerson($mapping['name'], $sourceFile);
                    $org = $this->resolveOrCreateOrg($mapping['party']);

                    $candidacy = Candidacy::firstOrCreate(
                        ['person_id' => $person->id, 'contest_id' => $contest->id],
                        ['outcome' => 'pending']
                    );

                    CandidacyEndorsement::firstOrCreate(
                        ['candidacy_id' => $candidacy->id, 'organization_id' => $org->id],
                        ['endorsement_type' => 'aval', 'is_primary' => true, 'source_id' => $sourceFile?->id]
                    );

                    ElectoralResult::updateOrCreate(
                        ['contest_id' => $contest->id, 'candidacy_id' => $candidacy->id, 'geographic_unit_id' => $municipio->id, 'metric_type' => 'nominal_votes'],
                        ['grain' => 'municipality', 'value' => $value, 'raw_value' => (string) ($row[$mapping['col']] ?? ''), 'source_id' => $sourceFile?->id, 'status' => 'validated']
                    );
                } else {
                    // Party total
                    $org = $this->resolveOrCreateOrg($mapping['party']);

                    ElectoralResult::updateOrCreate(
                        ['contest_id' => $contest->id, 'organization_id' => $org->id, 'geographic_unit_id' => $municipio->id, 'metric_type' => 'votes'],
                        ['grain' => 'municipality', 'value' => $value, 'raw_value' => (string) ($row[$mapping['col']] ?? ''), 'source_id' => $sourceFile?->id, 'status' => 'validated']
                    );
                }

                $this->stats['senado_results']++;
            }
        }
    }

    private function importCamara($spreadsheet, ?SourceFile $sourceFile): void
    {
        $sheet = $spreadsheet->getSheetByName('CAMARA x MPIO 2026');
        if (!$sheet) {
            $this->stats['errors'][] = 'Hoja CAMARA x MPIO 2026 no encontrada';
            return;
        }

        $rows = $sheet->toArray(null, true, true, false);
        $headers = $rows[0];

        $santander = GeographicUnit::where('type', 'department')->where('canonical_name', 'Santander')->first();

        $contest = Contest::firstOrCreate(
            ['electoral_event_id' => $this->eventLegislativo->id, 'corporation_id' => $this->camara->id, 'name' => 'Cámara Santander 2026'],
            ['seats' => 7, 'list_type' => 'preferential', 'status' => 'official', 'geographic_unit_id' => $santander?->id]
        );

        // Parse header to identify candidate columns vs party total columns
        // Groups: partido -> [candidate_cols], total_col
        $groups = [
            ['party' => 'Centro Democrático', 'candidates' => [2, 3, 4, 5, 6, 7], 'list_col' => 8, 'total_col' => 9],
            ['party' => 'Partido de la U', 'candidates' => [11, 12, 13, 14, 15, 16, 17], 'list_col' => 18, 'total_col' => 19],
            ['party' => 'Partido Conservador', 'candidates' => [20, 21, 22, 23, 24, 25, 26], 'list_col' => 27, 'total_col' => 28],
            ['party' => 'Partido Liberal', 'candidates' => [29, 30, 31, 32, 33, 34, 35], 'list_col' => 36, 'total_col' => 37],
            ['party' => 'Cambio Radical', 'candidates' => [38, 39, 40, 41, 42, 43, 44], 'list_col' => 45, 'total_col' => 46],
            ['party' => 'Pacto Histórico', 'candidates' => [47, 48, 49, 50, 51, 52], 'list_col' => 53, 'total_col' => 54],
            ['party' => 'Verde / En Marcha', 'candidates' => [55, 56, 57, 58, 59, 60, 61], 'list_col' => 62, 'total_col' => 63],
            ['party' => 'MIRA / Nuevo Liberal', 'candidates' => [64, 65, 66, 67, 68, 69, 70], 'list_col' => 71, 'total_col' => 72],
            ['party' => 'Avanza', 'candidates' => [73, 74, 75, 76, 77, 78], 'list_col' => 79, 'total_col' => 80],
        ];

        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $mpioRaw = TextNormalizer::cleanString($row[1] ?? '');
            if (empty($mpioRaw)) {
                continue;
            }

            $municipio = $this->resolveMunicipio($mpioRaw);
            if (!$municipio) {
                continue;
            }

            foreach ($groups as $group) {
                $org = $this->resolveOrCreateOrg($group['party']);

                // Party total
                $totalCol = $group['total_col'];
                $totalVotes = TextNormalizer::parseVotes($row[$totalCol] ?? null);
                if ($totalVotes !== null && $totalVotes > 0) {
                    ElectoralResult::updateOrCreate(
                        ['contest_id' => $contest->id, 'organization_id' => $org->id, 'geographic_unit_id' => $municipio->id, 'metric_type' => 'votes'],
                        ['grain' => 'municipality', 'value' => $totalVotes, 'raw_value' => (string) ($row[$totalCol] ?? ''), 'source_id' => $sourceFile?->id, 'status' => 'validated']
                    );
                    $this->stats['camara_results']++;
                }

                // Individual candidates
                foreach ($group['candidates'] as $colIdx) {
                    if ($colIdx >= count($headers)) {
                        continue;
                    }
                    $candidateName = TextNormalizer::cleanString($headers[$colIdx] ?? '');
                    if (empty($candidateName)) {
                        continue;
                    }

                    $votes = TextNormalizer::parseVotes($row[$colIdx] ?? null);
                    if ($votes === null || $votes === 0) {
                        continue;
                    }

                    $person = $this->resolveOrCreatePerson($candidateName, $sourceFile);
                    $candidacy = Candidacy::firstOrCreate(
                        ['person_id' => $person->id, 'contest_id' => $contest->id],
                        ['outcome' => 'pending']
                    );

                    CandidacyEndorsement::firstOrCreate(
                        ['candidacy_id' => $candidacy->id, 'organization_id' => $org->id],
                        ['endorsement_type' => 'aval', 'is_primary' => true, 'source_id' => $sourceFile?->id]
                    );

                    ElectoralResult::updateOrCreate(
                        ['contest_id' => $contest->id, 'candidacy_id' => $candidacy->id, 'geographic_unit_id' => $municipio->id, 'metric_type' => 'nominal_votes'],
                        ['grain' => 'municipality', 'value' => $votes, 'source_id' => $sourceFile?->id, 'status' => 'validated']
                    );
                    $this->stats['camara_results']++;
                }
            }
        }
    }

    private function importConsulta($spreadsheet, ?SourceFile $sourceFile): void
    {
        $sheet = $spreadsheet->getSheetByName('CONSULTA');
        if (!$sheet) {
            return;
        }

        $rows = $sheet->toArray(null, true, true, false);

        // Row 0: consultation group headers
        // Row 1: PROVINCIA, MUNICIPIO, candidate names...
        // Candidates: cols 2-10 = La Gran Consulta, col 11 = TOTAL, cols 12-13 = Frente por la Vida, col 14 = TOTAL, col 15 = Pacto Histórico
        $candidates = [
            ['col' => 2, 'name' => 'Paloma Valencia', 'group' => 'La Gran Consulta por Colombia'],
            ['col' => 3, 'name' => 'Juan Daniel Oviedo', 'group' => 'La Gran Consulta por Colombia'],
            ['col' => 4, 'name' => 'Juan Manuel Galán', 'group' => 'La Gran Consulta por Colombia'],
            ['col' => 5, 'name' => 'Juan Carlos Pinzón', 'group' => 'La Gran Consulta por Colombia'],
            ['col' => 6, 'name' => 'Vicky Dávila', 'group' => 'La Gran Consulta por Colombia'],
            ['col' => 7, 'name' => 'Enrique Peñalosa', 'group' => 'La Gran Consulta por Colombia'],
            ['col' => 8, 'name' => 'Aníbal Gaviria', 'group' => 'La Gran Consulta por Colombia'],
            ['col' => 9, 'name' => 'David Luna', 'group' => 'La Gran Consulta por Colombia'],
            ['col' => 10, 'name' => 'Mauricio Cárdenas', 'group' => 'La Gran Consulta por Colombia'],
            ['col' => 12, 'name' => 'Roy Barreras', 'group' => 'Frente por la Vida'],
            ['col' => 13, 'name' => 'Daniel Quintero', 'group' => 'Frente por la Vida'],
            ['col' => 15, 'name' => 'Iván Cepeda', 'group' => 'Pacto Histórico'],
        ];

        // Create contests for each consultation group
        $consultaContests = [];
        foreach (['La Gran Consulta por Colombia', 'Frente por la Vida', 'Pacto Histórico'] as $group) {
            $consultaContests[$group] = Contest::firstOrCreate(
                ['electoral_event_id' => $this->eventConsulta->id, 'name' => "Consulta {$group} 2026"],
                ['seats' => 1, 'list_type' => 'na', 'status' => 'official']
            );
        }

        // Skip header rows, start from row index 3 (BUCARAMANGA)
        for ($i = 3; $i < count($rows); $i++) {
            $row = $rows[$i];
            $mpioRaw = TextNormalizer::cleanString($row[1] ?? '');
            if (empty($mpioRaw) || mb_strtoupper($mpioRaw) === 'TOTAL') {
                continue;
            }

            $municipio = $this->resolveMunicipio($mpioRaw);
            if (!$municipio) {
                continue;
            }

            foreach ($candidates as $cand) {
                $votes = TextNormalizer::parseVotes($row[$cand['col']] ?? null);
                if ($votes === null || $votes === 0) {
                    continue;
                }

                $contest = $consultaContests[$cand['group']];
                $person = $this->resolveOrCreatePerson($cand['name'], $sourceFile);

                $candidacy = Candidacy::firstOrCreate(
                    ['person_id' => $person->id, 'contest_id' => $contest->id],
                    ['outcome' => 'pending']
                );

                ElectoralResult::updateOrCreate(
                    ['contest_id' => $contest->id, 'candidacy_id' => $candidacy->id, 'geographic_unit_id' => $municipio->id, 'metric_type' => 'votes'],
                    ['grain' => 'municipality', 'value' => $votes, 'source_id' => $sourceFile?->id, 'status' => 'validated']
                );
                $this->stats['consulta_results']++;
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
        $this->stats['persons_created']++;

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
            ->whereRaw("similarity(UPPER(canonical_name), ?) > 0.35", [$normalized])
            ->orderByRaw("similarity(UPPER(canonical_name), ?) DESC", [$normalized])
            ->first();

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
        if (!$org) {
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
            $this->stats['orgs_created']++;
        }

        $this->orgCache[$normalized] = $org;

        return $org;
    }

    private function buildCaches(): void
    {
        foreach (GeographicAlias::with('geographicUnit')->whereHas('geographicUnit', fn ($q) => $q->where('type', 'municipality'))->get() as $alias) {
            $this->municipioCache[$alias->normalized_alias] = $alias->geographicUnit;
        }
        foreach (PoliticalOrganization::all() as $org) {
            $this->orgCache[TextNormalizer::normalizeName($org->canonical_name)] = $org;
        }
        foreach (OrganizationAlias::with('organization')->get() as $alias) {
            $this->orgCache[$alias->normalized_alias] = $alias->organization;
        }
    }
}
