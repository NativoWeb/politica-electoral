<?php

namespace App\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\GenericExport;
use App\Http\Controllers\Concerns\ScopesTerritory;

class ExportController extends Controller
{
    use ScopesTerritory;

    private const PDF_MAX_ROWS = 500;

    // Mapa Politico rows (leaders, candidates 2027) carry key notes in observacion.
    private function defaultColumns(Request $request): string
    {
        $columns = 'nombre,municipio,tipo,cargo,partido,telefono,votos';

        return $request->input('source', 'mapa-politico') === 'mapa-politico' ? $columns.',observacion' : $columns;
    }

    public function excel(Request $request)
    {
        $data = $this->getData($request);
        $title = $request->input('title', 'Exportacion');
        $columns = $request->input('columns', $this->defaultColumns($request));

        return Excel::download(
            new GenericExport($data, explode(',', $columns), $title),
            str_replace(' ', '_', $title) . '.xlsx'
        );
    }

    public function pdf(Request $request)
    {
        ini_set('memory_limit', '256M');
        set_time_limit(120);

        $data = $this->getData($request);
        $title = $request->input('title', 'Exportacion');
        $columns = explode(',', $request->input('columns', $this->defaultColumns($request)));

        $totalCount = count($data);
        $truncated = false;
        if ($totalCount > self::PDF_MAX_ROWS) {
            $data = array_slice($data, 0, self::PDF_MAX_ROWS);
            $truncated = true;
        }

        $pdf = Pdf::loadView('exports.table', [
            'data' => $data,
            'columns' => $columns,
            'title' => $title,
            'date' => now()->format('d/m/Y H:i'),
            'truncated' => $truncated,
            'totalCount' => $totalCount,
        ])->setPaper('a4', 'landscape')
          ->setOption('isRemoteEnabled', false)
          ->setOption('isPhpEnabled', false);

        return $pdf->download(str_replace(' ', '_', $title) . '.pdf');
    }

    private function getData(Request $request): array
    {
        $source = $request->input('source', 'mapa-politico');
        $munId = $request->input('municipio');
        abort_if($munId !== null && ! is_string($munId), 422);
        if ($munId) {
            $this->authorizeGeo($munId);
        }

        if ($source === 'mapa-politico') {
            // The listing's rows for the same filters (municipio, campaign, tipos...), already unified, without its display caps.
            $rows = app(MapaPoliticoController::class)->rows($request, forExport: true)['data'];

            return $this->withLiderObservations(array_map(fn ($r) => $r + ['tipo' => $r['tipo_registro'] ?? null], $rows));
        }

        if ($source === 'senado' || $source === 'camara') {
            $corpName = $source === 'senado' ? 'Senado' : 'Cámara de Representantes';
            return $this->getCorporacionData($corpName, $munId);
        }

        if ($source === 'gobernador') {
            abort_unless($request->user()->hasPermission('view.gobernador'), 403);

            return $this->getGobernadorData();
        }

        if ($source === 'asamblea') {
            return $this->getCorporacionData('Asamblea', $munId);
        }

        return [];
    }

    // Electoral candidates have no notes of their own: take them from their lider record (same name and municipio).
    private function withLiderObservations(array $data): array
    {
        // "Malaga" and "Málaga" are the same municipio.
        $key = fn ($nombre, $municipio) => mb_strtolower(trim((string) $nombre)).'|'.Str::lower(Str::ascii(trim((string) $municipio)));
        $missing = array_filter($data, fn ($r) => ! array_key_exists('observacion', $r) && ! empty($r['nombre']));
        if (! $missing) {
            return $data;
        }

        // Only campaigns the user can see; when a subcampaign has a namesake, the own campaign's record decides.
        $own = $this->campaign()->ownId();
        $notes = $this->scopeToTerritory($this->visibleLideres())
            ->whereIn(DB::raw('LOWER(nombre)'), array_values(array_unique(array_map(fn ($r) => mb_strtolower(trim($r['nombre'])), $missing))))
            ->get(['nombre', 'municipio', 'observacion', 'campaign_id'])
            ->groupBy(fn ($l) => $key($l->nombre, $l->municipio))
            ->map(fn ($group) => $group->count() > 1 && $own !== null ? $group->where('campaign_id', $own) : $group)
            ->filter(fn ($group) => $group->count() === 1)
            ->map(fn ($group) => $group->first()->observacion)
            ->filter(fn ($obs) => $obs !== null && $obs !== '');

        return array_map(function ($r) use ($notes, $key) {
            if (! array_key_exists('observacion', $r)) {
                $r['observacion'] = $notes[$key($r['nombre'] ?? '', $r['municipio'] ?? '')] ?? null;
            }

            return $r;
        }, $data);
    }

    private function getCorporacionData(string $corpName, ?string $munId): array
    {
        $corpId = DB::table('corporations')->where('name', $corpName)->value('id');
        if (!$corpId) return [];

        $resQuery = DB::table('electoral_results')->whereIn('metric_type', ['votes', 'nominal_votes'])->where('value', '>', 0);
        if ($munId) $resQuery->where('geographic_unit_id', $munId); else $this->scopeToTerritory($resQuery);

        $query = DB::table('candidacies as c')
            ->join('persons as p', 'c.person_id', '=', 'p.id')
            ->join('contests as con', 'c.contest_id', '=', 'con.id')
            ->where('con.corporation_id', $corpId)
            ->leftJoin('candidacy_endorsements as ce', fn ($j) => $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true))
            ->leftJoin('political_organizations as po', 'ce.organization_id', '=', 'po.id')
            ->joinSub($resQuery->select('candidacy_id', DB::raw('SUM(value) as votos'))->groupBy('candidacy_id'), 'res', 'res.candidacy_id', '=', 'c.id')
            ->select('p.full_name as nombre', DB::raw('NULL as municipio'), 'po.canonical_name as partido', DB::raw('NULL as telefono'), DB::raw('res.votos'))
            ->selectRaw('? as tipo', [$corpName])
            ->selectRaw('? as cargo', [$corpName]);

        return $query->orderByDesc('res.votos')->limit(30)->get()->map(fn ($r) => (array) $r)->toArray();
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
