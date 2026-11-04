<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Campaign;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// The 'ALCALDIA ' sheet of "1-resultados alcaldias, concejos, ok.xlsx" has rows without votes nor provincia: campaign
// notes about possible aspirants ("SAN GIL | WILMER JIMENEZ | EX CANDIDATO ASAMBLEA"), not 2023 candidates. The import
// turned them into Alcaldía 2023 candidacies and the note into a "party". This moves the real people to lideres (CD),
// drops the fake candidacies, the junk rows ("NO TENEMOS"), the persons left unused and the note-parties left unused.
// A case is a candidacy with no electoral_results, in an Alcaldía contest of the 2023 territorial event, in the
// municipio of one of the rows below, whose person has that row's name (current name or import alias). Nothing else
// is touched; with --dry-run it only reads (PostgreSQL: read-only transaction).
class FixAlcaldiaAnotaciones extends Command
{
    protected $signature = 'data:fix-alcaldia-anotaciones {--dry-run : Solo muestra el plan, no guarda nada}';

    protected $description = 'Move the Alcaldía 2023 "candidacies" that were campaign notes (rows without votes) to lideres';

    private const MARKER = 'Posible aspirante Alcaldía';

    private const AUDIT_COMMAND = 'data:fix-alcaldia-anotaciones';

    // [fila Excel, municipio, nombre en el Excel, anotación legible | null, basura]. Ana Celina's note comes from the
    // García Rovira file (same row there with 'EX ALCALDE'); the main file leaves it empty.
    public const ROWS = [
        [17, 'BARICHARA', 'ALFONSO RODRIGUEZ', 'Ex alcalde', false],
        [21, 'BARRANCABERMEJA', 'DIANA TORCOROMA', null, false],
        [39, 'CAPITANEJO', 'ISIDRO MOGOLLON', 'Ex alcalde', false],
        [52, 'CHARALA', 'FELIPE', 'Dirigente', false],
        [69, 'CONCEPCION', 'BETO MORALES', 'Ex alcalde', false],
        [85, 'EL CARMEN DE CHUCURI', 'DIEGO PLATA', 'Ex alcalde', false],
        [118, 'GUACA', 'ANA CELINA CASTELLANOS', 'Ex alcalde', false],
        [133, 'HATO', 'JORGE', 'Ex alcalde', false],
        [141, 'JORDAN', 'NO TENEMOS', null, true],
        [157, 'LOS SANTOS', 'ROBERTO ALMEIDA', null, false],
        [170, 'MOGOTES', 'EDGAR HIGINIO RUEDA', 'Ex alcalde', false],
        [180, 'OIBA', 'YONY PEÑALOZA', null, false],
        [183, 'ONZAGA', 'JOHANA GRI,MALDOS', 'Ex alcaldesa', false],
        [190, 'PALMAS DEL SOCORRO', 'WILBERTH MENDOZA', 'Ex alcalde', false],
        [194, 'PARAMO', 'NO TENEMOS', null, true],
        [214, 'PUERTO WILCHES', 'DIOMEDEZ MUÑOZ', 'Dirigente', false],
        [221, 'SABANA DE TORRES', 'JUAN CARLOS', 'Sec. Planeación', false],
        [222, 'SABANA DE TORRES', 'ISAAC GOMEZ', 'Dirigente', false],
        [223, 'SABANA DE TORRES', 'RICARDO SILVESTRE CEDIEL', 'Familiar de César Cediel', false],
        [228, 'SAN ANDRES', 'NELSON SARAVIA', 'Registrador', false],
        [235, 'SAN GIL', 'WILMER  JIMENEZ', 'Ex candidato Asamblea', false],
        [238, 'SAN JOAQUIN', 'RAUL PRADA', 'Amigo de César Cediel', false],
        [245, 'SAN MIGUEL', 'FALTA LIDER POLITICO', null, true],
        [254, 'SANTA HELENA DEL OPON', 'VIDAL TIRADO', 'Candidato Asamblea', false],
        [273, 'TONA', 'ELKIN', 'Ex alcalde', false],
        [283, 'VETAS', 'IVON', 'Ex alcaldesa', false],
        [291, 'ZAPATOCA', 'SUSANA MURCIA', null, false],
    ];

