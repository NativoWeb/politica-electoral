<?php

namespace App\Services\Geo;

// pg_trgm's similarity() in PHP, for short lists matched in memory (the ~90 polling stations of a municipio): words are
// runs of letters and digits, lowercased, each padded with two spaces before and one after; the score is shared
// trigrams over all distinct trigrams of both texts.
class Trigram
{
    public static function similarity(string $a, string $b): float
    {
        $ta = self::trigrams($a);
        $tb = self::trigrams($b);
        if ($ta === [] || $tb === []) {
            return 0.0;
        }
        $shared = count(array_intersect_key($ta, $tb));

        return $shared / (count($ta) + count($tb) - $shared);
    }

    /** @return array<string, true> */
    public static function trigrams(string $text): array
    {
        $out = [];
        preg_match_all('/[a-z0-9]+/', strtolower($text), $words);
        foreach ($words[0] as $word) {
            $padded = '  '.$word.' ';
            for ($i = 0, $n = strlen($padded) - 2; $i < $n; $i++) {
                $out[substr($padded, $i, 3)] = true;
            }
        }

        return $out;
    }
}
