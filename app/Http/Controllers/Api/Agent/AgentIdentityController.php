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

class AgentIdentityController extends Controller
{
    public function __construct(private readonly AgentIdentityService $identity) {}

    public function link(AgentLinkRequest $request): JsonResponse
    {
        $phone = $request->validated('phone');
        $hash = WhatsAppLinkCode::hashCode($request->validated('code'));
        $failKey = 'agent_link_fail:'.hash('sha256', $phone);
        $maxFailures = (int) config('agent.link_max_failed_attempts', 5);

        try {
            return DB::transaction(function () use ($phone, $hash, $failKey, $maxFailures) {
                $link = WhatsAppLinkCode::active()->where('code_hash', $hash)->lockForUpdate()->first();

                if ($link && (int) Cache::get($failKey, 0) > $maxFailures) {
                    // A phone that already failed too often burns the code it finally hits.
                    $link->forceFill(['attempts' => $link->attempts + 1, 'expires_at' => now()])->save();

                    return $this->invalidCode();
                }

                if (! $link) {
                    $this->registerFailure($failKey, $hash);

                    return $this->invalidCode();
                }

                $user = User::whereKey($link->user_id)->lockForUpdate()->first();
                if (! $user || ! $user->is_active) {
                    return $this->invalidCode();
                }

                if (User::where('whatsapp_phone', $phone)->whereKeyNot($user->id)->exists()) {
                    return response()->json(['success' => false, 'message' => 'El número ya está vinculado a otro usuario.'], 409);
                }

                if ($user->whatsapp_phone !== null && $user->whatsapp_phone !== $phone) {
                    $user->tokens()->delete();
                }

                $user->forceFill(['whatsapp_phone' => $phone, 'whatsapp_verified_at' => now()])->save();
                $link->forceFill(['used_at' => now()])->save();
                Cache::forget($failKey);

                return response()->json(['success' => true, 'user_id' => $user->id, 'name' => $user->name]);
            });
        } catch (UniqueConstraintViolationException) {
            return response()->json(['success' => false, 'message' => 'El número ya está vinculado a otro usuario.'], 409);
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

        $user->tokens()->where('name', 'whatsapp-agent')->where('expires_at', '<=', now())->delete();

        $expiresAt = now()->addMinutes((int) config('agent.token_ttl_minutes', 15));
        $token = $user->createToken('whatsapp-agent', ['agent'], $expiresAt);

        return response()->json([
            'success' => true,
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('role');

        if (! $this->identity->isEligible($user)) {
            $user->currentAccessToken()?->delete();

            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        return response()->json(['success' => true, ...$this->identity->profile($user)]);
    }

    private function registerFailure(string $failKey, string $hash): void
    {
        Cache::add($failKey, 0, now()->addMinutes((int) config('agent.link_code_ttl_minutes', 10)));
        Cache::increment($failKey);

        WhatsAppLinkCode::where('code_hash', $hash)->increment('attempts');
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
