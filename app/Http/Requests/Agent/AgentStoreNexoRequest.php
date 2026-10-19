<?php

namespace App\Http\Requests\Agent;

class AgentStoreNexoRequest extends AgentFormRequest
{
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
            'parentesco' => ['nullable', 'string', 'max:50'],
            'cargo' => ['nullable', 'string', 'max:100'],
            'edad' => ['nullable', 'integer', 'min:0', 'max:120'],
            'gustos' => ['nullable', 'string', 'max:500'],
            'observaciones' => ['nullable', 'string', 'max:500'],
            'cedula' => ['nullable', 'string', 'max:20'],
            'telefono' => ['nullable', 'string', 'max:20'],
        ];
    }
}
