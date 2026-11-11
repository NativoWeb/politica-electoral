<?php

namespace App\Actions\Electoral;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Person;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Two persons rows that are the same human (D-01: identity is stable, a new election or party never makes a new
// Person). Everything that points at the absorbed person moves to the survivor in one transaction: candidacies (with
// their results and endorsements, which hang from the candidacy), aliases, contact points, facts, relationships,
// tenures, memberships, nexos, favorites, recent views, event figures, WhatsApp recipients, tags, documents,
// activities, lineage and staging matches. Exact duplicates are discarded instead of moved; a contest both persons ran
// in aborts the merge. The survivor's empty fields are filled, never overwritten; its name is not changed. The
// absorbed row stays soft-deleted with merged_into_id (person_merge_transactions keeps a FK to it); importers find it
// by name and follow merged_into_id to the survivor (Person::resolveIdByNormalizedName), never by alias.
// Used by data:merge-persons and by the "Unificar con otra persona" button of the ficha.
class MergePersons
{
    public const COMMAND = 'data:merge-persons';

    // [table, column pointing at the person, columns that make a row a duplicate of one the survivor already has,
    // polymorphic type column]. A null dedupe list means rows are always moved; with a type column only rows whose
    // type is a person (Person::MORPH_TYPES) are touched.
    private const REFS = [
        ['person_aliases', 'person_id', ['normalized_alias']],
        ['person_contact_points', 'person_id', ['type', 'value_raw']],
        ['person_facts', 'person_id', ['category', 'title', 'valid_from', 'valid_to']],
        ['office_tenures', 'person_id', ['office_id', 'corporation_id', 'geographic_unit_id', 'candidacy_id', 'start_date']],
        ['organization_memberships', 'person_id', ['organization_id', 'role', 'valid_from']],
        ['nexos_familiares', 'person_id', null],
        ['event_figures', 'person_id', ['event_id']],
        ['whatsapp_send_recipients', 'person_id', null],
        ['favorites', 'favoritable_id', ['user_id', 'favoritable_type'], 'favoritable_type'],
        ['recent_views', 'viewable_id', ['user_id', 'viewable_type'], 'viewable_type'],
        ['taggables', 'taggable_id', ['tag_id', 'taggable_type'], 'taggable_type'],
        ['document_associations', 'associable_id', ['document_id', 'associable_type'], 'associable_type'],
        ['activity_participants', 'participant_id', ['activity_id', 'participant_type'], 'participant_type'],
        ['source_records', 'target_id', null, 'target_entity'],
        ['campaigns', 'candidate_person_id', null],
        ['persons', 'merged_into_id', null],
    ];

    private const FILLABLE = ['photo_path', 'gender', 'birth_date'];

    private const DOCUMENT = ['document_type', 'doc_hash', 'doc_last4'];

    private array $columns = [];

    // Runs inside the caller's transaction. Rejects (422) missing or already merged persons and any conflict.
    public function merge(string $survivorId, string $absorbedId, ?string $userId, string $source, ?string $reason = null): array
    {
        if ($survivorId === $absorbedId) {
            throw new BusinessRuleException('No se puede unificar un registro consigo mismo.');
        }
        [$survivor, $absorbed] = $this->load($survivorId, $absorbedId, true);
        if (! $survivor || ! $absorbed) {
            throw new BusinessRuleException('Uno de los registros ya no existe.', 404);
        }
        if ($blocked = $this->blockedState($survivor, 'superviviente') ?? $this->blockedState($absorbed, 'absorbida')) {
            throw new BusinessRuleException('Uno de los registros ya fue unificado o eliminado. Recarga la ficha.');
        }
        $plan = $this->plan($survivor, $absorbed);
        if ($plan['conflicts']) {
            throw new BusinessRuleException('No se pueden unificar: '.implode(' ', $plan['conflicts']));
        }
        $transactionId = $this->apply($survivor, $absorbed, $plan, $userId, $source, $reason);

        return ['survivor' => $survivor, 'absorbed' => $absorbed, 'plan' => $plan, 'transaction_id' => $transactionId];
    }

    // [survivor, absorbed]; null where missing.
    public function load(string $survivorId, string $absorbedId, bool $lock): array
    {
        $persons = DB::table('persons')->whereIn('id', [$survivorId, $absorbedId]);
        if ($lock) {
            $persons->lockForUpdate();
        }
        $persons = $persons->get()->keyBy('id');

        return [$persons[$survivorId] ?? null, $persons[$absorbedId] ?? null];
    }

    public function blockedState(object $person, string $role): ?string
    {
        if ($person->merged_into_id) {
            return "La persona {$role} {$person->id} ya está fusionada en {$person->merged_into_id}.";
        }
        if ($person->deleted_at) {
            return "La persona {$role} {$person->id} está eliminada.";
        }

        return null;
    }

    public function plan(object $survivor, object $absorbed): array
    {
        $plan = ['refs' => [], 'conflicts' => [], 'fields' => [], 'alias' => null, 'staging' => [], 'warnings' => []];

        $plan['refs'][] = $this->planCandidacies($survivor->id, $absorbed->id, $plan['conflicts']);
        $plan['refs'][] = $this->planRelationships($survivor->id, $absorbed->id);
        foreach (self::REFS as $def) {
            $ref = $this->planRef($def[0], $def[1], $def[2], $def[3] ?? null, $survivor->id, $absorbed->id);
            if ($ref) {
                $plan['refs'][] = $ref;
            }
        }
        $plan['refs'] = array_values(array_filter($plan['refs']));

        $plan['fields'] = $this->planFields($survivor, $absorbed, $plan['conflicts']);
        $plan['alias'] = $this->planAlias($survivor, $absorbed, $plan['refs']);
        $plan['staging'] = $this->planStaging($survivor->id, $absorbed->id);

        if (count(explode(' ', self::key($absorbed->full_name))) > count(explode(' ', self::key($survivor->full_name)))) {
            $plan['warnings'][] = "El nombre de la absorbida ('{$absorbed->full_name}') es más largo que el del superviviente "
                ."('{$survivor->full_name}'); este comando no cambia el nombre.";
        }

        return $plan;
    }

    private function planCandidacies(string $survivorId, string $absorbedId, array &$conflicts): array
    {
        $rows = DB::table('candidacies as c')
            ->leftJoin('contests as ct', 'ct.id', '=', 'c.contest_id')
            ->whereIn('c.person_id', [$survivorId, $absorbedId])
            ->get(['c.id', 'c.person_id', 'c.contest_id', 'ct.name as contest']);

        $survivorContests = $rows->where('person_id', $survivorId)->pluck('contest_id')->all();
        $mine = $rows->where('person_id', $absorbedId);
        foreach ($mine as $row) {
            if (in_array($row->contest_id, $survivorContests, true)) {
                $conflicts[] = "Las dos personas tienen candidatura en la misma contienda '{$row->contest}' ({$row->contest_id}); "
                    .'resuélvelo a mano antes de fusionar.';
            }
        }

        return [
            'label' => 'candidacies.person_id', 'table' => 'candidacies', 'column' => 'person_id', 'key' => ['id'],
            'move' => $mine->map(fn ($r) => ['id' => $r->id])->values()->all(), 'drop' => [],
            'note' => $mine->pluck('contest')->implode('; '),
        ];
    }

    private function planRelationships(string $survivorId, string $absorbedId): ?array
    {
        if (! $this->hasTable('relationships')) {
            return null;
        }

        $rows = DB::table('relationships')
            ->where(fn ($q) => $q->whereIn('person_a_id', [$survivorId, $absorbedId])->orWhereIn('person_b_id', [$survivorId, $absorbedId]))
            ->get();

        $sig = fn (string $a, string $b, object $r) => implode('|', [
            $r->is_symmetric ? implode('|', [min($a, $b), max($a, $b)]) : "{$a}|{$b}", $r->type, $r->subtype ?? '',
        ]);

        $seen = [];
        foreach ($rows as $r) {
            if ($r->person_a_id !== $absorbedId && $r->person_b_id !== $absorbedId) {
                $seen[$sig($r->person_a_id, $r->person_b_id, $r)] = true;
            }
        }

        $move = [];
        $drop = [];
        foreach ($rows as $r) {
            if ($r->person_a_id !== $absorbedId && $r->person_b_id !== $absorbedId) {
                continue;
            }
            $a = $r->person_a_id === $absorbedId ? $survivorId : $r->person_a_id;
            $b = $r->person_b_id === $absorbedId ? $survivorId : $r->person_b_id;
            $key = $sig($a, $b, $r);
            if ($a === $b || isset($seen[$key])) {
                $drop[] = ['id' => $r->id];

                continue;
            }
            $seen[$key] = true;
            $move[] = ['id' => $r->id, 'person_a_id' => $a, 'person_b_id' => $b];
        }

        if (! $move && ! $drop) {
            return null;
        }

        return [
            'label' => 'relationships.person_a_id/person_b_id', 'table' => 'relationships', 'column' => null, 'key' => ['id'],
            'move' => $move, 'drop' => $drop, 'note' => $drop ? 'descarta auto-relación o duplicado' : '',
        ];
    }

    private function planRef(string $table, string $column, ?array $dedupe, ?string $typeColumn, string $survivorId, string $absorbedId): ?array
    {
        if (! $this->hasColumn($table, $column)) {
            return null;
        }

        $rows = $this->refQuery($table, $column, $typeColumn, $absorbedId)->get();
        if ($rows->isEmpty()) {
            return null;
        }

        $hasId = $this->hasColumn($table, 'id');
        $dedupe = $dedupe === null ? null : array_values(array_filter($dedupe, fn ($c) => $this->hasColumn($table, $c)));
        $key = $hasId ? ['id'] : array_merge([$column], $dedupe ?? []);
        $pick = fn (object $r) => array_combine($key, array_map(fn ($c) => $r->{$c}, $key));

        $seen = [];
        if ($dedupe) {
            foreach ($this->refQuery($table, $column, $typeColumn, $survivorId)->get() as $r) {
                $seen[$this->signature($r, $dedupe)] = true;
            }
        }

        $move = [];
        $drop = [];
        foreach ($rows as $r) {
            $sig = $dedupe ? $this->signature($r, $dedupe) : null;
            if ($sig !== null && isset($seen[$sig])) {
                $drop[] = $pick($r);

                continue;
            }
            if ($sig !== null) {
                $seen[$sig] = true;
            }
            $move[] = $pick($r);
        }

        return ['label' => "{$table}.{$column}", 'table' => $table, 'column' => $column, 'key' => $key, 'move' => $move, 'drop' => $drop, 'note' => $drop ? 'descarta duplicados' : ''];
    }

    private function planFields(object $survivor, object $absorbed, array &$conflicts): array
    {
        $fields = [];
        foreach (self::FILLABLE as $field) {
            if ($this->hasColumn('persons', $field) && self::isBlank($survivor->{$field}) && ! self::isBlank($absorbed->{$field})) {
                $fields[$field] = $absorbed->{$field};
            }
        }

        if ($this->hasColumn('persons', 'doc_hash')) {
            if (! self::isBlank($survivor->doc_hash) && ! self::isBlank($absorbed->doc_hash) && $survivor->doc_hash !== $absorbed->doc_hash) {
                $conflicts[] = 'Las dos personas tienen documentos de identidad distintos; no son la misma persona o hay que corregir uno a mano.';
            } elseif (self::isBlank($survivor->doc_hash) && ! self::isBlank($absorbed->doc_hash)) {
                foreach (self::DOCUMENT as $field) {
                    $fields[$field] = $absorbed->{$field};
                }
            }
        }

        if (($survivor->identity_status ?? null) === 'unverified' && ($absorbed->identity_status ?? null) === 'verified') {
            $fields['identity_status'] = 'verified';
        }

        return $fields;
    }

    // The absorbed name stays findable as an alias of the survivor.
    private function planAlias(object $survivor, object $absorbed, array $refs): ?array
    {
        if (! $this->hasTable('person_aliases')) {
            return null;
        }

        $wanted = self::key((string) $absorbed->normalized_name);
        if ($wanted === '' || $wanted === self::key((string) $survivor->normalized_name)) {
            return null;
        }

        $movedIds = collect($refs)->firstWhere('table', 'person_aliases')['move'] ?? [];
        $existing = DB::table('person_aliases')
            ->where(fn ($q) => $q->where('person_id', $survivor->id)->orWhereIn('id', array_column($movedIds, 'id')))
            ->pluck('normalized_alias')
            ->map(fn ($a) => self::key((string) $a));

        if ($existing->contains($wanted)) {
            return null;
        }

        return ['alias_name' => $absorbed->full_name, 'normalized_alias' => $absorbed->normalized_name];
    }

    private function planStaging(string $survivorId, string $absorbedId): array
    {
        if (! $this->hasColumn('import_staging_rows', 'matched_entities')) {
            return [];
        }

        return DB::table('import_staging_rows')
            ->whereRaw('CAST(matched_entities AS TEXT) LIKE ?', ['%'.$absorbedId.'%'])
            ->get(['id', 'matched_entities'])
            ->map(fn ($r) => ['id' => $r->id, 'matched_entities' => json_encode(self::replaceId(json_decode($r->matched_entities, true), $absorbedId, $survivorId))])
            ->all();
    }

    // Returns the person_merge_transactions id (null without that table).
    public function apply(object $survivor, object $absorbed, array $plan, ?string $userId = null, string $source = self::COMMAND, ?string $reason = null): ?string
    {
        $now = now();

        foreach ($plan['refs'] as $ref) {
            $table = $ref['table'];
            $stamp = $this->hasColumn($table, 'updated_at') ? ['updated_at' => $now] : [];

            foreach ($ref['drop'] as $where) {
                DB::table($table)->where($where)->delete();
            }

            if ($table === 'relationships') {
                foreach ($ref['move'] as $r) {
                    DB::table($table)->where('id', $r['id'])->update(['person_a_id' => $r['person_a_id'], 'person_b_id' => $r['person_b_id']] + $stamp);
                }

                continue;
            }

            if ($ref['key'] === ['id']) {
                foreach (array_chunk(array_column($ref['move'], 'id'), 500) as $ids) {
                    DB::table($table)->whereIn('id', $ids)->update([$ref['column'] => $survivor->id] + $stamp);
                }

                continue;
            }

            foreach ($ref['move'] as $where) {
                DB::table($table)->where($where)->update([$ref['column'] => $survivor->id] + $stamp);
            }
        }

        $late = $this->sweep($survivor->id, $absorbed->id);

        if ($plan['alias']) {
            DB::table('person_aliases')->insert([
                'id' => (string) Str::uuid(), 'person_id' => $survivor->id, 'alias_name' => $plan['alias']['alias_name'],
                'normalized_alias' => $plan['alias']['normalized_alias'], 'alias_type' => 'name', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach ($plan['staging'] as $row) {
            DB::table('import_staging_rows')->where('id', $row['id'])->update(['matched_entities' => $row['matched_entities'], 'updated_at' => $now]);
        }

        if ($plan['fields']) {
            DB::table('persons')->where('id', $survivor->id)->update($plan['fields'] + ['updated_at' => $now]);
        }

        DB::table('persons')->where('id', $absorbed->id)->update([
            'merged_into_id' => $survivor->id, 'identity_status' => 'merged', 'deleted_at' => $now, 'updated_at' => $now,
        ]);

        $counts = $this->counts($plan);
        $transactionId = null;
        if ($this->hasTable('person_merge_transactions')) {
            $transactionId = (string) Str::uuid();
            DB::table('person_merge_transactions')->insert([
                'id' => $transactionId,
                'survivor_id' => $survivor->id,
                'absorbed_id' => $absorbed->id,
                'performed_by' => $userId,
                'match_evidence' => json_encode(['method' => 'manual', 'command' => $source]),
                'before_snapshot' => json_encode([
                    'moved' => collect($plan['refs'])->mapWithKeys(fn ($r) => [$r['label'] => $r['move']])->filter()->all(),
                    'dropped' => collect($plan['refs'])->mapWithKeys(fn ($r) => [$r['label'] => $r['drop']])->filter()->all(),
                    'survivor_fields_filled' => array_keys($plan['fields']),
                    'absorbed_identity_status' => $absorbed->identity_status,
                    'staging_rows' => array_column($plan['staging'], 'id'),
                    'moved_late' => $late,
                ]),
                'status' => 'completed',
                'reason' => $reason ?: "Fusión manual ({$source})",
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        AuditLog::create([
            'user_id' => $userId,
            'action' => 'DATA_MERGE_PERSONS',
            'entity_type' => 'person',
            'entity_id' => $survivor->id,
            'metadata' => [
                'command' => $source,
                'absorbed_id' => $absorbed->id,
                'merge_transaction_id' => $transactionId,
                'campos_completados' => array_keys($plan['fields']),
                'alias_agregado' => $plan['alias'] !== null,
                'filas_tardias' => collect($late)->sum(fn ($rows) => count($rows)),
            ] + $counts,
        ]);

        return $transactionId;
    }

    // Rows written between the plan and this point (another request, the offline queue) still pointing at the absorbed
    // person: moved as well. A unique index hit here aborts the whole merge.
    private function sweep(string $survivorId, string $absorbedId): array
    {
        $refs = array_merge([['candidacies', 'person_id', null, null], ['relationships', 'person_a_id', null, null], ['relationships', 'person_b_id', null, null]], self::REFS);
        $moved = [];
        foreach ($refs as $def) {
            [$table, $column] = $def;
            if (! $this->hasColumn($table, $column)) {
                continue;
            }
            $key = $this->hasColumn($table, 'id') ? ['id'] : array_values(array_filter([$column, 'tag_id', $def[3] ?? null], fn ($c) => $c && $this->hasColumn($table, $c)));
            $rows = $this->refQuery($table, $column, $def[3] ?? null, $absorbedId)->get($key)
                ->map(fn ($r) => $key === ['id'] ? $r->id : (array) $r)->all();
            if (! $rows) {
                continue;
            }
            $stamp = $this->hasColumn($table, 'updated_at') ? ['updated_at' => now()] : [];
            $this->refQuery($table, $column, $def[3] ?? null, $absorbedId)->update([$column => $survivorId] + $stamp);
            $moved["{$table}.{$column}"] = $rows;
        }
        if ($this->hasTable('relationships')) {
            DB::table('relationships')->where('person_a_id', $survivorId)->where('person_b_id', $survivorId)->delete();
        }

        return $moved;
    }

    private function refQuery(string $table, string $column, ?string $typeColumn, string $personId)
    {
        $query = DB::table($table)->where($column, $personId);
        if ($typeColumn && $this->hasColumn($table, $typeColumn)) {
            $query->whereIn($typeColumn, Person::MORPH_TYPES);
        }

        return $query;
    }

    public function counts(array $plan): array
    {
        $refs = collect($plan['refs']);

        return [
            'candidaturas_movidas' => count($refs->firstWhere('table', 'candidacies')['move'] ?? []),
            'filas_movidas' => $refs->sum(fn ($r) => count($r['move'])),
            'filas_descartadas' => $refs->sum(fn ($r) => count($r['drop'])),
            'staging_reapuntadas' => count($plan['staging']),
        ];
    }

    private function signature(object $row, array $columns): string
    {
        return implode('|', array_map(fn ($c) => self::key((string) $row->{$c}), $columns));
    }

    private static function replaceId(mixed $value, string $from, string $to): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($v) => self::replaceId($v, $from, $to), $value);
        }

        return $value === $from ? $to : $value;
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }

    private static function key(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::upper(Str::ascii($value))));
    }

    private function hasTable(string $table): bool
    {
        return $this->columnsOf($table) !== [];
    }

    private function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->columnsOf($table), true);
    }

    private function columnsOf(string $table): array
    {
        return $this->columns[$table] ??= Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
    }
}
