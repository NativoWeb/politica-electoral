<?php

namespace App\Http\Requests\Agent;

class AgentStoreVotanteRequest extends AgentFormRequest
{
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
            'municipio_id' => ['required', 'uuid', 'exists:geographic_units,id'],
            'cedula' => ['nullable', 'string', 'max:20'],
            'cargo' => ['nullable', 'string', 'max:255'],
            'partido' => ['nullable', 'string', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'barrio' => ['nullable', 'string', 'max:100'],
            'zona' => ['nullable', 'in:rural,urbana'],
            'profesion' => ['nullable', 'string', 'max:255'],
            'genero' => ['nullable', 'string', 'max:20'],
            'fecha_nacimiento' => ['nullable', 'date', 'before:today'],
            ...AgentVotanteRules::editable(),
        ];
    }
}
