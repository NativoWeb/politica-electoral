<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppLinkCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WhatsAppLinkController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $ttl = (int) config('agent.link_code_ttl_minutes', 10);

        [$code, $link] = DB::transaction(function () use ($user, $ttl) {
            WhatsAppLinkCode::active()->where('user_id', $user->id)->update(['expires_at' => now()]);

            do {
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $hash = WhatsAppLinkCode::hashCode($code);
            } while (WhatsAppLinkCode::active()->where('code_hash', $hash)->exists());

            $link = WhatsAppLinkCode::create([
                'user_id' => $user->id,
                'code_hash' => $hash,
                'expires_at' => now()->addMinutes($ttl),
            ]);

            return [$code, $link];
        });

        return response()->json([
            'success' => true,
            'code' => $code,
            'expires_at' => $link->expires_at->toIso8601String(),
        ])->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($user) {
            $user->forceFill(['whatsapp_phone' => null, 'whatsapp_verified_at' => null])->save();
            $user->tokens()->delete();
            WhatsAppLinkCode::active()->where('user_id', $user->id)->update(['expires_at' => now()]);
        });

        return response()->json(['success' => true]);
    }
}
