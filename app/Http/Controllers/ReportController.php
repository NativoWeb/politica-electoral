<?php

namespace App\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function persona(string $id)
    {
        $persona = DB::table('persons')->where('id', $id)->first();
        abort_if(!$persona, 404);

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
                DB::table('electoral_results')->whereIn('metric_type', ['votes', 'nominal_votes'])
                    ->select('candidacy_id', DB::raw('SUM(value) as votos'))->groupBy('candidacy_id'),
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
            ->get();

        $cargos = DB::table('office_tenures as ot')
            ->leftJoin('offices as o', 'ot.office_id', '=', 'o.id')
            ->leftJoin('corporations as corp', 'ot.corporation_id', '=', 'corp.id')
            ->leftJoin('geographic_units as g', 'ot.geographic_unit_id', '=', 'g.id')
            ->where('ot.person_id', $id)
            ->select(
                DB::raw("COALESCE(o.name, corp.name) as cargo"),
                'g.canonical_name as municipio',
                'ot.start_date',
                'ot.end_date',
                'ot.status'
            )
            ->orderByDesc('ot.start_date')
            ->get();

        $pdf = Pdf::loadView('reports.persona', compact('persona', 'candidaturas', 'cargos'))
            ->setPaper('letter', 'portrait');

        return $pdf->download("perfil-{$persona->full_name}.pdf");
    }

    public function municipio(string $id)
    {
        $municipio = DB::table('geographic_units as g')
            ->leftJoin('geographic_units as prov', 'g.parent_id', '=', 'prov.id')
            ->where('g.id', $id)
            ->where('g.type', 'municipality')
            ->select('g.id', 'g.canonical_name as name', 'prov.canonical_name as provincia')
            ->first();
        abort_if(!$municipio, 404);

        $officeId = DB::table('offices')->where('name', 'Alcaldía')->value('id');

        $candidatos = DB::table('candidacies as c')
            ->join('persons as p', 'c.person_id', '=', 'p.id')
            ->join('contests as con', 'c.contest_id', '=', 'con.id')
            ->leftJoin('candidacy_endorsements as ce', function ($j) {
                $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true);
            })
            ->leftJoin('political_organizations as po', 'ce.organization_id', '=', 'po.id')
            ->leftJoinSub(
                DB::table('electoral_results')->whereIn('metric_type', ['votes', 'nominal_votes'])
                    ->select('candidacy_id', DB::raw('SUM(value) as votos'))->groupBy('candidacy_id'),
                'res', 'res.candidacy_id', '=', 'c.id'
            )
            ->where('con.office_id', $officeId)
            ->where('con.geographic_unit_id', $id)
            ->select(
                'p.full_name as name',
                'c.outcome',
                'po.canonical_name as partido',
                DB::raw('COALESCE(res.votos, 0) as votos')
            )
            ->orderByDesc('res.votos')
            ->get();

        $corpId = DB::table('corporations')->where('name', 'Concejo')->value('id');

        $concejales = DB::table('candidacies as c')
            ->join('persons as p', 'c.person_id', '=', 'p.id')
            ->join('contests as con', 'c.contest_id', '=', 'con.id')
            ->leftJoin('candidacy_endorsements as ce', function ($j) {
                $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true);
            })
            ->leftJoin('political_organizations as po', 'ce.organization_id', '=', 'po.id')
            ->leftJoinSub(
                DB::table('electoral_results')->whereIn('metric_type', ['votes', 'nominal_votes'])
                    ->select('candidacy_id', DB::raw('SUM(value) as votos'))->groupBy('candidacy_id'),
                'res', 'res.candidacy_id', '=', 'c.id'
            )
            ->where('con.corporation_id', $corpId)
            ->where('con.geographic_unit_id', $id)
            ->where('c.outcome', 'elected')
            ->select(
                'p.full_name as name',
                'po.canonical_name as partido',
                DB::raw('COALESCE(res.votos, 0) as votos')
            )
            ->orderByDesc('res.votos')
            ->get();

        $pdf = Pdf::loadView('reports.municipio', compact('municipio', 'candidatos', 'concejales'))
            ->setPaper('letter', 'portrait');

        return $pdf->download("municipio-{$municipio->name}.pdf");
    }
}
