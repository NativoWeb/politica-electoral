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
use App\Services\Eventos\EventScope;
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
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Only compromisos of CONFIRMED actas are listed and followed (a borrador's are edited in the acta).
// Follow-up of the compromisos of the actas (docs/eventos-api.md §14, docs/empresarios.md): every compromiso of the
// visible events (with view.eventos) and of the visible organizations (with view.empresarios), campaign +
// subcampaigns and territory; estado and avances need manage.eventos / manage.empresarios (per origin) in the own campaign.
class EventoCompromisoController extends Controller
{
    use ScopesTerritory;

    public function __construct(private EventScope $events, private OrganizacionScope $orgs) {}

    // GET /eventos/compromisos
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $page = $this->query($filters)->paginate(20)->withQueryString();
        $responsables = $this->responsables($page->getCollection()->pluck('id')->all());
        $page->through(fn ($c) => $this->row($c, $responsables[$c->id] ?? []));

        return Inertia::render('Eventos/Compromisos', [
            'compromisos' => $page,
            'filters' => $filters,
            'eventos' => $this->seesEvents() ? $this->events->options() : [],
            'organizaciones' => $this->seesOrgs() ? $this->orgs->query('o')->orderBy('o.nombre')->limit(1000)->get(['o.id', 'o.nombre'])->map(fn ($o) => (array) $o)->all() : [],
            'origenes' => ['evento' => $this->seesEvents(), 'organizacion' => $this->seesOrgs()],
            'municipios' => $this->municipios(),
            'estados' => EventCompromiso::LABELS,
            'can' => ['manage' => $this->canManage('evento') || $this->canManage('organizacion')],
        ]);
    }

    // GET /eventos/compromisos.xlsx: the same filters, unpaginated.
    public function export(Request $request): BinaryFileResponse
    {
        $filters = $this->filters($request);
        EventoImportController::raiseMemoryLimit();
        $items = $this->query($filters)->get();
        $responsables = $this->responsables($items->pluck('id')->all());

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
                'telefono' => $r->pluck('telefono')->filter()->implode(' / '),
                'fecha_limite' => $c->fecha_limite ? Carbon::parse($c->fecha_limite)->format('d/m/Y') : null,
                'estado' => EventCompromiso::LABELS[$c->estado] ?? $c->estado,
                'vencido' => self::isVencido($c) ? 'Sí' : 'No',
                'avances' => (int) $c->avances,
            ];
        })->all();

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'EVENTO_COMPROMISOS_EXPORTADOS',
            'entity_type' => 'evento',
            'entity_id' => $filters['evento'] ?? $filters['organizacion'],
            'metadata' => ['route' => 'eventos.compromisos.excel', 'filas' => count($rows), 'filtros' => array_keys(array_filter($filters))],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $columns = ['origen', 'evento', 'municipio', 'acta', 'fecha_acta', 'compromiso', 'responsables', 'telefono', 'fecha_limite', 'estado', 'vencido', 'avances'];
        $labels = [
            'origen' => 'ORIGEN', 'evento' => 'EVENTO / ORGANIZACIÓN', 'municipio' => 'MUNICIPIO', 'acta' => 'ACTA', 'fecha_acta' => 'FECHA DEL ACTA', 'compromiso' => 'COMPROMISO',
            'responsables' => 'RESPONSABLES', 'telefono' => 'TELÉFONOS', 'fecha_limite' => 'FECHA LÍMITE', 'estado' => 'ESTADO',
            'vencido' => 'VENCIDO', 'avances' => 'AVANCES',
        ];

        return Excel::download(new GenericExport($rows, $columns, 'Compromisos', $labels), 'compromisos.xlsx');
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

        $items = $this->query(['lider' => $liderId] + array_fill_keys(self::FILTER_KEYS, null))
            ->limit(200)->get();
        $responsables = $this->responsables($items->pluck('id')->all());

        return response()->json(['success' => true, 'data' => $items->map(fn ($c) => $this->row($c, $responsables[$c->id] ?? []))->values()]);
    }

    private const FILTER_KEYS = ['estado', 'vencidos', 'responsable', 'municipio', 'evento', 'organizacion', 'origen', 'desde', 'hasta', 'q'];

    private function query(array $f)
    {
        $today = EventCompromiso::today();
        $events = $this->seesEvents() && $f['origen'] !== 'organizacion';
        $orgs = $this->seesOrgs() && $f['origen'] !== 'evento';
        $query = DB::table('event_compromisos as c')
            ->join('event_actas as a', 'a.id', '=', 'c.acta_id')
            ->where('a.estado', EventActa::CONFIRMADA)
            ->leftJoin('events as ev', 'ev.id', '=', 'c.event_id')
            ->leftJoin('organizaciones as o', 'o.id', '=', 'c.organizacion_id')
            ->leftJoin('geographic_units as m', fn ($j) => $j->on('m.id', '=', 'ev.municipio_id')->orOn('m.id', '=', 'o.municipio_id'))
            ->where(function ($w) use ($events, $orgs) {
                if ($events) {
                    $w->orWhere(fn ($e) => $this->events->scopeEvents($e->whereNotNull('c.event_id')));
                }
                if ($orgs) {
                    $w->orWhere(fn ($o) => $this->orgs->scope($o->whereNotNull('c.organizacion_id'), 'o'));
                }
                if (! $events && ! $orgs) {
                    $w->whereRaw('1 = 0');
                }
            })
            ->select(['c.id', 'c.descripcion', 'c.estado', 'c.fecha_limite', 'c.campaign_id', 'c.created_at', 'a.id as acta_id', 'a.titulo as acta_titulo',
                'a.fecha as acta_fecha', 'ev.id as event_id', 'ev.nombre as evento', 'o.id as organizacion_id', 'o.nombre as organizacion',
                'm.id as municipio_id', 'm.canonical_name as municipio'])
            ->selectSub(DB::table('event_compromiso_avances as av')->whereColumn('av.compromiso_id', 'c.id')->selectRaw('count(*)'), 'avances')
            ->when($f['estado'], fn ($q, $v) => $q->where('c.estado', $v))
            ->when($f['vencidos'], fn ($q) => $q->whereNotNull('c.fecha_limite')->where('c.fecha_limite', '<', $today)->whereIn('c.estado', EventCompromiso::ABIERTOS))
            ->when($f['municipio'], fn ($q, $v) => $q->where('m.id', $v))
            ->when($f['evento'], fn ($q, $v) => $q->where('ev.id', $v))
            ->when($f['organizacion'], fn ($q, $v) => $q->where('o.id', $v))
            ->when($f['desde'], fn ($q, $v) => $q->where('a.fecha', '>=', $v))
            ->when($f['hasta'], fn ($q, $v) => $q->where('a.fecha', '<', Carbon::parse($v)->addDay()->toDateString()))
            ->when($f['responsable'], fn ($q, $v) => $q->whereExists(fn ($r) => $this->whereContains(
                $r->select(DB::raw(1))->from('event_compromiso_responsables as r')->whereColumn('r.compromiso_id', 'c.id'), 'r.nombre', $v)))
            ->when($f['q'], fn ($q, $v) => $this->whereContains($q, 'c.descripcion', $v))
            ->when($f['lider'] ?? null, fn ($q, $v) => $q->whereExists(fn ($r) => $r->select(DB::raw(1))
                ->from('event_compromiso_responsables as r')->whereColumn('r.compromiso_id', 'c.id')->where('r.lider_id', $v)));

        // Open and overdue first, then by deadline (no deadline last) and newest acta.
        return $query
            ->orderByRaw("CASE WHEN c.estado IN ('pendiente', 'en_curso') THEN 0 ELSE 1 END")
            ->orderByRaw('CASE WHEN c.fecha_limite IS NULL THEN 1 ELSE 0 END')
            ->orderBy('c.fecha_limite')
            ->orderByDesc('a.fecha')->orderBy('c.id');
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

    // [compromiso id => [{nombre, telefono, lider_id}]]
    private function responsables(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('event_compromiso_responsables')->whereIn('compromiso_id', $ids)->orderBy('orden')
            ->get(['compromiso_id', 'nombre', 'telefono', 'lider_id'])
            ->groupBy('compromiso_id')
            ->map(fn ($g) => $g->map(fn ($r) => ['nombre' => $r->nombre, 'telefono' => $r->telefono, 'lider_id' => $r->lider_id])->values()->all())
            ->all();
    }

    private function row(object $c, array $responsables): array
    {
        return [
            'id' => $c->id,
            'descripcion' => $c->descripcion,
            'estado' => $c->estado,
            'fecha_limite' => $c->fecha_limite ? substr((string) $c->fecha_limite, 0, 10) : null,
            'vencido' => self::isVencido($c),
            'avances' => (int) $c->avances,
            'responsables' => $responsables,
            'acta' => ['id' => $c->acta_id, 'titulo' => $c->acta_titulo, 'fecha' => $c->acta_fecha ? substr((string) $c->acta_fecha, 0, 10) : null],
            'origen' => $c->event_id ? 'evento' : 'organizacion',
            'evento' => $c->event_id ? ['id' => $c->event_id, 'nombre' => $c->evento, 'municipio' => $c->municipio] : null,
            'organizacion' => $c->organizacion_id ? ['id' => $c->organizacion_id, 'nombre' => $c->organizacion, 'municipio' => $c->municipio] : null,
            'campaign_id' => $c->campaign_id,
            'editable' => $this->canManage($c->event_id ? 'evento' : 'organizacion') && $this->campaign()->isOwn($c->campaign_id),
        ];
    }

    private function detail(EventCompromiso $c): array
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
            'editable' => $this->canManage($c->event_id ? 'evento' : 'organizacion') && $this->campaign()->isOwn($c->campaign_id),
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
    private function find(string $id, bool $write = false): EventCompromiso
    {
        abort_unless(Str::isUuid($id), 404);
        $c = EventCompromiso::find($id);
        $visible = $c && ($c->event_id
            ? $this->seesEvents() && $this->events->visibleEventId($c->event_id)
            : $this->seesOrgs() && $this->orgs->query('o')->where('o.id', $c->organizacion_id)->exists());
        abort_unless($visible, 404);
        abort_unless(EventActa::whereKey($c->acta_id)->where('estado', EventActa::CONFIRMADA)->exists(), 404);
        if ($write) {
            abort_unless($this->canManage($c->event_id ? 'evento' : 'organizacion'), 403);
            $this->authorizeCampaign($c->campaign_id, true);
        }

        return $c;
    }

    private static function isVencido(object $c): bool
    {
        return $c->fecha_limite !== null && in_array($c->estado, EventCompromiso::ABIERTOS, true)
            && substr((string) $c->fecha_limite, 0, 10) < EventCompromiso::today();
    }

    private function municipios(): array
    {
        $rows = DB::query()->fromSub($this->query(array_fill_keys(self::FILTER_KEYS, null))->reorder(), 'x')
            ->whereNotNull('x.municipio_id')->distinct()->get(['x.municipio_id', 'x.municipio']);

        return $rows->sortBy('municipio')->map(fn ($m) => ['id' => $m->municipio_id, 'nombre' => $m->municipio])->values()->all();
    }

    private function canManage(string $origen = 'evento'): bool
    {
        return (bool) request()->user()?->hasPermission($origen === 'evento' ? 'manage.eventos' : 'manage.empresarios');
    }

    private function seesEvents(): bool
    {
        return EventScope::canSeeAttendees(request()->user());
    }

    private function seesOrgs(): bool
    {
        return OrganizacionScope::canView(request()->user());
    }

    private function whereContains($query, string $column, string $value)
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], Str::lower($value)).'%';
        if (DB::connection()->getDriverName() === 'pgsql') {
            return $query->whereRaw("unaccent(LOWER({$column})) LIKE unaccent(?)", [$like]);
        }

        return $query->whereRaw("LOWER({$column}) LIKE ?", [$like]);
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
