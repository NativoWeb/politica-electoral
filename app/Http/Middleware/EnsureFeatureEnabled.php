<?php

namespace App\Http\Middleware;

use App\Support\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// "feature:campanas": while the module is off, or the user is not one of its beta users, its routes do not exist
// (404), before any permission check and also for the superadmin.
class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless(Features::enabledFor($feature, $request->user()), 404);

        return $next($request);
    }
}
