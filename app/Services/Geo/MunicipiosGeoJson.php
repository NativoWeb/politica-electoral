<?php

namespace App\Services\Geo;

use App\Console\Commands\ImportGeoMunicipios;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// FeatureCollection of the Santander municipios that have a polygon, built once and kept in the cache with its ETag.
// geo:import-municipios flushes it.
class MunicipiosGeoJson
{
    private const TTL = 86400;

    // 5 decimals ≈ 1 m, enough for a map and it keeps the simplified collection well under 1.5 MB.
    private const DECIMALS = 5;

    public static function key(bool $simplified): string
    {
        return 'geo:municipios.geojson:v1:'.($simplified ? 'simplified' : 'full');
    }

    // ['body' => string, 'etag' => string]
    public static function get(bool $simplified): array
    {
        return Cache::remember(self::key($simplified), self::TTL, function () use ($simplified) {
            $body = self::build($simplified);

            return ['body' => $body, 'etag' => '"'.hash('xxh128', $body).'"'];
        });
    }

    public static function flush(): void
    {
        Cache::forget(self::key(true));
        Cache::forget(self::key(false));
    }

    private static function build(bool $simplified): string
    {
        $column = $simplified ? 'COALESCE(m.geom_simplified, m.geom)' : 'm.geom';

        $row = DB::selectOne(
            "WITH RECURSIVE dept AS (
                SELECT id FROM geographic_units WHERE type = 'department' AND official_code = ?
            ), tree AS (
                SELECT id FROM dept
                UNION
                SELECT g.id FROM geographic_units g JOIN tree t ON g.parent_id = t.id
            )
            SELECT json_build_object(
                'type', 'FeatureCollection',
                'features', COALESCE(json_agg(json_build_object(
                    'type', 'Feature',
                    'id', m.id,
                    'properties', json_build_object(
                        'id', m.id,
                        'nombre', m.canonical_name,
                        'official_code', m.official_code,
                        'provincia', p.canonical_name,
                        'provincia_id', p.id
                    ),
                    'geometry', ST_AsGeoJSON({$column}, ?)::json
                ) ORDER BY m.official_code), '[]'::json)
            )::text AS body
            FROM geographic_units m
            JOIN tree t ON t.id = m.id
            LEFT JOIN geographic_units p ON p.id = m.parent_id AND p.type = 'province'
            WHERE m.type = 'municipality' AND m.is_active AND m.geom IS NOT NULL",
            [ImportGeoMunicipios::DEPARTMENT_CODE, self::DECIMALS]
        );

        return $row->body;
    }
}
