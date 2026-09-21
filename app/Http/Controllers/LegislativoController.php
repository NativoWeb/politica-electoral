<?php

namespace App\Http\Controllers;

use App\Services\CachedQueries;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class LegislativoController extends Controller
{
    public function senado()
    {
        return $this->renderLegislativo('Senado', 'Senado', null);
    }

    public function senadoMunicipio(string $munId)
    {
        return $this->renderLegislativo('Senado', 'Senado', $munId);
    }

    public function camara()
    {
        return $this->renderLegislativo('Camara', 'Cámara de Representantes', null);
    }

    public function camaraMunicipio(string $munId)
    {
        return $this->renderLegislativo('Camara', 'Cámara de Representantes', $munId);
    }

    private function renderLegislativo(string $view, string $corpName, ?string $munId)
    {
        $municipio = null;
        if ($munId) {
            $municipio = DB::table('geographic_units as g')
                ->leftJoin('geographic_units as prov', 'g.parent_id', '=', 'prov.id')
                ->where('g.id', $munId)->select('g.id', 'g.canonical_name as name', 'prov.canonical_name as provincia')->first();
            abort_if(!$municipio, 404);
        }

        $corpId = DB::table('corporations')->where('name', $corpName)->value('id');

        // Candidatos nominales — usar cache para datos generales
        $resultados = CachedQueries::results($corpName);

        // Si hay municipio, recalcular con votos filtrados
        if ($munId) {
            $resultados = DB::table('candidacies as c')
                ->join('persons as p', 'c.person_id', '=', 'p.id')
                ->join('contests as con', 'c.contest_id', '=', 'con.id')
                ->where('con.corporation_id', $corpId)
                ->leftJoin('candidacy_endorsements as ce', fn ($j) => $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true))
                ->leftJoin('political_organizations as po', 'ce.organization_id', '=', 'po.id')
                ->joinSub(
                    DB::table('electoral_results')->whereIn('metric_type', ['votes', 'nominal_votes'])
                        ->where('geographic_unit_id', $munId)->where('value', '>', 0)
                        ->select('candidacy_id', DB::raw('SUM(value) as votos'))->groupBy('candidacy_id'),
                    'res', 'res.candidacy_id', '=', 'c.id'
                )
                ->select('p.id', 'p.full_name as name', 'po.canonical_name as partido', 'po.acronym', 'po.color_hex',
                    DB::raw('res.votos'))
                ->orderByDesc('res.votos')
                ->limit(30)
                ->get()
                ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'partido' => $r->partido, 'acronym' => $r->acronym, 'color' => $r->color_hex, 'votos' => (int) $r->votos])
                ->toArray();
        }

        // Votos de lista por partido
        $listaQuery = DB::table('electoral_results as er')
            ->join('contests as con', 'er.contest_id', '=', 'con.id')
            ->join('political_organizations as po', 'er.organization_id', '=', 'po.id')
            ->where('con.corporation_id', $corpId)
            ->where('er.metric_type', 'votes');
        if ($munId) $listaQuery->where('er.geographic_unit_id', $munId);

        $votosLista = $listaQuery
            ->select('po.id', 'po.canonical_name as name', 'po.acronym', 'po.color_hex', DB::raw('SUM(er.value) as total'))
            ->groupBy('po.id', 'po.canonical_name', 'po.acronym', 'po.color_hex')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'acronym' => $r->acronym, 'color' => $r->color_hex, 'total' => (int) $r->total])
            ->toArray();

        // Fallback if no list votes with candidacy_id null
        if (empty($votosLista)) {
            $fallback = DB::table('electoral_results as er')
                ->join('contests as con', 'er.contest_id', '=', 'con.id')
                ->join('political_organizations as po', 'er.organization_id', '=', 'po.id')
                ->where('con.corporation_id', $corpId)
                ->where('er.metric_type', 'votes');
            if ($munId) $fallback->where('er.geographic_unit_id', $munId);
            $votosLista = $fallback->select('po.id', 'po.canonical_name as name', 'po.acronym', 'po.color_hex', DB::raw('SUM(er.value) as total'))
                ->groupBy('po.id', 'po.canonical_name', 'po.acronym', 'po.color_hex')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'acronym' => $r->acronym, 'color' => $r->color_hex, 'total' => (int) $r->total])
                ->toArray();
        }

        return Inertia::render($view, [
            'municipio' => $municipio,
            'resultados' => $resultados,
            'porPartido' => CachedQueries::partyTotals($corpName),
            'votosLista' => $votosLista,
            'allMunicipios' => CachedQueries::allMunicipios(),
        ]);
    }

    public function concejo()
    {
        // Redirect to first municipality — like Registraduría
        $firstMunicipio = DB::table('geographic_units')
            ->where('type', 'municipality')
            ->orderBy('canonical_name')
            ->value('id');

        if ($firstMunicipio) {
            return redirect("/concejo/{$firstMunicipio}");
        }

        return Inertia::render('Concejo', ['municipio' => null, 'concejales' => [], 'allMunicipios' => []]);
    }

    public function concejoMunicipio(string $munId)
    {
        $municipio = DB::table('geographic_units as g')
            ->leftJoin('geographic_units as prov', 'g.parent_id', '=', 'prov.id')
            ->where('g.id', $munId)
            ->select('g.id', 'g.canonical_name as name', 'prov.canonical_name as provincia')
            ->first();

        abort_if(!$municipio, 404);

        $corpId = DB::table('corporations')->where('name', 'Concejo')->value('id');
        $contest = DB::table('contests')
            ->where('corporation_id', $corpId)
            ->where('geographic_unit_id', $munId)
            ->first();

        $concejales = [];
        $totalVotos = 0;

        if ($contest) {
            $concejales = DB::table('candidacies as c')
                ->join('persons as p', 'c.person_id', '=', 'p.id')
                ->where('c.contest_id', $contest->id)
                ->leftJoin('candidacy_endorsements as ce', function ($j) {
                    $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true);
                })
                ->leftJoin('political_organizations as po', 'ce.organization_id', '=', 'po.id')
                ->leftJoinSub(
                    DB::table('electoral_results')->whereIn('metric_type', ['votes', 'nominal_votes'])
                        ->select('candidacy_id', DB::raw('SUM(value) as votos'))->groupBy('candidacy_id'),
                    'res', 'res.candidacy_id', '=', 'c.id'
                )
                ->select('p.id', 'p.full_name as name', 'po.canonical_name as partido', 'po.acronym',
                    'po.color_hex', 'c.outcome', 'c.list_position',
                    DB::raw('COALESCE(res.votos, 0) as votos'))
                ->orderByDesc('res.votos')
                ->get()
                ->map(fn ($r) => [
                    'id' => $r->id, 'name' => $r->name, 'partido' => $r->partido,
                    'acronym' => $r->acronym, 'color' => $r->color_hex,
                    'votos' => (int) $r->votos, 'outcome' => $r->outcome,
                    'listPosition' => $r->list_position,
                ])
                ->toArray();

            $totalVotos = collect($concejales)->sum('votos');
        }

        $allMunicipios = DB::table('geographic_units as g')
            ->leftJoin('geographic_units as prov', 'g.parent_id', '=', 'prov.id')
            ->where('g.type', 'municipality')
            ->select('g.id', 'g.canonical_name as name', 'prov.canonical_name as provincia')
            ->orderBy('g.canonical_name')
            ->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'provincia' => $r->provincia])
            ->toArray();

        return Inertia::render('Concejo', [
            'municipio' => $municipio,
            'concejales' => $concejales,
            'totalVotos' => $totalVotos,
            'seats' => $contest->seats ?? 0,
            'allMunicipios' => $allMunicipios,
        ]);
    }

    public function gobernador()
    {
        return $this->gobernadorMunicipio(null);
    }

    public function gobernadorMunicipio(?string $munId = null)
    {
        $municipio = null;
        if ($munId) {
            $municipio = DB::table('geographic_units as g')
                ->leftJoin('geographic_units as prov', 'g.parent_id', '=', 'prov.id')
                ->where('g.id', $munId)->select('g.id', 'g.canonical_name as name', 'prov.canonical_name as provincia')->first();
            abort_if(!$municipio, 404);
        }

        $officeId = DB::table('offices')->where('name', 'Gobernación')->value('id');

        // Gobernador has one contest for whole department — get results filtered by municipio geographic_unit
        $candidatos = DB::table('candidacies as c')
            ->join('contests as con', 'c.contest_id', '=', 'con.id')
            ->join('persons as p', 'c.person_id', '=', 'p.id')
            ->where('con.office_id', $officeId)
            ->leftJoin('candidacy_endorsements as ce', fn ($j) => $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true))
            ->leftJoin('political_organizations as po', 'ce.organization_id', '=', 'po.id')
            ->leftJoinSub(
                DB::table('electoral_results')->whereIn('metric_type', ['votes', 'nominal_votes'])
                    ->select('candidacy_id', DB::raw('SUM(value) as votos'))->groupBy('candidacy_id'),
                'res', 'res.candidacy_id', '=', 'c.id'
            )
            ->select('p.id', 'p.full_name as name', 'po.canonical_name as partido', 'po.acronym', 'po.color_hex',
                'c.outcome', DB::raw('COALESCE(res.votos, 0) as votos'))
            ->orderByDesc('res.votos')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'partido' => $r->partido,
                'acronym' => $r->acronym, 'color' => $r->color_hex,
                'votos' => (int) $r->votos, 'electo' => $r->outcome === 'elected',
            ])->toArray();

        $allMunicipios = CachedQueries::allMunicipios();

        return Inertia::render('Gobernador', [
            'municipio' => $municipio,
            'candidatos' => $candidatos,
            'allMunicipios' => $allMunicipios,
        ]);
    }

    public function asamblea()
    {
        return $this->asambleaMunicipio(null);
    }

    public function asambleaMunicipio(?string $munId = null)
    {
        $municipio = null;
        if ($munId) {
            $municipio = DB::table('geographic_units as g')
                ->leftJoin('geographic_units as prov', 'g.parent_id', '=', 'prov.id')
                ->where('g.id', $munId)->select('g.id', 'g.canonical_name as name', 'prov.canonical_name as provincia')->first();
            abort_if(!$municipio, 404);
        }

        $allMunicipios = CachedQueries::allMunicipios();

        return Inertia::render('Asamblea', [
            'municipio' => $municipio,
            'resultados' => $this->getResults('Asamblea'),
            'porPartido' => $this->getPartyTotals('Asamblea'),
            'allMunicipios' => $allMunicipios,
        ]);
    }

    public function consultas()
    {
        $contests = DB::table('contests as con')
            ->join('electoral_events as ev', 'con.electoral_event_id', '=', 'ev.id')
            ->where('ev.event_type', 'consultation')
            ->select('con.id', 'con.name')
            ->get();

        $resultados = [];
        foreach ($contests as $contest) {
            $candidatos = DB::table('candidacies as c')
                ->join('persons as p', 'c.person_id', '=', 'p.id')
                ->leftJoinSub(
                    DB::table('electoral_results')->where('metric_type', 'votes')
                        ->select('candidacy_id', DB::raw('SUM(value) as votos'))->groupBy('candidacy_id'),
                    'res', 'res.candidacy_id', '=', 'c.id'
                )
                ->where('c.contest_id', $contest->id)
                ->select('p.id', 'p.full_name as name', DB::raw('COALESCE(res.votos, 0) as votos'))
                ->orderByDesc('res.votos')
                ->get()
                ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'votos' => (int) $r->votos])
                ->toArray();

            $resultados[] = [
                'id' => $contest->id,
                'name' => $contest->name,
                'candidatos' => $candidatos,
            ];
        }

        return Inertia::render('Consultas', ['resultados' => $resultados]);
    }

    private function getResults(string $corporationName): array
    {
        $corpId = DB::table('corporations')->where('name', $corporationName)->value('id');
        if (!$corpId) return [];

        return DB::table('candidacies as c')
            ->join('persons as p', 'c.person_id', '=', 'p.id')
            ->join('contests as con', 'c.contest_id', '=', 'con.id')
            ->where('con.corporation_id', $corpId)
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
            ->select(
                'p.id',
                'p.full_name as name',
                'po.canonical_name as partido',
                'po.acronym',
                'po.color_hex',
                DB::raw('COALESCE(res.votos, 0) as votos')
            )
            ->orderByDesc('res.votos')
            ->limit(30)
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

    private function getPartyTotals(string $corporationName): array
    {
        $corpId = DB::table('corporations')->where('name', $corporationName)->value('id');
        if (!$corpId) return [];

        // Try direct organization_id first, fallback to endorsements
        $direct = DB::table('electoral_results as er')
            ->join('contests as con', 'er.contest_id', '=', 'con.id')
            ->where('con.corporation_id', $corpId)
            ->where('er.metric_type', 'votes')
            ->whereNotNull('er.organization_id')
            ->count();

        if ($direct > 0) {
            return DB::table('electoral_results as er')
                ->join('contests as con', 'er.contest_id', '=', 'con.id')
                ->where('con.corporation_id', $corpId)
                ->where('er.metric_type', 'votes')
                ->whereNotNull('er.organization_id')
                ->join('political_organizations as po', 'er.organization_id', '=', 'po.id')
                ->select('po.id', 'po.canonical_name as name', 'po.acronym', 'po.color_hex', DB::raw('SUM(er.value) as total'))
                ->groupBy('po.id', 'po.canonical_name', 'po.acronym', 'po.color_hex')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'acronym' => $r->acronym, 'color' => $r->color_hex, 'total' => (int) $r->total])
                ->toArray();
        }

        // Fallback: join through candidacy_endorsements
        return DB::table('electoral_results as er')
            ->join('candidacies as c', 'er.candidacy_id', '=', 'c.id')
            ->join('contests as con', 'er.contest_id', '=', 'con.id')
            ->join('candidacy_endorsements as ce', fn ($j) => $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true))
            ->join('political_organizations as po', 'ce.organization_id', '=', 'po.id')
            ->where('con.corporation_id', $corpId)
            ->whereIn('er.metric_type', ['votes', 'nominal_votes'])
            ->select('po.id', 'po.canonical_name as name', 'po.acronym', 'po.color_hex', DB::raw('SUM(er.value) as total'))
            ->groupBy('po.id', 'po.canonical_name', 'po.acronym', 'po.color_hex')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'acronym' => $r->acronym, 'color' => $r->color_hex, 'total' => (int) $r->total])
            ->toArray();
    }
}
