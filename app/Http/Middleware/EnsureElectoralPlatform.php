<?php

namespace App\Http\Middleware;

use App\Support\CurrentCampaign;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Writes to data shared by every campaign (electoral persons, elections, parties, territory, cargo catalog):
// only the platform, i.e. a superadmin or the root campaign's team.
class EnsureElectoralPlatform
{
    public function __construct(private readonly CurrentCampaign $campaign) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->campaign->canEditElectoral(), 403, 'Estos datos son de la plataforma: tu campaña solo puede consultarlos.');

        return $next($request);
    }
}
