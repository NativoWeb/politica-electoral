<?php

namespace App\Rules;

use App\Services\Configuracion\Catalogo;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

// The value is an active option of a list of the Centro de configuración, or one the record already has ($actuales):
// switching an option off never makes an old ficha fail to save.
class OpcionDeCatalogo implements ValidationRule
{
    public function __construct(private readonly string $catalogo, private readonly array $actuales = []) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! Catalogo::permite($this->catalogo, $value, array_values(array_filter($this->actuales, 'is_string')))) {
            $fail('Elija una opción de la lista. Si no la ve, recargue la página: la lista pudo cambiar.');
        }
    }

    // Adds the list rule to each ficha field of $rules (an "in:" rule of the field is replaced by it). $actuales: the
    // rows (lideres) of the record being edited, whose values stay valid.
    public static function enReglas(array $rules, iterable $actuales = [], array $campos = self::CAMPOS_FICHA): array
    {
        $actuales = collect($actuales)->map(fn ($row) => (array) $row)->all();
        foreach ($campos as $campo => $catalogo) {
            if (! array_key_exists($campo, $rules)) {
                continue;
            }
            $fieldRules = is_string($rules[$campo]) ? explode('|', $rules[$campo]) : $rules[$campo];
            $fieldRules = array_values(array_filter($fieldRules, fn ($r) => ! (is_string($r) && str_starts_with($r, 'in:'))));
            $fieldRules[] = new self($catalogo, array_column($actuales, $campo));
            $rules[$campo] = $fieldRules;
        }

        return $rules;
    }

    // Column of lideres => list.
    public const CAMPOS_FICHA = [
        'tipo_documento' => 'tipo_documento',
        'genero' => 'genero',
        'estado_civil' => 'estado_civil',
        'escolaridad' => 'escolaridad',
        'nivel_confianza' => 'nivel_confianza',
        'zona' => 'zona',
    ];
}
