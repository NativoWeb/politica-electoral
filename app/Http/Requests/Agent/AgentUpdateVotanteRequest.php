<?php

namespace App\Http\Requests\Agent;

use Illuminate\Contracts\Validation\Validator;

class AgentUpdateVotanteRequest extends AgentFormRequest
{
    public function rules(): array
    {
        return AgentVotanteRules::editable();
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
