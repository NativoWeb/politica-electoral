<?php

namespace App\Http\Requests\Eventos;

use Illuminate\Foundation\Http\FormRequest;

// POST /eventos/{id}/actas/sugerencias: who of the event's campaign the participants and responsables may be.
class ActaSuggestRequest extends FormRequest
{
    use AuthorizesEventActa;

    public function rules(): array
    {
        return [
            'participantes' => ['nullable', 'array', 'max:300'],
            'participantes.*.nombre' => ['nullable', 'string', 'max:200'],
            'participantes.*.telefono' => ['nullable', 'string', 'max:60'],
            'participantes.*.cedula' => ['nullable', 'string', 'max:20'],
            'responsables' => ['nullable', 'array', 'max:500'],
            'responsables.*.nombre' => ['nullable', 'string', 'max:200'],
            'responsables.*.telefono' => ['nullable', 'string', 'max:60'],
        ];
    }
}
