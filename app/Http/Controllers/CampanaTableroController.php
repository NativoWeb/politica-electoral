<?php

namespace App\Http\Controllers;

use App\Models\ElectoralCampaign;
use App\Services\CampanasElectorales\DetalleCampana;
use App\Services\CampanasElectorales\ElectoralCampaignAccess;
use App\Services\CampanasElectorales\TableroCampana;
use App\Services\Reportes\DescargaGuard;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Tablero de la campaña (docs/campanas-electorales.md, Fase 5), behind `feature:campanas` and view/manage.campanas.
// ElectoralCampaignAccess::find decides who sees the campaign before anything is read from the cache.
class CampanaTableroController extends Controller
{
    public const ACTUALIZAR_SEGUIDAS = 'Actualizaste el tablero varias veces seguidas. Espera un minuto y vuelve a intentarlo.';

    private const ESTADOS = ['ok' => 'Con meta', 'sin_meta' => 'Sin meta', 'insuficiente' => 'Pocas fichas con comuna'];

    private const FUENTE = 'Fuente: fichas activas del grupo político registradas en la plataforma y metas de la campaña. Cifras de 1 a 4 se muestran como «menos de 5».';

    public function __construct(
        private readonly TableroCampana $tablero,
        private readonly DetalleCampana $detalle,
        private readonly ElectoralCampaignAccess $access,
    ) {}

    // GET /campanas-electorales/{id}/tablero[?actualizar=1]
    public function show(Request $request, string $id): Response|JsonResponse
    {
        $c = $this->access->find($id);
        $fresh = $request->boolean('actualizar');
        if ($fresh) {
            $key = 'campanas-tablero-actualizar:'.$request->user()->id;
            if (! RateLimiter::attempt($key, (int) config('campanas_electorales.tablero.actualizar_por_minuto'), fn () => true, 60)) {
                $headers = ['Retry-After' => RateLimiter::availableIn($key)];
                if (self::wantsData($request)) {
                    return response()->json(['success' => false, 'message' => self::ACTUALIZAR_SEGUIDAS], 429, $headers);
                }
                abort(429, self::ACTUALIZAR_SEGUIDAS, $headers);
            }
        }
        $data = $this->data($c, $fresh);

        return self::wantsData($request)
            ? response()->json(['success' => true, 'data' => $data])
            : Inertia::render('CampanasElectorales/Tablero', $data);
    }

    // GET /campanas-electorales/{id}/tablero/territorio/{unitId}
    public function territorio(string $id, string $unitId): JsonResponse
    {
        $c = $this->access->find($id);
        $data = Str::isUuid($unitId) ? $this->tablero->territorio($c, $unitId) : null;
        abort_if($data === null, 404);

        return response()->json(['success' => true, 'data' => $data]);
    }

    // GET /campanas-electorales/{id}/tablero/exportar.xlsx
    public function excel(Request $request, DescargaGuard $guard, string $id): SymfonyResponse
    {
        $c = $this->access->find($id);

        return $guard->descargar($request, 'campanas.tablero.excel', function () use ($c) {
            $data = $this->data($c);

            return [
                'metadata' => $this->metadata($c, 'excel', $data),
                'generar' => fn () => $this->xlsx($c, $data),
            ];
        });
    }

    // GET /campanas-electorales/{id}/tablero/exportar.pdf
    public function pdf(Request $request, DescargaGuard $guard, string $id): SymfonyResponse
    {
        $c = $this->access->find($id);

        return $guard->descargar($request, 'campanas.tablero.pdf', function () use ($c) {
            $data = $this->data($c);
            $porId = array_column($data['territorios'], null, 'id');

            return [
                'metadata' => $this->metadata($c, 'pdf', $data),
                'generar' => fn () => Pdf::loadView('exports.tablero-campana', [
                    'data' => $data,
                    'c' => $c,
                    'fecha' => now('America/Bogota')->format('d/m/Y H:i'),
                    'mejores' => array_map(fn ($tid) => $porId[$tid], $data['ranking']['mejores']),
                    'porApoyar' => array_map(fn ($tid) => $porId[$tid], $data['ranking']['por_apoyar']),
                    'nombres' => array_column($data['territorios'], 'nombre', 'id'),
                    'estados' => self::ESTADOS,
                    'fuente' => self::FUENTE,
                ])->setPaper('a4', 'landscape')->setOption('isRemoteEnabled', false)->setOption('isPhpEnabled', false)
                    ->download(self::archivo($c, 'pdf')),
            ];
        });
    }

    private function data(ElectoralCampaign $c, bool $fresh = false): array
    {
        $readOnly = $this->access->readOnly($c);

        return [
            'campana' => $this->detalle->tarjeta($c),
            'soloLectura' => $readOnly,
            'can' => ['edit' => $readOnly === null],
        ] + $this->tablero->datos($c, $fresh);
    }

    private function metadata(ElectoralCampaign $c, string $formato, array $data): array
    {
        return [
            'fuente' => 'campanas.tablero', 'formato' => $formato, 'electoral_campaign_id' => $c->id, 'name' => $c->name,
            'filas' => count($data['territorios']), 'con_datos_personales' => false,
            'territorio_usuario' => $this->access->allowedMunicipios() !== null,
        ];
    }

