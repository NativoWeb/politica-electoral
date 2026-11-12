<?php

namespace App\Http\Requests\Empresarios;

use App\Models\Organizacion;
use App\Support\Cedula;
use App\Support\Nit;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

// Validation of an organization, shared by the form (OrganizacionRequest) and each row of the Excel load.
class OrganizacionRules
{
    public static function rules(): array
    {
        return [
            'tipo' => ['required', Rule::in(array_keys(Organizacion::TIPOS))],
            'nombre' => ['required', 'string', 'max:255'],
            'nit' => ['nullable', 'string', 'max:20'],
            'representante_nombre' => ['nullable', 'string', 'max:200'],
            'representante_cedula' => ['nullable', 'string', 'max:20'],
            'representante_celular' => ['nullable', 'string', 'max:60'],
            'representante_lider_id' => ['nullable', 'uuid'],
            'telefono' => ['nullable', 'string', 'max:60'],
            'correo' => ['nullable', 'email:rfc', 'max:150'],
            'sitio_web' => ['nullable', 'string', 'max:255', 'regex:/^(https?:\/\/)?[\w.-]+\.[a-z]{2,}(\/\S*)?$/iu'],
            'sector_economico_id' => ['nullable', 'uuid'],
            'sector_id' => ['nullable', 'uuid'],
            'departamento' => ['nullable', 'string', 'max:80'],
            'municipio_id' => ['nullable', 'uuid'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'barrio' => ['nullable', 'string', 'max:150'],
            'empleados' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'empleados_rango' => ['nullable', Rule::in(Organizacion::RANGOS)],
            'observaciones' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
            'gremio_ids' => ['nullable', 'array', 'max:50'],
            'gremio_ids.*' => ['uuid'],
        ];
    }

    public static function messages(): array
    {
        return [
            'tipo.required' => 'Elija si es empresa o gremio.',
            'tipo.in' => 'El tipo debe ser empresa o gremio.',
            'nombre.required' => 'Escriba el nombre o la razón social.',
            'correo.email' => 'El correo no es válido.',
            'sitio_web.regex' => 'El sitio web no es válido (ej.: www.empresa.com).',
            'empleados.integer' => 'El número de empleados debe ser un número entero.',
            'empleados.min' => 'El número de empleados no puede ser negativo.',
            'empleados_rango.in' => 'Elija un rango de empleados de la lista.',
        ];
    }

    // Validates and normalises: the NIT into nit + nit_dv and the representative's cédula to digits.
    // Returns [data, errors] with errors as [field => message].
    public static function check(array $input): array
    {
        $validator = Validator::make($input, self::rules(), self::messages());
        $errors = collect($validator->errors()->messages())->map(fn ($m) => $m[0])->all();
        $data = array_intersect_key($input, self::rules());
        try {
            $nit = Nit::parse($input['nit'] ?? null);
            $data['nit'] = $nit['nit'] ?? null;
            $data['nit_dv'] = $nit['dv'] ?? null;
        } catch (\InvalidArgumentException $e) {
            $errors['nit'] ??= $e->getMessage();
        }
        if (! empty($input['representante_cedula']) && ! isset($errors['representante_cedula'])) {
            $cedula = Cedula::normalize((string) $input['representante_cedula']);
            if (! preg_match('/^\d{5,10}$/', (string) $cedula)) {
                $errors['representante_cedula'] = 'La cédula del representante debe tener de 5 a 10 dígitos.';
            }
            $data['representante_cedula'] = $cedula;
        }

        return [$data, $errors];
    }
}
