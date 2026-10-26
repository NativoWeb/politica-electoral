<?php

namespace App\Support;

// "63.525.141", "13´952.615" and "63525141" are the same cédula: store digits only so the
// duplicate check and the unique index see them as equal. Non-numeric values (passports, typos
// with letters) are only trimmed.
class Cedula
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        $digits = preg_replace("/[\\s.,'´’‘`\\-]/u", '', $trimmed);

        return $digits !== '' && ctype_digit($digits) ? $digits : $trimmed;
    }

    // Normalizes the document fields present in $data (cedula and the referente's document).
    public static function normalizeFields(array $data): array
    {
        foreach (['cedula', 'referente_documento'] as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field])) {
                $data[$field] = self::normalize($data[$field]);
            }
        }

        return $data;
    }
}
