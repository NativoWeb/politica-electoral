<?php

namespace App\Support;

// Colombian NIT: the base number (6 to 10 digits, dots and spaces allowed) and an optional verification digit after
// a dash ("900.123.456-8"). The digit is checked with the DIAN algorithm (módulo 11 with the prime weights).
// Without a dash every digit is the base: "9001234568" is NOT read as 900123456 + 8.
class Nit
{
    private const WEIGHTS = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];

    // ['nit' => base, 'dv' => digit|null] or null for an empty value. Throws \InvalidArgumentException with a message.
    public static function parse(?string $value): ?array
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $clean = preg_replace('/[\s.]/u', '', $value);
        if (! preg_match('/^(\d{6,10})(?:-(\d))?$/', $clean, $m)) {
            throw new \InvalidArgumentException('El NIT debe tener de 6 a 10 dígitos, con o sin el dígito de verificación después de un guion (ej.: 900.123.456-8).');
        }
        $base = ltrim($m[1], '0');
        if (strlen($base) < 6) {
            throw new \InvalidArgumentException('El NIT debe tener de 6 a 10 dígitos, con o sin el dígito de verificación después de un guion (ej.: 900.123.456-8).');
        }
        $dv = isset($m[2]) && $m[2] !== '' ? $m[2] : null;
        if ($dv !== null && (string) self::dv($base) !== $dv) {
            throw new \InvalidArgumentException('El dígito de verificación del NIT no corresponde: revise el número.');
        }

        return ['nit' => $base, 'dv' => $dv];
    }

    // Excel load only: 10 digits without a dash whose last one is the verification digit of the first nine
    // ("9001234568") are read as "900123456-8". Anything else is returned as it came.
    public static function unglue(?string $value): ?string
    {
        $clean = preg_replace('/[\s.]/u', '', (string) $value);
        if (preg_match('/^(\d{9})(\d)$/', $clean, $m) && (string) self::dv($m[1]) === $m[2]) {
            return "{$m[1]}-{$m[2]}";
        }

        return $value;
    }

    public static function dv(string $base): int
    {
        $digits = array_reverse(str_split($base));
        $sum = 0;
        foreach ($digits as $i => $d) {
            $sum += (int) $d * self::WEIGHTS[$i];
        }
        $r = $sum % 11;

        return $r > 1 ? 11 - $r : $r;
    }

    public static function format(?string $nit, ?string $dv): ?string
    {
        if (! $nit) {
            return null;
        }
        $grouped = strrev(implode('.', str_split(strrev($nit), 3)));

        return $dv !== null && $dv !== '' ? "{$grouped}-{$dv}" : $grouped;
    }
}
