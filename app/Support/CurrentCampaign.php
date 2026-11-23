<?php

namespace App\Support;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Campaign isolation for the authenticated user (web session or WhatsApp agent token).
// Visible = the user's campaign plus every subcampaign below it; editable = only the user's own campaign.
// Without an authenticated user (artisan commands) nothing is restricted: commands pick their campaign
// explicitly with --campaign.
class CurrentCampaign
{
    private array $descendants = [];

    private ?array $allIds = null;

    public const VISTA_SESSION_KEY = 'grupo_politico_vista';

    public const CREAR_EN_OTRO_GRUPO = 'Estás viendo «%s». Para crear, elige tu grupo o «Todos los grupos» arriba.';

    public const SIN_RESULTADOS = 'Tu grupo político no tiene acceso a los resultados electorales.';

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
        return $this->user()?->campaignId();
    }

    public function isSuperadmin(): bool
    {
        return $this->user()?->role?->code === 'R01_SUPERADMIN';
    }

    // The user's campaign as the frontend sees it; null without an authenticated user.
    public function info(): ?array
    {
        $own = $this->ownId();
        $campaign = $own ? DB::table('campaigns')->where('id', $own)->first(['id', 'name', 'parent_id', 'whatsapp_enabled']) : null;

        return $campaign ? [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'whatsapp_enabled' => (bool) $campaign->whatsapp_enabled,
            'is_subcampaign' => $campaign->parent_id !== null,
            've_resultados_electorales' => $this->seesElectoralResults(),
        ] : null;
    }

    // null = unrestricted (no authenticated user). The superadmin's header choice narrows it to one grupo político.
    public function visibleIds(): ?array
    {
        $user = $this->user();
        if (! $user) {
            return null;
        }
        $selected = $this->selectedId();

        return $selected ? [$selected] : $this->visibleIdsFor($user);
    }

    // The grupo político the superadmin picked in the header (kept in the session); null = every group.
    // It can only narrow what the user already sees: a value outside visibleIdsFor() is ignored.
    public function selectedId(): ?string
    {
        $user = $this->user();
        if (! $user || $user->role?->code !== 'R01_SUPERADMIN' || ! request()->hasSession()) {
            return null;
        }
        $id = request()->session()->get(self::VISTA_SESSION_KEY);
        $all = $this->visibleIdsFor($user);

        return is_string($id) && $all !== null && in_array($id, $all, true) ? $id : null;
    }

    // The superadmin's header shows a group that is not its own: new records would land out of sight (in the own
    // group), so creating is refused until the header shows the own group or every group.
    public function createBlockedMessage(): ?string
    {
        $selected = $this->selectedId();
        if ($selected === null || $selected === $this->ownId()) {
            return null;
        }

        return sprintf(self::CREAR_EN_OTRO_GRUPO, DB::table('campaigns')->where('id', $selected)->value('name') ?? 'otro grupo');
    }

    // 422 with the reason (JSON: {message, errors.grupo}; Inertia: back with the error).
    public function abortIfCreateBlocked(): void
    {
        if ($message = $this->createBlockedMessage()) {
            throw ValidationException::withMessages(['grupo' => $message]);
        }
    }

    // The header selector: only for the superadmin with more than one group, every group it can pick (own first).
    public function selector(): ?array
    {
        $user = $this->user();
        if (! $user || ! $this->isSuperadmin()) {
            return null;
        }
        $all = $this->visibleIdsFor($user);
        if ($all === null || count($all) < 2) {
            return null;
        }
        $own = $this->ownId();
        $options = DB::table('campaigns')->whereIn('id', $all)->orderBy('name')->get(['id', 'name'])
            ->sortBy(fn ($c) => $c->id === $own ? 0 : 1)
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])
            ->values()->all();

        return ['options' => $options, 'selected' => $this->selectedId(), 'createBlocked' => $this->createBlockedMessage()];
    }

    // The same rule for a given user, also one who is not the authenticated one (the agent's whoami by phone).
    public function visibleIdsFor(User $user): ?array
    {
        $own = $user->campaignId();
        if ($own === null) {
            return null;
        }
        // The superadmin sees every campaign, not only its own branch (read only outside its own, like any other).
        if ($user->role?->code === 'R01_SUPERADMIN') {
            return $this->allIds ??= array_values(array_unique([$own, ...DB::table('campaigns')->pluck('id')->all()]));
        }

        return $this->withDescendants($own);
    }

    // [{id, name}] of every visible campaign, the own one first; empty unless there is more than one,
    // so a user who only sees their own campaign gets no campaign labels or filters.
    public function visibleCampaigns(): array
    {
        $visible = $this->visibleIds();
        if ($visible === null || count($visible) < 2) {
            return [];
        }

        $own = $this->ownId();

        return DB::table('campaigns')->whereIn('id', $visible)->orderBy('name')->get(['id', 'name'])
            ->sortBy(fn ($c) => $c->id === $own ? 0 : 1)
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])
            ->values()->all();
    }

    // The campaign a listing was filtered by, only when the user can see it; anything else means "all".
    // With a single visible campaign there is no filter on screen, so a leftover ?campana= never hides rows.
    public function filterId(?string $campaignId): ?string
    {
        if (! $campaignId || $this->visibleIds() === null || count($this->visibleIds()) < 2) {
            return null;
        }

        return in_array($campaignId, $this->visibleIds(), true) ? $campaignId : null;
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
        if (! $this->user()) {
            return true;
        }

        return $this->isSuperadmin() || $this->ownId() === Campaign::DEFAULT_ID;
    }

    // There is a single WhatsApp Business account for every grupo político, so its analytics are account-wide:
    // only the superadmin and the root campaign's team see them.
    public function seesWhatsAppAnalytics(): bool
    {
        return $this->isSuperadmin() || $this->ownId() === Campaign::DEFAULT_ID;
    }

    // The single rule for the shared electoral results (candidates, votes and parties of Alcaldía, Concejo, Senado,
    // Cámara, Asamblea and Gobernación): the superadmin always, anyone else only when their own grupo político has
    // ve_resultados_electorales on. Without an authenticated user (artisan commands) nothing is restricted.
    public function seesElectoralResults(): bool
    {
        $user = $this->user();

        return $user === null || $this->seesElectoralResultsFor($user);
    }

    // The same rule for a given user (the agent's whoami by phone). Cached on the request, never longer: the switch
    // can change between two requests served by the same container.
    public function seesElectoralResultsFor(User $user): bool
    {
        $cache = request()->attributes;
        $key = 'sees_electoral_results:'.$user->id;
        if (! $cache->has($key)) {
            $cache->set($key, $user->role?->code === 'R01_SUPERADMIN'
                || (bool) DB::table('campaigns')->where('id', $user->campaignId())->value('ve_resultados_electorales'));
        }

        return $cache->get($key);
    }

    public function abortUnlessSeesElectoralResults(): void
    {
        if (! $this->seesElectoralResults()) {
            self::denyElectoralResults();
        }
    }

    // 403 with the reason: {success: false, message} for JSON (AJAX convention), the Spanish 403 page otherwise.
    public static function denyElectoralResults(): never
    {
        if (request()->expectsJson()) {
            throw new HttpResponseException(response()->json(['success' => false, 'message' => self::SIN_RESULTADOS], 403));
        }

        abort(403, self::SIN_RESULTADOS);
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
