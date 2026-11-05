<?php

namespace App\Services\Eventos;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Who of a campaign a row of a list without cédula may be: same phone (only digits, last 10, in lideres.telefono or
// lider_contactos.valor; a cell may hold "+57 300 123 4567 / 310 765 4321") AND at least one word of the name in
// common (without accents), among the active lideres of the given municipios. It only SUGGESTS: the operator confirms.
class AttendeeMatcher
{
    // Words that say nothing about who the person is.
    private const STOPWORDS = ['del', 'las', 'los', 'san', 'don', 'dona', 'sra', 'senor', 'senora'];

    // Every phone number in a cell: mobiles (3xx…, spaces, dots or hyphens inside allowed, +57 in front) and,
    // when there is none, the last 10 digits of each group of 7 or more digits.
    public static function phones(?string $text): array
    {
        $text = (string) $text;
        if (trim($text) === '') {
            return [];
        }
        $found = [];
        if (preg_match_all('/(?<!\d)(?:\+?57[\s.\-]*)?(3(?:[\s.\-]?\d){9})(?!\d)/', $text, $m)) {
            foreach ($m[1] as $match) {
                $found[] = preg_replace('/\D/', '', $match);
            }
        }
        if ($found === []) {
            foreach (preg_split('/[\/,;|]|\s+y\s+|\s{2,}/u', $text) as $part) {
                $digits = preg_replace('/\D/', '', $part);
                if (strlen($digits) >= 7) {
                    $found[] = substr($digits, -10);
                }
            }
        }

        return array_values(array_unique($found));
    }

    public static function nameTokens(?string $name): array
    {
        $words = preg_split('/[^a-z]+/', Str::lower(Str::ascii((string) $name)), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter($words, fn ($w) => strlen($w) > 2 && ! in_array($w, self::STOPWORDS, true))));
    }

    // $rows: [key => ['phones' => [...], 'tokens' => [...], 'geo' => [municipio ids], 'cedula' => ?string]].
    // Returns [key => lider id] with the best candidate (most words in common); a tie suggests nobody. A lider with
    // another cédula than the row's is never suggested.
    public function suggest(string $campaignId, array $rows): array
    {
        $rows = array_filter($rows, fn ($r) => $r['phones'] && $r['tokens'] && $r['geo']);
        if ($rows === []) {
            return [];
        }
        $geoIds = array_values(array_unique(array_merge(...array_column($rows, 'geo'))));

        $lideres = DB::table('lideres')->where('campaign_id', $campaignId)->where('is_active', true)
            ->whereIn('geographic_unit_id', $geoIds)
            ->get(['id', 'nombre', 'telefono', 'cedula', 'geographic_unit_id'])->keyBy('id');
        if ($lideres->isEmpty()) {
            return [];
        }

        $byPhone = [];
        $add = function (string $liderId, ?string $text) use (&$byPhone) {
            foreach (self::phones($text) as $phone) {
                $byPhone[$phone][$liderId] = true;
            }
        };
        foreach ($lideres as $lider) {
            $add($lider->id, $lider->telefono);
        }
        foreach ($lideres->keys()->chunk(1000) as $chunk) {
            DB::table('lider_contactos')->whereIn('lider_id', $chunk->all())->get(['lider_id', 'valor'])
                ->each(fn ($c) => $add($c->lider_id, $c->valor));
        }

        $tokens = [];
        $result = [];
        foreach ($rows as $key => $row) {
            $best = null;
            $bestScore = 0;
            $tie = false;
            foreach ($row['phones'] as $phone) {
                foreach (array_keys($byPhone[$phone] ?? []) as $liderId) {
                    $lider = $lideres[$liderId];
                    if (! in_array($lider->geographic_unit_id, $row['geo'], true)) {
                        continue;
                    }
                    if ($row['cedula'] && $lider->cedula && $lider->cedula !== $row['cedula']) {
                        continue;
                    }
                    $tokens[$liderId] ??= self::nameTokens($lider->nombre);
                    $score = count(array_intersect($row['tokens'], $tokens[$liderId]));
                    if ($score > $bestScore) {
                        [$best, $bestScore, $tie] = [$liderId, $score, false];
                    } elseif ($score > 0 && $score === $bestScore && $liderId !== $best) {
                        $tie = true;
                    }
                }
            }
            // Two fichas equally likely: suggesting one would be a guess.
            if ($best !== null && ! $tie) {
                $result[$key] = $best;
            }
        }

        return $result;
    }

    // [normalized name => municipio id] of every municipio, to read the "Municipio" column of a list.
    public static function municipios(): array
    {
        $map = [];
        foreach (DB::table('geographic_units')->where('type', 'municipality')->get(['id', 'canonical_name']) as $m) {
            $map[ImportColumns::normalize($m->canonical_name)] ??= $m->id;
        }

        return $map;
    }
}
