<?php

namespace App\Http\Requests\Eventos;

use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\CampaignEvent;
use App\Models\EventImport;
use App\Services\Eventos\Ocr\OcrSettings;
use Illuminate\Support\Str;

// The import requests check the event (own campaign, territory) and the import before validating the body, as the
// controller does: another campaign gets its 404 (a visible subcampaign its 403) even when the body is invalid,
// so a 422 never tells that the event exists. An OCR load (§13) also needs its two switches (404 / 403).
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

        if ($this->routeIs('eventos.importar.ocr.*')) {
            abort_unless(OcrSettings::enabledFor($event->campaign_id), 403, OcrSettings::DISABLED_FOR_CAMPAIGN);
        }

        $import = $this->route('import') ?? $this->input('importacion');
        if ($import !== null) {
            $kind = Str::isUuid((string) $import) ? EventImport::where('event_id', $event->id)->whereKey($import)->value('kind') : null;
            abort_unless($kind !== null, 404);
            if ($kind === 'ocr') {
                abort_unless(OcrSettings::enabled(), 404);
                abort_unless(OcrSettings::enabledFor($event->campaign_id), 403, OcrSettings::DISABLED_FOR_CAMPAIGN);
            }
        }

        return true;
    }
}
