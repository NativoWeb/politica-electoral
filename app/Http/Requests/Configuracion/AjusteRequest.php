<?php

namespace App\Http\Requests\Configuracion;

use App\Support\Configuracion\AjusteRegistry;
use Illuminate\Foundation\Http\FormRequest;

// PUT /admin/configuracion/ajustes/{clave}: the value, checked against the type and range of that ajuste.
class AjusteRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(AjusteRegistry::existe((string) $this->route('clave')), 404);

        return true;
    }

    public function rules(): array
    {
        return ['valor' => AjusteRegistry::reglas((string) $this->route('clave'))];
    }

    public function messages(): array
    {
        $def = AjusteRegistry::definicion((string) $this->route('clave'));

        return [
            'valor.required' => 'Escriba un valor.',
            'valor.integer' => 'Escriba un número entero.',
            'valor.min' => "El valor mínimo es {$this->rango($def, 'min')}.",
            'valor.max' => "El valor máximo es {$this->rango($def, 'max')}.",
            'valor.regex' => 'Escriba una hora en cuartos de hora, por ejemplo 07:00 o 18:45.',
            'valor.boolean' => 'Elija encendido o apagado.',
        ];
    }

    private function rango(array $def, string $key): string
    {
        return number_format((int) ($def[$key] ?? 0), 0, ',', '.');
    }
}
