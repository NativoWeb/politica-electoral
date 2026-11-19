<?php

namespace App\Http\Requests\Agent;

use App\Rules\OpcionDeCatalogo;

class AgentStoreContactoRequest extends AgentFormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('tipo'))) {
            $this->merge(['tipo' => mb_strtolower(trim($this->input('tipo')))]);
        }
    }

    public function rules(): array
    {
        return [
            'tipo' => ['required', 'string', new OpcionDeCatalogo('tipo_contacto')],
            'valor' => ['required', 'string', 'max:200'],
            'principal' => ['sometimes', 'boolean'],
        ];
    }
}