    private static function wantsData(Request $request): bool
    {
        return $request->wantsJson() && ! $request->header('X-Inertia');
    }

    private static function archivo(ElectoralCampaign $c, string $ext): string
    {
        return 'tablero-'.(Str::slug($c->name) ?: 'campana').'.'.$ext;
    }

    // Excel: Resumen, Territorios and Evolución with the same figures as the JSON.
    private function xlsx(ElectoralCampaign $c, array $data): StreamedResponse
    {
        return new StreamedResponse(function () use ($c, $data) {
            $header = (new Style)->setFontBold()->setFontColor(Color::WHITE)->setBackgroundColor('003B71');
            $bold = (new Style)->setFontBold();
            $pct = (new Style)->setFormat('0.0%');
            $writer = new Writer;
            $writer->openToFile('php://output');

            $t = $data['totales'];
            $writer->getCurrentSheet()->setName('Resumen');
            $writer->addRow(Row::fromValues(['Tablero de la campaña', self::texto($c->name)], $bold));
            foreach ([
                ['Cargo', $c->cargoLabel()],
                ['Territorio', $data['campana']['territorio']['resumen']],
                ['Generado (hora de Bogotá)', now('America/Bogota')->format('d/m/Y H:i')],
                ['Estado', $c->isActive() ? 'Activa' : 'Archivada'],
                [],
                ['Votantes registrados', $t['votantes']['registrados']],
                ['Votantes registrados en territorios con meta', $t['votantes']['registrados_con_meta']],
                ['Meta de votantes', $t['votantes']['meta']],
                ['Avance votantes', $t['votantes']['pct']],
                ['Faltan votantes', $t['votantes']['falta']],
                ['Líderes registrados', $t['lideres']['registrados']],
                ['Líderes registrados en territorios con meta', $t['lideres']['registrados_con_meta']],
                ['Meta de líderes', $t['lideres']['meta']],
                ['Avance líderes', $t['lideres']['pct']],
                ['Faltan líderes', $t['lideres']['falta']],
                ['Índice (0 a 100)', $t['indice']],
                ['Territorios', $t['territorios']['total']],
                ['Territorios con meta', $t['territorios']['con_meta']],
                [],
                ['Cómo se calcula', $data['formula']['texto']],
                [],
                [self::FUENTE],
            ] as $fila) {
                $esPct = in_array($fila[0] ?? null, ['Avance votantes', 'Avance líderes'], true);
                $writer->addRow(Row::fromValues($fila, $esPct ? $pct : null));
            }

            $writer->addNewSheetAndMakeItCurrent()->setName('Territorios');
            $writer->addRow(Row::fromValues(['Municipio', 'Comuna', 'Meta votantes', 'Registrados', '%', 'Faltan', 'Meta líderes', 'Líderes', '%',
                'Faltan', 'Índice', 'Estado', 'Meta calculada'], $header));
            $nombres = array_column($data['territorios'], 'nombre', 'id');
            $comunas = $this->access->territorio()->comunas();
            foreach ($data['territorios'] as $f) {
                $esComuna = in_array($f['tipo'], ['comuna', 'corregimiento'], true);
                $municipio = $esComuna ? ($f['padre_id'] ? $nombres[$f['padre_id']] ?? '' : $comunas[$f['id']]['municipio'] ?? '') : $f['nombre'];
                $v = $f['votantes'];
                $l = $f['lideres'];
                $writer->addRow(Row::fromValuesWithStyles([
                    self::texto($municipio), $esComuna ? self::texto($f['nombre']) : '',
                    $v['meta'], $v['registrados'], $v['pct'], $v['falta'],
                    $l['meta'], $l['registrados'], $l['pct'], $l['falta'],
                    $f['indice'], self::ESTADOS[$f['estado']] ?? $f['estado'],
                    $v['meta_calculada'] || $l['meta_calculada'] ? 'Sí' : 'No',
                ], null, [4 => $pct, 8 => $pct]));
            }
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValues([self::FUENTE]));

            $writer->addNewSheetAndMakeItCurrent()->setName('Evolución');
            $writer->addRow(Row::fromValues(['Semana (desde el lunes)', 'Fichas nuevas', 'Carga masiva'], $header));
            $e = $data['evolucion'];
            if ($e['antes_texto']) {
                $writer->addRow(Row::fromValues(['Antes del '.$e['desde'], $e['antes'] ?? 'menos de 5', '']));
            }
            foreach ($e['semanas'] as $s) {
                $writer->addRow(Row::fromValues([$s['inicio'], $s['nuevos'] ?? 'menos de 5', $s['masiva'] ? 'Sí' : '']));
            }
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValues([$e['masiva_texto']]));
            $writer->addRow(Row::fromValues([self::FUENTE]));
            $writer->close();
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.self::archivo($c, 'xlsx').'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    // A cell never starts with a formula sign.
    private static function texto(string $v): string
    {
        return preg_match('/^[=+\-@\t\r]/u', $v) ? "'".$v : $v;
    }
}
