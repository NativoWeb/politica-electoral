<?php

namespace App\Services\Eventos\Actas;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

// How an acta writes and reads its dates and hours ("08 DE NOVIEMBRE DE 2026", "10:45 a. m.", "12:00 m.").
class ActaFormat
{
    public const MONTHS = [
        'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
    ];

    // Uppercase, without accents and spaces collapsed: to compare section titles and headers.
    public static function key(?string $text): string
    {
        return Str::upper(Str::squish(Str::ascii((string) $text)));
    }

    public static function longDate(?string $date): string
    {
        if (! $date) {
            return '';
        }
        $d = Carbon::parse($date);

        return Str::upper($d->format('d').' de '.self::MONTHS[$d->month - 1].' de '.$d->year);
    }

    public static function shortDate(?string $date): string
    {
        return $date ? Carbon::parse($date)->format('d/m/Y') : '';
    }

    // "08 DE NOVIEMBRE 2026", "8 de noviembre de 2026", "08/11/2026", "2026-11-08" → "2026-11-08"; anything else null.
    public static function parseDate(?string $text): ?string
    {
        $text = Str::lower(Str::ascii((string) $text));
        if (preg_match('/(\d{4})-(\d{1,2})-(\d{1,2})/', $text, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/(\d{1,2})\s*[\/.\-]\s*(\d{1,2})\s*[\/.\-]\s*(\d{4})/', $text, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/(\d{1,2})\s*(?:de\s+)?('.implode('|', self::MONTHS).'|setiembre)\s*(?:de\s+|del\s+)?(\d{4})/', $text, $m)) {
            $month = $m[2] === 'setiembre' ? 'septiembre' : $m[2];
            [$d, $mo, $y] = [(int) $m[1], array_search($month, self::MONTHS, true) + 1, (int) $m[3]];
        } else {
            return null;
        }

        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    // Every hour in a text, as "HH:MM": "10.45 A.M." → 10:45, "12:00 m" → 12:00, "3 p. m." → 15:00.
    public static function parseHours(?string $text): array
    {
        $text = Str::lower(Str::ascii((string) $text));
        preg_match_all('/(?<!\d)(\d{1,2})(?:\s*[:.]\s*(\d{2}))?\s*(p\.?\s*m\b\.?|a\.?\s*m\b\.?|m\b\.?)?/', $text, $all, PREG_SET_ORDER);
        $hours = [];
        foreach ($all as $m) {
            $minutes = $m[2] ?? '';
            $suffix = preg_replace('/[\s.]/', '', $m[3] ?? '');
            // A lone number ("Punto 3") is not an hour.
            if ($minutes === '' && $suffix === '') {
                continue;
            }
            $h = (int) $m[1];
            if ($suffix === 'pm' && $h < 12) {
                $h += 12;
            } elseif ($suffix === 'am' && $h === 12) {
                $h = 0;
            }
            if ($h > 23 || (int) $minutes > 59) {
                continue;
            }
            $hours[] = sprintf('%02d:%02d', $h, (int) $minutes);
        }

        return $hours;
    }

    // "10:45" → "10:45 a. m.", "12:00" → "12:00 m.", "15:30" → "3:30 p. m.".
    public static function hour(?string $hhmm): string
    {
        if (! $hhmm || ! preg_match('/^(\d{2}):(\d{2})/', $hhmm, $m)) {
            return '';
        }
        $h = (int) $m[1];
        if ($h === 12 && $m[2] === '00') {
            return '12:00 m.';
        }
        $suffix = $h < 12 ? 'a. m.' : 'p. m.';
        $h12 = $h % 12 === 0 ? 12 : $h % 12;

        return "{$h12}:{$m[2]} {$suffix}";
    }

    public static function hourRange(?string $from, ?string $to): string
    {
        return trim(self::hour($from).($to ? ' a '.self::hour($to) : ''));
    }
}