    // Organizations the import created from those notes; only these may be deleted, and only when nothing uses them.
    public const NOTE_ORGS = [
        'EXALCALDE', 'EX ALCALDE', 'EXALCALDESA', 'EX ALCALDESA', 'DIRIGENTE', 'SEC PLANEACION', 'REGISTRADOR',
        'FAMILIAR CESAR CEDIEL', 'AMIGO DE CESAR CEDIEL', 'EX CANDIDATO ASAMBLEA', 'CANDIDATO ASAMBLEA',
    ];

    private const PERSON_REFS = [
        ['office_tenures', 'person_id'], ['organization_memberships', 'person_id'], ['person_contact_points', 'person_id'],
        ['person_facts', 'person_id'], ['relationships', 'person_a_id'], ['relationships', 'person_b_id'],
        ['person_merge_transactions', 'survivor_id'], ['person_merge_transactions', 'absorbed_id'],
        ['persons', 'merged_into_id'], ['campaigns', 'candidate_person_id'], ['event_figures', 'person_id'],
        ['activity_participants', 'participant_id'], ['favorites', 'favoritable_id'], ['recent_views', 'viewable_id'],
        ['taggables', 'taggable_id'], ['document_associations', 'associable_id'], ['source_records', 'target_id'],
        ['whatsapp_send_recipients', 'person_id'],
    ];

    private const CANDIDACY_REFS = [
        ['office_tenures', 'candidacy_id'], ['favorites', 'favoritable_id'], ['recent_views', 'viewable_id'],
        ['taggables', 'taggable_id'], ['document_associations', 'associable_id'], ['source_records', 'target_id'],
    ];

    private const ORG_REFS = [
        ['coalition_members', 'organization_id'], ['electoral_lists', 'organization_id'],
        ['electoral_results', 'organization_id'], ['organization_memberships', 'organization_id'],
        ['political_organizations', 'merged_into_id'], ['favorites', 'favoritable_id'], ['recent_views', 'viewable_id'],
        ['taggables', 'taggable_id'], ['document_associations', 'associable_id'], ['source_records', 'target_id'],
    ];

    private array $tableExists = [];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        DB::beginTransaction();
        try {
            if ($dryRun && DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION READ ONLY');
            }

            $plan = $this->plan();
            if ($plan === null) {
                DB::rollBack();

                return self::FAILURE;
            }

            $this->printPlan($plan);

            if ($dryRun) {
                DB::rollBack();
                $this->warn('DRY-RUN: no se guardó nada.');

                return self::SUCCESS;
            }

            $counts = $this->counts($plan);
            if ($counts['candidaturas_borradas'] + $counts['personas_borradas'] + $counts['organizaciones_borradas'] === 0) {
                DB::rollBack();
                $this->info('Nada que corregir.');

                return self::SUCCESS;
            }

            $this->apply($plan);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        // The party list (filters and selectors) is cached for an hour and still has the deleted organizations.
        Cache::forget('all_partidos');
        $this->info('Corrección aplicada.');

        return self::SUCCESS;
    }

    private function plan(): ?array
    {
        $officeId = DB::table('offices')->where('name', 'Alcaldía')->value('id');
        $eventIds = DB::table('electoral_events')
            ->where('name', 'Elecciones territoriales 2023')
            ->orWhereBetween('election_date', ['2023-01-01', '2023-12-31'])
            ->pluck('id');
        if (! $officeId || $eventIds->isEmpty()) {
            $this->error('No se encontró el cargo "Alcaldía" o el evento territorial 2023.');

            return null;
        }

        $municipios = $this->municipioIndex();
        $cases = [];
        foreach (self::ROWS as [$fila, $municipio, $nombre, $anotacion, $basura]) {
            $cases[] = $this->planRow($fila, $municipio, $nombre, $anotacion, $basura, $municipios, $officeId, $eventIds);
        }

        $deletedCandidacies = collect($cases)->whereNotNull('candidacy_id')->whereIn('action', ['basura', 'lider_nuevo', 'lider_existente'])->pluck('candidacy_id')->all();

        return [
            'cases' => $cases,
            'persons' => $this->planPersons($cases, $deletedCandidacies),
            'orgs' => $this->planOrgs($deletedCandidacies),
        ];
    }

