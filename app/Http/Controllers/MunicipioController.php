<?php

namespace App\Http\Controllers;

use App\Services\CachedQueries;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class MunicipioController extends Controller
{
    public function show(string $id)
    {
        $municipio = DB::table('geographic_units as g')
            ->leftJoin('geographic_units as prov', 'g.parent_id', '=', 'prov.id')
            ->where('g.id', $id)
            ->where('g.type', 'municipality')
            ->select('g.id', 'g.canonical_name as name', 'prov.canonical_name as provincia')
            ->first();

        abort_if(! $municipio, 404);

        $allMunicipios = CachedQueries::allMunicipios();

        $alcaldia = $this->getAlcaldia($id);
        $alcaldeElecto = collect($alcaldia)->firstWhere('electo', true);

        // Alcaldes electos del Centro Democrático en TODO Santander
        $cdId = DB::table('political_organizations')->where('canonical_name', 'Centro Democrático')->value('id');
        $alcaldiaOffice = DB::table('offices')->where('name', 'Alcaldía')->value('id');
        $candidatosCD = $cdId ? DB::table('candidacies as c')
            ->join('contests as con', 'c.contest_id', '=', 'con.id')
            ->join('persons as p', 'c.person_id', '=', 'p.id')
            ->join('geographic_units as g', 'con.geographic_unit_id', '=', 'g.id')
            ->join('candidacy_endorsements as ce', function ($j) {
                $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true);
            })
            ->leftJoinSub($this->voteSub(), 'res', 'c.id', '=', 'res.candidacy_id')
            ->where('con.office_id', $alcaldiaOffice)
            ->where('ce.organization_id', $cdId)
            ->where('c.outcome', 'elected')
            ->select('p.id', 'p.full_name as name', 'g.canonical_name as municipio', DB::raw('COALESCE(res.votos, 0) as votos'))
            ->orderBy('g.canonical_name')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'municipio' => $r->municipio,
                'votos' => (int) $r->votos,
            ])
            ->toArray() : [];

        return Inertia::render('Municipio', [
            'municipio'      => $municipio,
            'alcaldia'       => $alcaldia,
            'alcaldeElecto'  => $alcaldeElecto,
            'candidatosCD'   => $candidatosCD,
            'concejo'        => $this->getConcejo($id),
            'partidosPie'    => $this->getPartidosPie($id),
            'eventos'        => $this->getEventos($id),
            'allMunicipios'  => $allMunicipios,
        ]);
    }

    private function voteSub()
    {
        return DB::table('electoral_results')
            ->whereIn('metric_type', ['votes', 'nominal_votes'])
            ->select('candidacy_id', DB::raw('SUM(value) as votos'))
            ->groupBy('candidacy_id');
    }

    private function getAlcaldia(string $munId): array
    {
        $officeId = DB::table('offices')->where('name', 'Alcaldía')->value('id');
        if (! $officeId) return [];

        return DB::table('candidacies as c')
            ->join('persons as p', 'c.person_id', '=', 'p.id')
            ->join('contests as con', 'c.contest_id', '=', 'con.id')
            ->join('electoral_events as ev', 'con.electoral_event_id', '=', 'ev.id')
            ->leftJoin('candidacy_endorsements as ce', function ($j) {
                $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true);
            })
            ->leftJoin('political_organizations as po', 'ce.organization_id', '=', 'po.id')
            ->leftJoinSub($this->voteSub(), 'res', 'res.candidacy_id', '=', 'c.id')
            ->where('con.office_id', $officeId)
            ->where('con.geographic_unit_id', $munId)
            ->select(
                'c.id',
                'p.id as person_id',
                'p.full_name as name',
                'c.outcome',
                'po.canonical_name as partido',
                'po.acronym',
                'po.color_hex',
                'ce.endorsement_type as aval_tipo',
                'ev.name as evento',
                'ev.election_date',
                DB::raw('COALESCE(res.votos, 0) as votos')
            )
            ->orderByDesc('res.votos')
            ->get()
            ->map(fn ($r) => [
                'id'        => $r->id,
                'personId'  => $r->person_id,
                'name'      => $r->name,
                'electo'    => $r->outcome === 'elected',
                'partido'   => $r->partido,
                'acronym'   => $r->acronym,
                'color'     => $r->color_hex,
                'aval'      => $r->aval_tipo,
                'votos'     => (int) $r->votos,
                'evento'    => $r->evento,
                'fecha'     => $r->election_date,
            ])
            ->toArray();
    }

    private function getConcejo(string $munId): array
    {
        $corpId = DB::table('corporations')->where('name', 'Concejo')->value('id');
        if (! $corpId) return [];

        return DB::table('candidacies as c')
            ->join('persons as p', 'c.person_id', '=', 'p.id')
            ->join('contests as con', 'c.contest_id', '=', 'con.id')
            ->leftJoin('candidacy_endorsements as ce', function ($j) {
                $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true);
            })
            ->leftJoin('political_organizations as po', 'ce.organization_id', '=', 'po.id')
            ->leftJoinSub($this->voteSub(), 'res', 'res.candidacy_id', '=', 'c.id')
            ->where('con.corporation_id', $corpId)
            ->where('con.geographic_unit_id', $munId)
            ->where('c.outcome', 'elected')
            ->select(
                'p.id',
                'p.full_name as name',
                'po.canonical_name as partido',
                'po.acronym',
                'po.color_hex',
                DB::raw('COALESCE(res.votos, 0) as votos')
            )
            ->orderBy('po.canonical_name')
            ->orderByDesc('res.votos')
            ->get()
            ->map(fn ($r) => [
                'id'      => $r->id,
                'name'    => $r->name,
                'partido' => $r->partido,
                'acronym' => $r->acronym,
                'color'   => $r->color_hex,
                'votos'   => (int) $r->votos,
            ])
            ->toArray();
    }

    private function getPartidosPie(string $munId): array
    {
        $officeId = DB::table('offices')->where('name', 'Alcaldía')->value('id');

        return DB::table('electoral_results as er')
            ->join('contests as con', 'er.contest_id', '=', 'con.id')
            ->join('candidacies as c', 'er.candidacy_id', '=', 'c.id')
            ->leftJoin('candidacy_endorsements as ce', function ($j) {
                $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true);
            })
            ->leftJoin('political_organizations as po', 'ce.organization_id', '=', 'po.id')
            ->where('con.geographic_unit_id', $munId)
            ->where('con.office_id', $officeId)
            ->where('er.metric_type', 'votes')
            ->whereNotNull('po.id')
            ->select(
                'po.id',
                'po.canonical_name as name',
                'po.acronym',
                'po.color_hex',
                DB::raw('SUM(er.value) as total')
            )
            ->groupBy('po.id', 'po.canonical_name', 'po.acronym', 'po.color_hex')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'id'      => $r->id,
                'name'    => $r->name,
                'acronym' => $r->acronym,
                'color'   => $r->color_hex,
                'total'   => (int) $r->total,
            ])
            ->toArray();
    }

    private function getCandidatosPartido(string $munId, string $orgId): array
    {
        return DB::table('candidacies as c')
            ->join('contests as con', 'c.contest_id', '=', 'con.id')
            ->join('persons as p', 'c.person_id', '=', 'p.id')
            ->join('candidacy_endorsements as ce', function ($j) {
                $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true);
            })
            ->leftJoinSub($this->voteSub(), 'res', 'c.id', '=', 'res.candidacy_id')
            ->where('con.geographic_unit_id', $munId)
            ->where('ce.organization_id', $orgId)
            ->select('p.id', 'p.full_name as name', 'c.outcome', DB::raw('COALESCE(res.votos, 0) as votos'))
            ->orderByDesc('res.votos')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'outcome' => $r->outcome,
                'votos' => (int) $r->votos,
            ])
            ->toArray();
    }

    private function getEventos(string $munId): array
    {
        return DB::table('electoral_events as ev')
            ->join('contests as con', 'con.electoral_event_id', '=', 'ev.id')
            ->where('con.geographic_unit_id', $munId)
            ->select('ev.id', 'ev.name', 'ev.election_date', 'ev.event_type')
            ->distinct()
            ->orderByDesc('ev.election_date')
            ->get()
            ->map(fn ($e) => [
                'id'   => $e->id,
                'name' => $e->name,
                'date' => $e->election_date,
                'type' => $e->event_type,
            ])
            ->toArray();
    }
}
