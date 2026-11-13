<?php

namespace App\Services\Geo;

use Illuminate\Support\Str;

// One spelling for a barrio text, applied the same way to the catalog (barrios.nombre_normalizado) and to what people
// typed in a ficha: no accents, uppercase, no "BARRIO" / "B/" / "CONJUNTO" / "URBANIZACION" / "VEREDA" prefix, no unit
// detail (", CASA 43", "TORRE 2", "APTO 301"...), Roman numerals as digits ("CORDONCILLO I" = "CORDONCILLO 1") and
// numbers without leading zeros ("05 DE ENERO" = "5 DE ENERO").
class BarrioNormalizer
{
    private const UNIT_DETAIL = '/[,;]?\s*\b(CASA|CS|TORRE|TORR|TR|APTO|APTOS|APT|AP|APARTAMENTO|BLOQUE|BLQ|BL|MZ|MZA|MANZANA|LOTE|LT|PISO|INT|INTERIOR|OFICINA|OF|LOCAL)\b\.?\s*(NO\.?\s*|N\.?\s*)?#?\s*([0-9][0-9A-Z-]*|[A-Z])\b.*$/';

    private const PREFIX = '/^(BARRIO|BARRIOS|BRR|BRRIO|BRIO|BR|B|CONJ[A-Z]*|RESIDENCIAL|RES|EDIFICIO|EDIF|ED|URBANIZACION|URB|VEREDA|VDA|SECTOR)\s+/';

    private const ROMAN = [
        'I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 'VI' => 6, 'VII' => 7, 'VIII' => 8, 'IX' => 9, 'X' => 10,
        'XI' => 11, 'XII' => 12, 'XIII' => 13, 'XIV' => 14, 'XV' => 15, 'XVI' => 16, 'XVII' => 17, 'XVIII' => 18, 'XIX' => 19, 'XX' => 20,
    ];

    // $strip: whole-word phrases removed anywhere (the ficha's own municipio: "BUCARAMANGA", "B MANGA"...), already
    // normalized with plain().
    public static function normalize(?string $text, array $strip = []): string
    {
        $s = self::plain($text);
        if ($s === '') {
            return '';
        }

        $s = preg_replace('#^\s*B\s*/\s*#', '', $s);
        $s = preg_replace(self::UNIT_DETAIL, '', $s);
        $s = self::squash(preg_replace('/[^A-Z0-9]+/', ' ', $s));

        foreach ($strip as $phrase) {
            if ($phrase !== '') {
                $s = self::squash(preg_replace('/(^| )'.preg_quote($phrase, '/').'( |$)/', ' ', $s));
            }
        }

        do {
            $before = $s;
            $s = preg_replace(self::PREFIX, '', $s);
        } while ($s !== $before && str_contains($s, ' '));

        $tokens = explode(' ', $s);
        if (count($tokens) > 1) {
            $tokens = array_map(fn ($t) => isset(self::ROMAN[$t]) ? (string) self::ROMAN[$t] : $t, $tokens);
        }
        $tokens = array_map(fn ($t) => ctype_digit($t) ? (string) (int) $t : $t, $tokens);

        return Str::limit(self::squash(implode(' ', $tokens)), 150, '');
    }

    // Accents removed, uppercase, single spaces: for names compared whole (municipios).
    public static function plain(?string $text): string
    {
        return self::squash(preg_replace('/[^A-Z0-9,;\/#.\- ]+/', ' ', Str::upper(Str::ascii((string) $text))));
    }

    // Whether $phrase (normalized) appears as whole words in $text (normalized).
    public static function containsPhrase(string $text, string $phrase): bool
    {
        return $phrase !== '' && preg_match('/(^| )'.preg_quote($phrase, '/').'( |$)/', $text) === 1;
    }

    private static function squash(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', $s));
    }
}
