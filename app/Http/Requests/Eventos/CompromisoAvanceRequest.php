<?php

namespace App\Http\Requests\Eventos;

use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\EventActa;
use App\Models\EventCompromiso;
use App\Services\Eventos\Actas\ActaFiles;
use App\Services\Eventos\EventScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

// POST /eventos/compromisos/{compromiso}/avances: the compromiso is checked before the body (404 outside the visible
// events, 403 in a subcampaign), so a 422 never tells that it exists.
class CompromisoAvanceRequest extends FormRequest
{
    use ScopesTerritory;

    public function authorize(): bool
    {
        $id = (string) $this->route('compromiso');
        abort_unless(Str::isUuid($id), 404);
        $c = EventCompromiso::query()->find($id, ['id', 'acta_id', 'event_id', 'campaign_id']);
        abort_unless($c && app(EventScope::class)->visibleEventId($c->event_id), 404);
        // A borrador's compromisos take no avances (they are not even listed).
        abort_unless(EventActa::whereKey($c->acta_id)->where('estado', EventActa::CONFIRMADA)->exists(), 404);
        $this->authorizeCampaign($c->campaign_id, true);

        return true;
    }

    public function rules(): array
    {
        return [
            'nota' => ['nullable', 'string', 'max:5000', 'required_without:archivo'],
            'archivo' => ['nullable', 'file', 'max:'.ActaFiles::maxKb()],
        ];
    }

    public function messages(): array
    {
        $mb = ActaFiles::maxMb();

        return [
            'nota.required_without' => 'Escriba una nota o adjunte una foto o un PDF.',
            'archivo.uploaded' => "No se pudo subir el archivo. Revise que pese menos de {$mb} MB e intente de nuevo.",
            'archivo.max' => "El archivo pesa más de {$mb} MB.",
        ];
    }
}
