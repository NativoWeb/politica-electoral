<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\AgentLinkRequest;
use App\Http\Requests\Agent\AgentPhoneRequest;
use App\Models\User;
use App\Models\WhatsAppLinkCode;
use App\Services\Agent\AgentIdentityService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AgentIdentityController extends Controller
{
    private const GLOBAL_FAIL_KEY = 'agent_link_fail:global';

    public function __construct(private readonly AgentIdentityService $identity) {}

    public function link(AgentLinkRequest $request): JsonResponse
    {
        $phone = $request->validated('phone');
        $hash = WhatsAppLinkCode::hashCode($request->validated('code'));
        $phoneHash = WhatsAppLinkCode::hashPhone($phone);
        $failKey = 'agent_link_fail:'.hash('sha256', $phone);
        $maxFailures = (int) config('agent.link_max_failed_attempts', 5);

        if ((int) Cache::get(self::GLOBAL_FAIL_KEY, 0) > (int) config('agent.link_global_max_failures', 100)) {
            return response()->json(['success' => false, 'message' => 'Demasiados intentos. Intenta más tarde.'], 429);
        }

        try {
            return DB::transaction(function () use ($phone, $hash, $phoneHash, $failKey, $maxFailures) {
                // A right code sent from a phone other than the one declared on the web is a failure.
                $link = WhatsAppLinkCode::active()
                    ->where('code_hash', $hash)
                    ->where('phone_hash', $phoneHash)
                    ->lockForUpdate()
                    ->first();

                if ($link && (int) Cache::get($failKey, 0) > $maxFailures) {
                    // A phone that already failed too often burns the code it finally hits.
                    $link->forceFill(['attempts' => $link->attempts + 1, 'expires_at' => now()])->save();

                    return $this->invalidCode();
                }

                if (! $link) {
                    $this->registerFailure($failKey);

                    return $this->invalidCode();
                }

                $user = User::whereKey($link->user_id)->lockForUpdate()->first();
                if (! $user || ! $user->is_active) {
                    return $this->invalidCode();
                }

                // The code stays unused: it is refused, not burnt, in case WhatsApp is enabled later on.
                if (! $user->campaignHasWhatsapp()) {
                    return $this->campaignWithoutWhatsapp();
                }

                if (User::where('whatsapp_phone', $phone)->whereKeyNot($user->id)->exists()) {
                    $this->registerFailure($failKey);

                    return $this->phoneTaken();
                }

                if ($user->whatsapp_phone !== null && $user->whatsapp_phone !== $phone) {
                    $user->tokens()->where('name', AgentIdentityService::TOKEN_NAME)->delete();
                }

                $user->forceFill(['whatsapp_phone' => $phone, 'whatsapp_verified_at' => now()])->save();
                $link->forceFill(['used_at' => now()])->save();
                Cache::forget($failKey);

                return response()->json(['success' => true, 'user_id' => $user->id, 'name' => $user->name]);
            });
        } catch (UniqueConstraintViolationException) {
            $this->registerFailure($failKey);

            return $this->phoneTaken();
        }
    }

    public function whoami(Request $request): JsonResponse
    {
        $phone = $request->query('phone');
        $user = $this->identity->findEligibleByPhone(is_string($phone) ? $this->identity->normalizePhone($phone) : null);
        if (! $user) {
            return $this->notFound();
        }

        return response()->json(['success' => true, ...$this->identity->profile($user)]);
    }

    public function token(AgentPhoneRequest $request): JsonResponse
    {
        $user = $this->identity->findEligibleByPhone($this->identity->normalizePhone($request->validated('phone')));
        if (! $user) {
            return $this->notFound();
        }

        $user->tokens()->where('name', AgentIdentityService::TOKEN_NAME)->where('expires_at', '<=', now())->delete();

        $expiresAt = now()->addMinutes((int) config('agent.token_ttl_minutes', 15));
        $token = $user->createToken(AgentIdentityService::TOKEN_NAME, ['agent'], $expiresAt);

        return response()->json([
            'success' => true,
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['success' => true, ...$this->identity->profile($request->user())]);
    }

    // Counts per phone (lockout of that phone) and globally (lockout of /link for everyone).
    private function registerFailure(string $failKey): void
    {
        $window = now()->addMinutes((int) config('agent.link_code_ttl_minutes', 10));

        Cache::add($failKey, 0, $window);
        Cache::increment($failKey);

        Cache::add(self::GLOBAL_FAIL_KEY, 0, $window);
        $global = (int) Cache::increment(self::GLOBAL_FAIL_KEY);
        if ($global === (int) config('agent.link_global_max_failures', 100) + 1) {
            Log::warning('Agent /link failure threshold exceeded; linking paused for all phones.', ['failures' => $global]);
        }
    }

    private function phoneTaken(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'El número ya está vinculado a otro usuario.'], 409);
    }

    private function campaignWithoutWhatsapp(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Tu campaña no tiene habilitado el agente de WhatsApp.'], 403);
    }

    private function invalidCode(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Código inválido o vencido.'], 422);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Not found.'], 404);
    }
}
