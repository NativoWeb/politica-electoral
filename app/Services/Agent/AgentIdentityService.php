<?php

namespace App\Services\Agent;

use App\Http\Middleware\EnsureCanWrite;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AgentIdentityService
{
    public const TOKEN_NAME = 'whatsapp-agent';

    public const PHONE_REGEX = '/^\+[1-9]\d{7,14}$/';

    // A '+' sent unencoded in a query string arrives as a space; restore it before matching.
    public function normalizePhone(?string $phone): ?string
    {
        $phone = trim((string) $phone);
        if ($phone !== '' && ctype_digit($phone[0])) {
            $phone = '+'.$phone;
        }

        return preg_match(self::PHONE_REGEX, $phone) ? $phone : null;
    }

    public function isEligible(?User $user): bool
    {
        return $user !== null
            && $user->is_active
            && ! $user->must_change_password
            && $user->whatsapp_phone !== null
            && $user->whatsapp_verified_at !== null;
    }

    public function findEligibleByPhone(?string $phone): ?User
    {
        if ($phone === null) {
            return null;
        }

        $user = User::with('role')->where('whatsapp_phone', $phone)->first();

        return $this->isEligible($user) ? $user : null;
    }

    // Same rule enforced by agent.can_write, so what /me reports is what the write endpoints allow.
    public function canWrite(User $user): bool
    {
        return in_array($user->role?->code, EnsureCanWrite::WRITE_ROLES, true)
            && $user->hasPermission('write.data');
    }

    public function profile(User $user): array
    {
        $restricted = $user->hasTerritoryScopeRestriction();

        $municipios = $restricted
            ? DB::table('geographic_units')
                ->whereIn('id', $user->territory_scope)
                ->orderBy('canonical_name')
                ->get(['id', 'canonical_name'])
                ->map(fn ($g) => ['id' => $g->id, 'name' => $g->canonical_name])
                ->values()
                ->all()
            : [];

        return [
            'id' => $user->id,
            'name' => $user->name,
            'role_code' => $user->role?->code,
            'permissions' => $user->resolvedPermissions(),
            'territory' => [
                'restricted' => $restricted,
                'municipios' => $municipios,
            ],
            'can_write' => $this->canWrite($user),
        ];
    }
}
