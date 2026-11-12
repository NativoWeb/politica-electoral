<?php

namespace App\Services\Empresarios;

use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\Organizacion;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The organizations a user may open (docs/empresarios.md): visible campaigns (own + subcampaigns), the user's
// territory by municipio (an organization without municipio only for users without territory), not deleted.
// Writing needs the own campaign: a subcampaign's organization is read-only (403), another campaign's is a 404.
class OrganizacionScope
{
    use ScopesTerritory;

    public static function canView(?User $user): bool
    {
        return (bool) $user && ($user->hasPermission('view.empresarios') || $user->hasPermission('manage.empresarios'));
    }

    public static function canManage(?User $user): bool
    {
        return (bool) $user && $user->hasPermission('manage.empresarios');
    }

    public function query(string $alias = 'o'): Builder
    {
        return $this->scope(DB::table("organizaciones as {$alias}"), $alias);
    }

    public function scope(Builder $query, string $alias = 'o'): Builder
    {
        $query->whereNull("{$alias}.deleted_at");
        $this->campaign()->scope($query, "{$alias}.campaign_id");
        $this->scopeToTerritory($query, "{$alias}.municipio_id");

        return $query;
    }

    public function find(string $id, bool $write = false): Organizacion
    {
        abort_unless(Str::isUuid($id), 404);
        $org = Organizacion::find($id);
        abort_unless($org !== null, 404);
        $this->authorizeCampaign($org->campaign_id, $write);
        $this->authorizeGeo($org->municipio_id);

        return $org;
    }

    public function editable(object $org): bool
    {
        return self::canManage(request()->user()) && $this->campaign()->isOwn($org->campaign_id);
    }
}
