<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CampaignRequest;
use App\Models\Campaign;
use App\Services\CampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

// Campañas screen, superadmin only (route middleware `superadmin`). Campaigns are deactivated, never deleted:
// a deactivated campaign's users can neither log in nor keep their session (User::accessBlockedReason).
class CampaignController extends Controller
{
    public function __construct(private readonly CampaignService $campaigns) {}

    public function index(): Response
    {
        // Counted straight on the tables: the Lider global scope would hide campaigns outside the superadmin's tree.
        $users = DB::table('users')->groupBy('campaign_id')->select('campaign_id', DB::raw('count(*) as total'))
            ->pluck('total', 'campaign_id');
        $lideres = DB::table('lideres')->where('is_active', true)->groupBy('campaign_id')
            ->select('campaign_id', DB::raw('count(*) as total'))->pluck('total', 'campaign_id');

        $campaigns = Campaign::with(['parent:id,name', 'candidate:id,full_name'])->orderBy('name')->get()
            ->sortBy(fn (Campaign $c) => $c->id === Campaign::DEFAULT_ID ? 0 : 1)
            ->map(fn (Campaign $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'parentId' => $c->parent_id,
                'parentName' => $c->parent?->name,
                'whatsappEnabled' => $c->whatsapp_enabled,
                'isActive' => $c->is_active,
                'isDefault' => $c->id === Campaign::DEFAULT_ID,
                'candidateId' => $c->candidate_person_id,
                'candidateName' => $c->candidate?->full_name,
                'usersCount' => (int) ($users[$c->id] ?? 0),
                'lideresCount' => (int) ($lideres[$c->id] ?? 0),
            ])
            ->values();

        return Inertia::render('Admin/Campaigns', [
            'campaigns' => $campaigns,
            'defaultId' => Campaign::DEFAULT_ID,
        ]);
    }

    public function store(CampaignRequest $request): RedirectResponse
    {
        $campaign = $this->campaigns->create($request->validated());

        return back()->with('success', "Campaña \"{$campaign->name}\" creada.");
    }

    public function update(CampaignRequest $request, string $id): RedirectResponse
    {
        $campaign = Campaign::findOrFail($id);
        $campaign->update($request->validated());

        return back()->with('success', "Campaña \"{$campaign->name}\" actualizada.");
    }

    public function toggle(string $id): RedirectResponse
    {
        $campaign = Campaign::findOrFail($id);
        if ($campaign->id === Campaign::DEFAULT_ID) {
            return back()->withErrors(['toggle' => 'La campaña principal no se puede desactivar.']);
        }

        $campaign->update(['is_active' => ! $campaign->is_active]);

        return back()->with('success', $campaign->is_active
            ? "Campaña \"{$campaign->name}\" activada: sus usuarios ya pueden entrar."
            : "Campaña \"{$campaign->name}\" desactivada: sus usuarios ya no pueden entrar.");
    }

    // Electoral persons by name, to pick a campaign's candidate.
    public function candidates(Request $request): JsonResponse
    {
        $q = Str::upper(Str::ascii(trim((string) $request->query('q', ''))));
        if (mb_strlen($q) < 3) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
        $persons = DB::table('persons as p')
            ->whereNull('p.deleted_at')
            ->whereNull('p.merged_into_id')
            ->where('p.normalized_name', 'like', $like)
            ->orderBy('p.full_name')
            ->limit(10)
            ->select('p.id', 'p.full_name')
            ->selectSub(DB::table('candidacies')->whereColumn('person_id', 'p.id')->selectRaw('count(*)'), 'candidacies')
            ->get()
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->full_name, 'candidacies' => (int) $p->candidacies]);

        return response()->json(['success' => true, 'data' => $persons]);
    }
}
