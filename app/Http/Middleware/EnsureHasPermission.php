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
            // API calls and writes get a 403. A page visit (also an Inertia one, e.g. after login to a
            // url.intended the user may not open) goes home, which picks a view the user does have.
            if ($request->wantsJson() || ! $request->isMethod('GET')) {
                abort(403, 'No tiene permisos para acceder a esta sección.');
            }

            return redirect('/');
        }

        return $next($request);
    }
}
