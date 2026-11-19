<?php

namespace App\Http\Requests;

use App\Models\Campaign;
use App\Services\CampaignService;
use App\Support\CurrentCampaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

// Create and edit a campaign from /admin/campanas. The slug is derived from the name when left empty.
class CampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(CurrentCampaign::class)->isSuperadmin();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'slug' => CampaignService::slug($this->input('slug'), $this->input('name')),
            'parent_id' => $this->input('parent_id') ?: null,
            'candidate_person_id' => $this->input('candidate_person_id') ?: null,
        ]);
        // A switch that was not sent keeps its stored value (and leaves no audit): only a sent one is changed.
        foreach (['whatsapp_enabled', 've_resultados_electorales'] as $switch) {
            if ($this->has($switch)) {
                $this->merge([$switch => $this->boolean($switch)]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', Rule::unique('campaigns', 'slug')->ignore($this->route('id'))],
            'parent_id' => ['nullable', 'uuid', 'exists:campaigns,id'],
            'candidate_person_id' => ['nullable', 'uuid', Rule::exists('persons', 'id')->whereNull('deleted_at')],
            'whatsapp_enabled' => ['boolean'],
            've_resultados_electorales' => ['boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                // The root group always sees the electoral results: they are the platform's own data.
                if ($this->route('id') === Campaign::DEFAULT_ID && $this->has('ve_resultados_electorales')
                    && ! $this->boolean('ve_resultados_electorales')) {
                    $validator->errors()->add('ve_resultados_electorales', 'El grupo político principal siempre ve los resultados electorales.');
                }
                if ($validator->errors()->has('parent_id')) {
                    return;
                }
                $error = app(CampaignService::class)->parentError($this->route('id'), $this->input('parent_id'));
                if ($error) {
                    $validator->errors()->add('parent_id', $error);
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Escribe el nombre del grupo político.',
            'slug.required' => 'El identificador no puede quedar vacío.',
            'slug.unique' => 'Ya existe un grupo político con ese identificador. Escribe otro.',
            'parent_id.exists' => 'Grupo principal no encontrado.',
            'candidate_person_id.exists' => 'El candidato elegido no existe.',
        ];
    }
}
