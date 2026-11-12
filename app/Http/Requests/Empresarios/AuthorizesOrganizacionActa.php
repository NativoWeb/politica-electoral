<?php

namespace App\Http\Requests\Empresarios;

use App\Models\EventActa;
use App\Services\Empresarios\OrganizacionScope;
use Illuminate\Support\Str;

// The organization (own campaign, territory) and the acta are checked before the body, so a 422 never tells that an
// organization of another campaign exists.
trait AuthorizesOrganizacionActa
{
    public function authorize(): bool
    {
        $org = app(OrganizacionScope::class)->find((string) $this->route('id'), true);
        $acta = $this->route('acta');
        if ($acta !== null) {
            abort_unless(Str::isUuid((string) $acta) && EventActa::where('organizacion_id', $org->id)->whereKey($acta)->exists(), 404);
        }

        return true;
    }
}
