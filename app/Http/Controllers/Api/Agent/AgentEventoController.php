<?php

namespace App\Http\Controllers\Api\Agent;

use App\Exports\GenericExport;
use App\Http\Controllers\Concerns\DeliversAgentContactData;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Http\Controllers\Controller;
use App\Http\Controllers\EventoController;
use App\Http\Controllers\EventoIntegracionController;
use App\Http\Requests\Agent\AgentEventosTerritorioRequest;
use App\Http\Requests\Agent\AgentListAsistentesRequest;
use App\Http\Requests\Agent\AgentListEventosRequest;
use App\Models\CampaignEvent;
use App\Models\EventActa;
use App\Models\EventAttendee;
use App\Models\EventCompromiso;
use App\Models\EventType;
use App\Services\Agent\AgentContactQuota;
use App\Services\Agent\AuthorizedContacts;
use App\Services\Eventos\Actas\CompromisoListing;
use App\Services\Eventos\EventScope;
use App\Services\Eventos\TerritoryView;
use App\Support\Cedula;
use App\Support\Provincias;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

// Read-only access of the WhatsApp agent to Eventos especiales, as the token owner and through the web's own queries:
// visible campaigns (own + subcampaigns), territory and, for attendees, view.eventos. Events, attendees and
// organizers list several persons: their contact data only if they are a lider who authorized it. Nothing here
// writes; deliveries are audited with ids and counts and those with contact data count against the daily quota.
class AgentEventoController extends Controller
{
    use DeliversAgentContactData, ScopesTerritory;

    private const ATTENDEE_CONTACTS = ['cedula', 'celular', 'fijo', 'direccion', 'fecha_nacimiento'];

    private const TERRITORY_EVENTS = 50;

    private const TERRITORY_ATTENDEES = 20;

    public function __construct(
        private readonly AgentContactQuota $quota,
        private readonly EventScope $events,
        private readonly TerritoryView $territory,
    ) {}

