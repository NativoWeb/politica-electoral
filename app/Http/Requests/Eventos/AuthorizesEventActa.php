<?php

namespace App\Http\Requests\Eventos;

use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\CampaignEvent;
use App\Models\EventActa;
use Illuminate\Support\Str;

// The acta requests check the event (own campaign, territory) and the acta before validating the body, as the
// import requests do, so a 422 never tells that an event of another campaign exists.
trait AuthorizesEventActa
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

        $acta = $this->route('acta');
        if ($acta !== null) {
            abort_unless(Str::isUuid((string) $acta) && EventActa::where('event_id', $event->id)->whereKey($acta)->exists(), 404);
        }

        return true;
    }
}
