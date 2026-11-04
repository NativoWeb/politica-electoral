<?php

namespace App\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\GenericExport;
use App\Exports\SheetsExport;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Services\GobernacionResultados;

class ExportController extends Controller
{
    use ScopesTerritory;

    private const PDF_MAX_ROWS = 500;

    // Mapa Politico rows (leaders, candidates 2027) carry key notes in observacion.
    private function defaultColumns(Request $request): string
    {
        $columns = 'nombre,municipio,tipo,cargo,partido,telefono,votos';

        return in_array($request->input('source', 'mapa-politico'), ['mapa-politico', 'votantes'], true) ? $columns.',observacion' : $columns;
    }

    public function excel(Request $request)
    {
        if ($request->input('source') === 'gobernador') {
            $report = $this->gobernadorReport($request);
            $resumen = array_map(fn ($r) => ['concepto' => $r[0], 'valor' => $r[1]], $report['resumen']);

            return Excel::download(new SheetsExport([
                new GenericExport($resumen, ['concepto', 'valor'], 'Resumen', ['concepto' => 'CONCEPTO', 'valor' => 'VALOR']),
                new GenericExport($report['data'], $report['columns'], 'Candidatos', ['porcentaje' => '% VÁLIDOS']),
            ]), $report['filename'].'.xlsx');
        }

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

        if ($request->input('source') === 'gobernador') {
            $report = $this->gobernadorReport($request);

            return Pdf::loadView('exports.table', [
                'data' => $report['data'],
                'columns' => $report['columns'],
                'title' => $report['title'],
                'date' => now()->format('d/m/Y H:i'),
                // The notice already heads the PDF; figures are aligned apart from text.
                'resumen' => array_values(array_map(
                    fn ($r) => [$r[0], is_int($r[1]) ? number_format($r[1], 0, ',', '.') : $r[1], is_int($r[1]) || str_ends_with((string) $r[1], '%')],
                    array_filter($report['resumen'], fn ($r) => $r[0] !== 'Aviso'),
                )),
                'aviso' => $report['aviso'],
            ])->setPaper('a4', 'landscape')
                ->setOption('isRemoteEnabled', false)
                ->setOption('isPhpEnabled', false)
                ->download($report['filename'].'.pdf');
        }

        // Votantes reads one row past the PDF cap instead of the whole listing, and counts the total apart.
        $data = $this->getData($request, self::PDF_MAX_ROWS + 1);
        $title = $request->input('title', 'Exportacion');
        $columns = explode(',', $request->input('columns', $this->defaultColumns($request)));

        $totalCount = $request->input('source') === 'votantes' && count($data) > self::PDF_MAX_ROWS
            ? app(VotanteController::class)->listing($request)->count()
            : count($data);
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

    private function getData(Request $request, ?int $votantesLimit = null): array
    {
        $source = $request->input('source', 'mapa-politico');

        // Votantes filters municipio by name and its listing is already scoped to the user's territory.
        if ($source === 'votantes') {
            abort_unless($request->user()->hasPermission('view.votantes'), 403);

            return $this->getVotantesData($request, $votantesLimit);
        }

        $munId = $request->input('municipio');
        abort_if($munId !== null && ! is_string($munId), 422);
        if ($munId) {
            $this->authorizeGeo($munId);
        }

        if ($source === 'mapa-politico') {
            abort_unless($request->user()->hasPermission('view.mapa'), 403);

            // The listing's rows for the same filters (municipio, campaign, tipos...), already unified, without its display caps.
            $rows = app(MapaPoliticoController::class)->rows($request, forExport: true)['data'];

            return $this->withLiderObservations(array_map(fn ($r) => $r + ['tipo' => $r['tipo_registro'] ?? null], $rows));
        }

        if ($source === 'senado' || $source === 'camara') {
            $corpName = $source === 'senado' ? 'Senado' : 'Cámara de Representantes';
            return $this->getCorporacionData($corpName, $munId);
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

    // Only the exported columns: select() drops the listing's referidos_count subquery.
    private function getVotantesData(Request $request, ?int $limit = null): array
    {
        return app(VotanteController::class)->listing($request)
            ->select(['lideres.nombre', 'lideres.municipio', 'lideres.cargo', 'lideres.partido', 'lideres.telefono', 'lideres.votos', 'lideres.observacion', 'lideres.cedula'])
            ->orderBy('lideres.nombre')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn ($l) => [
                'nombre' => $l->nombre,
                'municipio' => $l->municipio,
                'tipo' => 'Votante',
                'cargo' => $l->cargo,
                'partido' => $l->partido,
                'telefono' => $l->telefono,
                'votos' => $l->votos,
                'observacion' => $l->observacion,
                'cedula' => $l->cedula,
            ])->all();
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

    // The same figures /gobernador shows: candidates and blank vote with their % over valid votes, the turnout summary
    // and its source. A municipio has no Gobernación results: the file says so, as the screen does.
    private function gobernadorReport(Request $request): array
    {
        abort_unless($request->user()->hasPermission('view.gobernador'), 403);

        $munId = $request->input('municipio');
        abort_if($munId !== null && ! is_string($munId), 422);
        $resultados = app(GobernacionResultados::class);
        $municipio = null;
        if ($munId) {
            $this->authorizeGeo($munId);
            $municipio = $resultados->municipio($munId);
            abort_if(! $municipio, 404);
        }

        $officeId = $resultados->officeId();
        $candidatos = $officeId ? $resultados->candidatos($officeId, conVotos: ! $municipio) : [];
        $resumen = $officeId && ! $municipio ? $resultados->resumen($officeId) : null;
        $base = GobernacionResultados::base($candidatos, $resumen);
        $pct = fn (?int $votos) => ($p = GobernacionResultados::porcentaje($votos, $base)) === null ? null : $p.'%';

        $data = array_map(fn ($c) => [
            'nombre' => $c['name'],
            'partido' => $c['partido'],
            'cargo' => $c['electo'] ? 'Gobernador Electo' : 'Candidato',
            'votos' => $c['votos'],
            'porcentaje' => $pct($c['votos']),
        ], $candidatos);
        if ($resumen && $resumen['blanco'] !== null) {
            $data[] = ['nombre' => 'Voto en blanco', 'partido' => null, 'cargo' => null, 'votos' => $resumen['blanco'], 'porcentaje' => $pct($resumen['blanco'])];
        }

        $ambito = $municipio ? $municipio->name.($municipio->provincia ? " ({$municipio->provincia})" : '') : 'Santander (total departamental)';
        $filas = [['Ámbito', $ambito]];
        if ($municipio) {
            $filas[] = ['Aviso', GobernacionResultados::SIN_RESULTADOS_MUNICIPIO];
        } elseif ($resumen) {
            $filas = array_merge($filas, [
                ['Potencial electoral', $resumen['potencial']],
                ['Votantes', $resumen['votantes']],
                ['Votos válidos', $resumen['validos']],
                ['Voto en blanco', $resumen['blanco']],
                ['Votos nulos', $resumen['nulos']],
                ['No marcados', $resumen['noMarcados']],
                ['Participación', $resumen['participacion'] === null ? null : number_format($resumen['participacion'], 2, ',', '').'%'],
            ]);
            if ($resumen['fuente']) {
                $filas[] = ['Fuente', $resumen['fuente'].($resumen['fuenteUrl'] ? ' — '.$resumen['fuenteUrl'] : '')];
            }
        }

        $title = 'Gobernador '.($municipio->name ?? 'Santander');

        return [
            'title' => $title,
            'filename' => str_replace(' ', '_', Str::ascii($title)),
            'columns' => ['nombre', 'partido', 'cargo', 'votos', 'porcentaje'],
            'data' => $data,
            'resumen' => $filas,
            'aviso' => $municipio ? GobernacionResultados::SIN_RESULTADOS_MUNICIPIO : null,
        ];
    }
}
