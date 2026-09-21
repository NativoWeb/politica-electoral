<?php

namespace App\Http\Controllers;

use App\Models\Candidacy;
use App\Models\Contest;
use App\Models\ElectoralEvent;
use App\Models\ElectoralResult;
use App\Models\GeographicUnit;
use App\Models\Person;
use App\Models\PoliticalOrganization;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        return redirect('/mapa-politico');
    }

    private function getKpis($alcaldiaOffice): array
    {
        $totalVotosAlcaldia = (int) DB::table('electoral_results')
            ->join('contests', 'electoral_results.contest_id', '=', 'contests.id')
            ->where('contests.office_id', $alcaldiaOffice)
            ->where('electoral_results.metric_type', 'votes')
            ->sum('electoral_results.value');

        return [
            'personas' => Person::count(),
            'municipios' => GeographicUnit::where('type', 'municipality')->count(),
            'contiendas' => Contest::count(),
            'resultados' => ElectoralResult::count(),
            'partidos' => PoliticalOrganization::count(),
            'electos' => Candidacy::where('outcome', 'elected')->count(),
            'totalVotosAlcaldia' => $totalVotosAlcaldia,
            'alcaldesElectos' => Candidacy::whereHas('contest', fn ($q) => $q->where('office_id', $alcaldiaOffice))
                ->where('outcome', 'elected')->count(),
        ];
    }

    private function getEvents(): array
    {
        return ElectoralEvent::orderBy('election_date', 'desc')
            ->get(['id', 'name', 'event_type', 'election_date', 'political_period', 'status'])
            ->map(fn ($e) => [
                'id' => $e->id,
                'name' => $e->name,
                'type' => $e->event_type,
                'date' => $e->election_date->format('Y-m-d'),
                'period' => $e->political_period,
            ])
            ->toArray();
    }

    private function getTopPartidosAlcaldia($alcaldiaOffice): array
    {
        return DB::table('electoral_results as er')
            ->join('contests', 'er.contest_id', '=', 'contests.id')
            ->join('candidacies as c', 'er.candidacy_id', '=', 'c.id')
            ->join('candidacy_endorsements as ce', function ($j) {
                $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true);
            })
            ->join('political_organizations as po', 'ce.organization_id', '=', 'po.id')
            ->where('contests.office_id', $alcaldiaOffice)
            ->where('er.metric_type', 'votes')
            ->select(
                'po.id',
                'po.canonical_name as name',
                'po.acronym',
                'po.color_hex',
                DB::raw('SUM(er.value) as total_votos'),
                DB::raw('COUNT(DISTINCT er.geographic_unit_id) as municipios')
            )
            ->groupBy('po.id', 'po.canonical_name', 'po.acronym', 'po.color_hex')
            ->orderByDesc('total_votos')
            ->limit(15)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'acronym' => $r->acronym,
                'color' => $r->color_hex,
                'totalVotos' => (int) $r->total_votos,
                'municipios' => (int) $r->municipios,
            ])
            ->toArray();
    }

    private function getTopMunicipios($alcaldiaOffice): array
    {
        return DB::table('electoral_results')
            ->join('contests', 'electoral_results.contest_id', '=', 'contests.id')
            ->join('geographic_units', 'electoral_results.geographic_unit_id', '=', 'geographic_units.id')
            ->where('contests.office_id', $alcaldiaOffice)
            ->where('electoral_results.metric_type', 'votes')
            ->select(
                'geographic_units.id',
                'geographic_units.canonical_name as name',
                DB::raw('SUM(electoral_results.value) as total_votos'),
                DB::raw('COUNT(DISTINCT electoral_results.candidacy_id) as candidatos')
            )
            ->groupBy('geographic_units.id', 'geographic_units.canonical_name')
            ->orderByDesc('total_votos')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'totalVotos' => (int) $r->total_votos,
                'candidatos' => (int) $r->candidatos,
            ])
            ->toArray();
    }

    private function getTopAlcaldes($alcaldiaOffice): array
    {
        return DB::table('persons')
            ->join('candidacies', 'persons.id', '=', 'candidacies.person_id')
            ->join('contests', 'candidacies.contest_id', '=', 'contests.id')
            ->join('electoral_results', 'candidacies.id', '=', 'electoral_results.candidacy_id')
            ->where('contests.office_id', $alcaldiaOffice)
            ->where('electoral_results.metric_type', 'votes')
            ->where('electoral_results.value', '>', 0)
            ->select(
                'persons.id',
                'persons.full_name as name',
                DB::raw('MAX(CASE WHEN candidacies.outcome = \'elected\' THEN 1 ELSE 0 END) as fue_electo'),
                DB::raw('SUM(electoral_results.value) as total_votos')
            )
            ->groupBy('persons.id', 'persons.full_name')
            ->orderByDesc('total_votos')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'electo' => (bool) $r->fue_electo,
                'totalVotos' => (int) $r->total_votos,
            ])
            ->toArray();
    }

    private function getResumenCorporaciones(): array
    {
        $offices = DB::table('offices')->pluck('id', 'name');
        $result = [];

        foreach (['Alcaldía', 'Gobernación', 'Concejo Municipal', 'Asamblea Departamental'] as $officeName) {
            $officeId = $offices[$officeName] ?? null;
            if (!$officeId) continue;

            $totalVotos = (int) DB::table('electoral_results')
                ->join('contests', 'electoral_results.contest_id', '=', 'contests.id')
                ->where('contests.office_id', $officeId)
                ->whereIn('electoral_results.metric_type', ['votes', 'nominal_votes'])
                ->sum('electoral_results.value');

            $candidatos = (int) DB::table('candidacies')
                ->join('contests', 'candidacies.contest_id', '=', 'contests.id')
                ->where('contests.office_id', $officeId)
                ->count();

            $electos = (int) DB::table('candidacies')
                ->join('contests', 'candidacies.contest_id', '=', 'contests.id')
                ->where('contests.office_id', $officeId)
                ->where('candidacies.outcome', 'elected')
                ->count();

            $result[] = [
                'office' => $officeName,
                'totalVotos' => $totalVotos,
                'candidatos' => $candidatos,
                'electos' => $electos,
            ];
        }

        // Senado y Cámara (legislativo — sin office_id filtrable fácilmente, usar event type)
        foreach (['Senado', 'Cámara'] as $corp) {
            $corpId = DB::table('corporations')->where('name', $corp)->value('id');
            if (!$corpId) continue;

            $totalVotos = (int) DB::table('electoral_results')
                ->join('contests', 'electoral_results.contest_id', '=', 'contests.id')
                ->where('contests.corporation_id', $corpId)
                ->whereIn('electoral_results.metric_type', ['votes', 'nominal_votes'])
                ->sum('electoral_results.value');

            $candidatos = (int) DB::table('candidacies')
                ->join('contests', 'candidacies.contest_id', '=', 'contests.id')
                ->where('contests.corporation_id', $corpId)
                ->count();

            $result[] = [
                'office' => $corp,
                'totalVotos' => $totalVotos,
                'candidatos' => $candidatos,
                'electos' => 0,
            ];
        }

        return $result;
    }

    private function getMunicipiosForMap($alcaldiaOffice): array
    {
        return DB::table('geographic_units as g')
            ->where('g.type', 'municipality')
            ->leftJoin('geographic_units as prov', 'g.parent_id', '=', 'prov.id')
            ->leftJoinSub(
                DB::table('electoral_results')
                    ->join('contests', 'electoral_results.contest_id', '=', 'contests.id')
                    ->where('contests.office_id', $alcaldiaOffice)
                    ->where('electoral_results.metric_type', 'votes')
                    ->select('electoral_results.geographic_unit_id', DB::raw('SUM(electoral_results.value) as total_votos'))
                    ->groupBy('electoral_results.geographic_unit_id'),
                'votes', 'g.id', '=', 'votes.geographic_unit_id'
            )
            ->leftJoinSub(
                DB::table('candidacies')
                    ->join('contests', 'candidacies.contest_id', '=', 'contests.id')
                    ->join('persons', 'candidacies.person_id', '=', 'persons.id')
                    ->where('contests.office_id', $alcaldiaOffice)
                    ->where('candidacies.outcome', 'elected')
                    ->select('contests.geographic_unit_id', 'persons.full_name as alcalde'),
                'alcalde', 'g.id', '=', 'alcalde.geographic_unit_id'
            )
            ->select('g.id', 'g.canonical_name as name', 'prov.canonical_name as provincia', 'votes.total_votos', 'alcalde.alcalde')
            ->orderBy('g.canonical_name')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'provincia' => $r->provincia,
                'totalVotos' => (int) ($r->total_votos ?? 0),
                'alcalde' => $r->alcalde,
            ])
            ->toArray();
    }
}
