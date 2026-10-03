<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureHasPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasPermission($permission)) {
            if ($request->wantsJson() || $request->inertia()) {
                abort(403, 'No tiene permisos para acceder a esta sección.');
            }

            return redirect('/');
        }

        return $next($request);
    }
}
