<?php

namespace App\Http\Requests\Agent;

use Illuminate\Contracts\Validation\Validator;

class AgentStoreReferidoRequest extends AgentFormRequest
{
    public function rules(): array
    {
        return [
            'lider_id' => ['required', 'uuid'],
        ];
    }

    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator) {
                if ($this->input('lider_id') === $this->route('id')) {
                    $validator->errors()->add('lider_id', 'Una persona no puede ser su propio referido.');
                }
            },
        ];
    }
}
