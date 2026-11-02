<?php

use App\Exceptions\BusinessRuleException;
use App\Http\Middleware\AuditActivity;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureAgentCanWrite;
use App\Http\Middleware\EnsureAgentUserEligible;
use App\Http\Middleware\EnsureCampaignWhatsApp;
use App\Http\Middleware\EnsureCanWrite;
use App\Http\Middleware\EnsureElectoralPlatform;
use App\Http\Middleware\EnsureEventosOcrEnabled;
use App\Http\Middleware\EnsureHasPermission;
use App\Http\Middleware\EnsureOfflineOwner;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureSuperadmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\VerifyAgentHmac;
use App\Support\DatabaseErrorLog;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
        // Right after auth, before permission/can.write: a queue of another account gets 409, never a 403 that drops it.
        $middleware->appendToPriorityList(after: AuthenticatesRequests::class, append: EnsureOfflineOwner::class);
        $middleware->web(append: [
            HandleInertiaRequests::class,
            EnsureAccountActive::class,
        ]);
        $middleware->alias([
            'can.write' => EnsureCanWrite::class,
            'electoral.platform' => EnsureElectoralPlatform::class,
            'campaign.whatsapp' => EnsureCampaignWhatsApp::class,
            'password.changed' => EnsurePasswordChanged::class,
            'permission' => EnsureHasPermission::class,
            'superadmin' => EnsureSuperadmin::class,
            'audit' => AuditActivity::class,
            'agent.hmac' => VerifyAgentHmac::class,
            'agent.user' => EnsureAgentUserEligible::class,
            'agent.can_write' => EnsureAgentCanWrite::class,
            'abilities' => CheckAbilities::class,
            'offline.owner' => EnsureOfflineOwner::class,
            'eventos.ocr' => EnsureEventosOcrEnabled::class,
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

        // Laravel's throttle text is English ("Too Many Attempts."); pages use errors/429.blade.php.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/agent/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Demasiadas solicitudes. Espere un momento e intente de nuevo.',
                ], 429, $e->getHeaders());
            }
        });

        // A body over post_max_size (an attendee Excel too big for PHP). It is rejected before the session starts, so
        // there is no redirect with errors: JSON gets the message, a page gets errors/413.blade.php (Spanish).
        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            if ($request->is('api/agent/*') || $request->expectsJson()) {
                $message = 'El archivo es demasiado grande para subirlo. Divídalo en varios archivos más pequeños e intente de nuevo.';

                return response()->json(['success' => false, 'message' => $message, 'errors' => ['archivo' => [$message]]], 413);
            }
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

        // A JSON 404 never names the model ("No query results for model [App\Models\...]") and reads the same whether
        // the record does not exist or belongs to a campaign the user cannot see. Messages the app wrote are kept.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            $frameworkText = $e->getMessage() === '' || $e->getPrevious() instanceof ModelNotFoundException
                || (str_starts_with($e->getMessage(), 'The route ') && str_ends_with($e->getMessage(), ' could not be found.'));
            if ($request->expectsJson() && $frameworkText) {
                return response()->json(['message' => 'No se encontró lo que busca.'], 404, $e->getHeaders());
            }
        });
    })->create();
