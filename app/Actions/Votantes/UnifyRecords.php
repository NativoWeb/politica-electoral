<?php

namespace App\Actions\Votantes;

use App\Actions\Electoral\MergePersons;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\AuditLog;
use App\Services\CachedQueries;
use App\Services\Import\TextNormalizer;
use App\Support\Cedula;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// "Unificar con otra persona" in the ficha: the record being viewed and any other one the user can edit become one.
//   lideres:  lider + lider of the same campaign -> MergeLideres (the user picks the cédula when both differ).
//   vinculo:  candidate (person) + lider -> the candidate takes the lider's name (old name kept as alias), which is
//             how the Mapa links them; the lider must be inside the territory of one of the candidacies.
//   personas: candidate + candidate -> MergePersons (aborts on a shared contest or different identity documents).
// Candidates are shared electoral data: only who may edit it (canEditElectoral) unites them. Each merge keeps both
// records as they were in merge_history, for a later "Separar"; the audit log only gets ids and the case.
class UnifyRecords
{
    use ScopesTerritory;

    public const SOURCE = 'ficha:unificar';

    private const PHONE_TYPES = ['celular', 'fijo', 'whatsapp', 'telefono', 'teléfono'];

    public function __construct(
        private readonly MergeLideres $mergeLideres,
        private readonly MergePersons $mergePersons,
        private readonly LinkCandidateToLider $link,
        private readonly UpdateVotante $updateVotante,
    ) {}

