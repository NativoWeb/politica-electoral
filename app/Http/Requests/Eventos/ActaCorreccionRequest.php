<?php

namespace App\Http\Requests\Eventos;

// Body of PUT /eventos/{id}/actas/{acta}/correccion (docs/eventos-api.md §14 "Corregir un acta confirmada"): the acta's
// body plus the version the user started from.
class ActaCorreccionRequest extends ActaRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'version' => ['required', 'integer', 'min:0']];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'version.required' => 'Falta la versión del acta. Recargue la página e intente de nuevo.',
            'version.integer' => 'La versión del acta no es válida. Recargue la página e intente de nuevo.',
        ];
    }
}
