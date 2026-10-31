<?php

namespace App\Http\Middleware;

use App\Support\CurrentCampaign;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperadmin
{
    public function __construct(private readonly CurrentCampaign $campaign) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->campaign->isSuperadmin(), 403, 'Solo un superadministrador puede entrar aquí.');

        return $next($request);
    }
}
