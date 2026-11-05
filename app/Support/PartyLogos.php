<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Logos uploaded from Admin → Partidos, by party name and by acronym. The frontend falls back to /img/partidos when absent.
class PartyLogos
{
    public const CACHE_KEY = 'party_logos';

    public const DIRECTORY = 'partidos';

    public static function url(?string $path): ?string
    {
        return $path ? '/storage/'.ltrim($path, '/') : null;
    }

    public static function map(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }
        // Shared on every page: a database without the column yet (before the migration) shows the bundled logos.
        if (! Schema::hasColumn('political_organizations', 'logo_path')) {
            return [];
        }

        return Cache::rememberForever(self::CACHE_KEY, function () {
            $map = [];
            DB::table('political_organizations')->whereNotNull('logo_path')->orderBy('canonical_name')
                ->get(['canonical_name', 'acronym', 'logo_path'])
                ->each(function ($org) use (&$map) {
                    $map[$org->canonical_name] = self::url($org->logo_path);
                    if ($org->acronym && ! isset($map[$org->acronym])) {
                        $map[$org->acronym] = self::url($org->logo_path);
                    }
                });

            return $map;
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
