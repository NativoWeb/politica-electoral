<?php

namespace App\Http\Controllers;

use App\Exports\GenericExport;
use App\Exports\SheetsExport;
use App\Exports\StreamedXlsx;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Services\Configuracion\Ajuste;
use App\Services\GobernacionResultados;
use App\Services\Reportes\DescargaGuard;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class ExportController extends Controller
{
    use ScopesTerritory;

    public const PDF_MAX_ROWS = 500;

    private const GOBERNADOR_COLUMNS = ['nombre', 'partido', 'cargo', 'votos', 'porcentaje'];

    // Votantes Excel: written row by row; past this many rows the file says it was cut (Bucaramanga alone has ~39.000).
    // Default of the ajuste votantes.export_max_filas.
    public const VOTANTES_EXCEL_MAX_ROWS = 50000;

    // Up to this many rows an Excel is built as always (PhpSpreadsheet); bigger ones are streamed (openspout).
    public static function streamAfterRows(): int
    {
        return (int) config('exports.stream_after_rows', 2000);
    }

    // Mapa Politico rows (leaders, candidates 2027) carry key notes in observacion.
    private function defaultColumns(Request $request): string
    {
        $columns = 'nombre,municipio,tipo,cargo,partido,telefono,votos';

        return in_array($request->input('source', 'mapa-politico'), ['mapa-politico', 'votantes'], true) ? $columns.',observacion' : $columns;
    }

    public function excel(Request $request)
    {
        $this->authorizeSource($request);

        return $this->guarded($request, 'excel', fn () => $this->buildExcel($request));
    }

    private function buildExcel(Request $request)
    {
        if ($request->input('source') === 'gobernador') {
            $report = $this->gobernadorReport($request);
            $resumen = array_map(fn ($r) => ['concepto' => $r[0], 'valor' => $r[1]], $report['resumen']);

            return Excel::download(new SheetsExport([
                new GenericExport($resumen, ['concepto', 'valor'], 'Resumen', ['concepto' => 'CONCEPTO', 'valor' => 'VALOR']),
                new GenericExport($report['data'], $report['columns'], 'Candidatos', ['porcentaje' => '% VÁLIDOS']),
            ]), $report['filename'].'.xlsx');
        }

        $title = $request->input('title', 'Exportacion');
        $columns = $this->columns($request);
        $filename = str_replace(' ', '_', $title).'.xlsx';
        $heading = $this->removedColumns($request) ? [DescargaGuard::AVISO_SIN_DATOS] : [];

        if ($request->input('source') === 'votantes') {
            abort_unless($request->user()->hasPermission('view.votantes'), 403);
            $total = app(VotanteController::class)->listing($request)->count();
            if ($total > self::streamAfterRows()) {
                $max = Ajuste::int('votantes.export_max_filas');
                $rows = $this->votantesQuery($request)->limit($max)->cursor()->map(fn ($l) => $this->votanteRow($l));

                return StreamedXlsx::download($rows, $columns, $title, $filename,
                    notice: $total > $max ? self::cutNotice($max, $total) : null, heading: $heading);
            }
        }

        [$data, $total] = $this->getDataWithTotal($request);
        if (count($data) > self::streamAfterRows() || $total > count($data)) {
            return StreamedXlsx::download($data, $columns, $title, $filename,
                notice: $total > count($data) ? self::cutNotice(count($data), $total) : null, heading: $heading);
        }

        // A file without the personal columns says so above its headings.
        return Excel::download(new GenericExport($data, $columns, $title, notice: $heading[0] ?? null), $filename);
    }

    // Gobernador, Senado, Cámara and Asamblea are only electoral results; the Mapa leaves them out by itself (rows()).
    // The screen's own permission and the municipio are checked here too, before DescargaGuard writes its audit row.
    private function authorizeSource(Request $request): void
    {
        $source = $request->input('source', 'mapa-politico');
        if (in_array($source, ['gobernador', 'senado', 'camara', 'asamblea'], true)) {
            $this->campaign()->abortUnlessSeesElectoralResults();
        }
        $permission = match ($source) {
            'votantes' => 'view.votantes',
            'mapa-politico' => 'view.mapa',
            'gobernador' => 'view.gobernador',
            default => null,
        };
        abort_if($permission && ! $request->user()->hasPermission($permission), 403);
        $munId = $request->input('municipio');
        abort_if($munId !== null && ! is_string($munId), 422);
        if ($munId && $source !== 'votantes') {
            $this->authorizeGeo($munId);
        }
    }

    // The requested columns without the personal ones when the user lacks export.datos_personales (H3).
    private function columns(Request $request): array
    {
        return DescargaGuard::columnasPermitidas($this->requestedColumns($request), $request->user())[0] ?: ['nombre'];
    }

    // Personal columns taken out of a file of people (Mapa político, Votantes): the file and the X-Columnas-Quitadas
    // header say so. Electoral results (Senado, Gobernador...) never had personal data to take out.
    private function removedColumns(Request $request): array
    {
        if (! in_array($request->input('source', 'mapa-politico'), ['mapa-politico', 'votantes'], true)) {
            return [];
        }

        return DescargaGuard::columnasPermitidas($this->requestedColumns($request), $request->user())[1];
    }

    private function requestedColumns(Request $request): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $request->input('columns', $this->defaultColumns($request))))));
    }

    // One download at a time, 5 per minute and 30 per hour, and an audit row before the file (DescargaGuard).
    private function guarded(Request $request, string $format, \Closure $build)
    {
        $source = (string) $request->input('source', 'mapa-politico');
        [$columns, $removed] = DescargaGuard::columnasPermitidas($this->requestedColumns($request), $request->user());

        $response = app(DescargaGuard::class)->descargar($request, "EXPORT exportar.{$source}.{$format}", fn () => [
            'metadata' => [
                'fuente' => $source,
                'formato' => $format,
                // The columns the file really has: Gobernador has its own, the rest falls back to the name as columns() does.
                'columnas' => $source === 'gobernador' ? self::GOBERNADOR_COLUMNS : ($columns ?: ['nombre']),
                'columnas_quitadas' => $removed,
                'filtros' => array_keys($request->except(['source', 'title', 'columns', 'search'])),
                'busqueda' => is_string($request->input('search')) ? $request->input('search') : null,
                'con_datos_personales' => (bool) array_intersect($columns, DescargaGuard::COLUMNAS_SENSIBLES),
            ],
            'generar' => $build,
        ]);
        if ($shown = $this->removedColumns($request)) {
            $response->headers->set('X-Columnas-Quitadas', implode(',', $shown));
        }

        return $response;
    }

    private static function cutNotice(int $shown, int $total): string
    {
        return 'Archivo recortado: '.number_format($shown, 0, ',', '.').' de '.number_format($total, 0, ',', '.')
            .' registros. Filtra por municipio, tipo o barrio para exportar el resto.';
    }

    public function pdf(Request $request)
    {
        $this->authorizeSource($request);

        return $this->guarded($request, 'pdf', fn () => $this->buildPdf($request));
    }

    private function buildPdf(Request $request)
    {
        EventoImportController::raiseMemoryLimit();
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

        // Votantes reads one row past the PDF cap and counts the total apart. The Mapa reads as its Excel does, so both
        // files give the same total for the same filters (its total depends on the rows merged while reading).
        $isVotantes = $request->input('source') === 'votantes';
        [$data, $mapaTotal] = $this->getDataWithTotal($request, $isVotantes ? self::PDF_MAX_ROWS + 1 : null);
        $title = $request->input('title', 'Exportacion');
        $columns = $this->columns($request);

        $totalCount = $request->input('source') === 'votantes' && count($data) > self::PDF_MAX_ROWS
            ? app(VotanteController::class)->listing($request)->count()
            : max(count($data), $mapaTotal);
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
            'aviso' => $this->removedColumns($request) ? DescargaGuard::AVISO_SIN_DATOS : null,
        ])->setPaper('a4', 'landscape')
            ->setOption('isRemoteEnabled', false)
            ->setOption('isPhpEnabled', false);

        return $pdf->download(str_replace(' ', '_', $title).'.pdf');
    }

    // [rows, total]: the total counts the rows a cut Mapa section left out ($limit caps each person section).
    private function getDataWithTotal(Request $request, ?int $limit = null): array
    {
        $source = $request->input('source', 'mapa-politico');

        // Votantes filters municipio by name and its listing is already scoped to the user's territory.
        if ($source === 'votantes') {
            abort_unless($request->user()->hasPermission('view.votantes'), 403);

            $data = $this->getVotantesData($request, $limit);

            return [$data, count($data)];
        }

        $munId = $request->input('municipio');
        abort_if($munId !== null && ! is_string($munId), 422);
        if ($munId) {
            $this->authorizeGeo($munId);
        }

        if ($source === 'mapa-politico') {
            abort_unless($request->user()->hasPermission('view.mapa'), 403);

            // The listing's rows for the same filters (municipio, campaign, tipos...), already unified, each person
            // section up to MapaPoliticoController::EXPORT_MAX_ROWS (or $limit).
            $result = app(MapaPoliticoController::class)->rows($request, forExport: true, sectionLimit: $limit);
            $rows = $this->withLiderObservations(array_map(fn ($r) => $r + ['tipo' => $r['tipo_registro'] ?? null], $result['data']));

            return [$rows, $result['approxTotal'] ?? count($rows)];
        }

        if ($source === 'senado' || $source === 'camara') {
            $corpName = $source === 'senado' ? 'Senado' : 'Cámara de Representantes';
            $data = $this->getCorporacionData($corpName, $munId);

            return [$data, count($data)];
        }

        if ($source === 'asamblea') {
            $data = $this->getCorporacionData('Asamblea', $munId);

            return [$data, count($data)];
        }

        return [[], 0];
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
        return $this->votantesQuery($request)
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn ($l) => $this->votanteRow($l))->all();
    }

    private function votantesQuery(Request $request)
    {
        return app(VotanteController::class)->listing($request)
            ->select(['lideres.nombre', 'lideres.municipio', 'lideres.cargo', 'lideres.partido', 'lideres.telefono', 'lideres.votos', 'lideres.observacion', 'lideres.cedula'])
            ->orderBy('lideres.nombre')
            ->orderBy('lideres.id');
    }

    private function votanteRow(object $l): array
    {
        return [
            'nombre' => $l->nombre,
            'municipio' => $l->municipio,
            'tipo' => 'Votante',
            'cargo' => $l->cargo,
            'partido' => $l->partido,
            'telefono' => $l->telefono,
            'votos' => $l->votos,
            'observacion' => $l->observacion,
            'cedula' => $l->cedula,
        ];
    }

    private function getCorporacionData(string $corpName, ?string $munId): array
    {
        $corpId = DB::table('corporations')->where('name', $corpName)->value('id');
        if (! $corpId) {
            return [];
        }

        $resQuery = DB::table('electoral_results')->whereIn('metric_type', ['votes', 'nominal_votes'])->where('value', '>', 0);
        if ($munId) {
            $resQuery->where('geographic_unit_id', $munId);
        } else {
            $this->scopeToTerritory($resQuery);
        }

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
            'columns' => self::GOBERNADOR_COLUMNS,
            'data' => $data,
            'resumen' => $filas,
            'aviso' => $municipio ? GobernacionResultados::SIN_RESULTADOS_MUNICIPIO : null,
        ];
    }
}
