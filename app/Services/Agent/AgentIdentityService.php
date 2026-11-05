<?php

namespace App\Services\Agent;

use App\Http\Middleware\EnsureCanWrite;
use App\Models\User;
use App\Support\CurrentCampaign;
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

    // Checked on every agent call (whoami, token, and each acting-as-user request), so revoking use.whatsapp,
    // deactivating the user or switching off the campaign's WhatsApp cuts the assistant on the next message.
    public function isEligible(?User $user): bool
    {
        return $user !== null && $this->ineligibilityReason($user) === null;
    }

    // Why a user may not use the agent (a code for the audit trail, no personal data), or null when they may.
    public function ineligibilityReason(User $user): ?string
    {
        return match (true) {
            ! $user->is_active, $user->accessBlockedReason() !== null => 'cuenta_bloqueada',
            (bool) $user->must_change_password => 'debe_cambiar_contrasena',
            $user->whatsapp_phone === null, $user->whatsapp_verified_at === null => 'numero_desvinculado',
            ! $user->campaignHasWhatsapp() => 'campana_sin_whatsapp',
            ! $user->canUseWhatsappAssistant() => 'permiso_retirado',
            default => null,
        };
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
            // visible_ids: the campaigns whose data the user reads (own first, then its subcampaigns), as the web scopes them.
            // all: true for the superadmin, who sees every campaign (visible_ids lists those existing right now).
            'campaign' => [
                'id' => $user->campaignId(),
                'name' => DB::table('campaigns')->where('id', $user->campaignId())->value('name'),
                'all' => $user->role?->code === 'R01_SUPERADMIN',
                'visible_ids' => app(CurrentCampaign::class)->visibleIdsFor($user),
            ],
        ];
    }
}
