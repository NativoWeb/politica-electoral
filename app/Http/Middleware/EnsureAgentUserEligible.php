<?php

namespace App\Http\Middleware;

use App\Services\Agent\AgentIdentityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// For every token-authenticated agent route (after auth:sanctum + abilities:agent): a user that was
// deactivated, must change the password or unlinked WhatsApp after the token was issued loses it.
class EnsureAgentUserEligible
{
    public function __construct(private readonly AgentIdentityService $identity) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user()?->loadMissing('role');

        if (! $this->identity->isEligible($user)) {
            $user?->currentAccessToken()?->delete();

            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
