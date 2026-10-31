<?php

namespace App\Services;

use App\Models\Campaign;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Rules shared by the Campañas screen and `campaign:create`: slug derived from the name, unique slugs and a
// parent tree without cycles in which the root campaign (Campaign::DEFAULT_ID) never gets a parent.
class CampaignService
{
    public static function slug(?string $slug, ?string $name): string
    {
        return Str::slug(trim((string) ($slug ?: $name)));
    }

    public function slugTaken(string $slug, ?string $ignoreId = null): bool
    {
        return DB::table('campaigns')->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }

    // Why $parentId cannot be the parent of $campaignId (null for a new campaign), or null when it can.
    public function parentError(?string $campaignId, ?string $parentId): ?string
    {
        if ($campaignId === Campaign::DEFAULT_ID) {
            return $parentId === null ? null : 'La campaña principal no puede tener campaña madre.';
        }
        if ($parentId === null) {
            return null;
        }
        if (! DB::table('campaigns')->where('id', $parentId)->exists()) {
            return 'Campaña madre no encontrada.';
        }

        // Walking up from the new parent must never reach the campaign itself.
        $seen = [];
        for ($current = $parentId; $current !== null && ! isset($seen[$current]); $current = DB::table('campaigns')->where('id', $current)->value('parent_id')) {
            if ($current === $campaignId) {
                return 'Una campaña no puede ser madre de sí misma ni de una campaña que está por encima de ella.';
            }
            $seen[$current] = true;
        }

        return null;
    }

    public function create(array $data): Campaign
    {
        $campaign = new Campaign([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'parent_id' => $data['parent_id'] ?? null,
            'candidate_person_id' => $data['candidate_person_id'] ?? null,
            'whatsapp_enabled' => (bool) ($data['whatsapp_enabled'] ?? false),
            'is_active' => true,
        ]);
        $campaign->save();

        return $campaign;
    }
}
