<?php

namespace App\Console\Commands;

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
use App\Models\PoliticalOrganization;
use App\Models\SourceFile;
use App\Services\Import\TextNormalizer;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

class ImportAsambleaResultados extends Command
{
    protected $signature = 'import:asamblea-resultados
        {--dry-run : Show what would be imported without inserting}';

    protected $description = 'Import Asamblea 2023 results per municipality from ASAMBLEA 2023 RESULTADOS.xlsx';

    private const FILE_PATH = 'C:\\Users\\Marly Rangel\\Downloads\\ASAMBLEA 2023 RESULTADOS.xlsx';

    private const PARTY_MAP = [
        'CD' => [
            'canonical' => 'Centro Democrático',
            'list_col' => 4,
            'total_col' => 20,
            'cand_start' => 5,
            'cand_end' => 19,
        ],
        'LIBERAL' => [
            'canonical' => 'Liberal',
            'list_col' => 21,
            'total_col' => 38,
            'cand_start' => 22,
            'cand_end' => 37,
        ],
        'CAMBIO RADICAL' => [
            'canonical' => 'Cambio Radical',
            'list_col' => 39,
            'total_col' => 52,
            'cand_start' => 40,
            'cand_end' => 51,
        ],
        'CONSERVADOR' => [
            'canonical' => 'Conservador',
            'list_col' => 53,
            'total_col' => 67,
            'cand_start' => 54,
            'cand_end' => 66,
        ],
        'VERDE' => [
            'canonical' => 'Alianza Verde',
            'list_col' => 68,
            'total_col' => 85,
            'cand_start' => 69,
            'cand_end' => 84,
        ],
        'ASI' => [
            'canonical' => 'ASI',
            'list_col' => 86,
            'total_col' => 102,
            'cand_start' => 87,
            'cand_end' => 101,
        ],
        'LA U' => [
            'canonical' => 'Partido de la U',
            'list_col' => 103,
            'total_col' => 112,
            'cand_start' => 104,
            'cand_end' => 111,
        ],
        'SUMEMOS' => [
            'canonical' => 'Sumemos',
            'list_col' => 113,
            'total_col' => 130,
            'cand_start' => 114,
            'cand_end' => 129,
        ],
        'NUEVO LIBERALISMO' => [
            'canonical' => 'Nuevo Liberalismo',
            'list_col' => 131,
            'total_col' => 146,
            'cand_start' => 132,
            'cand_end' => 145,
        ],
    ];

    private const ELECTED_CANDIDATES = [
        'MARTIN ALONSO GUTIERREZ' => 'Centro Democrático',
        'LIGIA PATRICIA ALVAREZ ALARCON' => 'Centro Democrático',
        'HUGO ANDRES CARDOZO RUEDA' => 'Centro Democrático',
        'FRANCISCO JAVIER GONZALEZ GAMBOA' => 'Liberal',
        'RAMON ANDRES RAMIREZ URIBE' => 'Liberal',
        'JESUS ALFREDO ARIZA OBREGON' => 'Cambio Radical',
        'LEONARDO ARDILA QUINTERO' => 'Cambio Radical',
        'WILSON DANOVIS LOZANO JAIMES' => 'Alianza Verde',
        'GIOVANNI HERALDO LEAL RUIZ' => 'Alianza Verde',
        'ARLEY OCTAVIO VALERO SAENZ' => 'Conservador',
        'VICTOR ALFONSO PALACIOS' => 'Conservador',
        'DAVID CASTRILLON CALDERON' => 'ASI',
        'EDUIS ALEXIS VARGAS ROJAS' => 'Partido de la U',
        'ERLIG DIANA JIMENEZ BECERRA' => 'Sumemos',
        'MARIO ANDRES MORALES FUENTES' => 'Nuevo Liberalismo',
    ];

    private array $stats = [
        'persons_created' => 0,
        'persons_existing' => 0,
        'candidacies_created' => 0,
        'candidacies_existing' => 0,
        'endorsements_created' => 0,
        'candidate_results_created' => 0,
        'candidate_results_updated' => 0,
        'list_results_created' => 0,
        'list_results_updated' => 0,
        'elected_marked' => 0,
        'errors' => [],
    ];

