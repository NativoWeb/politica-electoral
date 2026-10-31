<?php

namespace App\Http\Middleware;

use App\Support\CurrentCampaign;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Admin screens and writes of the data shared by every campaign (electoral persons, elections, parties, territory,
// catalogs, cargos, imports): only the platform, i.e. a superadmin or the root campaign's team. Other campaigns
// still read that data through the map, voters and reports.
class EnsureElectoralPlatform
{
    public function __construct(private readonly CurrentCampaign $campaign) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->campaign->canEditElectoral(), 403, 'Esta sección es solo para el equipo de la plataforma.');

        return $next($request);
    }
}
