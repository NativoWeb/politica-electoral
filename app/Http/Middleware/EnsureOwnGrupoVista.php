<?php

namespace App\Http\Middleware;

use App\Support\CurrentCampaign;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Routes that create records in the user's own grupo político: refused (422) while the superadmin's header shows
// another group, so nothing is created out of sight.
class EnsureOwnGrupoVista
{
    public function __construct(private readonly CurrentCampaign $campaign) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->campaign->abortIfCreateBlocked();

        return $next($request);
    }
}
