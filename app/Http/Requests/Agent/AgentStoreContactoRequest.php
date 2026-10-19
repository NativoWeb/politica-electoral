<?php

namespace App\Http\Requests\Agent;

use Illuminate\Validation\Rule;

class AgentStoreContactoRequest extends AgentFormRequest
{
    public const TIPOS = ['celular', 'fijo', 'whatsapp', 'email', 'otro'];

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('tipo'))) {
            $this->merge(['tipo' => mb_strtolower(trim($this->input('tipo')))]);
        }
    }

    public function rules(): array
    {
        return [
            'tipo' => ['required', 'string', Rule::in(self::TIPOS)],
            'valor' => ['required', 'string', 'max:200'],
            'principal' => ['sometimes', 'boolean'],
        ];
    }
}
