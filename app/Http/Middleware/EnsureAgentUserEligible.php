<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Services\Agent\AgentIdentityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

// For every token-authenticated agent route (after auth:sanctum + abilities:agent): a user that was
// deactivated, must change the password or unlinked WhatsApp after the token was issued loses it.
class EnsureAgentUserEligible
{
    public function __construct(private readonly AgentIdentityService $identity) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user()?->loadMissing('role');

        if (! $this->identity->isEligible($user)) {
            $token = $user?->currentAccessToken();
            $token?->delete();
            if ($user && $token) {
                $this->auditRevocation($request, $user->id, $this->identity->ineligibilityReason($user));
            }

            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }

    // The revocation is recorded, but a failing audit write never keeps the token alive nor breaks the 401.
    private function auditRevocation(Request $request, string $userId, ?string $reason): void
    {
        try {
            AuditLog::create([
                'user_id' => $userId,
                'action' => 'AGENT_TOKEN_REVOKED',
                'entity_type' => 'usuario',
                'entity_id' => $userId,
                'metadata' => ['motivo' => $reason, 'ruta' => $request->route()?->getName()],
                'ip_address' => $request->ip(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
