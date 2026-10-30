<?php

namespace App\Support;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Campaign isolation for the authenticated user (web session or WhatsApp agent token).
// Visible = the user's campaign plus every subcampaign below it; editable = only the user's own campaign.
// Without an authenticated user (artisan commands) nothing is restricted: commands pick their campaign
// explicitly with --campaign.
class CurrentCampaign
{
    private array $descendants = [];

    public function user(): ?User
    {
        $user = request()->user();

        return $user instanceof User ? $user : null;
    }

    public function restricted(): bool
    {
        return $this->user() !== null;
    }

    public function ownId(): ?string
    {
        $user = $this->user();
        if (! $user) {
            return null;
        }
        // A model built in memory (User::create) does not carry the column default until it is reloaded.
        if (! $user->campaign_id) {
            $user->campaign_id = DB::table('users')->where('id', $user->id)->value('campaign_id') ?? Campaign::DEFAULT_ID;
        }

        return $user->campaign_id;
    }

    // null = unrestricted (no authenticated user).
    public function visibleIds(): ?array
    {
        $own = $this->ownId();

        return $own === null ? null : $this->withDescendants($own);
    }

    public function isVisible(?string $campaignId): bool
    {
        $visible = $this->visibleIds();

        return $visible === null || ($campaignId !== null && in_array($campaignId, $visible, true));
    }

    public function isOwn(?string $campaignId): bool
    {
        $own = $this->ownId();

        return $own === null || $campaignId === $own;
    }

    // Shared electoral data (persons, candidacies, endorsements) is edited only by the platform:
    // superadmins and the root campaign's team, which keeps the behaviour it had before campaigns.
    public function canEditElectoral(): bool
    {
        $user = $this->user();
        if (! $user) {
            return true;
        }

        return $user->role?->code === 'R01_SUPERADMIN' || $this->ownId() === Campaign::DEFAULT_ID;
    }

    public function scope($query, string $column = 'campaign_id')
    {
        $visible = $this->visibleIds();
        if ($visible !== null) {
            $query->whereIn($column, $visible);
        }

        return $query;
    }

    public function withDescendants(string $campaignId): array
    {
        if (isset($this->descendants[$campaignId])) {
            return $this->descendants[$campaignId];
        }

        $ids = [$campaignId];
        $frontier = [$campaignId];
        while ($frontier) {
            $children = DB::table('campaigns')->whereIn('parent_id', $frontier)->pluck('id')
                ->reject(fn ($id) => in_array($id, $ids, true))->values()->all();
            $ids = array_merge($ids, $children);
            $frontier = $children;
        }

        return $this->descendants[$campaignId] = $ids;
    }

    // Resolves the --campaign option of the ETL commands (slug or id); the root campaign by default.
    public static function resolveForCommand(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return Campaign::DEFAULT_ID;
        }

        return DB::table('campaigns')->where('slug', $value)->value('id')
            ?? (Str::isUuid($value) ? DB::table('campaigns')->where('id', $value)->value('id') : null);
    }
}