    // Lideres of the user's own campaign and territory, and living candidates (for who may unite them), by name,
    // cédula or phone. Never the record itself, nor what is already the same ficha.
    public function search(string $id, string $q): array
    {
        [$kind, $record] = $this->record($id);
        $q = trim(preg_replace('/\s+/u', ' ', $q));
        if (mb_strlen($q) < 2) {
            return [];
        }
        $like = '%'.self::escapeLike(mb_strtolower($q)).'%';
        $digits = preg_replace('/\D/', '', $q);
        $byNumber = strlen($digits) >= 2 && ! preg_match('/\pL/u', $q);

        // A candidate already linked to a lider: that lider is the same ficha, never offered.
        $sameFicha = $kind === 'person' ? $this->link->linkedLiderId($record) : null;
        $lideres = $this->scopeToTerritory($this->ownLideres())
            ->where('is_active', true)
            ->where('id', '!=', $id)
            ->when($sameFicha, fn ($w) => $w->where('id', '!=', $sameFicha))
            ->where(function ($w) use ($like, $digits, $byNumber) {
                $w->whereRaw("LOWER(nombre) LIKE ? ESCAPE '\\'", [$like]);
                if ($byNumber) {
                    $w->orWhere('cedula', 'like', "%{$digits}%")->orWhere('telefono', 'like', "%{$digits}%");
                }
            })
            ->orderBy('nombre')
            ->limit(10)
            ->get(['id', 'nombre', 'cedula', 'telefono', 'municipio', 'cargo'])
            ->map(fn ($l) => ['id' => $l->id, 'tipo' => 'lider', 'nombre' => $l->nombre, 'cedula' => $l->cedula,
                'telefono' => $l->telefono, 'municipio' => $l->municipio, 'cargo' => $l->cargo, 'candidatura' => null]);

        $candidatos = collect();
        if (! $byNumber && $this->campaign()->canEditElectoral()) {
            $sameFicha = $kind === 'lider' ? $this->updateVotante->linkedPersonIds($record)->all() : [];
            $normalized = '%'.self::escapeLike(TextNormalizer::normalizeName($q)).'%';
            $candidatos = $this->territoryPersons(DB::table('persons as p'))
                ->whereNull('p.deleted_at')
                ->whereNull('p.merged_into_id')
                ->where('p.id', '!=', $id)
                ->whereNotIn('p.id', $sameFicha)
                ->where(fn ($w) => $w->whereRaw("LOWER(p.full_name) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("p.normalized_name LIKE ? ESCAPE '\\'", [$normalized]))
                ->orderBy('p.full_name')
                ->limit(10)
                ->get(['p.id', 'p.full_name'])
                // A candidate already linked to a lider ficha is found as that lider.
                ->reject(fn ($p) => $this->link->linkedLiderId($p) !== null)
                ->map(function ($p) {
                    $candidatura = $this->candidacies([$p->id])[0] ?? null;

                    return ['id' => $p->id, 'tipo' => 'candidato', 'nombre' => $p->full_name, 'cedula' => null, 'telefono' => null,
                        'municipio' => $candidatura['territorio'] ?? null, 'cargo' => $candidatura['cargo'] ?? null, 'candidatura' => $candidatura];
                });
        }

        return $lideres->concat($candidatos)->values()->all();
    }

    // What the merge would leave, computed by running it and rolling back. A merge that cannot be done throws.
    public function preview(string $id, string $otherId, ?string $cedula): array
    {
        DB::beginTransaction();
        try {
            return $this->run($id, $otherId, $cedula, true);
        } finally {
            DB::rollBack();
        }
    }

    public function execute(string $id, string $otherId, ?string $cedula): array
    {
        $result = DB::transaction(fn () => $this->run($id, $otherId, $cedula, false));
        // Rankings and fichas cached by name (Senado, Cámara, Asamblea, Concejo) change when a candidate does.
        if ($result['caso'] !== 'lideres') {
            CachedQueries::flush();
        }

        return $result;
    }

    private function run(string $id, string $otherId, ?string $cedula, bool $dryRun): array
    {
        if ($id === $otherId) {
            throw new BusinessRuleException('No se puede unificar un registro consigo mismo.');
        }
        [$kindA, $a] = $this->record($id);
        [$kindB, $b] = $this->record($otherId);
        $caso = match (true) {
            $kindA === 'lider' && $kindB === 'lider' => 'lideres',
            $kindA === 'person' && $kindB === 'person' => 'personas',
            default => 'vinculo',
        };
        if ($caso !== 'lideres' && ! $this->campaign()->canEditElectoral()) {
            throw new BusinessRuleException('Solo la campaña principal puede unificar candidatos: sus datos son compartidos por todas las campañas.', 403);
        }

        $cedulas = null;
        if ($caso === 'lideres') {
            $ca = Cedula::normalize($a->cedula) ?: null;
            $cb = Cedula::normalize($b->cedula) ?: null;
            if ($ca && $cb && $ca !== $cb) {
                $cedulas = [$ca, $cb];
                $cedula = Cedula::normalize($cedula);
                if (! in_array($cedula, $cedulas, true)) {
                    if (! $dryRun) {
                        throw new BusinessRuleException('Los dos registros tienen cédulas distintas: elige cuál es la correcta.');
                    }
                    $cedula = $ca;
                }
            } else {
                $cedula = null;
            }
        }

        [$keptKind, $keptId, $absorbedId, $campaignId, $snapshot] = match ($caso) {
            'lideres' => $this->mergeLideres($a, $b, $cedula),
            'vinculo' => $this->linkCandidate(...($kindA === 'person' ? [$a, $b] : [$b, $a])),
            'personas' => $this->mergePersons($a, $b),
        };

        $historyId = (string) Str::uuid();
        $userId = request()->user()?->id;
        DB::table('merge_history')->insert([
            'id' => $historyId, 'type' => $caso, 'kept_id' => $keptId, 'absorbed_id' => $absorbedId,
            'campaign_id' => $campaignId, 'user_id' => $userId, 'snapshot' => json_encode($snapshot), 'created_at' => now(),
        ]);
        AuditLog::create([
            'user_id' => $userId,
            'action' => 'MERGE_RECORDS',
            'entity_type' => $keptKind,
            'entity_id' => $keptId,
            'metadata' => ['caso' => $caso, 'absorbed_id' => $absorbedId, 'merge_history_id' => $historyId, 'cedulas_distintas' => $cedulas !== null],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return ['caso' => $caso, 'id' => $keptId, 'cedulas' => $cedulas, 'final' => $this->summary($keptKind, $keptId)];
    }

    private function mergeLideres(object $a, object $b, ?string $cedula): array
    {
        $snapshot = ['lideres' => [(array) $a, (array) $b], 'referencias' => []];
        foreach (MergeLideres::references() as [$table, $column]) {
            $rows = DB::table($table)->whereIn($column, [$a->id, $b->id])->get();
            if ($rows->isNotEmpty()) {
                $snapshot['referencias']["{$table}.{$column}"] = $rows->map(fn ($r) => (array) $r)->all();
            }
        }
        $kept = $this->mergeLideres->handle($a->id, $b->id, $cedula);

        return ['lider', $kept->id, $kept->id === $a->id ? $b->id : $a->id, $a->campaign_id, $snapshot];
    }

    private function linkCandidate(object $person, object $lider): array
    {
        if ($problem = $this->link->territoryProblem($person->id, $lider)) {
            throw new BusinessRuleException($problem);
        }
        $current = $this->link->linkedLiderId($person);
        if ($current !== null && $current !== $lider->id) {
            throw new BusinessRuleException('Este candidato ya está unido a otra ficha de líder: unifica primero esas dos fichas de líder.');
        }
        $person = DB::table('persons')->where('id', $person->id)->lockForUpdate()->first();
        $changes = $this->link->link($person, $lider);

        return ['lider', $lider->id, $person->id, $lider->campaign_id, ['person' => (array) $person, 'lider_id' => $lider->id] + $changes];
    }

    // The record being viewed survives, unless only the other one is linked to a lider ficha: its name holds that link.
    private function mergePersons(object $a, object $b): array
    {
        $linkA = $this->link->linkedLiderId($a);
        $linkB = $this->link->linkedLiderId($b);
        if ($linkA && $linkB && $linkA !== $linkB) {
            throw new BusinessRuleException('Cada candidato está unido a una ficha de líder distinta: unifica primero esas dos fichas de líder.');
        }
        [$survivor, $absorbed] = ! $linkA && $linkB ? [$b, $a] : [$a, $b];
        $result = $this->mergePersons->merge($survivor->id, $absorbed->id, request()->user()?->id, self::SOURCE);

        $snapshot = [
            'persons' => [(array) $result['survivor'], (array) $result['absorbed']],
            'person_merge_transaction_id' => $result['transaction_id'],
            'movidas' => collect($result['plan']['refs'])->mapWithKeys(fn ($r) => [$r['label'] => $r['move']])->filter()->all(),
            'descartadas' => collect($result['plan']['refs'])->mapWithKeys(fn ($r) => [$r['label'] => $r['drop']])->filter()->all(),
            'campos_completados' => $result['plan']['fields'],
            'alias' => $result['plan']['alias'],
        ];

        return ['person', $survivor->id, $absorbed->id, null, $snapshot];
    }

    // A record of the ficha the user may change: a lider of their own campaign and territory, or a living candidate
    // they can see. Anything they cannot see is a 404.
    private function record(string $id): array
    {
        if (! Str::isUuid($id)) {
            throw new BusinessRuleException('No encontrado.', 404);
        }
        $lider = DB::table('lideres')->where('id', $id)->first();
        if ($lider) {
            $this->authorizeLiderRow($lider, true);

            return ['lider', $lider];
        }
        $person = DB::table('persons')->where('id', $id)->whereNull('deleted_at')->whereNull('merged_into_id')->first();
        if (! $person) {
            throw new BusinessRuleException('No encontrado.', 404);
        }
        $this->authorizePersonOrLider($id);

        return ['person', $person];
    }

    // Candidates with a candidacy, restricted to the user's territory like authorizePersonOrLider.
    private function territoryPersons($query)
    {
        $allowed = $this->allowedGeoIds();

        return $query->whereExists(function ($c) use ($allowed) {
            $c->from('candidacies as c')
                ->join('contests as con', 'c.contest_id', '=', 'con.id')
                ->whereColumn('c.person_id', 'p.id');
            if ($allowed !== null) {
                $c->where(fn ($q) => $q->whereIn('con.geographic_unit_id', $allowed)
                    ->orWhereExists(fn ($r) => $r->from('electoral_results as er')
                        ->whereColumn('er.candidacy_id', 'c.id')
                        ->whereIn('er.geographic_unit_id', $allowed)));
            }
        });
    }

    // Name, cédula, phones, cargos and candidacies of a record, as the ficha will show it.
    private function summary(string $kind, string $id): array
    {
        if ($kind === 'person') {
            $person = DB::table('persons')->where('id', $id)->first();
            $linked = $this->link->linkedLiderId($person);
            if ($linked) {
                return $this->summary('lider', $linked);
            }

            return [
                'nombre' => $person->full_name,
                'cedula' => null,
                'telefonos' => [],
                'cargos' => [],
                'candidaturas' => $this->candidacies([$id]),
            ];
        }

        $lider = DB::table('lideres')->where('id', $id)->first();
        $phones = [$lider->telefono];
        if (preg_match_all('/Otro teléfono registrado: ([^\n]+?)\.?(?:\n|$)/u', (string) $lider->observacion, $m)) {
            $phones = array_merge($phones, $m[1]);
        }
        $phones = array_merge($phones, DB::table('lider_contactos')->where('lider_id', $id)
            ->whereIn(DB::raw('LOWER(tipo)'), self::PHONE_TYPES)->pluck('valor')->all());

        return [
            'nombre' => $lider->nombre,
            'cedula' => $lider->cedula,
            'telefonos' => collect($phones)->map(fn ($p) => trim((string) $p))->filter()->unique(fn ($p) => preg_replace('/\D/', '', $p))->values()->all(),
            'cargos' => collect(explode(',', (string) $lider->cargo))->map(fn ($c) => trim($c))->filter()->values()->all(),
            'candidaturas' => $this->candidacies($this->updateVotante->linkedPersonIds($lider)->all()),
        ];
    }

    // Newest first: what they ran for, where, the year and the party.
    private function candidacies(array $personIds): array
    {
        if (! $personIds) {
            return [];
        }

        return DB::table('candidacies as c')
            ->join('contests as con', 'con.id', '=', 'c.contest_id')
            ->leftJoin('electoral_events as ev', 'ev.id', '=', 'con.electoral_event_id')
            ->leftJoin('geographic_units as g', 'g.id', '=', 'con.geographic_unit_id')
            ->whereIn('c.person_id', $personIds)
            ->orderByDesc('ev.election_date')
            ->get(['c.id', 'con.name as contest', 'g.canonical_name as territorio', 'ev.election_date'])
            ->map(fn ($c) => [
                'cargo' => $c->contest,
                'territorio' => $c->territorio ?? 'Nacional',
                'anio' => $c->election_date ? (int) substr((string) $c->election_date, 0, 4) : null,
                'partido' => DB::table('candidacy_endorsements as ce')
                    ->join('political_organizations as po', 'po.id', '=', 'ce.organization_id')
                    ->where('ce.candidacy_id', $c->id)
                    ->orderByDesc('ce.is_primary')
                    ->value('po.canonical_name'),
            ])
            ->all();
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
