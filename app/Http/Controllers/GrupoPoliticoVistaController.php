<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Support\CurrentCampaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// The superadmin's header selector: which grupos políticos the whole app shows (all, or the ones marked).
// Kept in the session; CurrentCampaign::visibleIds() reads it and only ever narrows what the user sees.
class GrupoPoliticoVistaController extends Controller
{
    public function __construct(private readonly CurrentCampaign $campaign) {}

    public function update(Request $request): JsonResponse
    {
        // campaign_ids: [] = every group. campaign_id (a single id or null) is what a page loaded before the list sends.
        $data = $request->validate([
            'campaign_ids' => ['sometimes', 'array', 'max:500'],
            'campaign_ids.*' => ['uuid', 'distinct'],
            'campaign_id' => ['sometimes', 'nullable', 'uuid'],
        ], [
            'campaign_ids.array' => 'Los grupos políticos elegidos no son válidos.',
            'campaign_ids.max' => 'Los grupos políticos elegidos no son válidos.',
            'campaign_ids.*.uuid' => 'El grupo político elegido no es válido.',
            'campaign_ids.*.distinct' => 'Un grupo político está repetido.',
            'campaign_id.uuid' => 'El grupo político elegido no es válido.',
        ]);
        $ids = array_key_exists('campaign_ids', $data)
            ? array_values($data['campaign_ids'])
            : array_values(array_filter([$data['campaign_id'] ?? null]));

        $all = $this->campaign->visibleIdsFor($request->user()) ?? [];
        if (array_diff($ids, $all) !== []) {
            return response()->json([
                'success' => false,
                'message' => 'No puedes ver ese grupo político.',
                'errors' => ['campaign_ids' => ['No puedes ver ese grupo político.']],
            ], 422);
        }

        $before = $this->campaign->selectedIds();
        // Every group marked is the same as «Todos los grupos»: it also keeps the groups created later in sight.
        $after = $ids === [] || count($ids) === count($all) ? null : $ids;
        if ($after === null) {
            $request->session()->forget(CurrentCampaign::VISTA_SESSION_KEY);
        } else {
            $request->session()->put(CurrentCampaign::VISTA_SESSION_KEY, $after);
        }

        // The bell of compromisos is cached per user: it must count the newly chosen groups.
        Cache::forget(EventoCompromisoController::alertsKey($request));

        if (self::sorted($before) !== self::sorted($after)) {
            $names = DB::table('campaigns')->whereIn('id', array_merge($before ?? [], $after ?? []))->pluck('name', 'id');
            $label = fn (?array $list) => $list === null
                ? 'Todos los grupos'
                : collect($list)->map(fn ($id) => $names[$id] ?? $id)->sort()->implode(', ');
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'GRUPO_POLITICO_VISTA',
                'entity_type' => 'campana',
                'entity_id' => $after !== null && count($after) === 1 ? $after[0] : null,
                'before_state' => ['grupo' => $label($before), 'ids' => $before],
                'after_state' => ['grupo' => $label($after), 'ids' => $after],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        return response()->json(['success' => true, 'selected' => $after]);
    }

    private static function sorted(?array $ids): ?array
    {
        if ($ids === null) {
            return null;
        }
        sort($ids);

        return $ids;
    }
}
