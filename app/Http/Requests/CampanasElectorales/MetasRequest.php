<?php

namespace App\Http\Requests\CampanasElectorales;

use App\Models\ElectoralCampaign;
use App\Services\CampanasElectorales\ElectoralCampaignAccess;
use App\Services\CampanasElectorales\MetasService;
use Illuminate\Foundation\Http\FormRequest;

// Body of PUT /campanas-electorales/{id}/metas: up to lote_max goals, each a whole number from 0 to meta_max or null.
// The campaign is found (404/403) and must be active (422) before the body is looked at.
class MetasRequest extends FormRequest
{
    public ElectoralCampaign $campana;

    public function authorize(ElectoralCampaignAccess $access): bool
    {
        $this->campana = $access->find((string) $this->route('id'), true);
        $access->ensureActive($this->campana);

        return true;
    }

    public function rules(): array
    {
        $max = (int) config('campanas_electorales.meta_max');
        $numero = ['nullable', 'numeric', 'integer', 'min:0', "max:{$max}"];

        return [
            'metas' => ['required', 'array', 'min:1', 'max:'.(int) config('campanas_electorales.lote_max')],
            'metas.*' => ['array'],
            'metas.*.geographic_unit_id' => ['required', 'uuid', 'distinct'],
            'metas.*.votos_meta' => $numero,
            'metas.*.lideres_meta' => $numero,
            'metas.*.votantes_meta' => $numero,
        ];
    }

    public function messages(): array
    {
        $lote = (int) config('campanas_electorales.lote_max');

        return [
            'metas.required' => 'No hay metas para guardar.',
            'metas.array' => 'No hay metas para guardar.',
            'metas.min' => 'No hay metas para guardar.',
            'metas.max' => "Guarda hasta {$lote} territorios a la vez.",
            'metas.*.array' => 'Ese territorio no es de esta campaña.',
            'metas.*.geographic_unit_id.required' => 'Ese territorio no es de esta campaña.',
            'metas.*.geographic_unit_id.uuid' => 'Ese territorio no es de esta campaña.',
            'metas.*.geographic_unit_id.distinct' => 'Ese territorio está repetido.',
            'metas.*.votos_meta.max' => MetasService::tope(),
            'metas.*.lideres_meta.max' => MetasService::tope(),
            'metas.*.votantes_meta.max' => MetasService::tope(),
            'metas.*.votos_meta.*' => MetasService::NUMERO,
            'metas.*.lideres_meta.*' => MetasService::NUMERO,
            'metas.*.votantes_meta.*' => MetasService::NUMERO,
        ];
    }

    // The items as sent, keeping which goal keys came (a missing key changes nothing).
    public function items(): array
    {
        $validated = $this->validated()['metas'];

        return array_map(fn ($item) => array_intersect_key($item, array_flip(['geographic_unit_id', 'votos_meta', 'lideres_meta', 'votantes_meta'])), $validated);
    }
}
