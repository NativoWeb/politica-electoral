<?php

namespace App\Http\Requests\Empresarios;

use App\Models\OrganizacionMiembro;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// POST /empresarios/{id}/miembros: an existing ficha (lider_id) or a person typed by hand (nombre, cédula, teléfono).
// PUT /empresarios/miembros/{miembro}: rol and nota.
class MiembroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'lider_id' => ['nullable', 'uuid'],
            'nombre' => [$creating ? 'required_without:lider_id' : 'nullable', 'nullable', 'string', 'max:200'],
            'cedula' => ['nullable', 'string', 'max:20', 'regex:/^[\d.\s]{5,20}$/'],
            'telefono' => ['nullable', 'string', 'max:60'],
            'municipio' => ['nullable', 'string', 'max:120'],
            'rol' => ['required', Rule::in(array_keys(OrganizacionMiembro::ROLES))],
            'nota' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required_without' => 'Busque la persona o escriba su nombre.',
            'cedula.regex' => 'La cédula solo lleva números.',
            'rol.required' => 'Elija el rol.',
            'rol.in' => 'Elija un rol de la lista.',
        ];
    }
}
