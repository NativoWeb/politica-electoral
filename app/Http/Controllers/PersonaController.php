<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PersonaController extends Controller
{
    public function show(string $id)
    {
        $persona = DB::table('persons')->where('id', $id)->first();
        abort_if(!$persona, 404);

        return Inertia::render('Persona', [
            'persona' => [
                'id' => $persona->id,
                'name' => $persona->full_name,
                'firstName' => $persona->first_name,
                'lastName' => $persona->last_name,
                'status' => $persona->identity_status,
            ],
            'candidaturas' => $this->getCandidaturas($id),
            'cargos' => $this->getCargos($id),
        ]);
    }

    private function getCandidaturas(string $personId): array
    {
        return DB::table('candidacies as c')
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
            ->where('c.person_id', $personId)
            ->select(
                'c.id',
                'con.name as contienda',
                'ev.name as evento',
                'ev.election_date',
                DB::raw("COALESCE(o.name, corp.name) as cargo"),
                'g.canonical_name as municipio',
                'g.id as municipio_id',
                'c.outcome',
                'po.canonical_name as partido',
                'po.acronym',
                'po.color_hex',
                DB::raw('COALESCE(res.votos, 0) as votos')
            )
            ->orderByDesc('ev.election_date')
            ->get()
            ->map(fn ($r) => [
                'id'        => $r->id,
                'contienda' => $r->contienda,
                'evento'    => $r->evento,
                'fecha'     => $r->election_date,
                'cargo'     => $r->cargo,
                'municipio' => $r->municipio,
                'municipioId' => $r->municipio_id,
                'electo'    => $r->outcome === 'elected',
                'outcome'   => $r->outcome,
                'partido'   => $r->partido,
                'acronym'   => $r->acronym,
                'color'     => $r->color_hex,
                'votos'     => (int) $r->votos,
            ])
            ->toArray();
    }

    private function getCargos(string $personId): array
    {
        return DB::table('office_tenures as ot')
            ->leftJoin('offices as o', 'ot.office_id', '=', 'o.id')
            ->leftJoin('corporations as corp', 'ot.corporation_id', '=', 'corp.id')
            ->leftJoin('geographic_units as g', 'ot.geographic_unit_id', '=', 'g.id')
            ->where('ot.person_id', $personId)
            ->select(
                'ot.id',
                DB::raw("COALESCE(o.name, corp.name) as cargo"),
                'g.canonical_name as municipio',
                'g.id as municipio_id',
                'ot.start_date',
                'ot.end_date',
                'ot.status'
            )
            ->orderByDesc('ot.start_date')
            ->get()
            ->map(fn ($r) => [
                'id'        => $r->id,
                'cargo'     => $r->cargo,
                'municipio' => $r->municipio,
                'municipioId' => $r->municipio_id,
                'inicio'    => $r->start_date,
                'fin'       => $r->end_date,
                'status'    => $r->status,
            ])
            ->toArray();
    }
}
