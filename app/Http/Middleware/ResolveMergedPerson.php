<?php

namespace App\Http\Middleware;

use App\Models\Person;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

// A route {id} that is a merged person (old link, offline queue) is swapped for its survivor before the controller
// authorizes or writes anything. Lider ids and other ids pass through unchanged.
class ResolveMergedPerson
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $id = $route?->parameter('id');
        if (is_string($id) && Str::isUuid($id)) {
            $route->setParameter('id', Person::resolveId($id));
        }

        return $next($request);
    }
}
