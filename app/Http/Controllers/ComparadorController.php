<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ComparadorController extends Controller
{
    public function index()
    {
        return Inertia::render('Comparador');
    }

    public function comparar(Request $request): JsonResponse
    {
        $type = $request->input('type', 'persona');
        $idA = $request->input('a');
        $idB = $request->input('b');

        if (!$idA || !$idB) {
            return response()->json(['error' => 'Se requieren dos entidades para comparar'], 422);
        }

        $data = match ($type) {
            'persona' => $this->compararPersonas($idA, $idB),
            'municipio' => $this->compararMunicipios($idA, $idB),
            default => ['error' => 'Tipo no soportado'],
        };

        return response()->json($data);
    }

    private function compararPersonas(string $idA, string $idB): array
    {
        $getPersonData = function (string $id) {
            $person = DB::table('persons')->where('id', $id)->select('id', 'full_name as name')->first();
            if (!$person) return null;

            $candidaturas = DB::table('candidacies as c')
                ->join('contests as con', 'c.contest_id', '=', 'con.id')
                ->join('electoral_events as ev', 'con.electoral_event_id', '=', 'ev.id')
                ->leftJoin('offices as o', 'con.office_id', '=', 'o.id')
                ->leftJoin('corporations as corp', 'con.corporation_id', '=', 'corp.id')
                ->leftJoin('geographic_units as g', 'con.geographic_unit_id', '=', 'g.id')
                ->leftJoin('candidacy_endorsements as ce', function ($j) {
                    $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true);
                })
                ->leftJoin('political_organizations as po', 'ce.organization_id', '=', 'po.id')
                ->leftJoinSub(
                    DB::table('electoral_results')
                        ->whereIn('metric_type', ['votes', 'nominal_votes'])
                        ->select('candidacy_id', DB::raw('SUM(value) as votos'))
                        ->groupBy('candidacy_id'),
                    'res', 'res.candidacy_id', '=', 'c.id'
                )
                ->where('c.person_id', $id)
                ->select(
                    'ev.name as evento',
                    DB::raw("COALESCE(o.name, corp.name) as cargo"),
                    'g.canonical_name as municipio',
                    'c.outcome',
                    'po.canonical_name as partido',
                    DB::raw('COALESCE(res.votos, 0) as votos')
                )
                ->orderByDesc('ev.election_date')
                ->get()
                ->toArray();

            $totalVotos = collect($candidaturas)->sum('votos');
            $victorias = collect($candidaturas)->where('outcome', 'elected')->count();

            return [
                'id' => $person->id,
                'name' => $person->name,
                'candidaturas' => $candidaturas,
                'totalVotos' => $totalVotos,
                'victorias' => $victorias,
                'totalCandidaturas' => count($candidaturas),
            ];
        };

        return [
            'type' => 'persona',
            'a' => $getPersonData($idA),
            'b' => $getPersonData($idB),
        ];
    }

    private function compararMunicipios(string $idA, string $idB): array
    {
        $getMuniData = function (string $id) {
            $muni = DB::table('geographic_units as g')
                ->leftJoin('geographic_units as prov', 'g.parent_id', '=', 'prov.id')
                ->where('g.id', $id)
                ->select('g.id', 'g.canonical_name as name', 'prov.canonical_name as provincia')
                ->first();
            if (!$muni) return null;

            $officeId = DB::table('offices')->where('name', 'Alcaldía')->value('id');

            $alcalde = DB::table('candidacies as c')
                ->join('persons as p', 'c.person_id', '=', 'p.id')
                ->join('contests as con', 'c.contest_id', '=', 'con.id')
                ->where('con.office_id', $officeId)
                ->where('con.geographic_unit_id', $id)
                ->where('c.outcome', 'elected')
                ->select('p.full_name as name')
                ->first();

            $totalVotos = DB::table('electoral_results as er')
                ->join('contests as con', 'er.contest_id', '=', 'con.id')
                ->where('con.office_id', $officeId)
                ->where('con.geographic_unit_id', $id)
                ->where('er.metric_type', 'votes')
                ->sum('er.value');

            $candidatos = DB::table('candidacies as c')
                ->join('contests as con', 'c.contest_id', '=', 'con.id')
                ->where('con.office_id', $officeId)
                ->where('con.geographic_unit_id', $id)
                ->count();

            $concejalCount = DB::table('candidacies as c')
                ->join('contests as con', 'c.contest_id', '=', 'con.id')
                ->join('corporations as corp', 'con.corporation_id', '=', 'corp.id')
                ->where('corp.name', 'Concejo')
                ->where('con.geographic_unit_id', $id)
                ->where('c.outcome', 'elected')
                ->count();

            $partidos = DB::table('electoral_results as er')
                ->join('contests as con', 'er.contest_id', '=', 'con.id')
                ->join('candidacies as c', 'er.candidacy_id', '=', 'c.id')
                ->leftJoin('candidacy_endorsements as ce', function ($j) {
                    $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true);
                })
                ->leftJoin('political_organizations as po', 'ce.organization_id', '=', 'po.id')
                ->where('con.geographic_unit_id', $id)
                ->where('con.office_id', $officeId)
                ->where('er.metric_type', 'votes')
                ->whereNotNull('po.id')
                ->select('po.canonical_name as name', DB::raw('SUM(er.value) as votos'))
                ->groupBy('po.canonical_name')
                ->orderByDesc('votos')
                ->get()
                ->toArray();

            return [
                'id' => $muni->id,
                'name' => $muni->name,
                'provincia' => $muni->provincia,
                'alcalde' => $alcalde->name ?? null,
                'totalVotos' => (int) $totalVotos,
                'candidatos' => $candidatos,
                'concejales' => $concejalCount,
                'partidos' => $partidos,
            ];
        };

        return [
            'type' => 'municipio',
            'a' => $getMuniData($idA),
            'b' => $getMuniData($idB),
        ];
    }
}
