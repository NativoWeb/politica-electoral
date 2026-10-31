<?php

namespace App\Support;

// How a province is shown on screen. "Soto" (old name kept in some databases) and "Area Metropolitana" (its
// current canonical name in production, without accent) are both shown as "Área Metropolitana", as in the
// rest of the platform's screens. Stored values (lideres.provincia, geographic_units) are never rewritten here.
class Provincias
{
    private const DISPLAY = [
        'Soto' => 'Área Metropolitana',
        'Area Metropolitana' => 'Área Metropolitana',
    ];

    public static function display(?string $canonical): ?string
    {
        return $canonical === null ? null : (self::DISPLAY[$canonical] ?? $canonical);
    }
}