    private function planRow(int $fila, string $municipio, string $nombre, ?string $anotacion, bool $basura, array $municipios, string $officeId, Collection $eventIds): array
    {
        $case = [
            'fila' => $fila, 'municipio' => $municipio, 'persona' => $nombre, 'anotacion' => $anotacion,
            'action' => 'ya_corregido', 'detalle' => '', 'candidacy_id' => null, 'person_id' => null, 'gu' => null,
            'lider_id' => null, 'match' => null, 'observacion' => null,
        ];

        $gu = $municipios[self::key($municipio)] ?? null;
        if (! $gu) {
            return ['action' => 'omitido', 'detalle' => 'municipio no encontrado'] + $case;
        }
        $case['gu'] = $gu;
        $case['municipio'] = $gu->canonical_name;

        $candidates = DB::table('candidacies as c')
            ->join('contests as ct', 'ct.id', '=', 'c.contest_id')
            ->leftJoin('persons as p', 'p.id', '=', 'c.person_id')
            ->where('ct.office_id', $officeId)
            ->whereIn('ct.electoral_event_id', $eventIds)
            ->where('ct.geographic_unit_id', $gu->id)
            ->whereNotExists(fn ($q) => $q->from('electoral_results as er')->whereColumn('er.candidacy_id', 'c.id'))
            ->select('c.id as candidacy_id', 'c.person_id', 'p.full_name', 'p.normalized_name')
            ->get();

        $aliases = DB::table('person_aliases')->whereIn('person_id', $candidates->pluck('person_id')->filter())
            ->get(['person_id', 'normalized_alias'])->groupBy('person_id');

        $wanted = self::key($nombre);
        $matches = $candidates->filter(fn ($c) => self::key((string) $c->normalized_name) === $wanted
            || ($aliases[$c->person_id] ?? collect())->contains(fn ($a) => self::key((string) $a->normalized_alias) === $wanted))->values();

        if ($matches->isEmpty()) {
            return ['detalle' => 'sin candidatura falsa (ya corregido)'] + $case;
        }
        if ($matches->count() > 1) {
            return ['action' => 'omitido', 'detalle' => 'más de una candidatura coincide; revisar a mano'] + $case;
        }

        $cand = $matches->first();
        $case['candidacy_id'] = $cand->candidacy_id;
        $case['person_id'] = $cand->person_id;
        $case['persona'] = $cand->full_name ?? $nombre;

        $refs = $this->references($cand->candidacy_id, self::CANDIDACY_REFS);
        if ($refs) {
            return ['action' => 'omitido', 'detalle' => 'la candidatura tiene referencias: '.implode(', ', $refs)] + $case;
        }

        if ($basura) {
            return ['action' => 'basura', 'detalle' => 'fila basura: se borra la candidatura'] + $case;
        }

        $note = self::MARKER.($anotacion ? " — {$anotacion}" : '');
        $lideres = $this->lideresIn($gu);
        $names = array_unique(array_filter([$cand->full_name, $nombre]));

        $exact = $lideres->filter(fn ($l) => collect($names)->contains(fn ($n) => self::key($n) === self::key($l->nombre)))->values();
        $approx = $exact->isEmpty()
            ? $lideres->filter(fn ($l) => collect($names)->contains(fn ($n) => self::approxSameName($n, $l->nombre)))->values()
            : collect();
        $found = $exact->isNotEmpty() ? $exact : $approx;

        if ($found->count() > 1) {
            return ['action' => 'omitido', 'detalle' => 'varias fichas de líder coinciden: '.$found->pluck('nombre')->implode(' / ')] + $case;
        }

        if ($found->count() === 1) {
            $lider = $found->first();
            $current = trim((string) $lider->observacion);
            $case['lider_id'] = $lider->id;
            $case['match'] = $exact->isNotEmpty() ? 'exacto' : 'aproximado';
            if (! str_contains($current, self::MARKER)) {
                $case['observacion'] = $current === '' ? $note : "{$current} | {$note}";
            }

            return [
                'action' => 'lider_existente',
                'detalle' => "conserva ficha '{$lider->nombre}' (coincidencia {$case['match']}); "
                    .($case['observacion'] !== null ? "observación += '{$note}'" : 'observación ya anotada'),
            ] + $case;
        }

        $case['observacion'] = $note;

        return ['action' => 'lider_nuevo', 'detalle' => "nuevo líder CD; observación '{$note}'"] + $case;
    }

