<?php

namespace App\Http\Requests;

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
            'whatsapp_enabled' => $this->boolean('whatsapp_enabled'),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', Rule::unique('campaigns', 'slug')->ignore($this->route('id'))],
            'parent_id' => ['nullable', 'uuid', 'exists:campaigns,id'],
            'candidate_person_id' => ['nullable', 'uuid', Rule::exists('persons', 'id')->whereNull('deleted_at')],
            'whatsapp_enabled' => ['boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
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
            'name.required' => 'Escribe el nombre de la campaña.',
            'slug.required' => 'El identificador no puede quedar vacío.',
            'slug.unique' => 'Ya existe una campaña con ese identificador. Escribe otro.',
            'parent_id.exists' => 'Campaña madre no encontrada.',
            'candidate_person_id.exists' => 'El candidato elegido no existe.',
        ];
    }
}
