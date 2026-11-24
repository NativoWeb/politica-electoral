<?php

namespace App\Services\Geo;

use Illuminate\Support\Str;

// One spelling for a polling station name, applied the same way to the Registraduría list (puestos_votacion) and to
// what a ficha says: no accents, uppercase, punctuation as spaces, initials joined ("I.E." = "I E" = "IE") and the
// usual abbreviations ("INSTITUCION EDUCATIVA" = "IE", "COLEGIO" = "COL", "UNIVERSIDAD" = "UNIV"...). A text without
// letters ("", "0", "00") is empty.
class PuestoNormalizer
{
    private const WORDS = [
        'INSTITUCION' => 'INST', 'INSTITUTO' => 'INST', 'INSTIT' => 'INST', 'EDUCATIVA' => 'EDUC', 'EDUCATIVO' => 'EDUC',
        'COLEGIO' => 'COL', 'ESCUELA' => 'ESC', 'UNIVERSIDAD' => 'UNIV', 'TECNICO' => 'TEC', 'TECNICA' => 'TEC',
        'NACIONAL' => 'NAL', 'NUESTRA' => 'NTRA', 'SENORA' => 'SRA',
    ];

    private const PREFIX = '/^(PUESTO|PTO)( DE VOTACION)? /';

    public static function normalize(?string $text): string
    {
        $s = Str::upper(Str::ascii((string) $text));
        if (! preg_match('/[A-Z]/', $s)) {
            return '';
        }
        $s = self::squash(preg_replace('/[^A-Z0-9]+/', ' ', $s));
        $s = preg_replace(self::PREFIX, '', $s);

        // Initials written with spaces or dots: "I E" -> "IE" (two or more single letters in a row).
        $s = preg_replace_callback('/(?<![A-Z0-9])([A-Z] )+[A-Z](?![A-Z0-9])/', fn ($m) => str_replace(' ', '', $m[0]), $s);

        $tokens = array_map(fn ($t) => self::WORDS[$t] ?? $t, explode(' ', $s));
        $s = implode(' ', $tokens);
        $s = preg_replace('/(^| )INST EDUC( |$)/', '$1IE$2', $s);

        return Str::limit(self::squash($s), 200, '');
    }

    // The name without the kind of institution in front ("IE INEM CUSTODIO..." -> "INEM CUSTODIO..."), so the official
    // name and the way people write it meet. Never empty: a name that is only a prefix stays as it is.
    public static function core(string $norm): string
    {
        $core = preg_replace('/^((IE|COL|ESC|INST|EDUC|TEC|SEC|CEN) )+/', '', $norm);

        return $core === '' ? $norm : $core;
    }

    private static function squash(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', $s));
    }
}
