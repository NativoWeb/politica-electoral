<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Candidacy;
use App\Models\Contest;
use App\Models\ElectoralEvent;
use App\Models\ElectoralResult;
use App\Models\GeographicUnit;
use App\Models\Person;
use App\Models\PoliticalOrganization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class EleccionCrudController extends Controller
{
    public function index(Request $request)
    {
        $events = ElectoralEvent::withCount('contests')
            ->orderByDesc('election_date')
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'name' => $e->name,
                'type' => $e->event_type,
                'date' => $e->election_date?->format('Y-m-d'),
                'period' => $e->political_period,
                'status' => $e->status,
                'contests' => $e->contests_count,
            ]);

        return Inertia::render('Admin/Elecciones', [
            'events' => $events,
            'oficios' => DB::table('offices')->orderBy('name')->get(['id', 'name']),
            'corporaciones' => DB::table('corporations')->orderBy('name')->get(['id', 'name']),
            'municipios' => GeographicUnit::where('type', 'municipality')->orderBy('canonical_name')->get(['id', 'canonical_name']),
            'partidos' => PoliticalOrganization::orderBy('canonical_name')->get(['id', 'canonical_name', 'acronym']),
        ]);
    }

    public function showEvent(string $id)
    {
        $event = ElectoralEvent::findOrFail($id);

        $contests = Contest::where('electoral_event_id', $id)
            ->with(['office', 'corporation', 'geographicUnit'])
            ->withCount('candidacies')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'office' => $c->office?->name,
                'officeId' => $c->office_id,
                'corporation' => $c->corporation?->name,
                'municipio' => $c->geographicUnit?->canonical_name,
                'municipioId' => $c->geographic_unit_id,
                'seats' => $c->seats,
                'candidacies' => $c->candidacies_count,
            ]);

        return response()->json([
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'type' => $event->event_type,
                'date' => $event->election_date?->format('Y-m-d'),
                'period' => $event->political_period,
                'status' => $event->status,
            ],
            'contests' => $contests,
        ]);
    }

    public function showContest(string $id)
    {
        $contest = Contest::with(['office', 'corporation', 'geographicUnit'])->findOrFail($id);

        $candidacies = Candidacy::where('contest_id', $id)
            ->with(['person', 'organization'])
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'personId' => $c->person_id,
                'personName' => $c->person?->full_name,
                'party' => $c->organization?->canonical_name,
                'partyId' => $c->organization_id,
                'outcome' => $c->outcome,
                'listPosition' => $c->list_position,
                'votos' => ElectoralResult::where('candidacy_id', $c->id)
                    ->where('metric_type', 'votes')
                    ->sum('value'),
            ]);

        return response()->json([
            'contest' => [
                'id' => $contest->id,
                'office' => $contest->office?->name,
                'corporation' => $contest->corporation?->name,
                'municipio' => $contest->geographicUnit?->canonical_name,
                'seats' => $contest->seats,
            ],
            'candidacies' => $candidacies,
        ]);
    }

    // --- Event CRUD ---

    public function storeEvent(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'event_type' => 'required|in:territorial,legislative,presidential,consultation,other',
            'election_date' => 'required|date',
            'political_period' => 'nullable|string|max:20',
            'status' => 'nullable|in:scheduled,in_progress,completed,annulled',
        ]);

        ElectoralEvent::create([...$data, 'status' => $data['status'] ?? 'completed']);

        return back()->with('success', "Evento «{$data['name']}» creado.");
    }

    public function updateEvent(Request $request, string $id)
    {
        $event = ElectoralEvent::findOrFail($id);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'event_type' => 'required|in:territorial,legislative,presidential,consultation,other',
            'election_date' => 'required|date',
            'political_period' => 'nullable|string|max:20',
            'status' => 'nullable|in:scheduled,in_progress,completed,annulled',
        ]);

        $event->update($data);

        return back()->with('success', "Evento actualizado.");
    }

    public function destroyEvent(string $id)
    {
        ElectoralEvent::findOrFail($id)->delete();
        return back()->with('success', 'Evento eliminado.');
    }

    // --- Contest CRUD ---

    public function storeContest(Request $request)
    {
        $data = $request->validate([
            'electoral_event_id' => 'required|exists:electoral_events,id',
            'office_id' => 'nullable|exists:offices,id',
            'corporation_id' => 'nullable|exists:corporations,id',
            'geographic_unit_id' => 'nullable|exists:geographic_units,id',
            'seats' => 'nullable|integer|min:1',
        ]);

        Contest::create($data);

        return back()->with('success', 'Contienda creada.');
    }

    // --- Candidacy CRUD ---

    public function storeCandidacy(Request $request)
    {
        $data = $request->validate([
            'contest_id' => 'required|exists:contests,id',
            'person_id' => 'required|exists:persons,id',
            'organization_id' => 'nullable|exists:political_organizations,id',
            'outcome' => 'nullable|in:elected,lost,withdrawn,disqualified',
            'list_position' => 'nullable|integer',
            'votos' => 'nullable|integer|min:0',
        ]);

        $votos = $data['votos'] ?? null;
        unset($data['votos']);

        $candidacy = Candidacy::create([...$data, 'status' => 'confirmed']);

        if ($votos !== null && $votos >= 0) {
            ElectoralResult::create([
                'candidacy_id' => $candidacy->id,
                'contest_id' => $candidacy->contest_id,
                'geographic_unit_id' => Contest::find($candidacy->contest_id)?->geographic_unit_id,
                'metric_type' => 'votes',
                'value' => $votos,
            ]);
        }

        return back()->with('success', 'Candidatura registrada.');
    }

    public function updateCandidacy(Request $request, string $id)
    {
        $candidacy = Candidacy::findOrFail($id);

        $data = $request->validate([
            'organization_id' => 'nullable|exists:political_organizations,id',
            'outcome' => 'nullable|in:elected,lost,withdrawn,disqualified',
            'list_position' => 'nullable|integer',
        ]);

        $candidacy->update($data);

        return back()->with('success', 'Candidatura actualizada.');
    }

    // --- Result CRUD ---

    public function storeResult(Request $request)
    {
        $data = $request->validate([
            'candidacy_id' => 'required|exists:candidacies,id',
            'contest_id' => 'required|exists:contests,id',
            'geographic_unit_id' => 'nullable|exists:geographic_units,id',
            'organization_id' => 'nullable|exists:political_organizations,id',
            'metric_type' => 'required|in:votes,nominal_votes,list_votes,blank,null,total',
            'value' => 'required|integer|min:0',
        ]);

        // Get geo from contest if not provided
        if (empty($data['geographic_unit_id'])) {
            $data['geographic_unit_id'] = Contest::find($data['contest_id'])?->geographic_unit_id;
        }

        ElectoralResult::create($data);

        return back()->with('success', 'Resultado registrado.');
    }

    public function updateResult(Request $request, string $id)
    {
        $result = ElectoralResult::findOrFail($id);

        $data = $request->validate([
            'value' => 'required|integer|min:0',
            'metric_type' => 'nullable|in:votes,nominal_votes,list_votes,blank,null,total',
        ]);

        $result->update($data);

        return back()->with('success', 'Resultado actualizado.');
    }
}
