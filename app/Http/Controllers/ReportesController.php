<?php

namespace App\Http\Controllers;

use App\Exports\StreamedXlsx;
use App\Services\Reportes\Catalogo\Columna;
use App\Services\Reportes\DescargaGuard;
use App\Services\Reportes\Fuentes\PersonasFuente;
use App\Services\Reportes\ReporteException;
use App\Services\Reportes\ReporteService;
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

            $nombre = self::nombreArchivo($f->etiqueta(), array_values(array_filter($partes, fn ($p) => ! str_starts_with($p, 'Búsqueda'))), $ahora);
            $encabezado = [
                'Reporte de '.mb_strtolower($f->etiqueta()).($partes ? ' — '.implode(' · ', array_slice($partes, 0, 3)) : ''),
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
    public static function nombreArchivo(string $fuente, array $partes, CarbonImmutable $fecha): string
    {
        $base = $partes ? implode(' ', array_slice($partes, 0, 3)) : $fuente;
        $slug = trim(preg_replace('/[^A-Za-z0-9]+/', '_', Str::ascii($base)), '_');

        return 'Reporte_'.(mb_substr($slug, 0, 80) ?: 'Personas').'_'.$fecha->format('Y-m-d').'.xlsx';
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
