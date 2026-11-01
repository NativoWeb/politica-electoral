<?php

namespace App\Http\Requests\Eventos;

use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\CampaignEvent;
use App\Models\EventImport;
use Illuminate\Support\Str;

// The import requests check the event (own campaign, territory) and the import before validating the body, as the
// controller does: another campaign gets its 404 (a visible subcampaign its 403) even when the body is invalid,
// so a 422 never tells that the event exists.
trait AuthorizesEventImport
{
    use ScopesTerritory;

    public function authorize(): bool
    {
        $id = (string) $this->route('id');
        abort_unless(Str::isUuid($id), 404);
        $event = CampaignEvent::query()->find($id, ['id', 'campaign_id', 'municipio_id']);
        abort_unless($event !== null, 404);
        $this->authorizeCampaign($event->campaign_id, true);
        $this->authorizeGeo($event->municipio_id);

        $import = $this->route('import');
        if ($import !== null) {
            abort_unless(
                Str::isUuid((string) $import) && EventImport::where('event_id', $event->id)->whereKey($import)->exists(),
                404,
            );
        }

        return true;
    }
}
