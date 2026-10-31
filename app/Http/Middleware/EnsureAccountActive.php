<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

// An open session ends on the next request once the user or their campaign is deactivated
// (User::accessBlockedReason); the login refuses them from then on. Superadmins never depend on a campaign.
// The reason waits in the new session until the login page shows it: the request that closes the session is
// often a background one (prefetch, service worker, sync), so a flash would be gone before the login renders.
class EnsureAccountActive
{
    public const SESSION_KEY = 'login.blocked_reason';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $reason = $user instanceof User ? $user->accessBlockedReason() : null;
        if ($reason === null) {
            return $next($request);
        }

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'FORCED_LOGOUT',
            'entity_type' => 'session',
            'metadata' => ['reason' => $reason, 'path' => $request->path()],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        // Same cleanup as a manual logout (LoginController::logout).
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put(self::SESSION_KEY, $reason);
        Inertia::clearHistory();

        if ($request->expectsJson() && ! $request->inertia()) {
            return response()->json(['success' => false, 'message' => $reason], 401)->header('Clear-Site-Data', '"cache"');
        }

        return redirect('/login')->header('Clear-Site-Data', '"cache"');
    }
}
