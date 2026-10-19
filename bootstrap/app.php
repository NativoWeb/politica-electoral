<?php

use App\Http\Middleware\AuditActivity;
use App\Http\Middleware\EnsureAgentUserEligible;
use App\Http\Middleware\EnsureCanWrite;
use App\Http\Middleware\EnsureHasPermission;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\VerifyAgentHmac;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trusted proxies come from config/trustedproxy.php (env TRUSTED_PROXIES, empty = none).
        // The agent signature is checked before any limiter or auth, so unsigned traffic consumes nothing.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: VerifyAgentHmac::class);
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
        $middleware->alias([
            'can.write' => EnsureCanWrite::class,
            'password.changed' => EnsurePasswordChanged::class,
            'permission' => EnsureHasPermission::class,
            'audit' => AuditActivity::class,
            'agent.hmac' => VerifyAgentHmac::class,
            'agent.user' => EnsureAgentUserEligible::class,
            'abilities' => CheckAbilities::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/agent/*') || $request->expectsJson()
        );
    })->create();
