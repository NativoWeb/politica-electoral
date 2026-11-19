<?php

namespace App\Http\Requests\Configuracion;

use Illuminate\Foundation\Http\FormRequest;

// POST /admin/configuracion/listas/{catalogo}/opciones (etiqueta, color) and PUT /admin/configuracion/opciones/{id}
// (etiqueta, activo, color). The valor is never sent: it is derived on creation and never changes.
class OpcionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('color') && in_array($this->input('color'), ['', null], true)) {
            $this->merge(['color' => null]);
        }
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'etiqueta' => [$creating ? 'required' : 'sometimes', 'string', 'max:100', 'regex:/\S/'],
            'activo' => ['sometimes', 'boolean'],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'etiqueta.required' => 'Escriba el nombre de la opción.',
            'etiqueta.regex' => 'Escriba el nombre de la opción.',
            'etiqueta.max' => 'El nombre no puede tener más de 100 caracteres.',
            'color.regex' => 'El color debe ser un código como #1D4ED8.',
        ];
    }
}