    public function index(AgentListEventosRequest $request): JsonResponse
    {
        $f = $request->filters();
        $tipoText = $f['tipo'];
        $f['tipo'] = $tipoText !== null ? $this->typeIdsMatching($tipoText) : null;
        $canView = EventScope::canSeeAttendees($request->user());
        $web = app(EventoController::class);

        $page = $web->eventsQuery($f, $canView)
            ->when($f['tipo'] === [], fn ($q) => $q->whereRaw('1 = 0'))
            ->orderBy('events.id')
            ->paginate($request->perPage(), ['*'], 'page', $request->page());

        $ids = $page->getCollection()->pluck('id')->all();
        $actas = $canView ? $this->actasCount($ids) : [];
        $abiertos = $canView ? $this->openCompromisosCount($ids) : [];

        $data = $page->getCollection()->map(function (CampaignEvent $e) use ($web, $canView, $actas, $abiertos) {
            $row = $web->summary($e);
            unset($row['editable']);

            return $row + [
                'asistentes_total' => $canView ? (int) $e->attendees_count : null,
                'actas_total' => $canView ? (int) ($actas[$e->id] ?? 0) : null,
                'compromisos_abiertos' => $canView ? (int) ($abiertos[$e->id] ?? 0) : null,
            ];
        })->values()->all();

        $this->audit($request, 'agent.eventos.list', $this->auditFilters(array_merge($f, ['tipo' => $tipoText])), [
            'total' => $page->total(), 'entregadas' => count($data), 'page' => $page->currentPage(), 'per_page' => $page->perPage(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'last_page' => $page->lastPage(),
                'tipos' => $this->visibleTypes()->where('is_active', true)->pluck('nombre')->unique()->values()->all(),
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $event = $this->findEvent($id);
        $web = app(EventoController::class);

        $data = $web->detail($event);
        unset($data['public_url'], $data['consent_text_id'], $data['editable']);
        $authorized = AuthorizedContacts::of(array_column($data['organizadores'], 'lider_id'));
        $conContacto = [];
        $data['organizadores'] = array_map(function (array $o) use ($authorized, &$conContacto) {
            $o = AuthorizedContacts::filter($o, $authorized, ['cedula', 'telefono'], 'lider_id', $delivered);
            if ($delivered) {
                $conContacto[$o['lider_id']] = true;
            }

            return $o;
        }, $data['organizadores']);
        $kpis = $web->kpis($event);
        $data += ['kpis' => $kpis, 'asistentes_total' => $kpis['total'], 'actas' => $this->actasOf($event->id)];

        $conDatos = count($conContacto);
        $counts = ['organizadores' => count($data['organizadores']), 'actas' => count($data['actas']), 'con_datos' => $conDatos];
        if ($blocked = $this->overQuota($request, 'agent.evento.show', [], $counts, $event->id)) {
            return $blocked;
        }
        $this->deliver($request, 'agent.evento.show', [], $counts, $event->id);

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => ['con_datos' => $conDatos, 'cupo_restante' => $this->quota->remaining($request->user()->id)],
        ]);
    }

    public function asistentes(AgentListAsistentesRequest $request, string $id): JsonResponse
    {
        $event = $this->findEvent($id);
        $filters = $request->filters();
        $page = $this->attendeesQuery($event, $filters)
            ->paginate($request->perPage(), ['*'], 'page', $request->page());

        $attendees = $page->getCollection()->all();
        $authorized = AuthorizedContacts::of(array_map(fn (EventAttendee $a) => $a->lider_id, $attendees));
        $conContacto = [];
        $data = array_map(function (EventAttendee $a) use ($authorized, &$conContacto) {
            $row = AuthorizedContacts::filter($this->attendeeRow($a), $authorized, self::ATTENDEE_CONTACTS, 'lider_id', $delivered);
            if ($delivered) {
                $conContacto[$row['lider_id']] = true;
            }

            return $row;
        }, $attendees);

        $conDatos = count($conContacto);
        $auditFilters = $this->auditFilters($filters);
        $counts = ['total' => $page->total(), 'entregadas' => count($data), 'con_datos' => $conDatos, 'page' => $page->currentPage(), 'per_page' => $page->perPage()];
        if ($blocked = $this->overQuota($request, 'agent.evento.asistentes.list', $auditFilters, $counts, $event->id)) {
            return $blocked;
        }
        $this->deliver($request, 'agent.evento.asistentes.list', $auditFilters, $counts, $event->id);

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'last_page' => $page->lastPage(),
                'con_datos' => $conDatos,
                'cupo_restante' => $this->quota->remaining($request->user()->id),
            ],
        ]);
    }

    // The web's Excel of the attendees (same columns), at most agent.export_max_rows rows, contact data only of
    // authorized lideres, and cut before the first row with contact data that would pass today's quota.
    public function exportAsistentes(AgentListAsistentesRequest $request, string $id): Response
    {
        $event = $this->findEvent($id);
        $filters = $request->filters();
        $max = max(1, (int) config('agent.export_max_rows', 1000));
        $web = app(EventoController::class);

        $query = $this->attendeesQuery($event, $filters);
        $total = (clone $query)->count();
        $attendees = $query->limit($max)->get();
        $authorized = AuthorizedContacts::of($attendees->pluck('lider_id')->all());

        $rows = [];
        $withData = 0;
        $remaining = $this->quota->remaining($request->user()->id);
        $quotaTruncated = false;
        foreach ($attendees as $a) {
            $row = AuthorizedContacts::filter($web->attendeeExportRow($a) + ['lider_id' => $a->lider_id], $authorized, self::ATTENDEE_CONTACTS, 'lider_id', $delivered);
            unset($row['lider_id']);
            if ($delivered && $remaining > 0 && $withData >= $remaining) {
                $quotaTruncated = true;
                break;
            }
            $withData += $delivered ? 1 : 0;
            $rows[] = $row;
        }

        $auditFilters = $this->auditFilters($filters);
        $counts = ['via' => 'agent', 'filas' => count($rows), 'total' => $total, 'con_datos' => $withData, 'max_filas' => $max,
            'recortado_por_cupo' => $quotaTruncated];
        // Refused for the quota: audited as the agent's own action, so EVENTO_ASISTENTES_EXPORTADOS only counts real files.
        if ($blocked = $this->overQuota($request, 'agent.evento.asistentes.export', $auditFilters, $counts, $event->id)) {
            return $blocked;
        }

        try {
            $filename = 'asistentes-'.(Str::slug(Str::limit($event->nombre, 60, '')) ?: 'evento').'.xlsx';
            $response = Excel::download(new GenericExport($rows, array_keys(EventoController::ATTENDEE_EXPORT_LABELS), 'Asistentes',
                EventoController::ATTENDEE_EXPORT_LABELS), $filename);
        } catch (Throwable $e) {
            $this->quota->release($request->user()->id, $withData);

            throw $e;
        }
        $this->deliver($request, 'EVENTO_ASISTENTES_EXPORTADOS', $auditFilters, $counts, $event->id);

        $response->headers->set('X-Total-Count', (string) $total);
        $response->headers->set('X-Export-Truncated', $total > $max ? 'true' : 'false');
        $response->headers->set('X-Quota-Truncated', $quotaTruncated ? 'true' : 'false');
        $response->headers->set('X-Rows-Delivered', (string) count($rows));
        $response->headers->set('X-Rows-With-Data', (string) $withData);

        return $response;
    }

    // The web's "vista por territorio" of one municipio: kpis, its events and the first attendees.
    public function territorio(AgentEventosTerritorioRequest $request): JsonResponse
    {
        $web = Request::create('/eventos/territorio', 'GET', array_filter([
            'municipio' => $request->validated('municipio_id'),
            'barrio' => $request->validated('barrio'),
        ]));
        $integracion = app(EventoIntegracionController::class);
        [$municipio, , $key] = $integracion->territoryFilters($web);
        abort_unless($municipio !== null, 404, 'Municipio no encontrado.');

        $eventos = $this->territory->eventos($municipio->id, $key)->limit(self::TERRITORY_EVENTS)->get()
            ->map(fn ($e) => $integracion->territoryEvent($e))->all();
        $asistentes = $this->territory->asistentes($municipio->id, $key)->limit(self::TERRITORY_ATTENDEES)->get();
        $kpis = $this->territory->kpis($municipio->id, $key);

        $authorized = AuthorizedContacts::of($asistentes->pluck('lider_id')->all());
        $conContacto = [];
        $rows = $asistentes->map(function ($a) use ($authorized, &$conContacto) {
            $row = AuthorizedContacts::filter($this->territoryAttendee($a), $authorized, ['cedula', 'celular'], 'lider_id', $delivered);
            if ($delivered) {
                $conContacto[$row['lider_id']] = true;
            }

            return $row;
        })->all();

        $conDatos = count($conContacto);
        $auditFilters = ['municipio_id' => $municipio->id, 'barrio' => $key !== null ? true : null];
        $counts = ['eventos' => count($eventos), 'asistentes' => count($rows), 'asistentes_total' => $kpis['asistentes'], 'con_datos' => $conDatos];
        if ($blocked = $this->overQuota($request, 'agent.eventos.territorio', array_filter($auditFilters), $counts)) {
            return $blocked;
        }
        $this->deliver($request, 'agent.eventos.territorio', array_filter($auditFilters), $counts);

        return response()->json([
            'success' => true,
            'data' => [
                'municipio' => ['id' => $municipio->id, 'nombre' => $municipio->canonical_name, 'provincia' => Provincias::display($municipio->provincia)],
                'kpis' => $kpis,
                'eventos' => $eventos,
                'asistentes' => $rows,
                'asistentes_total' => $kpis['asistentes'],
            ],
            'meta' => ['con_datos' => $conDatos, 'cupo_restante' => $this->quota->remaining($request->user()->id)],
        ]);
    }

    protected function auditEntityType(): string
    {
        return 'evento';
    }

    // A visible event (campaign + subcampaigns, territory, not deleted), or 404 for anything else: the agent is not
    // told that an event outside the territory exists.
    private function findEvent(string $id): CampaignEvent
    {
        abort_unless($this->events->visibleEventId($id) !== null, 404);

        return app(EventoController::class)->findEvent($id);
    }

    // The web's attendees query without its search, which also matches any part of anyone's cédula: here a name, or a
    // cédula only of an attendee whose lider authorized their data, so the agent never confirms who else attended.
    private function attendeesQuery(CampaignEvent $event, array $filters): Builder
    {
        return app(EventoController::class)->attendeesQuery($event, ['q' => null] + $filters)
            ->when($filters['q'], function (Builder $q, string $term) {
                $cedula = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) Cedula::normalize($term)).'%';
                $q->where(fn (Builder $w) => app(CompromisoListing::class)->whereContains($w, "nombres || ' ' || apellidos", $term)
                    ->orWhere(fn (Builder $c) => $c->where('cedula', 'like', $cedula)->whereExists(fn ($l) => $l->select(DB::raw(1))
                        ->from('lideres')->whereColumn('lideres.id', 'event_attendees.lider_id')
                        ->where('lideres.autoriza_datos', true))));
            });
    }

    private function attendeeRow(EventAttendee $a): array
    {
        return [
            'id' => $a->id,
            'nombres' => $a->nombres,
            'apellidos' => $a->apellidos,
            'barrio' => $a->barrio,
            'source' => $a->source,
            'origen' => EventoIntegracionController::SOURCES[$a->source] ?? $a->source,
            'link_status' => $a->link_status,
            'estado' => EventoIntegracionController::LINK_STATUSES[$a->link_status] ?? $a->link_status,
            'en_votantes' => $a->lider_id !== null,
            'lider_id' => $a->lider_id,
            'registrado_por' => $a->registeredBy?->name,
            'created_at' => $a->created_at?->copy()->setTimezone(EventoController::TZ)->toIso8601String(),
            'cedula' => $a->cedula,
            'celular' => $a->celular,
            'fijo' => $a->fijo,
            'direccion' => $a->direccion,
            'fecha_nacimiento' => $a->fecha_nacimiento?->toDateString(),
        ];
    }

    private function territoryAttendee(object $a): array
    {
        return [
            'id' => $a->id,
            'nombres' => $a->nombres,
            'apellidos' => $a->apellidos,
            'cedula' => $a->cedula,
            'celular' => $a->celular,
            'barrio' => $a->barrio,
            'source' => $a->source,
            'link_status' => $a->link_status,
            'en_votantes' => $a->lider_id !== null,
            'lider_id' => $a->lider_id,
            'evento' => ['id' => $a->event_id, 'nombre' => $a->evento, 'starts_at' => EventScope::iso($a->starts_at)],
            'campaign_id' => $a->campaign_id,
        ];
    }

    // Event types of the visible campaigns (and the global ones), inactive included: old events keep theirs.
    private function visibleTypes()
    {
        $visible = $this->campaign()->visibleIds();

        return EventType::query()
            ->when($visible !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('campaign_id')->orWhereIn('campaign_id', $visible)))
            ->orderBy('nombre')->get(['id', 'nombre', 'is_active']);
    }

    // Ids of the visible types whose name contains $text, ignoring case, accents and extra spaces.
    private function typeIdsMatching(string $text): array
    {
        $needle = $this->typeKey($text);

        return $this->visibleTypes()
            ->filter(fn (EventType $t) => $needle !== '' && str_contains($this->typeKey($t->nombre), $needle))
            ->pluck('id')->values()->all();
    }

    private function typeKey(string $value): string
    {
        return Str::lower(Str::squish(Str::ascii($value)));
    }

    // event id => actas (borrador and confirmadas).
    private function actasCount(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        return DB::table('event_actas')->whereIn('event_id', $eventIds)->groupBy('event_id')
            ->selectRaw('event_id, count(*) as total')->pluck('total', 'event_id')->all();
    }

    // event id => open compromisos of its confirmed actas (the ones that are followed up).
    private function openCompromisosCount(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        return DB::table('event_compromisos as c')
            ->join('event_actas as a', 'a.id', '=', 'c.acta_id')
            ->whereIn('c.event_id', $eventIds)
            ->where('a.estado', EventActa::CONFIRMADA)
            ->whereIn('c.estado', EventCompromiso::ABIERTOS)
            ->groupBy('c.event_id')
            ->selectRaw('c.event_id, count(*) as total')->pluck('total', 'c.event_id')->all();
    }

    private function actasOf(string $eventId): array
    {
        return DB::table('event_actas as a')
            ->where('a.event_id', $eventId)
            ->select(['a.id', 'a.titulo', 'a.fecha', 'a.estado'])
            ->selectSub(DB::table('event_compromisos as c')->whereColumn('c.acta_id', 'a.id')->selectRaw('count(*)'), 'compromisos')
            ->selectSub(DB::table('event_compromisos as c')->whereColumn('c.acta_id', 'a.id')
                ->whereIn('c.estado', EventCompromiso::ABIERTOS)->selectRaw('count(*)'), 'abiertos')
            ->orderByDesc('a.fecha')->orderByDesc('a.created_at')->orderBy('a.id')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'titulo' => $a->titulo,
                'fecha' => $a->fecha ? substr((string) $a->fecha, 0, 10) : null,
                'estado' => $a->estado,
                'compromisos' => (int) $a->compromisos,
                'abiertos' => (int) $a->abiertos,
            ])->all();
    }

    // Ids and closed values as they are; free text (a name, a barrio) only as "sent".
    private function auditFilters(array $filters): array
    {
        $filters = array_filter($filters, fn ($v) => $v !== null && $v !== '' && $v !== []);
        foreach (['q', 'barrio', 'tipo'] as $text) {
            if (isset($filters[$text])) {
                $filters[$text] = true;
            }
        }

        return $filters;
    }
}
