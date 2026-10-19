<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

// Service-to-service auth for the WhatsApp agent. Signature over:
// timestamp \n nonce \n METHOD \n path-with-raw-query \n raw-body
class VerifyAgentHmac
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('agent.hmac_secret');
        if ($secret === '') {
            return $this->deny();
        }

        $timestamp = (string) $request->header('X-Agent-Timestamp', '');
        $nonce = (string) $request->header('X-Agent-Nonce', '');
        $signature = strtolower((string) $request->header('X-Agent-Signature', ''));

        if (! ctype_digit($timestamp)
            || ! preg_match('/^[A-Za-z0-9._~-]{16,64}$/', $nonce)
            || ! preg_match('/^[0-9a-f]{64}$/', $signature)) {
            return $this->deny();
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > (int) config('agent.max_clock_skew', 300)) {
            return $this->deny();
        }

        $payload = $timestamp."\n".$nonce."\n".strtoupper($request->getMethod())."\n"
            .$request->getRequestUri()."\n".$request->getContent();

        if (! hash_equals(hash_hmac('sha256', $payload, $secret), $signature)) {
            return $this->deny();
        }

        // Stored only after the signature checks out, so unsigned traffic cannot fill the cache.
        if (! Cache::add('agent_nonce:'.hash('sha256', $nonce), 1, (int) config('agent.nonce_ttl', 600))) {
            return $this->deny();
        }

        return $next($request);
    }

    private function deny(): Response
    {
        return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
    }
}
