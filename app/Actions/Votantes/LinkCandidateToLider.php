<?php

namespace App\Actions\Votantes;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Services\Import\TextNormalizer;
use App\Support\GeoTerritory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// A candidate (person) and a lider are one individual when the candidate carries the lider's exact name and the lider
// is the only one with that name in the contest's territory (linkedLiderId / UpdateVotante::linkedPersonIds).
// Linking renames the candidate to the lider's name and keeps the old name as an alias; nothing else changes.
class LinkCandidateToLider
{
    use ScopesTerritory;

    // The single lider with the candidate's exact name where they ran: in the contest's municipio, or anywhere below a
    // departmental/national contest. A lider in the very municipio of a contest decides before the wider territory;
    // two namesakes at the deciding level link none. Namesakes count in the whole campaign, not only in the user's
    // territory, and the one found must then be inside it. The user's own campaign decides first; a subcampaign's
    // lider is used only when the own campaign has none.
    public function linkedLiderId(object $person): ?string
    {
        $contestGeoIds = DB::table('candidacies as c')
            ->join('contests as con', 'con.id', '=', 'c.contest_id')
            ->where('c.person_id', $person->id)
            ->distinct()
            ->pluck('con.geographic_unit_id');
        if ($contestGeoIds->isEmpty()) {
            return null;
        }
        $direct = $contestGeoIds->filter()->values()->all();
        $national = $contestGeoIds->contains(null);
        $territory = $national ? null : collect($direct)->flatMap(fn ($g) => GeoTerritory::selfAndDescendants($g))->unique()->values()->all();

        $find = fn ($lideres) => $lideres
            ->whereRaw('LOWER(TRIM(l.nombre)) = LOWER(TRIM(?))', [$person->full_name])
            ->when($territory !== null, fn ($q) => $q->whereIn('l.geographic_unit_id', $territory))
            ->get(['l.id', 'l.geographic_unit_id']);

        $lideres = $find($this->ownLideres('l'));
        if ($lideres->isEmpty()) {
            $lideres = $find($this->visibleLideres('l'));
        }

        $inContestUnit = $lideres->whereIn('geographic_unit_id', $direct);
        $deciding = ($inContestUnit->isNotEmpty() ? $inContestUnit : $lideres)->unique('id');
        if ($deciding->count() !== 1) {
            return null;
        }
        $lider = $deciding->first();
        $allowed = $this->allowedGeoIds();

        return $allowed === null || in_array($lider->geographic_unit_id, $allowed, true) ? $lider->id : null;
    }

    // Whether the person ran for something held in that municipio, its department or nationally (what lets a lider
    // be linked to them).
    public function ranIn(string $personId, ?string $geoId): bool
    {
        return $geoId !== null && GeoTerritory::whereCovers(DB::table('candidacies as c'), 'con.geographic_unit_id', $geoId)
            ->join('contests as con', 'con.id', '=', 'c.contest_id')
            ->where('c.person_id', $personId)
            ->exists();
    }

    // Why the lider cannot be this candidate, in words the user understands; null when it can.
    public function territoryProblem(string $personId, object $lider): ?string
    {
        if ($this->ranIn($personId, $lider->geographic_unit_id)) {
            return null;
        }
        $where = DB::table('candidacies as c')
            ->join('contests as con', 'con.id', '=', 'c.contest_id')
            ->leftJoin('geographic_units as g', 'g.id', '=', 'con.geographic_unit_id')
            ->where('c.person_id', $personId)
            ->distinct()
            ->pluck('g.canonical_name')
            ->map(fn ($name) => $name ?? 'todo el país')
            ->unique()
            ->implode(', ');
        $liderPlace = $lider->municipio ?: 'otro lugar';

        return $where === ''
            ? 'Esta persona no tiene candidaturas: no se puede unificar con un líder.'
            : "El candidato se postuló en {$where} y el líder está en {$liderPlace}, fuera de ese territorio: no pueden ser la misma ficha. "
                .'Solo se unifica un candidato con un líder del municipio, provincia o departamento donde se postuló.';
    }

    // Runs inside the caller's transaction, after authorization. Returns what changed (for merge_history).
    public function link(object $person, object $lider): array
    {
        $now = now();
        $oldKey = TextNormalizer::normalizeName($person->full_name);
        $aliasId = null;
        $exists = DB::table('person_aliases')->where('person_id', $person->id)->where('normalized_alias', $oldKey)->exists();
        if (! $exists && $oldKey !== TextNormalizer::normalizeName($lider->nombre)) {
            $aliasId = (string) Str::uuid();
            DB::table('person_aliases')->insert([
                'id' => $aliasId, 'person_id' => $person->id, 'alias_name' => $person->full_name,
                'normalized_alias' => $oldKey, 'alias_type' => 'name', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        UpdateVotante::renamePerson($person->id, $lider->nombre);
        // Linking is by name: with a namesake ficha in the contest's territory it would not hold.
        if ($this->linkedLiderId(DB::table('persons')->where('id', $person->id)->first()) !== $lider->id) {
            throw new BusinessRuleException('Hay otro líder con el mismo nombre en el territorio de la candidatura: no se puede saber cuál es este candidato. Unifica primero los líderes duplicados.');
        }

        return ['alias_id' => $aliasId];
    }
}
