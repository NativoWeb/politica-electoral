<?php

namespace App\Services\Electo;

use Carbon\CarbonImmutable;

// Pure value normalization for the Electo export (no database). Every function returns null for "no usable value";
// the callers record WHY a value was dropped as a migration flag.
class Normalizer
{
    public const OBSERVACION_MAX = 4000;

    public const MIN_DOCUMENTO_DIGITS = 5;

    public const MAX_AGE = 110;

    // DIAN document codes used by Electo.
    public const TIPO_DOCUMENTO = ['13' => 'CC', '12' => 'TI', '22' => 'CE', '11' => 'RC', '21' => 'TE', '31' => 'NIT', '41' => 'PA', '42' => 'DE'];

    // Inferred from the first names (diagnóstico §1.1): 1 = F, 2 = M. The importer marks it genero_inferido and keeps
    // the original code in migracion_flags.electo_codigos.genero.
    public const GENERO = ['1' => 'Femenino', '2' => 'Masculino', '3' => 'Otro'];

    // Electo escolaridad catalog (unaccented upper key) => the values of our screens (list escolaridad of
    // App\Support\Configuracion\CatalogoRegistry).
    public const ESCOLARIDAD = [
        'NINGUNO' => 'Ninguno', 'PRIMARIA' => 'Primaria', 'BACHILLER' => 'Secundaria', 'TECNICO' => 'Técnico',
        'TECNOLOGO' => 'Tecnólogo', 'PROFESIONAL' => 'Universitario', 'ESPECIALIZACION' => 'Posgrado',
        'MAESTRIA' => 'Posgrado', 'DOCTORADO' => 'Posgrado',
    ];

    // Lowercase, unaccented, single spaces: the key to compare names.
    public static function key(?string $value): string
    {
        $value = (string) $value;
        $ascii = strtr(mb_strtolower($value, 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u']);

        return trim(preg_replace('/\s+/u', ' ', $ascii));
    }

    // Name key independent of word order and punctuation ("PEREZ JUAN" = "Juan Pérez").
    public static function personKey(?string $name): string
    {
        $words = preg_split('/\s+/', trim(preg_replace('/[^a-z0-9 ]/', ' ', self::key($name))), -1, PREG_SPLIT_NO_EMPTY);
        sort($words);

        return implode(' ', $words);
    }

    public static function name(?string $nombres, ?string $apellidos): ?string
    {
        $full = self::singleLine(trim((string) $nombres).' '.trim((string) $apellidos));

        return $full === null ? null : mb_substr(mb_strtoupper($full, 'UTF-8'), 0, 255);
    }

    public static function singleLine(?string $value, int $max = 0): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));
        if ($value === '') {
            return null;
        }

