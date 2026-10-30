<?php

namespace App\Http\Controllers\Concerns;

use App\Support\CurrentCampaign;
use Illuminate\Support\Facades\DB;

// Enforces the user's territory_scope (allowed municipio ids) on single records, not only on listings,
// and the campaign isolation of lideres and nexos: a record of a campaign the user cannot see is a 404,
// one the user can see but that belongs to another campaign (a subcampaign) is read-only (403 on writes).
trait ScopesTerritory
{
    protected function campaign(): CurrentCampaign
    {
        return app(CurrentCampaign::class);
    }

    // Lideres of every campaign the user can see: for reads.
    protected function visibleLideres(?string $alias = null)
    {
        return $this->campaign()->scope(DB::table($alias ? "lideres as {$alias}" : 'lideres'), ($alias ?? 'lideres').'.campaign_id');
    }

    // Lideres of the user's own campaign: for writes and for links that lead to a write.
    protected function ownLideres(?string $alias = null)
    {
        $query = DB::table($alias ? "lideres as {$alias}" : 'lideres');
        $own = $this->campaign()->ownId();
        if ($own !== null) {
            $query->where(($alias ?? 'lideres').'.campaign_id', $own);
        }

        return $query;
    }

    // Rows of the user's own campaign before a subcampaign's, where the first match is the one used.
    protected function ownFirst($query, string $column = 'campaign_id')
    {
        $own = $this->campaign()->ownId();
        if ($own !== null) {
            $query->orderByRaw("CASE WHEN {$column} = ? THEN 0 ELSE 1 END", [$own]);
        }

        return $query;
    }

    protected function authorizeCampaign(?string $campaignId, bool $write = false): void
    {
        abort_unless($this->campaign()->isVisible($campaignId), 404);
        if ($write && ! $this->campaign()->isOwn($campaignId)) {
            abort(403, 'Este registro pertenece a otra campaña: solo se puede consultar.');
        }
    }

    // $lider needs campaign_id and geographic_unit_id.
    protected function authorizeLiderRow(object $lider, bool $write = false): void
    {
        $this->authorizeCampaign($lider->campaign_id ?? null, $write);
        $this->authorizeGeo($lider->geographic_unit_id ?? null);
    }

    // A missing lider is left to the caller.
    protected function authorizeLiderWrite(?string $liderId): void
    {
        $lider = $liderId ? DB::table('lideres')->where('id', $liderId)->first(['geographic_unit_id', 'campaign_id']) : null;
        if ($lider) {
            $this->authorizeLiderRow($lider, true);
        }
    }

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
        $lider = $liderId ? DB::table('lideres')->where('id', $liderId)->first(['geographic_unit_id', 'campaign_id']) : null;
        if ($lider) {
            $this->authorizeLiderRow($lider);
        }
    }

    // Electoral persons belong to a territory through their candidacies or their results there.
    // $write only matters for lideres: shared electoral persons are guarded by canEditElectoral() where edited.
    protected function authorizePersonOrLider(?string $id, bool $write = false): void
    {
        if (! $id) {
            return;
        }
        $lider = DB::table('lideres')->where('id', $id)->first(['geographic_unit_id', 'campaign_id']);
        if ($lider) {
            $this->authorizeLiderRow($lider, $write);

            return;
        }
        $allowed = $this->allowedGeoIds();
        if ($allowed === null) {
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