    private function planPersons(array $cases, array $deletedCandidacies): array
    {
        $persons = [];
        foreach ($cases as $case) {
            if (! $case['person_id'] || ! in_array($case['candidacy_id'], $deletedCandidacies, true)) {
                continue;
            }
            $id = $case['person_id'];
            $persons[$id] ??= ['id' => $id, 'persona' => $case['persona'], 'lider_id' => null, 'nexos' => 0];
            $persons[$id]['lider_id'] ??= $case['lider_id'] ?? ($case['action'] === 'lider_nuevo' ? 'nuevo' : null);
        }

        foreach ($persons as $id => &$p) {
            $others = DB::table('candidacies')->where('person_id', $id)->whereNotIn('id', $deletedCandidacies)->count();
            if ($others > 0) {
                $p['action'] = 'conservar';
                $p['detalle'] = "tiene {$others} candidatura(s) real(es)";

                continue;
            }

            $refs = $this->references($id, self::PERSON_REFS);
            $nexos = $this->hasTable('nexos_familiares') ? DB::table('nexos_familiares')->where('person_id', $id)->get(['campaign_id']) : collect();
            $nexosMovable = $p['lider_id'] !== null && $nexos->every(fn ($n) => $n->campaign_id === Campaign::DEFAULT_ID);
            if ($nexos->isNotEmpty() && ! $nexosMovable) {
                $refs[] = 'nexos_familiares';
            }

            if ($refs) {
                $p['action'] = 'conservar';
                $p['detalle'] = 'referenciada en: '.implode(', ', $refs);

                continue;
            }

            $p['nexos'] = $nexos->count();
            $p['action'] = 'borrar';
            $p['detalle'] = $p['nexos'] ? "sin candidaturas; {$p['nexos']} nexo(s) pasan al líder" : 'sin candidaturas ni referencias';
        }

        return array_values($persons);
    }

    private function planOrgs(array $deletedCandidacies): array
    {
        $orgs = DB::table('political_organizations')->get(['id', 'canonical_name'])
            ->filter(fn ($o) => in_array(self::key($o->canonical_name), self::NOTE_ORGS, true))
            ->keyBy('id');

        $partidos = collect();
        foreach (['lideres', 'lider_info_politica'] as $table) {
            if ($this->hasTable($table)) {
                $partidos = $partidos->merge(DB::table($table)->whereNotNull('partido')->distinct()->pluck('partido')->map(fn ($v) => [$table, self::key($v)]));
            }
        }

        $plan = [];
        foreach ($orgs as $org) {
            $refs = $this->references($org->id, self::ORG_REFS);
            $endorsements = DB::table('candidacy_endorsements')->where('organization_id', $org->id)->whereNotIn('candidacy_id', $deletedCandidacies)->count();
            if ($endorsements) {
                $refs[] = "candidacy_endorsements ({$endorsements})";
            }
            foreach ($partidos as [$table, $value]) {
                if ($value === self::key($org->canonical_name)) {
                    $refs[] = "{$table}.partido";
                }
            }
            $plan[] = [
                'id' => $org->id,
                'nombre' => $org->canonical_name,
                'action' => $refs ? 'conservar' : 'borrar',
                'detalle' => $refs ? 'en uso: '.implode(', ', array_unique($refs)) : 'sin uso',
            ];
        }

        return $plan;
    }

