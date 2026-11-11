<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// Where a contest applies: a candidacy of a departmental contest (Cámara, Asamblea, Gobernación) or of a contest
// with no unit (national) belongs to every municipio below it, not only to a municipio of the same id.
class GeoTerritory
{
    private const TTL = 3600;

    private const MAX_DEPTH = 10;

    // Kept for the life of the PHP process (one web request under php-fpm) on top of the 1 h cache: the Mapa asks
    // once per row. A long-lived process that moves units calls flush().
    private static array $ancestors = [];

    private static array $descendants = [];

    public static function flush(): void
    {
        self::$ancestors = self::$descendants = [];
    }

    // The unit and every unit above it (municipio, provincia, departamento, país).
    public static function selfAndAncestors(?string $geoId): array
    {
        if (! $geoId) {
            return [];
        }

        return self::$ancestors[$geoId] ??= Cache::remember("geo_ancestors:{$geoId}", self::TTL, function () use ($geoId) {
            $ids = [];
            $current = $geoId;
            while ($current && ! in_array($current, $ids, true) && count($ids) < self::MAX_DEPTH) {
                $ids[] = $current;
                $current = DB::table('geographic_units')->where('id', $current)->value('parent_id');
            }

            return $ids;
        });
    }

    // The unit and every unit below it.
    public static function selfAndDescendants(string $geoId): array
    {
        return self::$descendants[$geoId] ??= Cache::remember("geo_descendants:{$geoId}", self::TTL, function () use ($geoId) {
            $ids = [$geoId];
            $level = [$geoId];
            for ($depth = 0; $level && $depth < self::MAX_DEPTH; $depth++) {
                $level = array_values(array_diff(
                    DB::table('geographic_units')->whereIn('parent_id', $level)->pluck('id')->all(),
                    $ids,
                ));
                $ids = array_merge($ids, $level);
            }

            return $ids;
        });
    }

    // Whether a contest of $contestGeoId is held where $geoId is: the same unit, one above it, or no unit (national).
    public static function covers(?string $contestGeoId, ?string $geoId): bool
    {
        return $contestGeoId === null || $contestGeoId === $geoId || in_array($contestGeoId, self::selfAndAncestors($geoId), true);
    }

    // Restricts $column (a contest's geographic_unit_id) to the contests held where $geoId is.
    public static function whereCovers($query, string $column, ?string $geoId)
    {
        return $query->where(fn ($q) => $q->whereNull($column)->orWhereIn($column, self::selfAndAncestors($geoId)));
    }
}
