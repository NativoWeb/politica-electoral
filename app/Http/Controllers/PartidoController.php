<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PartidoController extends Controller
{
    public function show(string $id)
    {
        $partido = DB::table('political_organizations')->where('id', $id)
            ->select('id', 'canonical_name as name', 'acronym', 'type', 'status', 'color_hex')
            ->first();
        abort_if(!$partido, 404);

        return Inertia::render('Partido', [
            'partido' => $partido,
            'candidatos' => $this->getCandidatos($id),
            'municipios' => $this->getMunicipios($id),
            'aliases' => $this->getAliases($id),
        ]);
    }

    private function getCandidatos(string $orgId): array
    {
        return DB::table('candidacy_endorsements as ce')
            ->join('candidacies as c', 'ce.candidacy_id', '=', 'c.id')
            ->join('persons as p', 'c.person_id', '=', 'p.id')
            ->join('contests as con', 'c.contest_id', '=', 'con.id')
            ->join('electoral_events as ev', 'con.electoral_event_id', '=', 'ev.id')
            ->leftJoin('offices as o', 'con.office_id', '=', 'o.id')
            ->leftJoin('corporations as corp', 'con.corporation_id', '=', 'corp.id')
            ->leftJoin('geographic_units as g', 'con.geographic_unit_id', '=', 'g.id')
            ->leftJoinSub(
                DB::table('electoral_results')->whereIn('metric_type', ['votes','nominal_votes'])
                    ->select('candidacy_id', DB::raw('SUM(value) as votos'))->groupBy('candidacy_id'),
                'res', 'res.candidacy_id', '=', 'c.id'
            )
            ->where('ce.organization_id', $orgId)
            ->select(
                'p.id', 'p.full_name as name',
                DB::raw("COALESCE(o.name, corp.name) as cargo"),
                'g.canonical_name as municipio', 'g.id as municipio_id',
                'ev.name as evento', 'c.outcome',
                DB::raw('COALESCE(res.votos, 0) as votos')
            )
            ->orderByDesc('res.votos')
            ->limit(50)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'cargo' => $r->cargo,
                'municipio' => $r->municipio,
                'municipioId' => $r->municipio_id,
                'evento' => $r->evento,
                'electo' => $r->outcome === 'elected',
                'votos' => (int) $r->votos,
            ])
            ->toArray();
    }

    private function getMunicipios(string $orgId): array
    {
        return DB::table('candidacy_endorsements as ce')
            ->join('candidacies as c', 'ce.candidacy_id', '=', 'c.id')
            ->join('contests as con', 'c.contest_id', '=', 'con.id')
            ->join('geographic_units as g', 'con.geographic_unit_id', '=', 'g.id')
            ->leftJoinSub(
                DB::table('electoral_results')->whereIn('metric_type', ['votes','nominal_votes'])
                    ->select('candidacy_id', DB::raw('SUM(value) as votos'))->groupBy('candidacy_id'),
                'res', 'res.candidacy_id', '=', 'c.id'
            )
            ->where('ce.organization_id', $orgId)
            ->where('g.type', 'municipality')
            ->select('g.id', 'g.canonical_name as name', DB::raw('SUM(COALESCE(res.votos,0)) as total'),
                DB::raw('COUNT(DISTINCT c.id) as candidaturas'))
            ->groupBy('g.id', 'g.canonical_name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['id'=>$r->id, 'name'=>$r->name, 'total'=>(int)$r->total, 'candidaturas'=>(int)$r->candidaturas])
            ->toArray();
    }

    private function getAliases(string $orgId): array
    {
        return DB::table('organization_aliases')
            ->where('organization_id', $orgId)
            ->select('raw_alias', 'normalized_alias')
            ->get()
            ->map(fn ($r) => $r->raw_alias)
            ->toArray();
    }
}
