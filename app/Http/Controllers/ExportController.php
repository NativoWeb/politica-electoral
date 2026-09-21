<?php

namespace App\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\GenericExport;

class ExportController extends Controller
{
    public function excel(Request $request)
    {
        $data = $this->getData($request);
        $title = $request->input('title', 'Exportacion');
        $columns = $request->input('columns', 'nombre,municipio,tipo,cargo,partido,telefono,votos');

        return Excel::download(
            new GenericExport($data, explode(',', $columns), $title),
            str_replace(' ', '_', $title) . '.xlsx'
        );
    }

    public function pdf(Request $request)
    {
        $data = $this->getData($request);
        $title = $request->input('title', 'Exportacion');
        $columns = explode(',', $request->input('columns', 'nombre,municipio,tipo,cargo,partido,telefono,votos'));

        $pdf = Pdf::loadView('exports.table', [
            'data' => $data,
            'columns' => $columns,
            'title' => $title,
            'date' => now()->format('d/m/Y H:i'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download(str_replace(' ', '_', $title) . '.pdf');
    }

    private function getData(Request $request): array
    {
        $source = $request->input('source', 'mapa-politico');
        $munId = $request->input('municipio');
        $tipo = $request->input('tipo', 'todos');
        $cargo = $request->input('cargo');
        $search = $request->input('search');
        $partido = $request->input('partido');
        $barrio = $request->input('barrio');

        if ($source === 'mapa-politico') {
            return $this->getMapaPoliticoData($munId, $tipo, $cargo, $search, $partido, $barrio);
        }

        if ($source === 'senado' || $source === 'camara') {
            $corpName = $source === 'senado' ? 'Senado' : 'Cámara de Representantes';
            return $this->getCorporacionData($corpName, $munId);
        }

        if ($source === 'gobernador') {
            return $this->getGobernadorData();
        }

        if ($source === 'asamblea') {
            return $this->getCorporacionData('Asamblea', $munId);
        }

        return [];
    }

    private function getMapaPoliticoData(?string $munId, string $tipo, ?string $cargo, ?string $search, ?string $partido, ?string $barrio): array
    {
        $cargos = $cargo ? explode(',', $cargo) : [];
        $data = [];

        $votesSubQuery = DB::table('electoral_results')
            ->whereIn('metric_type', ['votes', 'nominal_votes'])
            ->select('candidacy_id', DB::raw('SUM(value) as votos'))
            ->groupBy('candidacy_id');

        // Alcaldía
        if ($tipo === 'todos' || $tipo === 'alcaldia') {
            $officeId = DB::table('offices')->where('name', 'Alcaldía')->value('id');
            $query = DB::table('candidacies as c')
                ->join('contests as con', 'c.contest_id', '=', 'con.id')
                ->join('persons as p', 'c.person_id', '=', 'p.id')
                ->where('con.office_id', $officeId)
                ->leftJoin('candidacy_endorsements as ce', fn ($j) => $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true))
                ->leftJoin('political_organizations as po', 'ce.organization_id', '=', 'po.id')
                ->leftJoin('geographic_units as g', 'con.geographic_unit_id', '=', 'g.id')
                ->leftJoinSub(clone $votesSubQuery, 'res', 'res.candidacy_id', '=', 'c.id')
                ->select('p.full_name as nombre', 'g.canonical_name as municipio',
                    DB::raw("'Alcaldía' as tipo"),
                    DB::raw("CASE WHEN c.outcome = 'elected' THEN 'Alcalde Electo' ELSE 'Candidato' END as cargo"),
                    'po.canonical_name as partido', DB::raw('NULL as telefono'),
                    DB::raw('COALESCE(res.votos, 0) as votos'));
            if ($munId) $query->where('con.geographic_unit_id', $munId);
            if ($search) $query->where('p.full_name', 'ilike', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%');
            $data = array_merge($data, $query->orderByDesc('res.votos')->get()->map(fn ($r) => (array) $r)->toArray());
        }

        // Concejo
        if ($tipo === 'todos' || $tipo === 'concejo') {
            $corpId = DB::table('corporations')->where('name', 'Concejo')->value('id');
            $query = DB::table('candidacies as c')
                ->join('contests as con', 'c.contest_id', '=', 'con.id')
                ->join('persons as p', 'c.person_id', '=', 'p.id')
                ->where('con.corporation_id', $corpId)
                ->leftJoin('candidacy_endorsements as ce', fn ($j) => $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true))
                ->leftJoin('political_organizations as po', 'ce.organization_id', '=', 'po.id')
                ->leftJoin('geographic_units as g', 'con.geographic_unit_id', '=', 'g.id')
                ->leftJoinSub(clone $votesSubQuery, 'res', 'res.candidacy_id', '=', 'c.id')
                ->select('p.full_name as nombre', 'g.canonical_name as municipio',
                    DB::raw("'Concejo' as tipo"),
                    DB::raw("CASE WHEN c.outcome = 'elected' THEN 'Concejal Electo' ELSE 'Candidato Concejo' END as cargo"),
                    'po.canonical_name as partido', DB::raw('NULL as telefono'),
                    DB::raw('COALESCE(res.votos, 0) as votos'));
            if ($munId) $query->where('con.geographic_unit_id', $munId);
            if ($search) $query->where('p.full_name', 'ilike', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%');
            if (!empty($cargos)) $query->whereIn(DB::raw("CASE WHEN c.outcome = 'elected' THEN 'Concejal Electo' ELSE 'Candidato Concejo' END"), $cargos);
            $data = array_merge($data, $query->orderByDesc('res.votos')->get()->map(fn ($r) => (array) $r)->toArray());
        }

        // Líderes
        if ($tipo === 'todos' || $tipo === 'lideres') {
            $query = DB::table('lideres')
                ->select('nombre', 'municipio', DB::raw("'Líderes' as tipo"), 'cargo', 'partido', 'telefono', DB::raw('0 as votos'));
            if ($munId) $query->where('geographic_unit_id', $munId);
            if ($search) $query->where('nombre', 'ilike', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%');
            if (!empty($cargos)) $query->whereIn('cargo', $cargos);
            if ($barrio) $query->where('barrio', 'ilike', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $barrio) . '%');
            $data = array_merge($data, $query->orderBy('nombre')->get()->map(fn ($r) => (array) $r)->toArray());
        }

        // Senado / Cámara / Asamblea
        $corpMap = ['senado' => 'Senado', 'camara' => 'Cámara de Representantes', 'asamblea' => 'Asamblea'];
        foreach ($corpMap as $tipoKey => $corpName) {
            if ($tipo === $tipoKey || $tipo === 'todos') {
                $data = array_merge($data, $this->getCorporacionData($corpName, $munId));
            }
        }

        // Filtrar por partido
        if ($partido) {
            $data = array_values(array_filter($data, fn ($row) => ($row['partido'] ?? '') === $partido));
        }

        return $data;
    }

    private function getCorporacionData(string $corpName, ?string $munId): array
    {
        $corpId = DB::table('corporations')->where('name', $corpName)->value('id');
        if (!$corpId) return [];

        $resQuery = DB::table('electoral_results')->whereIn('metric_type', ['votes', 'nominal_votes'])->where('value', '>', 0);
        if ($munId) $resQuery->where('geographic_unit_id', $munId);

        return DB::table('candidacies as c')
            ->join('persons as p', 'c.person_id', '=', 'p.id')
            ->join('contests as con', 'c.contest_id', '=', 'con.id')
            ->where('con.corporation_id', $corpId)
            ->leftJoin('candidacy_endorsements as ce', fn ($j) => $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true))
            ->leftJoin('political_organizations as po', 'ce.organization_id', '=', 'po.id')
            ->joinSub($resQuery->select('candidacy_id', DB::raw('SUM(value) as votos'))->groupBy('candidacy_id'), 'res', 'res.candidacy_id', '=', 'c.id')
            ->select('p.full_name as nombre', DB::raw('NULL as municipio'), 'po.canonical_name as partido', DB::raw('NULL as telefono'), DB::raw('res.votos'))
            ->selectRaw('? as tipo', [$corpName])
            ->selectRaw('? as cargo', [$corpName])
            ->orderByDesc('res.votos')->limit(30)->get()->map(fn ($r) => (array) $r)->toArray();
    }

    private function getGobernadorData(): array
    {
        $officeId = DB::table('offices')->where('name', 'Gobernación')->value('id');
        if (!$officeId) return [];

        return DB::table('candidacies as c')
            ->join('contests as con', 'c.contest_id', '=', 'con.id')
            ->join('persons as p', 'c.person_id', '=', 'p.id')
            ->where('con.office_id', $officeId)
            ->leftJoin('candidacy_endorsements as ce', fn ($j) => $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true))
            ->leftJoin('political_organizations as po', 'ce.organization_id', '=', 'po.id')
            ->leftJoinSub(DB::table('electoral_results')->whereIn('metric_type', ['votes', 'nominal_votes'])->select('candidacy_id', DB::raw('SUM(value) as votos'))->groupBy('candidacy_id'), 'res', 'res.candidacy_id', '=', 'c.id')
            ->select('p.full_name as nombre', DB::raw("'Santander' as municipio"), DB::raw("'Gobernación' as tipo"), DB::raw("CASE WHEN c.outcome = 'elected' THEN 'Gobernador Electo' ELSE 'Candidato' END as cargo"), 'po.canonical_name as partido', DB::raw('NULL as telefono'), DB::raw('COALESCE(res.votos, 0) as votos'))
            ->orderByDesc('res.votos')->get()->map(fn ($r) => (array) $r)->toArray();
    }
}