    private array $municipioCache = [];

    private array $personCache = [];

    public function handle(): int
    {
        ini_set('memory_limit', '512M');

        if (! file_exists(self::FILE_PATH)) {
            $this->error('Archivo no encontrado: '.self::FILE_PATH);

            return self::FAILURE;
        }

        $dryRun = $this->option('dry-run');

        $event = ElectoralEvent::where('name', 'Elecciones territoriales 2023')->first();
        if (! $event) {
            $this->error('Electoral event "Elecciones territoriales 2023" not found.');

            return self::FAILURE;
        }

        $corp = Corporation::where('name', 'Asamblea')->first();
        if (! $corp) {
            $this->error('Corporation "Asamblea" not found.');

            return self::FAILURE;
        }

        $contest = Contest::where('electoral_event_id', $event->id)
            ->where('corporation_id', $corp->id)
            ->first();
        if (! $contest) {
            $this->error('Contest for Asamblea 2023 not found.');

            return self::FAILURE;
        }

        $this->info("Contest: {$contest->name} (id: {$contest->id})");

        $sourceFile = $this->registerSourceFile();
        $this->buildCaches();

        $this->info('Leyendo Excel...');
        $reader = new Xlsx;
        $reader->setLoadSheetsOnly(['ASAMBLEA']);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load(self::FILE_PATH);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);
        $headers = $rows[0];

        $this->info('Filas totales: '.count($rows));

        // Phase 1: Resolve organizations
        $this->info('');
        $this->info('=== Fase 1: Organizaciones políticas ===');
        $partyOrgs = $this->resolveOrganizations($dryRun);
        if ($partyOrgs === null) {
            return self::FAILURE;
        }

        // Phase 2: Resolve/create candidates and candidacies
        $this->info('');
        $this->info('=== Fase 2: Candidatos y candidaturas ===');
        $candidacyMap = $this->resolveOrCreateCandidacies(
            $headers, $contest, $partyOrgs, $sourceFile, $dryRun
        );

        // Phase 3: Import results per municipality
        $this->info('');
        $this->info('=== Fase 3: Resultados por municipio ===');
        $this->importResults($rows, $contest, $candidacyMap, $partyOrgs, $sourceFile, $dryRun);

        // Phase 4: Mark elected candidates
        $this->info('');
        $this->info('=== Fase 4: Diputados electos ===');
        $this->markElectedCandidates($contest, $dryRun);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        // Phase 5: Validation
        $this->info('');
        $this->info('=== Fase 5: Validación de totales ===');
        if (! $dryRun) {
            $this->validateTotals($contest, $partyOrgs);
        }

        $this->printSummary($dryRun);