    private function apply(array $plan): void
    {
        $now = now();
        $liderIds = [];

        foreach ($plan['cases'] as $i => $case) {
            if (! in_array($case['action'], ['basura', 'lider_nuevo', 'lider_existente'], true)) {
                continue;
            }

            if ($case['action'] === 'lider_nuevo') {
                $case['lider_id'] = (string) Str::uuid();
                DB::table('lideres')->insert([
                    'id' => $case['lider_id'],
                    'campaign_id' => Campaign::DEFAULT_ID,
                    'nombre' => $case['persona'],
                    'municipio' => $case['gu']->canonical_name,
                    'provincia' => $case['gu']->provincia,
                    'geographic_unit_id' => $case['gu']->id,
                    'cargo' => '',
                    'tipo' => 'directorio',
                    'partido' => null,
                    'observacion' => $case['observacion'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } elseif ($case['action'] === 'lider_existente' && $case['observacion'] !== null) {
                DB::table('lideres')->where('id', $case['lider_id'])->update(['observacion' => $case['observacion'], 'updated_at' => $now]);
            }
            if ($case['person_id'] && $case['lider_id']) {
                $liderIds[$case['person_id']] = $case['lider_id'];
            }

            DB::table('candidacy_endorsements')->where('candidacy_id', $case['candidacy_id'])->delete();
            DB::table('candidacies')->where('id', $case['candidacy_id'])->delete();

            $this->audit('DATA_FIX_ALCALDIA_CANDIDATURA', 'candidacy', $case['candidacy_id'], [
                'accion' => $case['action'],
                'fila_excel' => $case['fila'],
                'geographic_unit_id' => $case['gu']->id,
                'person_id' => $case['person_id'],
                'lider_id' => $case['lider_id'],
                'coincidencia' => $case['match'],
                'observacion_actualizada' => $case['observacion'] !== null,
            ]);
        }

        foreach ($plan['persons'] as $p) {
            if ($p['action'] !== 'borrar') {
                continue;
            }
            if ($p['nexos'] && isset($liderIds[$p['id']])) {
                DB::table('nexos_familiares')->where('person_id', $p['id'])->update(['person_id' => $liderIds[$p['id']], 'updated_at' => $now]);
            }
            DB::table('person_aliases')->where('person_id', $p['id'])->delete();
            DB::table('persons')->where('id', $p['id'])->delete();
            $this->audit('DATA_FIX_ALCALDIA_PERSONA_BORRADA', 'person', $p['id'], [
                'lider_id' => $liderIds[$p['id']] ?? null, 'nexos_movidos' => $p['nexos'],
            ]);
        }

        foreach ($plan['orgs'] as $org) {
            if ($org['action'] !== 'borrar') {
                continue;
            }
            DB::table('organization_aliases')->where('organization_id', $org['id'])->delete();
            DB::table('political_organizations')->where('id', $org['id'])->delete();
            $this->audit('DATA_FIX_ALCALDIA_ORGANIZACION_BORRADA', 'political_organization', $org['id'], []);
        }

        $this->audit('DATA_FIX_ALCALDIA_ANOTACIONES', null, null, $this->counts($plan));
    }

    private function printPlan(array $plan): void
    {
        $this->info('Casos (filas sin votos de la hoja ALCALDIA):');
        $this->table(['Fila', 'Municipio', 'Persona', 'Acción', 'Detalle'], collect($plan['cases'])->map(fn ($c) => [
            $c['fila'], $c['municipio'], $c['persona'], $c['action'], $c['detalle'],
        ])->all());

        $this->info('Personas (persons) afectadas:');
        $this->table(['Persona', 'Acción', 'Detalle'], collect($plan['persons'])->map(fn ($p) => [$p['persona'], $p['action'], $p['detalle']])->all());

        $this->info('Organizaciones creadas desde anotaciones:');
        $this->table(['Organización', 'Acción', 'Detalle'], collect($plan['orgs'])->map(fn ($o) => [$o['nombre'], $o['action'], $o['detalle']])->all());

        $this->info('Resumen: '.collect($this->counts($plan))->map(fn ($v, $k) => "{$k}={$v}")->implode(', '));
    }

    private function counts(array $plan): array
    {
        $cases = collect($plan['cases']);

        return [
            'candidaturas_borradas' => $cases->whereIn('action', ['basura', 'lider_nuevo', 'lider_existente'])->count(),
            'lideres_nuevos' => $cases->where('action', 'lider_nuevo')->count(),
            'lideres_existentes' => $cases->where('action', 'lider_existente')->count(),
            'basura' => $cases->where('action', 'basura')->count(),
            'omitidos' => $cases->where('action', 'omitido')->count(),
            'ya_corregidos' => $cases->where('action', 'ya_corregido')->count(),
            'personas_borradas' => collect($plan['persons'])->where('action', 'borrar')->count(),
            'personas_conservadas' => collect($plan['persons'])->where('action', 'conservar')->count(),
            'organizaciones_borradas' => collect($plan['orgs'])->where('action', 'borrar')->count(),
            'organizaciones_conservadas' => collect($plan['orgs'])->where('action', 'conservar')->count(),
        ];
    }

    private function audit(string $action, ?string $entityType, ?string $entityId, array $metadata): void
    {
        AuditLog::create([
            'user_id' => null,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'metadata' => ['command' => self::AUDIT_COMMAND] + $metadata,
        ]);
    }

    // key => {id, canonical_name, provincia}, by canonical name and by import alias.
    private function municipioIndex(): array
    {
        $units = DB::table('geographic_units as g')
            ->leftJoin('geographic_units as prov', function ($j) {
                $j->on('prov.id', '=', 'g.parent_id')->where('prov.type', 'province');
            })
            ->where('g.type', 'municipality')
            ->get(['g.id', 'g.canonical_name', 'prov.canonical_name as provincia'])
            ->keyBy('id');

        $index = [];
        if ($this->hasTable('geographic_aliases')) {
            foreach (DB::table('geographic_aliases')->whereIn('geographic_unit_id', $units->keys())->get(['geographic_unit_id', 'normalized_alias']) as $alias) {
                $index[self::key((string) $alias->normalized_alias)] = $units[$alias->geographic_unit_id];
            }
        }
        foreach ($units as $unit) {
            $index[self::key($unit->canonical_name)] = $unit;
        }

        return $index;
    }

    private function lideresIn(object $gu): Collection
    {
        return DB::table('lideres')
            ->where('campaign_id', Campaign::DEFAULT_ID)
            ->where(fn ($q) => $q->where('geographic_unit_id', $gu->id)->orWhereNull('geographic_unit_id'))
            ->get(['id', 'nombre', 'municipio', 'observacion', 'geographic_unit_id'])
            ->filter(fn ($l) => $l->geographic_unit_id === $gu->id || self::key((string) $l->municipio) === self::key($gu->canonical_name))
            ->values();
    }

    private function references(string $id, array $refs): array
    {
        $found = [];
        foreach ($refs as [$table, $column]) {
            if ($this->hasTable($table) && Schema::hasColumn($table, $column) && DB::table($table)->where($column, $id)->exists()) {
                $found[] = "{$table}.{$column}";
            }
        }

        return $found;
    }

    private function hasTable(string $table): bool
    {
        return $this->tableExists[$table] ??= Schema::hasTable($table);
    }

    public static function key(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::upper(Str::ascii($value))));
    }

    // "Diego Plata" ~ "Diego Fernando Plata Alvarez": two or more words, same first name, every word found in order
    // (one typo allowed in words of 5+ letters) and the last word among the other name's last two (a surname).
    public static function approxSameName(string $short, string $full): bool
    {
        $s = explode(' ', self::key($short));
        $f = explode(' ', self::key($full));
        if (count($s) < 2 || count($f) < count($s)) {
            return false;
        }

        $same = fn (string $a, string $b) => $a === $b || (min(strlen($a), strlen($b)) >= 5 && levenshtein($a, $b) <= 1);
        if (! $same($s[0], $f[0])) {
            return false;
        }

        $pos = 0;
        $last = -1;
        foreach (array_slice($s, 1) as $word) {
            $last = -1;
            for ($j = $pos + 1; $j < count($f); $j++) {
                if ($same($word, $f[$j])) {
                    $last = $j;
                    break;
                }
            }
            if ($last < 0) {
                return false;
            }
            $pos = $last;
        }

        return $last >= count($f) - 2;
    }
}
