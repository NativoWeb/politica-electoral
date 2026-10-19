<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Facades\DB;

// Enforces the user's territory_scope (allowed municipio ids) on single records, not only on listings.
trait ScopesTerritory
{
    protected function allowedGeoIds(): ?array
    {
        return request()->user()?->allowedGeoIds();
    }

    protected function denyOutsideTerritory(): never
    {
        abort(403, 'Este registro está fuera de tu territorio.');
    }

    protected function authorizeGeo(?string $geoId): void
    {
        $allowed = $this->allowedGeoIds();
        if ($allowed !== null && (! $geoId || ! in_array($geoId, $allowed, true))) {
            $this->denyOutsideTerritory();
        }
    }

    // A missing lider is left to the caller (404); an existing one outside the territory is denied.
    protected function authorizeLider(?string $liderId): void
    {
        if ($this->allowedGeoIds() === null || ! $liderId) {
            return;
        }
        $lider = DB::table('lideres')->where('id', $liderId)->first(['geographic_unit_id']);
        if ($lider) {
            $this->authorizeGeo($lider->geographic_unit_id);
        }
    }

    // Electoral persons belong to a territory through their candidacies or their results there.
    protected function authorizePersonOrLider(?string $id): void
    {
        $allowed = $this->allowedGeoIds();
        if ($allowed === null || ! $id) {
            return;
        }
        if (DB::table('lideres')->where('id', $id)->exists()) {
            $this->authorizeLider($id);

            return;
        }
        if (! DB::table('persons')->where('id', $id)->exists()) {
            return;
        }
        $inTerritory = DB::table('candidacies as c')
            ->join('contests as con', 'c.contest_id', '=', 'con.id')
            ->where('c.person_id', $id)
            ->where(fn ($q) => $q->whereIn('con.geographic_unit_id', $allowed)
                ->orWhereExists(fn ($r) => $r->from('electoral_results as er')
                    ->whereColumn('er.candidacy_id', 'c.id')
                    ->whereIn('er.geographic_unit_id', $allowed)))
            ->exists();
        if (! $inTerritory) {
            $this->denyOutsideTerritory();
        }
    }

    protected function duplicateCedulaMessage(string $cedula, object $existente): string
    {
        $allowed = $this->allowedGeoIds();
        if ($allowed !== null && ! in_array($existente->geographic_unit_id ?? null, $allowed, true)) {
            return "Ya existe un líder con cédula {$cedula} en otro territorio.";
        }

        return "Ya existe un líder con cédula {$cedula}: {$existente->nombre}";
    }

    protected function scopeToTerritory($query, string $column = 'geographic_unit_id')
    {
        $allowed = $this->allowedGeoIds();
        if ($allowed !== null) {
            $query->whereIn($column, $allowed);
        }

        return $query;
    }
}