        return self::SUCCESS;
    }

    private function resolveOrganizations(bool $dryRun): ?array
    {
        $partyOrgs = [];

        foreach (self::PARTY_MAP as $key => $config) {
            $canonical = $config['canonical'];
            $org = PoliticalOrganization::whereRaw('LOWER(canonical_name) = LOWER(?)', [$canonical])->first();

            if ($org) {
                $this->line("  Existente: {$canonical} (id: ".substr($org->id, 0, 8).'...)');
            } elseif ($dryRun) {
                $this->line("  [DRY-RUN] Crearía: {$canonical}");
            } else {
                $org = PoliticalOrganization::create([
                    'type' => 'party',
                    'canonical_name' => $canonical,
                    'acronym' => $this->getAcronym($key),
                    'status' => 'active',
                ]);
                $this->info("  Creada: {$canonical} (id: ".substr($org->id, 0, 8).'...)');
            }

            $partyOrgs[$key] = $org;
        }

        return $partyOrgs;
    }

    private function resolveOrCreateCandidacies(
        array $headers,
        Contest $contest,
        array $partyOrgs,
        ?SourceFile $sourceFile,
        bool $dryRun
    ): array {
        $candidacyMap = [];

        foreach (self::PARTY_MAP as $partyKey => $config) {
            $org = $partyOrgs[$partyKey];
            $position = 1;

            for ($col = $config['cand_start']; $col <= $config['cand_end']; $col++) {
                $name = TextNormalizer::cleanString($headers[$col] ?? '');
                if (empty($name)) {
                    continue;
                }

                $normalized = TextNormalizer::normalizeName($name);

                if ($dryRun) {
                    $exists = isset($this->personCache[$normalized]);
                    $this->line("  [{$partyKey}] ".($exists ? 'Existente' : 'Nuevo').": {$name}");
                    $candidacyMap[$col] = ['name' => $name, 'party' => $partyKey, 'position' => $position];
                    $position++;

                    continue;
                }

                $person = $this->resolveOrCreatePerson($name, $sourceFile);
                $candidacy = Candidacy::where('person_id', $person->id)
                    ->where('contest_id', $contest->id)
                    ->first();

                if ($candidacy) {
                    $this->stats['candidacies_existing']++;
                } else {
                    $candidacy = Candidacy::create([
                        'person_id' => $person->id,
                        'contest_id' => $contest->id,
                        'list_position' => $position,
                        'outcome' => 'pending',
                    ]);
                    $this->stats['candidacies_created']++;
                }

                // Ensure endorsement exists
                if ($org) {
                    $existing = CandidacyEndorsement::where('candidacy_id', $candidacy->id)
                        ->where('organization_id', $org->id)
                        ->first();

                    if (! $existing) {
                        CandidacyEndorsement::create([
                            'candidacy_id' => $candidacy->id,
                            'organization_id' => $org->id,
                            'endorsement_type' => 'party',
                            'is_primary' => true,
                            'source_id' => $sourceFile?->id,
                        ]);
                        $this->stats['endorsements_created']++;
                    }
                }

                $candidacyMap[$col] = $candidacy;
                $position++;
            }
        }

        $this->info("  Candidaturas creadas: {$this->stats['candidacies_created']}, existentes: {$this->stats['candidacies_existing']}");
        $this->info("  Avales creados: {$this->stats['endorsements_created']}");

        return $candidacyMap;
    }

    private function importResults(
        array $rows,
        Contest $contest,
        array $candidacyMap,
        array $partyOrgs,
        ?SourceFile $sourceFile,
        bool $dryRun
    ): void {
        $processed = 0;
        $skipped = 0;

        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $provincia = TextNormalizer::cleanString($row[0] ?? '');
            $mpioRaw = TextNormalizer::cleanString($row[1] ?? '');

            if (empty($provincia) || empty($mpioRaw)) {
                $skipped++;

                continue;
            }

            $mpioUpper = mb_strtoupper($mpioRaw);
            if (str_contains($mpioUpper, 'TOTAL')) {
                $skipped++;

                continue;
            }

            $municipio = $this->resolveMunicipio($mpioRaw);
            if (! $municipio) {
                $this->stats['errors'][] = "Fila {$i}: Municipio no encontrado: {$mpioRaw}";
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $processed++;

                continue;
            }

            // Candidate votes
            foreach ($candidacyMap as $col => $candidacy) {
                if (! ($candidacy instanceof Candidacy)) {
                    continue;
                }

                $votes = TextNormalizer::parseVotes($row[$col] ?? null);
                if ($votes === null) {
                    continue;
                }

                $result = ElectoralResult::where('contest_id', $contest->id)
                    ->where('candidacy_id', $candidacy->id)
                    ->where('geographic_unit_id', $municipio->id)
                    ->where('metric_type', 'nominal_votes')
                    ->first();

                if ($result) {
                    if ($result->value !== $votes) {
                        $result->update(['value' => $votes, 'source_id' => $sourceFile?->id]);
                        $this->stats['candidate_results_updated']++;
                    }
                } else {
                    ElectoralResult::create([
                        'contest_id' => $contest->id,
                        'candidacy_id' => $candidacy->id,
                        'geographic_unit_id' => $municipio->id,
                        'grain' => 'municipality',
                        'metric_type' => 'nominal_votes',
                        'value' => $votes,
                        'source_id' => $sourceFile?->id,
                        'status' => 'validated',
                    ]);
                    $this->stats['candidate_results_created']++;
                }
            }

            // List votes per party
            foreach (self::PARTY_MAP as $partyKey => $config) {
                $org = $partyOrgs[$partyKey];
                if (! $org) {
                    continue;
                }

                $listVotes = TextNormalizer::parseVotes($row[$config['list_col']] ?? null);
                if ($listVotes === null) {
                    continue;
                }

                $result = ElectoralResult::where('contest_id', $contest->id)
                    ->whereNull('candidacy_id')
                    ->where('organization_id', $org->id)
                    ->where('geographic_unit_id', $municipio->id)
                    ->where('metric_type', 'votes')
                    ->first();

                if ($result) {
                    if ($result->value !== $listVotes) {
                        $result->update(['value' => $listVotes, 'source_id' => $sourceFile?->id]);
                        $this->stats['list_results_updated']++;
                    }
                } else {
                    ElectoralResult::create([
                        'contest_id' => $contest->id,
                        'candidacy_id' => null,
                        'organization_id' => $org->id,
                        'geographic_unit_id' => $municipio->id,
                        'grain' => 'municipality',
                        'metric_type' => 'votes',
                        'value' => $listVotes,
                        'source_id' => $sourceFile?->id,
                        'status' => 'validated',
                    ]);
                    $this->stats['list_results_created']++;
                }
            }

            $processed++;
            if ($processed % 20 === 0) {
                $this->line("  Procesados: {$processed} municipios...");
            }
        }

        $this->info("  Municipios procesados: {$processed}, filas omitidas: {$skipped}");
    }

    private function markElectedCandidates(Contest $contest, bool $dryRun): void
    {
        // Pre-load all candidacies for this contest with their persons
        $contestCandidacies = Candidacy::where('contest_id', $contest->id)
            ->with('person')
            ->get();

        foreach (self::ELECTED_CANDIDATES as $fullName => $partyName) {
            $normalized = TextNormalizer::normalizeName($fullName);
            $electedWords = preg_split('/\s+/', $normalized);

            // Try exact match first
            $candidacy = $contestCandidacies->first(
                fn ($c) => $c->person->normalized_name === $normalized
            );

            // Try word-overlap: all words of the DB name appear in the elected full name
            if (! $candidacy) {
                $candidacy = $contestCandidacies->first(function ($c) use ($electedWords) {
                    $dbWords = preg_split('/\s+/', $c->person->normalized_name);

                    foreach ($dbWords as $w) {
                        if (! in_array($w, $electedWords)) {
                            return false;
                        }
                    }

                    return true;
                });
            }

            // Try pg_trgm fuzzy as last resort
            if (! $candidacy) {
                $person = Person::whereRaw(
                    'similarity(normalized_name, ?) > 0.3',
                    [$normalized]
                )
                    ->whereHas('candidacies', fn ($q) => $q->where('contest_id', $contest->id))
                    ->orderByRaw('similarity(normalized_name, ?) DESC', [$normalized])
                    ->first();

                if ($person) {
                    $candidacy = $contestCandidacies->first(
                        fn ($c) => $c->person_id === $person->id
                    );
                }
            }

            if (! $candidacy) {
                $this->stats['errors'][] = "Electo no encontrado: {$fullName}";

                continue;
            }

            if ($dryRun) {
                $this->line("  [DRY-RUN] Marcaría electo: {$candidacy->person->full_name}");

                continue;
            }

            if ($candidacy->outcome !== 'elected') {
                $candidacy->update(['outcome' => 'elected']);
                $this->stats['elected_marked']++;
                $this->line("  Electo: {$candidacy->person->full_name}");
            }
        }
    }

    private function validateTotals(Contest $contest, array $partyOrgs): void
    {
        foreach (self::PARTY_MAP as $partyKey => $config) {
            $org = $partyOrgs[$partyKey];
            if (! $org) {
                continue;
            }

            $candidateVotes = ElectoralResult::where('contest_id', $contest->id)
                ->whereNotNull('candidacy_id')
                ->whereHas('candidacy.endorsements', function ($q) use ($org) {
                    $q->where('organization_id', $org->id)->where('is_primary', true);
                })
                ->where('metric_type', 'nominal_votes')
                ->sum('value');

            $listVotes = ElectoralResult::where('contest_id', $contest->id)
                ->whereNull('candidacy_id')
                ->where('organization_id', $org->id)
                ->where('metric_type', 'votes')
                ->sum('value');

            $total = $candidateVotes + $listVotes;
            $this->line("  {$config['canonical']}: candidatos={$candidateVotes} + lista={$listVotes} = {$total}");
        }
    }

    private function resolveOrCreatePerson(string $fullName, ?SourceFile $sourceFile): Person
    {
        $normalized = TextNormalizer::normalizeName($fullName);

        if (isset($this->personCache[$normalized])) {
            $this->stats['persons_existing']++;

            return $this->personCache[$normalized];
        }

        $person = Person::where('normalized_name', $normalized)->first();
        if ($person) {
            $this->personCache[$normalized] = $person;
            $this->stats['persons_existing']++;

            return $person;
        }

        // Fuzzy match
        $person = Person::whereRaw(
            'similarity(normalized_name, ?) > 0.6',
            [$normalized]
        )
            ->orderByRaw('similarity(normalized_name, ?) DESC', [$normalized])
            ->first();

        if ($person) {
            $this->personCache[$normalized] = $person;
            $this->stats['persons_existing']++;

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
            ->whereRaw('similarity(UPPER(canonical_name), ?) > 0.35', [$normalized])
            ->orderByRaw('similarity(UPPER(canonical_name), ?) DESC', [$normalized])
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

    private function buildCaches(): void
    {
        foreach (GeographicAlias::with('geographicUnit')
            ->whereHas('geographicUnit', fn ($q) => $q->where('type', 'municipality'))
            ->get() as $a) {
            $this->municipioCache[$a->normalized_alias] = $a->geographicUnit;
        }

        foreach (Person::all() as $p) {
            $this->personCache[$p->normalized_name] = $p;
        }

    }

    private function registerSourceFile(): ?SourceFile
    {
        if (! file_exists(self::FILE_PATH)) {
            return null;
        }

        $hash = hash_file('sha256', self::FILE_PATH);

        return SourceFile::firstOrCreate(
            ['sha256_hash' => $hash],
            [
                'original_name' => basename(self::FILE_PATH),
                'storage_path' => self::FILE_PATH,
                'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'size_bytes' => filesize(self::FILE_PATH),
                'file_type' => 'xlsx',
            ]
        );
    }

    private function getAcronym(string $key): ?string
    {
        return match ($key) {
            'CD' => 'CD',
            'LIBERAL' => 'PL',
            'CAMBIO RADICAL' => 'CR',
            'CONSERVADOR' => 'PC',
            'VERDE' => 'AV',
            'ASI' => 'ASI',
            'LA U' => 'U',
            'SUMEMOS' => null,
            'NUEVO LIBERALISMO' => 'NL',
            default => null,
        };
    }

    private function printSummary(bool $dryRun): void
    {
        $this->info('');
        $this->info('=== Resumen '.($dryRun ? '(DRY-RUN)' : '').' ===');
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Personas creadas', $this->stats['persons_created']],
                ['Personas existentes', $this->stats['persons_existing']],
                ['Candidaturas creadas', $this->stats['candidacies_created']],
                ['Candidaturas existentes', $this->stats['candidacies_existing']],
                ['Avales (endorsements) creados', $this->stats['endorsements_created']],
                ['Resultados candidatos creados', $this->stats['candidate_results_created']],
                ['Resultados candidatos actualizados', $this->stats['candidate_results_updated']],
                ['Resultados lista creados', $this->stats['list_results_created']],
                ['Resultados lista actualizados', $this->stats['list_results_updated']],
                ['Diputados marcados electos', $this->stats['elected_marked']],
                ['Errores', count($this->stats['errors'])],
            ]
        );

        if (! empty($this->stats['errors'])) {
            $this->warn('Errores:');
            foreach ($this->stats['errors'] as $err) {
                $this->warn("  - {$err}");
            }
        }
    }
}
