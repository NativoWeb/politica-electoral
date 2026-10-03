<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            if ($request->inertia()) {
                return inertia()->location(route('password.force-change'));
            }

            return redirect()->route('password.force-change');
        }

        return $next($request);
    }
}