        return $max > 0 ? mb_substr($value, 0, $max) : $value;
    }

    // Free text: CR removed, runs of blank lines collapsed, cut to $max. Returns [text, was_cut].
    public static function text(?string $value, int $max = self::OBSERVACION_MAX): array
    {
        $value = str_replace("\r", '', (string) $value);
        $value = trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+\n/', "\n", $value)));
        if ($value === '') {
            return [null, false];
        }
        if (mb_strlen($value) > $max) {
            return [rtrim(mb_substr($value, 0, $max - 1)).'…', true];
        }

        return [$value, false];
    }

    // Digits only without leading zeros. Returns [cedula|null, flag|null]. A code-0 document ("sin dato") that starts
    // with zeros is an Electo placeholder (00012345), not a cédula: matching it would merge strangers.
    public static function cedula(?string $raw, ?string $tipoCode): array
    {
        $trimmed = trim((string) $raw);
        if ($trimmed === '') {
            return [null, null];
        }
        if (trim((string) $tipoCode) === '0' && str_starts_with($trimmed, '0')) {
            return [null, 'documento_sin_tipo'];
        }
        $compact = preg_replace("/[\\s.,'´’‘`\\-]/u", '', $trimmed);
        if ($compact !== '' && ctype_digit($compact)) {
            $digits = ltrim($compact, '0');

            return strlen($digits) >= self::MIN_DOCUMENTO_DIGITS ? [$digits, null] : [null, 'documento_invalido'];
        }
        $alnum = mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $compact));

        return strlen($alnum) >= self::MIN_DOCUMENTO_DIGITS && preg_match('/\d/', $alnum) ? [$alnum, null] : [null, 'documento_invalido'];
    }

    // Key to compare a cédula already stored in lideres with an Electo one.
    public static function cedulaKey(?string $stored): ?string
    {
        $compact = preg_replace("/[\\s.,'´’‘`\\-]/u", '', trim((string) $stored));
        if ($compact === '') {
            return null;
        }
        if (ctype_digit($compact)) {
            $digits = ltrim($compact, '0');

            return $digits === '' ? null : $digits;
        }

        return mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $compact)) ?: null;
    }

    public static function tipoDocumento(?string $code): ?string
    {
        return self::TIPO_DOCUMENTO[trim((string) $code)] ?? null;
    }

    public static function genero(?string $code): ?string
    {
        return self::GENERO[trim((string) $code)] ?? null;
    }

    public static function escolaridad(?string $catalogName): ?string
    {
        return self::ESCOLARIDAD[strtoupper(str_replace(' ', '', self::key($catalogName)))] ?? null;
    }

    public static function estadoCivil(?string $catalogName): ?string
    {
        $value = self::singleLine($catalogName, 30);

        return $value === null || self::key($value) === 'no aplica' ? null : $value;
    }

    public static function profesion(?string $catalogName): ?string
    {
        $value = self::singleLine($catalogName, 255);

        return $value === null || self::key($value) === 'no aplica' ? null : $value;
    }

    public static function bool(?string $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['true', 't', '1', 'si', 'sí'], true);
    }

    // Wall-clock date of "YYYY-MM-DD[ HH:MM:SS.mmm][ -0500]". Returns [Y-m-d|null, invalid]. Invalid = present but
    // unusable: unparseable, the 1900-01-01 / 0001-01-01 fillers, before 1901 or after 2100.
    public static function date(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [null, false];
        }
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?)?(?:\s*[+-]\d{2}:?\d{2})?$/', $raw, $m)
            || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[1] <= 1900 || (int) $m[1] > 2100) {
            return [null, true];
        }

        return ["{$m[1]}-{$m[2]}-{$m[3]}", false];
    }

    // Birth date: also invalid in the future or above MAX_AGE.
    public static function birthDate(?string $raw, CarbonImmutable $today): array
    {
        [$date, $invalid] = self::date($raw);
        if ($date === null) {
            return [null, $invalid];
        }
        $birth = CarbonImmutable::parse($date);
        if ($birth->greaterThan($today) || $birth->diffInYears($today) > self::MAX_AGE) {
            return [null, true];
        }

        return [$date, false];
    }

    public static function isMinor(?string $birthDate, CarbonImmutable $today): bool
    {
        return $birthDate !== null && CarbonImmutable::parse($birthDate)->addYears(18)->greaterThan($today);
    }

    // Wall-clock "Y-m-d H:i:s" (the -0500 offset is Colombia's local time, which is what the screens store).
    public static function dateTime(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if (! preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2})(?::(\d{2}))?)?/', $raw, $m)) {
            return null;
        }
        [$date] = self::date($m[1]);

        return $date === null ? null : $date.' '.($m[2] ?? '00:00').':'.($m[3] ?? '00');
    }

    // Digits only, without the 57 country code. null when there are fewer than 7 or more than 10 digits.
    public static function phone(?string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $raw);
        if (strlen($digits) === 12 && str_starts_with($digits, '57')) {
            $digits = substr($digits, 2);
        }

        return strlen($digits) >= 7 && strlen($digits) <= 10 ? $digits : null;
    }

    public static function isMobile(string $digits): bool
    {
        return (bool) preg_match('/^3\d{9}$/', $digits);
    }

    public static function email(?string $raw): ?string
    {
        $value = mb_strtolower(trim((string) $raw));

        return $value !== '' && strlen($value) <= 200 && filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    // 5-digit DIVIPOLA municipio code; "00000" and malformed values are "sin municipio".
    public static function divipola(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if (! preg_match('/^\d{1,5}$/', $raw)) {
            return null;
        }
        $code = str_pad($raw, 5, '0', STR_PAD_LEFT);

        return $code === '00000' ? null : $code;
    }

    public static function int(?string $raw): ?int
    {
        $raw = trim((string) $raw);

        return preg_match('/^\d{1,9}$/', $raw) ? (int) $raw : null;
    }
}
