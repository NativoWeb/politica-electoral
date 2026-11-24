<?php

namespace App\Services\CampanasElectorales;

use App\Exceptions\BusinessRuleException;
use App\Models\ElectoralCampaign;
use App\Models\User;
use App\Support\CurrentCampaign;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

// Who sees and who changes an electoral campaign (docs/campanas-electorales.md). Seen: its owner group is visible
// (CurrentCampaign::visibleIds, which honours the superadmin's header). Changed: besides, manage.campanas, the own
// group and no territory_scope. Anything not seen is a 404; a user with a territory only sees the campaigns that cross
// it, and only its rows.
class ElectoralCampaignAccess
{
    public const OTRO_GRUPO = 'Esta campaña pertenece a otro grupo político: solo se puede consultar.';

    public const CON_TERRITORIO = 'Tu usuario tiene un territorio asignado: las campañas son solo de consulta.';

    public const ARCHIVADA = 'La campaña está archivada: reactívala para cambiarla.';

    private TerritorioCampana $territorio;

    public function __construct(private readonly CurrentCampaign $campaign)
    {
        $this->territorio = new TerritorioCampana;
    }

    public function territorio(): TerritorioCampana
    {
        return $this->territorio;
    }

    public function campaign(): CurrentCampaign
    {
        return $this->campaign;
    }

    public function user(): ?User
    {
        return $this->campaign->user();
    }

    public function canManage(): bool
    {
        return (bool) $this->user()?->hasPermission('manage.campanas');
    }

    // Municipio ids of the user's territory_scope, or null when unrestricted.
    public function allowedMunicipios(): ?array
    {
        return $this->user()?->allowedGeoIds();
    }

    // Campaigns of every visible group, with their territories.
    public function query(): Builder
    {
        $query = ElectoralCampaign::query()->with('territories:id,electoral_campaign_id,geographic_unit_id');
        $visible = $this->campaign->visibleIds();
        if ($visible !== null) {
            $query->whereIn('campaign_id', $visible);
        }

        return $query;
    }

    public function territoryIds(ElectoralCampaign $c): array
    {
        return $c->territories->pluck('geographic_unit_id')->all();
    }

    public function crossesTerritory(ElectoralCampaign $c): bool
    {
        $allowed = $this->allowedMunicipios();

        return $allowed === null
            || array_intersect($this->territorio->municipiosDe($c->scope_level, $this->territoryIds($c)), $allowed) !== [];
    }

    // The campaign the user may see (404 otherwise); with $write, also one they may change (403 otherwise).
    public function find(string $id, bool $write = false): ElectoralCampaign
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }
        $c = $this->query()->find($id);
        if (! $c || ! $this->crossesTerritory($c)) {
            abort(404);
        }
        if ($write) {
            $this->authorizeWrite($c);
        }

        return $c;
    }

    public function authorizeWrite(ElectoralCampaign $c): void
    {
        if (! $this->canManage()) {
            abort(403, 'No tienes permiso para gestionar campañas electorales.');
        }
        if (! $this->campaign->isOwn($c->campaign_id)) {
            abort(403, self::OTRO_GRUPO);
        }
        if ($this->allowedMunicipios() !== null) {
            abort(403, self::CON_TERRITORIO);
        }
    }

    public function ensureActive(ElectoralCampaign $c): void
    {
        if (! $c->isActive()) {
            throw new BusinessRuleException(self::ARCHIVADA, 422);
        }
    }

    // Why the campaign is read only for this user, or null when they may edit it.
    public function readOnly(ElectoralCampaign $c): ?array
    {
        return match (true) {
            ! $this->canManage() => ['motivo' => 'permiso', 'texto' => 'Solo lectura: no tienes permiso para gestionar campañas electorales.'],
            ! $this->campaign->isOwn($c->campaign_id) => ['motivo' => 'subgrupo', 'texto' => 'Solo lectura: esta campaña es de otro grupo político.'],
            $this->allowedMunicipios() !== null => ['motivo' => 'territorio', 'texto' => 'Solo lectura: tu usuario tiene un territorio asignado.'],
            ! $c->isActive() => ['motivo' => 'archivada', 'texto' => 'Solo lectura: la campaña está archivada. Reactívala para editarla.'],
            default => null,
        };
    }

    // Manage, own group and no territory: may archive, reactivate and download the template.
    public function canWriteGroup(ElectoralCampaign $c): bool
    {
        return $this->canManage() && $this->campaign->isOwn($c->campaign_id) && $this->allowedMunicipios() === null;
    }

    // The rows a user with a territory may see: their municipios and the comunas below them.
    public function visibleFilas(array $filas): array
    {
        $allowed = $this->allowedMunicipios();
        if ($allowed === null) {
            return $filas;
        }

        return array_values(array_filter($filas, fn ($f) => in_array($f['municipio_id'], $allowed, true)));
    }
}
