<?php

namespace App\Services\Geo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// FeatureCollection of the comunas and corregimientos of one municipio (by DANE code), cached with its ETag like
// MunicipiosGeoJson. A municipio without comunas (or unknown) gives an empty collection: the map shows "sin mapa".
// geo:import-comunas flushes it.
class ComunasGeoJson
{
    private const TTL = 86400;

    private const DECIMALS = 5;

    public static function key(string $municipioCode, bool $simplified): string
    {
        return "geo:comunas.geojson:v1:{$municipioCode}:".($simplified ? 'simplified' : 'full');
    }

    // ['body' => string, 'etag' => string]
    public static function get(string $municipioCode, bool $simplified): array
    {
        return Cache::remember(self::key($municipioCode, $simplified), self::TTL, function () use ($municipioCode, $simplified) {
            $body = self::build($municipioCode, $simplified);

            return ['body' => $body, 'etag' => '"'.hash('xxh128', $body).'"'];
        });
    }

    public static function flush(string $municipioCode): void
    {
        Cache::forget(self::key($municipioCode, true));
        Cache::forget(self::key($municipioCode, false));
    }

    private static function build(string $municipioCode, bool $simplified): string
    {
        $column = $simplified ? 'COALESCE(c.geom_simplified, c.geom)' : 'c.geom';

        return DB::selectOne(
            "SELECT json_build_object(
                'type', 'FeatureCollection',
                'features', COALESCE(json_agg(json_build_object(
                    'type', 'Feature',
                    'id', c.id,
                    'properties', json_build_object(
                        'id', c.id,
                        'nombre', c.canonical_name,
                        'codigo', c.official_code,
                        'tipo', c.type,
                        'numero', (c.metadata->>'numero')::int,
                        'poblacion', (c.metadata->>'poblacion')::int,
                        'municipio_id', m.id,
                        'municipio', m.canonical_name
                    ),
                    'geometry', ST_AsGeoJSON({$column}, ?)::json
                ) ORDER BY c.type, c.official_code) FILTER (WHERE c.id IS NOT NULL), '[]'::json)
            )::text AS body
            FROM geographic_units m
            JOIN geographic_units c ON c.parent_id = m.id AND c.type IN ('comuna', 'corregimiento') AND c.is_active AND c.geom IS NOT NULL
            WHERE m.type = 'municipality' AND m.official_code = ?",
            [self::DECIMALS, $municipioCode]
        )?->body ?? '{"type":"FeatureCollection","features":[]}';
    }
}
