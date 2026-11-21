<?php

namespace App\Http\Controllers;

use App\Exports\StreamedXlsx;
use App\Models\Campaign;
use App\Models\User;
use App\Services\Reportes\Catalogo\Columna;
use App\Services\Reportes\DescargaGuard;
use App\Services\Reportes\Fuentes\Fuente;
use App\Services\Reportes\Fuentes\PersonasFuente;
use App\Services\Reportes\ReporteException;
use App\Services\Reportes\ReporteService;
use App\Services\Reportes\ReportSpec;
use App\Support\CurrentCampaign;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

// Módulo Reportes (docs/reportes.md): build a list of people with filters, see it, open their ficha and download it.
// view.reportes lets in (routes); each fuente asks its own view permission and export.datos_personales guards the
// personal columns, in the screen and in the file.
class ReportesController extends Controller
{
    public const PDF_MAX_COLUMNAS = 8;

    public const PDF_MUNICIPIOS = 10;

    public const PIE_LEGAL = 'Contiene datos personales: uso exclusivo del grupo político (Ley 1581).';

    public function __construct(private readonly ReporteService $reportes, private readonly DescargaGuard $guard) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Reportes/Index', $this->reportes->props($request->user()));
    }

    public function conteo(Request $request, string $fuente): JsonResponse
    {
        [$f, $spec] = $this->reportes->preparar($fuente, $request->input('spec'), $request->user(), false);

        return response()->json(['success' => true, 'total' => $f->contar($spec, $request->user())]);
    }

    public function lista(Request $request, string $fuente): JsonResponse
    {
        $user = $request->user();
        [$f, $spec] = $this->reportes->preparar($fuente, $request->input('spec'), $user, true);
        $pagina = $request->input('pagina', 1);
        if (! is_int($pagina) && ! (is_string($pagina) && ctype_digit($pagina))) {
            throw ReporteException::invalido('La página pedida no es válida.', ['pagina' => ['Debe ser un número.']]);
        }
        $pagina = max(1, min((int) $pagina, ReporteService::MAX_PAGINA));

        return response()->json([
            'success' => true,
            'total' => $f->contar($spec, $user),
            'pagina' => $pagina,
            'por_pagina' => ReporteService::POR_PAGINA,
            'columnas' => $spec->columnas,
            'filas' => $f->pagina($spec, $user, $pagina, ReporteService::POR_PAGINA),
        ]);
    }

    public function excel(Request $request, string $fuente): HttpResponse
    {
        $user = $request->user();
        [$f, $spec] = $this->reportes->preparar($fuente, $request->input('spec'), $user, true);

        return $this->guard->descargar($request, "EXPORT reportes.{$fuente}.excel", function () use ($f, $spec, $user) {
            $total = $f->contar($spec, $user);
            $tope = ReporteService::topes()['excel'];
            $filas = min($total, $tope);
            $recortado = $total > $tope;
            $partes = $f->describir($spec, $user);
            $ahora = CarbonImmutable::now('America/Bogota');
            $catalogo = $f->columnas($user);

            $nombre = self::nombreArchivo($f->etiqueta(), self::sinBusqueda($partes), $ahora);
            $encabezado = [
                self::titulo($f->etiqueta(), $partes),
                'Filtros: '.($partes ? implode(' · ', $partes) : 'ninguno (todas las personas activas)'),
                'Generado por '.$user->name.' el '.$ahora->format('d/m/Y').' a las '.$ahora->format('H:i').' (hora de Bogotá)',
            ];
            $aviso = $recortado
                ? 'Tu lista tiene '.self::numero($total).' personas. Este archivo trae las primeras '.self::numero($tope)
                    .'. Para tener el resto, divide la lista por municipio.'
                : null;

            return [
                'metadata' => [
                    'fuente' => $f->clave(),
                    'formato' => 'excel',
                    'spec' => $spec->toArray(),
                    'columnas' => $spec->columnas,
                    'filas' => $filas,
                    'total' => $total,
                    'recortado' => $recortado,
                    'con_datos_personales' => $this->reportes->conDatosPersonales($f, $spec, $user),
                ],
                'generar' => function () use ($f, $spec, $user, $filas, $nombre, $encabezado, $aviso, $catalogo, $total, $recortado) {
                    $rows = (function () use ($f, $spec, $user, $filas) {
                        set_time_limit(300);
                        yield from $f->filas($spec, $user, $filas);
                    })();

                    return StreamedXlsx::download($rows, $spec->columnas, 'Reporte', $nombre,
                        array_map(fn (Columna $c) => $c->etiqueta, $catalogo), $aviso, $encabezado, [
                            'X-Reporte-Filas' => (string) $filas,
                            'X-Reporte-Total' => (string) $total,
                            'X-Reporte-Recortado' => $recortado ? '1' : '0',
                        ], self::anchos($spec->columnas, $catalogo), null);
                },
            ];
        });
    }

    // Up to PDF_MAX_COLUMNAS columns in the order chosen (a wider table does not fit a landscape page); the rest are
    // named in X-Reporte-Columnas-Omitidas. Same guard, quota and errors as the Excel.
    public function pdf(Request $request, string $fuente): HttpResponse
    {
        $user = $request->user();
        [$f, $spec] = $this->reportes->preparar($fuente, $request->input('spec'), $user, true);
        $omitidas = array_slice($spec->columnas, self::PDF_MAX_COLUMNAS);
        $spec = $spec->conColumnas(array_slice($spec->columnas, 0, self::PDF_MAX_COLUMNAS));

        $response = $this->guard->descargar($request, "EXPORT reportes.{$fuente}.pdf", function () use ($f, $spec, $user, $omitidas) {
            $total = $f->contar($spec, $user);
            $tope = ReporteService::topes()['pdf'];
            $filas = min($total, $tope);
            $recortado = $total > $tope;

            return [
                'metadata' => [
                    'fuente' => $f->clave(),
                    'formato' => 'pdf',
                    'spec' => $spec->toArray(),
                    'columnas' => $spec->columnas,
                    'columnas_omitidas' => $omitidas,
                    'filas' => $filas,
                    'total' => $total,
                    'recortado' => $recortado,
                    'con_datos_personales' => $this->reportes->conDatosPersonales($f, $spec, $user),
                ],
                'generar' => fn () => $this->generarPdf($f, $spec, $user, $filas, $total, $recortado),
            ];
        });
        if ($omitidas) {
            $response->headers->set('X-Reporte-Columnas-Omitidas', implode(',', $omitidas));
        }

        return $response;
    }

    private function generarPdf(Fuente $f, ReportSpec $spec, User $user, int $filas, int $total, bool $recortado): HttpResponse
    {
        // 700 rows with long texts measured 254 MB: give the PDF its own room instead of the shared 256M.
        ini_set('memory_limit', '512M');
        set_time_limit(300);

        $partes = $f->describir($spec, $user);
        $ahora = CarbonImmutable::now('America/Bogota');
        $catalogo = $f->columnas($user);
        $grupo = app(CurrentCampaign::class)->info();

        $pdf = Pdf::loadView('reportes.lista', [
            'titulo' => self::titulo($f->etiqueta(), $partes),
            'grupo' => $grupo['name'] ?? null,
            'logo' => self::logo($grupo['id'] ?? null),
            'generado' => 'Generado por '.$user->name.', '.self::fechaHora($ahora),
            'filtros' => $partes,
            'total' => $total,
            'desglose' => $f->porMunicipio($spec, $user, self::PDF_MUNICIPIOS),
            'aviso' => $recortado
                ? 'Este PDF muestra las primeras '.self::numero($filas).' de '.self::numero($total).' personas. Para tenerlas todas, descarga el Excel.'
                : null,
            'columnas' => array_combine($spec->columnas, array_map(fn ($c) => $catalogo[$c]->etiqueta, $spec->columnas)),
            'filas' => $filas > 0 ? iterator_to_array($f->filas($spec, $user, $filas), false) : [],
        ])->setPaper('a4', 'landscape')
            ->setOption('isRemoteEnabled', false)
            ->setOption('isPhpEnabled', false)
            ->setOption('isJavascriptEnabled', false)
            ->setOption('defaultFont', 'DejaVu Sans');

        // Page numbers with the canvas, after the layout (no PHP inside the template).
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $metrics = $dompdf->getFontMetrics();
        $letra = $metrics->getFont('DejaVu Sans');
        $y = $canvas->get_height() - 30;
        $gris = [0.33, 0.36, 0.41];
        $canvas->page_text(34, $y, self::PIE_LEGAL, $letra, 8, $gris);
        $canvas->page_text($canvas->get_width() - 34 - $metrics->getTextWidth('Página 000 de 000', $letra, 8), $y,
            'Página {PAGE_NUM} de {PAGE_COUNT}', $letra, 8, $gris);

        return $pdf->download(self::nombreArchivo($f->etiqueta(), self::sinBusqueda($partes), $ahora, 'pdf'))->withHeaders([
            'X-Reporte-Filas' => (string) $filas,
            'X-Reporte-Total' => (string) $total,
            'X-Reporte-Recortado' => $recortado ? '1' : '0',
        ]);
    }

    // The Centro Democrático logo, embedded (never fetched), only on the files of its grupo and subgrupos.
    private static function logo(?string $grupo): ?string
    {
        $archivo = public_path('img/partidos/centro-democratico.png');
        if ($grupo === null || ! is_file($archivo)
            || ! in_array($grupo, app(CurrentCampaign::class)->withDescendants(Campaign::DEFAULT_ID), true)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode((string) file_get_contents($archivo));
    }

    public function opciones(Request $request, string $filtro): JsonResponse
    {
        $user = $request->user();
        $fuente = $this->reportes->fuente(PersonasFuente::CLAVE, $user);
        $q = $request->query('q', '');
        $municipios = $request->query('municipios', []);
        $opciones = $fuente->opciones($filtro, is_string($q) ? $q : '', is_array($municipios) ? $municipios : [], $user);
        if ($opciones === null) {
            return response()->json(['success' => false, 'message' => 'Ese filtro no tiene opciones para buscar.'], 404);
        }

        return response()->json(['success' => true, 'opciones' => $opciones]);
    }

    // "Reporte_Gran_elector_Bucaramanga_2026-11-20.xlsx": ASCII, no more than three filters.
    public static function nombreArchivo(string $fuente, array $partes, CarbonImmutable $fecha, string $extension = 'xlsx'): string
    {
        $base = $partes ? implode(' ', array_slice($partes, 0, 3)) : $fuente;
        $slug = trim(preg_replace('/[^A-Za-z0-9]+/', '_', Str::ascii($base)), '_');

        return 'Reporte_'.(mb_substr($slug, 0, 80) ?: 'Personas').'_'.$fecha->format('Y-m-d').'.'.$extension;
    }

    // The heading of the Excel and the cover of the PDF: "Reporte de personas — Gran elector · Bucaramanga".
    private static function titulo(string $fuente, array $partes): string
    {
        return 'Reporte de '.mb_strtolower($fuente).($partes ? ' — '.implode(' · ', array_slice($partes, 0, 3)) : '');
    }

    // The searched text never goes into a file name.
    private static function sinBusqueda(array $partes): array
    {
        return array_values(array_filter($partes, fn ($p) => ! str_starts_with($p, 'Búsqueda')));
    }

    // "20/11/2026 3:40 p. m."
    public static function fechaHora(CarbonImmutable $fecha): string
    {
        return $fecha->format('d/m/Y g:i').' '.($fecha->format('A') === 'AM' ? 'a. m.' : 'p. m.');
    }

    // Characters per column: the name wide, lists of contacts and events wider, the rest as their heading (12 to 40).
    private static function anchos(array $columnas, array $catalogo): array
    {
        $fijos = ['nombre' => 35, 'contactos' => 40, 'eventos' => 40, 'direccion' => 35, 'referente' => 30, 'marcas' => 30, 'observacion' => 60];
        $out = [];
        foreach ($columnas as $c) {
            $out[$c] = $fijos[$c] ?? max(12, min(40, mb_strlen($catalogo[$c]->etiqueta ?? $c) + 4));
        }

        return $out;
    }

    private static function numero(int $n): string
    {
        return number_format($n, 0, ',', '.');
    }
}
