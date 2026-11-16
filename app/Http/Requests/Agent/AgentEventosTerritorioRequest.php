<?php

namespace App\Http\Requests\Agent;

// Query of GET /eventos/territorio: one municipio (required) and, optionally, one barrio.
class AgentEventosTerritorioRequest extends AgentFormRequest
{
    public function rules(): array
    {
        return [
            'municipio_id' => ['required', 'uuid'],
            'barrio' => ['sometimes', 'string', 'min:2', 'max:120'],
        ];
    }
}
