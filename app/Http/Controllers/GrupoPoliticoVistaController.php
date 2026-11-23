<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Support\CurrentCampaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// The superadmin's header selector: which grupo político the whole app shows (all, or a single one).
// Kept in the session; CurrentCampaign::visibleIds() reads it and only ever narrows what the user sees.
class GrupoPoliticoVistaController extends Controller
{
    public function __construct(private readonly CurrentCampaign $campaign) {}

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['campaign_id' => ['nullable', 'uuid']], [
            'campaign_id.uuid' => 'El grupo político elegido no es válido.',
        ]);
        $id = $data['campaign_id'] ?? null;

        $all = $this->campaign->visibleIdsFor($request->user()) ?? [];
        if ($id !== null && ! in_array($id, $all, true)) {
            return response()->json([
                'success' => false,
                'message' => 'No puedes ver ese grupo político.',
                'errors' => ['campaign_id' => ['No puedes ver ese grupo político.']],
            ], 422);
        }

        $before = $this->campaign->selectedId();
        if ($id === null) {
            $request->session()->forget(CurrentCampaign::VISTA_SESSION_KEY);
        } else {
            $request->session()->put(CurrentCampaign::VISTA_SESSION_KEY, $id);
        }

        // The bell of compromisos is cached per user: it must count the newly chosen group.
        Cache::forget(EventoCompromisoController::alertsKey($request));

        if ($before !== $id) {
            $names = DB::table('campaigns')->whereIn('id', array_filter([$before, $id]))->pluck('name', 'id');
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'GRUPO_POLITICO_VISTA',
                'entity_type' => 'campana',
                'entity_id' => $id,
                'before_state' => ['grupo' => $before ? $names[$before] ?? $before : 'Todos los grupos'],
                'after_state' => ['grupo' => $id ? $names[$id] : 'Todos los grupos'],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        return response()->json(['success' => true, 'selected' => $id]);
    }
}
