<?php

namespace App\Rules;

use App\Support\NombreFicha;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

// Rejects a ficha's name with digits. $actual: the name already stored when editing; sending it back unchanged is
// allowed, so an old name with digits does not block saving the ficha's other fields (data:limpiar-nombres-numeros
// and the «Nombre con números» filter take care of those).
final class NombreSinNumeros implements ValidationRule
{
    public function __construct(private readonly ?string $actual = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! NombreFicha::tieneNumeros($value)) {
            return;
        }
        if ($this->actual !== null && self::clave($value) === self::clave($this->actual)) {
            return;
        }
        $fail(NombreFicha::MENSAJE);
    }

    private static function clave(string $nombre): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($nombre)));
    }
}
