<?php

use App\Exceptions\BusinessRuleException;
use App\Http\Middleware\AuditActivity;
use App\Http\Middleware\EnsureAgentCanWrite;
use App\Http\Middleware\EnsureAgentUserEligible;
use App\Http\Middleware\EnsureCampaignWhatsApp;
use App\Http\Middleware\EnsureCanWrite;
use App\Http\Middleware\EnsureElectoralPlatform;
use App\Http\Middleware\EnsureHasPermission;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\VerifyAgentHmac;
use App\Support\DatabaseErrorLog;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
            'electoral.platform' => EnsureElectoralPlatform::class,
            'campaign.whatsapp' => EnsureCampaignWhatsApp::class,
            'password.changed' => EnsurePasswordChanged::class,
            'permission' => EnsureHasPermission::class,
            'audit' => AuditActivity::class,
            'agent.hmac' => VerifyAgentHmac::class,
            'agent.user' => EnsureAgentUserEligible::class,
            'agent.can_write' => EnsureAgentCanWrite::class,
            'abilities' => CheckAbilities::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/agent/*') || $request->expectsJson()
        );
        $exceptions->dontReport(BusinessRuleException::class);

        // PostgreSQL errors carry bound values and a "Failing row contains (...)" detail with personal data.
        $exceptions->report(function (QueryException $e) {
            Log::error(DatabaseErrorLog::message($e), [
                'sqlstate' => $e->errorInfo[0] ?? null,
                'sql' => $e->getSql(),
            ]);

            return false;
        });

        // The agent API always answers {success: false, message}; 5xx keep Laravel's generic message.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/agent/*')) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
            }
        });
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/agent/*') && $e->getStatusCode() < 500) {
                $message = $e->getStatusCode() === 404 ? 'No encontrado.' : ($e->getMessage() ?: 'Solicitud no permitida.');

                return response()->json(['success' => false, 'message' => $message], $e->getStatusCode(), $e->getHeaders());
            }
        });
    })->create();
