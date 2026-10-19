<?php

namespace App\Http\Middleware;

use App\Services\Agent\AgentIdentityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Agent writes need a write role (EnsureCanWrite::WRITE_ROLES) AND the write.data permission (R01 has '*').
class EnsureAgentCanWrite
{
    public function __construct(private readonly AgentIdentityService $identity) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user()?->loadMissing('role');

        if (! $user || ! $this->identity->canWrite($user)) {
            return response()->json(['success' => false, 'message' => 'No tiene permisos para esta acción.'], 403);
        }

        return $next($request);
    }
}
