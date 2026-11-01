<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// The offline queue is sent with X-Offline-Owner: the userKey() of the account that queued the change
// (resources/js/lib/userKey.js). If another account is logged in now, nothing is processed: 409 owner_mismatch.
// Without the header (normal online requests) the request goes through unchanged.
class EnsureOfflineOwner
{
    public const HEADER = 'X-Offline-Owner';

    public function handle(Request $request, Closure $next): Response
    {
        $owner = $request->header(self::HEADER);
        if ($owner === null) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user || ! is_string($owner) || ! in_array(strtolower(trim($owner)), self::keysFor((string) $user->id), true)) {
            return response()->json([
                'success' => false,
                'code' => 'owner_mismatch',
                'message' => 'Estos cambios se guardaron sin conexión con otra cuenta. Inicie sesión con esa cuenta para enviarlos.',
            ], 409);
        }

        return $next($request);
    }

    // Same keys as userKey(): SHA-256 of "electoral:{id}", or "id:{id}" where the browser has no WebCrypto.
    public static function keysFor(string $userId): array
    {
        return [hash('sha256', 'electoral:'.$userId), 'id:'.strtolower($userId)];
    }
}
