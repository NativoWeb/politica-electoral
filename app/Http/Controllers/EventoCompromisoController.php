<?php

namespace App\Http\Controllers;

use App\Exports\GenericExport;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Http\Requests\Eventos\CompromisoAvanceRequest;
use App\Models\AuditLog;
use App\Models\EventActa;
use App\Models\EventCompromiso;
use App\Models\EventCompromisoAvance;
use App\Services\Empresarios\OrganizacionScope;
use App\Services\Eventos\Actas\ActaFileException;
use App\Services\Eventos\Actas\ActaFiles;
use App\Services\Eventos\Actas\CompromisoAlertas;
use App\Services\Eventos\Actas\CompromisoListing;
use App\Services\Eventos\EventScope;
use App\Services\Reportes\DescargaGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Only compromisos of CONFIRMED actas are listed and followed (a borrador's are edited in the acta).
// Follow-up of the compromisos of the actas (docs/eventos-api.md §14, docs/empresarios.md): every compromiso of the
// visible events (with view.eventos) and of the visible organizations (with view.empresarios), campaign +
// subcampaigns and territory; estado and avances need manage.eventos / manage.empresarios (per origin) in the own campaign.
class EventoCompromisoController extends Controller
{
    use ScopesTerritory;

    public function __construct(private EventScope $events, private OrganizacionScope $orgs, private CompromisoListing $listing) {}

    // GET /eventos/compromisos
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $page = $this->listing->query($filters)->paginate(20)->withQueryString();
        $responsables = $this->listing->responsables($page->getCollection()->pluck('id')->all());
        $page->through(fn ($c) => $this->listing->row($c, $responsables[$c->id] ?? []));

        return Inertia::render('Eventos/Compromisos', [
            'compromisos' => $page,
            'filters' => $filters,
            'eventos' => $this->listing->seesEvents() ? $this->events->options() : [],
            'organizaciones' => $this->listing->seesOrgs() ? $this->orgs->query('o')->orderBy('o.nombre')->limit(1000)->get(['o.id', 'o.nombre'])->map(fn ($o) => (array) $o)->all() : [],
            'origenes' => ['evento' => $this->listing->seesEvents(), 'organizacion' => $this->listing->seesOrgs()],
            'municipios' => $this->municipios(),
            'estados' => EventCompromiso::LABELS,
            'can' => ['manage' => $this->listing->canManage('evento') || $this->listing->canManage('organizacion')],
        ]);
    }

    // GET /eventos/compromisos.xlsx: the same filters, unpaginated. Through DescargaGuard (lock, quota, audit before
    // the file); without export.datos_personales, without the phones of the responsables.
    public function export(Request $request, DescargaGuard $guard): SymfonyResponse
    {
        $filters = $this->filters($request);
        $columns = ['origen', 'evento', 'municipio', 'acta', 'fecha_acta', 'compromiso', 'responsables', 'telefono', 'fecha_limite', 'estado', 'vencido', 'avances'];
        [$columns, $removed] = DescargaGuard::columnasPermitidas($columns, $request->user());

        $response = $guard->descargar($request, 'EXPORT eventos.compromisos.excel', function () use ($filters, $columns, $removed) {
            EventoImportController::raiseMemoryLimit();
            $items = $this->listing->query($filters)->get();
            $responsables = $this->listing->responsables($items->pluck('id')->all());

            $rows = $items->map(function ($c) use ($responsables) {
                $r = collect($responsables[$c->id] ?? []);

                return [
                    'origen' => $c->event_id ? 'Evento' : 'Organización',
                    'evento' => $c->evento ?? $c->organizacion,
                    'municipio' => $c->municipio,
                    'acta' => $c->acta_titulo,
                    'fecha_acta' => $c->acta_fecha ? Carbon::parse($c->acta_fecha)->format('d/m/Y') : null,
                    'compromiso' => $c->descripcion,
                    'responsables' => $r->pluck('nombre')->implode(' / '),
                    'telefono' => CompromisoListing::telefonosConNombre($r->all()),
                    'fecha_limite' => $c->fecha_limite ? Carbon::parse($c->fecha_limite)->format('d/m/Y') : null,
                    'estado' => EventCompromiso::LABELS[$c->estado] ?? $c->estado,
                    'vencido' => CompromisoListing::isVencido($c) ? 'Sí' : 'No',
                    'avances' => (int) $c->avances,
                ];
            })->all();

            $labels = [
                'origen' => 'ORIGEN', 'evento' => 'EVENTO / ORGANIZACIÓN', 'municipio' => 'MUNICIPIO', 'acta' => 'ACTA', 'fecha_acta' => 'FECHA DEL ACTA', 'compromiso' => 'COMPROMISO',
                'responsables' => 'RESPONSABLES', 'telefono' => 'TELÉFONOS', 'fecha_limite' => 'FECHA LÍMITE', 'estado' => 'ESTADO',
                'vencido' => 'VENCIDO', 'avances' => 'AVANCES',
            ];

            return [
                'metadata' => [
                    'fuente' => 'eventos.compromisos', 'formato' => 'excel', 'evento_id' => $filters['evento'], 'organizacion_id' => $filters['organizacion'],
                    'columnas' => $columns, 'columnas_quitadas' => $removed, 'filas' => count($rows), 'filtros' => array_keys(array_filter($filters)),
                    'con_datos_personales' => (bool) array_intersect($columns, DescargaGuard::COLUMNAS_SENSIBLES),
                ],
                'generar' => fn () => Excel::download(new GenericExport($rows, $columns, 'Compromisos', $labels,
                    $removed ? DescargaGuard::AVISO_SIN_DATOS : null), 'compromisos.xlsx'),
            ];
        });
        if ($removed) {
            $response->headers->set('X-Columnas-Quitadas', implode(',', $removed));
        }

        return $response;
    }

    // GET /eventos/compromisos/{compromiso}: the compromiso with its history.
    public function show(string $compromiso): JsonResponse
    {
        $c = $this->find($compromiso);

        return response()->json(['success' => true, 'data' => $this->detail($c)]);
    }

    // PUT /eventos/compromisos/{compromiso}/estado {estado, nota?}: the change is written as an avance.
    public function estado(Request $request, string $compromiso): JsonResponse
    {
        $c = $this->find($compromiso, true);
        $data = $request->validate([
            'estado' => ['required', Rule::in(EventCompromiso::ESTADOS)],
            'nota' => ['nullable', 'string', 'max:5000'],
        ], ['estado.required' => 'Elija el estado.', 'estado.in' => 'Ese estado no existe.']);
        if ($data['estado'] === $c->estado) {
            throw ValidationException::withMessages(['estado' => 'El compromiso ya está en ese estado.']);
        }

        $from = $c->estado;
        DB::transaction(function () use ($c, $data, $request) {
            $avance = new EventCompromisoAvance;
            $avance->forceFill([
                'compromiso_id' => $c->id,
                'nota' => isset($data['nota']) && trim($data['nota']) !== '' ? trim($data['nota']) : null,
                'estado_anterior' => $c->estado,
                'estado_nuevo' => $data['estado'],
                'user_id' => $request->user()->id,
            ])->save();
            $c->forceFill(['estado' => $data['estado']])->save();
        });
        $this->audit($request, 'EVENTO_COMPROMISO_ESTADO', $c, ['de' => $from, 'a' => $data['estado']]);
        self::forgetAlerts($request);

        return response()->json(['success' => true, 'message' => 'Estado actualizado: '.EventCompromiso::LABELS[$data['estado']].'.', 'data' => $this->detail($c->fresh())]);
    }

    // PUT /eventos/compromisos/{compromiso}/recordatorio {recordar_el: YYYY-MM-DD|null}
    public function recordatorio(Request $request, string $compromiso): JsonResponse
    {
        $c = $this->find($compromiso, true);
        $data = $request->validate(['recordar_el' => ['nullable', 'date_format:Y-m-d']], ['recordar_el.date_format' => 'Elija una fecha válida.']);
        $c->forceFill(['recordar_el' => $data['recordar_el'] ?? null])->save();
        $this->audit($request, 'EVENTO_COMPROMISO_RECORDATORIO', $c, ['con_fecha' => $c->recordar_el !== null]);
        self::forgetAlerts($request);

        return response()->json(['success' => true, 'message' => $c->recordar_el ? 'Recordatorio guardado.' : 'Recordatorio quitado.', 'data' => $this->detail($c)]);
    }

    // GET /eventos/compromisos/alertas: the bell (counts and a short list), cached 5 minutes per user.
    public function alertas(Request $request, CompromisoAlertas $alertas): JsonResponse
    {
        $data = Cache::remember(self::alertsKey($request), 300, fn () => $alertas->summary());

        return response()->json(['success' => true, 'data' => $data]);
    }

    public static function alertsKey(Request $request): string
    {
        return 'compromisos-alertas:'.$request->user()->id;
    }

    private static function forgetAlerts(Request $request): void
    {
        Cache::forget(self::alertsKey($request));
    }

    // POST /eventos/compromisos/{compromiso}/avances {nota?, archivo?} (multipart)
    public function avance(CompromisoAvanceRequest $request, string $compromiso): JsonResponse
    {
        $c = $this->find($compromiso, true);
        $file = null;
        if ($request->hasFile('archivo')) {
            try {
                $file = ActaFiles::store($request->file('archivo'), "eventos/compromisos/{$c->id}", false);
            } catch (ActaFileException $e) {
                throw ValidationException::withMessages(['archivo' => $e->getMessage()]);
            }
        }
        $nota = trim((string) $request->input('nota'));

        $avance = new EventCompromisoAvance;
        $avance->forceFill([
            'compromiso_id' => $c->id,
            'nota' => $nota !== '' ? $nota : null,
            'archivo_path' => $file[0] ?? null,
            'archivo_mime' => $file[1] ?? null,
            'archivo_nombre' => $file[2] ?? null,
            'user_id' => $request->user()->id,
        ]);
        try {
            $avance->save();
        } catch (\Throwable $e) {
            ActaFiles::delete($file[0] ?? null);
            throw $e;
        }
        $this->audit($request, 'EVENTO_COMPROMISO_AVANCE', $c, ['avance_id' => $avance->id, 'con_archivo' => $file !== null]);

        return response()->json(['success' => true, 'message' => 'Avance registrado.', 'data' => $this->detail($c)], 201);
    }

    // GET /eventos/compromisos/avances/{avance}/archivo
    public function file(Request $request, string $avance): StreamedResponse
    {
        abort_unless(Str::isUuid($avance), 404);
        $a = EventCompromisoAvance::findOrFail($avance);
        $c = $this->find($a->compromiso_id);
        abort_unless($a->archivo_path && Storage::disk(ActaFiles::DISK)->exists($a->archivo_path), 404);
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'EVENTO_COMPROMISO_ARCHIVO_DESCARGADO',
            'entity_type' => $c->event_id ? 'evento' : 'organizacion',
            'entity_id' => $c->event_id ?? $c->organizacion_id,
            'metadata' => ['compromiso_id' => $c->id, 'avance_id' => $a->id],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return Storage::disk(ActaFiles::DISK)->download($a->archivo_path, ActaFiles::downloadName($a->archivo_nombre, $a->archivo_mime),
            ['Content-Type' => $a->archivo_mime, 'X-Content-Type-Options' => 'nosniff']);
    }

    // GET /eventos/persona/{liderId}/compromisos: the compromisos a ficha is responsible for.
    public function persona(string $liderId): JsonResponse
    {
        abort_unless(Str::isUuid($liderId), 404);
        $lider = $this->visibleLideres()->where('id', $liderId)->first(['id', 'campaign_id', 'geographic_unit_id']);
        abort_unless($lider, 404);
        $this->authorizeLiderRow($lider);

        $items = $this->listing->query(['lider' => $liderId] + array_fill_keys(CompromisoListing::FILTER_KEYS, null))
            ->limit(200)->get();
        $responsables = $this->listing->responsables($items->pluck('id')->all());

        return response()->json(['success' => true, 'data' => $items->map(fn ($c) => $this->listing->row($c, $responsables[$c->id] ?? []))->values()]);
    }

    private function filters(Request $request): array
    {
        $uuid = fn ($v) => is_string($v) && Str::isUuid($v) ? $v : null;
        $date = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null;
        $text = fn ($v) => is_string($v) && trim($v) !== '' ? Str::limit(trim($v), 100, '') : null;

        return [
            'estado' => in_array($request->query('estado'), EventCompromiso::ESTADOS, true) ? $request->query('estado') : null,
            'vencidos' => $request->boolean('vencidos') ? '1' : null,
            'responsable' => $text($request->query('responsable')),
            'municipio' => $uuid($request->query('municipio')),
            'evento' => $uuid($request->query('evento')),
            'organizacion' => $uuid($request->query('organizacion')),
            'origen' => in_array($request->query('origen'), ['evento', 'organizacion'], true) ? $request->query('origen') : null,
            'desde' => $date($request->query('desde')),
            'hasta' => $date($request->query('hasta')),
            'q' => $text($request->query('q')),
        ];
    }

    public function detail(EventCompromiso $c): array
    {
        $c->load(['responsables', 'avances.user:id,name', 'acta:id,titulo,fecha', 'event:id,nombre', 'organizacion:id,nombre']);

        return [
            'id' => $c->id,
            'descripcion' => $c->descripcion,
            'estado' => $c->estado,
            'fecha_limite' => $c->fecha_limite?->toDateString(),
            'recordar_el' => $c->recordar_el?->toDateString(),
            'vencido' => $c->vencido(),
            'responsables' => $c->responsables->map(fn ($r) => $r->only(['nombre', 'telefono', 'lider_id']))->values()->all(),
            'acta' => ['id' => $c->acta?->id, 'titulo' => $c->acta?->titulo, 'fecha' => $c->acta?->fecha?->toDateString()],
            'origen' => $c->event_id ? 'evento' : 'organizacion',
            'evento' => $c->event_id ? ['id' => $c->event?->id, 'nombre' => $c->event?->nombre] : null,
            'organizacion' => $c->organizacion_id ? ['id' => $c->organizacion?->id, 'nombre' => $c->organizacion?->nombre] : null,
            'editable' => $this->listing->canManage($c->event_id ? 'evento' : 'organizacion') && $this->campaign()->isOwn($c->campaign_id),
            'avances' => $c->avances->map(fn (EventCompromisoAvance $a) => [
                'id' => $a->id,
                'nota' => $a->nota,
                'estado_anterior' => $a->estado_anterior,
                'estado_nuevo' => $a->estado_nuevo,
                'archivo' => $a->archivo_path ? ['nombre' => ActaFiles::downloadName($a->archivo_nombre, $a->archivo_mime), 'url' => "/eventos/compromisos/avances/{$a->id}/archivo"] : null,
                'usuario' => $a->user?->name,
                'created_at' => $a->created_at ? Carbon::parse($a->created_at)->setTimezone(EventoController::TZ)->toIso8601String() : null,
            ])->values()->all(),
        ];
    }

    // A compromiso of a visible event (campaign, territory, not deleted): 404 otherwise; writes need the own campaign.
    public function find(string $id, bool $write = false): EventCompromiso
    {
        abort_unless(Str::isUuid($id), 404);
        $c = EventCompromiso::find($id);
        $visible = $c && ($c->event_id
            ? $this->listing->seesEvents() && $this->events->visibleEventId($c->event_id)
            : $this->listing->seesOrgs() && $this->orgs->query('o')->where('o.id', $c->organizacion_id)->exists());
        abort_unless($visible, 404);
        abort_unless(EventActa::whereKey($c->acta_id)->where('estado', EventActa::CONFIRMADA)->exists(), 404);
        if ($write) {
            abort_unless($this->listing->canManage($c->event_id ? 'evento' : 'organizacion'), 403);
            $this->authorizeCampaign($c->campaign_id, true);
        }

        return $c;
    }

    private function municipios(): array
    {
        $rows = DB::query()->fromSub($this->listing->query(array_fill_keys(CompromisoListing::FILTER_KEYS, null))->reorder(), 'x')
            ->whereNotNull('x.municipio_id')->distinct()->get(['x.municipio_id', 'x.municipio']);

        return $rows->sortBy('municipio')->map(fn ($m) => ['id' => $m->municipio_id, 'nombre' => $m->municipio])->values()->all();
    }

    private function audit(Request $request, string $action, EventCompromiso $c, array $metadata): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => $c->event_id ? 'evento' : 'organizacion',
            'entity_id' => $c->event_id ?? $c->organizacion_id,
            'metadata' => ['compromiso_id' => $c->id, ...$metadata],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
