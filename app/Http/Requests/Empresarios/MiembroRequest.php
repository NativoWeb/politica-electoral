<?php

namespace App\Http\Requests\Empresarios;

use App\Rules\NombreSinNumeros;
use App\Rules\OpcionDeCatalogo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        $miembro = $this->route('miembro');
        $miembroId = is_object($miembro) ? $miembro->getKey() : $miembro;
        $rolActual = ! $creating && is_string($miembroId) && Str::isUuid($miembroId)
            ? DB::table('organizacion_miembros')->where('id', $miembroId)->value('rol') : null;

        return [
            'lider_id' => ['nullable', 'uuid'],
            // A person typed by hand gets a ficha: its name follows the ficha's rule. With lider_id the name is not used.
            'nombre' => [$creating ? 'required_without:lider_id' : 'nullable', 'nullable', 'string', 'max:200',
                ...($this->filled('lider_id') ? [] : [new NombreSinNumeros])],
            'cedula' => ['nullable', 'string', 'max:20', 'regex:/^[\d.\s]{5,20}$/'],
            'telefono' => ['nullable', 'string', 'max:60'],
            'municipio' => ['nullable', 'string', 'max:120'],
            'rol' => ['required', 'string', new OpcionDeCatalogo('rol_miembro', [$rolActual])],
            'nota' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required_without' => 'Busque la persona o escriba su nombre.',
            'cedula.regex' => 'La cédula solo lleva números.',
            'rol.required' => 'Elija el rol.',
        ];
    }
}
