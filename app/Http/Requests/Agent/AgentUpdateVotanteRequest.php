<?php

namespace App\Http\Requests\Agent;

use Illuminate\Contracts\Validation\Validator;

class AgentUpdateVotanteRequest extends AgentFormRequest
{
    // Field operators (R09) may not rename a votante through the agent: for them nombre stays "Campo no permitido.".
    public function rules(): array
    {
        $rules = AgentVotanteRules::editable();
        if ($this->user()?->role?->code !== 'R09_CAMPO') {
            $rules['nombre'] = ['sometimes', 'string', 'min:3', 'max:150'];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('nombre'))) {
            $this->merge(['nombre' => preg_replace('/\s+/u', ' ', trim($this->input('nombre')))]);
        }
    }

    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator) {
                if ($this->all() === []) {
                    $validator->errors()->add('body', 'No hay campos para actualizar.');
                }
            },
        ];
    }
}
