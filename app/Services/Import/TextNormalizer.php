<?php

namespace App\Services\Import;

class TextNormalizer
{
    public static function normalizeName(string $value): string
    {
        $value = trim($value);
        $value = mb_strtoupper($value);
        $value = str_replace(
            ['Á', 'É', 'Í', 'Ó', 'Ú', 'Ñ', 'Ü'],
            ['A', 'E', 'I', 'O', 'U', 'N', 'U'],
            $value
        );
        $value = preg_replace('/\s+/', ' ', $value);

        return $value;
    }

    public static function parseVotes(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $str = trim((string) $raw);
        $str = str_replace(["\xC2\xA0", "\xA0", ' '], '', $str);

        // Handle ambiguous decimal separators (e.g. 1.466 could be 1466)
        // In Colombian electoral context, votes are always integers
        // If string has a dot followed by exactly 3 digits, treat dot as thousands separator
        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $str)) {
            $str = str_replace('.', '', $str);
        }

        // Handle comma as thousands separator (e.g. 1,540,391)
        if (preg_match('/^\d{1,3}(,\d{3})+$/', $str)) {
            $str = str_replace(',', '', $str);
        }

        // If it's a float from Excel (e.g. 257.037), check if it looks like thousands
        if (is_float($raw)) {
            $intVal = (int) round($raw);
            // If the float is very close to an integer, use the integer
            if (abs($raw - $intVal) < 0.01) {
                return $intVal >= 0 ? $intVal : null;
            }
            // Otherwise it's ambiguous — still return the rounded value but it may need review
            return $intVal >= 0 ? $intVal : null;
        }

        if (!is_numeric($str)) {
            return null;
        }

        $val = (int) $str;

        return $val >= 0 ? $val : null;
    }

    public static function cleanString(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    public static function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName));
        if (count($parts) <= 2) {
            return [
                'first_name' => $parts[0] ?? '',
                'last_name' => $parts[1] ?? '',
            ];
        }

        // Heuristic: last two words are last name, rest is first name
        $lastName = implode(' ', array_slice($parts, -2));
        $firstName = implode(' ', array_slice($parts, 0, -2));

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
        ];
    }
}
