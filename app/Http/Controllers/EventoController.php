<?php

namespace App\Http\Controllers;

use App\Actions\Eventos\ApproveAttendees;
use App\Actions\Eventos\RegisterAttendee;
use App\Exports\GenericExport;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Http\Middleware\AuditActivity;
use App\Http\Requests\Eventos\AttendeeIdsRequest;
use App\Http\Requests\Eventos\AttendeeInput;
use App\Http\Requests\Eventos\EventRequest;
use App\Http\Requests\Eventos\EventTypeRequest;
use App\Http\Requests\Eventos\OperatorAttendeeRequest;
use App\Models\AuditLog;
use App\Models\CampaignEvent;
use App\Models\EventAttendee;
use App\Models\EventType;
use App\Services\Eventos\Ocr\OcrSettings;
use App\Support\Cedula;
use App\Support\PlanillaQr;
use App\Support\Provincias;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

// Eventos especiales (contract in docs/eventos-api.md). Campaign isolation as in Votantes: events of the own
// campaign and its subcampaigns are visible, only the own campaign's are written; a user with a territory only
// reaches events of their municipios.
class EventoController extends Controller
{
    use ScopesTerritory;

    public const TZ = 'America/Bogota';

    public const PLANILLA_ROWS = 12;

    public const PLANILLA_MAX_PAGES = 20;

    public function index(Request $request): Response
    {
        $filters = [
            'provincia' => $this->uuidOrNull($request->query('provincia')),
            'municipio' => $this->uuidOrNull($request->query('municipio')),
            'barrio' => $this->textOrNull($request->query('barrio')),
            'tipo' => $this->uuidOrNull($request->query('tipo')),
            'status' => in_array($request->query('status'), CampaignEvent::STATUSES, true) ? $request->query('status') : null,
            'desde' => $this->dateOrNull($request->query('desde')),
            'hasta' => $this->dateOrNull($request->query('hasta')),
            'q' => $this->textOrNull($request->query('q')),
            'campana' => $this->campaign()->filterId($this->uuidOrNull($request->query('campana'))),
        ];
        $canSeeAttendees = $this->canView();

        $query = CampaignEvent::query()->with(['type', 'municipio', 'provincia', 'campaign', 'barrios']);
        $this->campaign()->scope($query, 'events.campaign_id');
        $this->scopeToTerritory($query, 'events.municipio_id');
        if ($canSeeAttendees) {
            $query->withCount('attendees');
        }

        $query
            ->when($filters['campana'], fn ($q, $v) => $q->where('campaign_id', $v))
            ->when($filters['provincia'], fn ($q, $v) => $q->where('provincia_id', $v))
            ->when($filters['municipio'], fn ($q, $v) => $q->where('municipio_id', $v))
            ->when($filters['tipo'], fn ($q, $v) => $q->where('event_type_id', $v))
            ->when($filters['status'], fn ($q, $v) => $q->where('status', $v))
            ->when($filters['desde'], fn ($q, $v) => $q->where('starts_at', '>=', Carbon::parse($v, self::TZ)->startOfDay()->utc()))
            ->when($filters['hasta'], fn ($q, $v) => $q->where('starts_at', '<=', Carbon::parse($v, self::TZ)->endOfDay()->utc()))
            ->when($filters['q'], fn ($q, $v) => $this->whereContains($q, 'nombre', $v))
            ->when($filters['barrio'], fn ($q, $v) => $q->whereHas('barrios', fn ($b) => $b->where(
                'nombre_normalizado', 'like', '%'.$this->escapeLike(AttendeeInput::barrioKey($v)).'%'
            )));

        $eventos = $query->orderByDesc('starts_at')->paginate(20)->withQueryString()
            ->through(fn (CampaignEvent $e) => $this->summary($e) + [
                'asistentes_total' => $canSeeAttendees ? (int) $e->attendees_count : null,
            ]);

        return Inertia::render('Eventos/Index', [
            'eventos' => $eventos,
            'filters' => $filters,
            'provincias' => $this->provincias(),
            'tipos' => $this->tipos(),
            'statuses' => CampaignEvent::STATUSES,
            'can' => ['manage' => $this->canManage(), 'view' => $canSeeAttendees, 'register' => $this->canRegister()],
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        $event = $this->findEvent($id);
        $filters = $this->attendeeFilters($request);

        $asistentes = $this->attendeesQuery($event, $filters)->paginate(50)->withQueryString();
        $fichas = $this->linkedFichas($asistentes->getCollection());
        $asistentes->through(fn (EventAttendee $a) => $this->attendee($a) + [
            'ficha' => $a->lider_id ? ($fichas[$a->lider_id] ?? null) : null,
        ]);

        return Inertia::render('Eventos/Show', [
            'evento' => $this->detail($event),
            'asistentes' => $asistentes,
            'kpis' => $this->kpis($event),
            'filters' => $filters,
            'tipos' => $this->tipos(),
            'provincias' => $this->provincias(),
            'can' => [
                'manage' => $this->canManage(),
                'register' => $this->canRegister(),
                'edit' => $this->canManage() && $this->campaign()->isOwn($event->campaign_id),
                // Reading printed sheets with AI (§13): only while both switches are on.
                'ocr' => $this->canManage() && $this->campaign()->isOwn($event->campaign_id) && OcrSettings::enabledFor($event->campaign_id),
            ],
        ]);
    }

    // GET /eventos/{id}/asistentes.xlsx: the attendees the detail shows with the same filters, unpaginated.
    public function exportAttendees(Request $request, string $id): BinaryFileResponse
    {
        $event = $this->findEvent($id);
        $filters = $this->attendeeFilters($request);
        ini_set('memory_limit', '256M');

        $attendees = $this->attendeesQuery($event, $filters)->get();
        $rows = $attendees->map(fn (EventAttendee $a) => [
            'nombres' => $a->nombres,
            'apellidos' => $a->apellidos,
            'cedula' => $a->cedula,
            'celular' => $a->celular,
            'fijo' => $a->fijo,
            'fecha_nacimiento' => $a->fecha_nacimiento?->format('d/m/Y'),
            'direccion' => $a->direccion,
            'barrio' => $a->barrio,
            'origen' => EventoIntegracionController::SOURCES[$a->source] ?? $a->source,
            'registrado_por' => $a->registeredBy?->name,
            'estado' => EventoIntegracionController::LINK_STATUSES[$a->link_status] ?? $a->link_status,
            'en_votantes' => $a->lider_id ? 'Sí' : 'No',
            'fecha_registro' => $a->created_at?->copy()->setTimezone(self::TZ)->format('d/m/Y g:i a'),
        ])->all();

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'EVENTO_ASISTENTES_EXPORTADOS',
            'entity_type' => 'evento',
            'entity_id' => $event->id,
            'metadata' => ['route' => 'eventos.asistentes.excel', 'filas' => count($rows), 'filtros' => array_keys(array_filter($filters))],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $columns = ['nombres', 'apellidos', 'cedula', 'celular', 'fijo', 'fecha_nacimiento', 'direccion', 'barrio',
            'origen', 'registrado_por', 'estado', 'en_votantes', 'fecha_registro'];
        $labels = [
            'nombres' => 'NOMBRES', 'apellidos' => 'APELLIDOS', 'cedula' => 'CÉDULA', 'celular' => 'CELULAR', 'fijo' => 'TELÉFONO FIJO',
            'fecha_nacimiento' => 'FECHA DE NACIMIENTO', 'direccion' => 'DIRECCIÓN', 'barrio' => 'BARRIO',
            'origen' => 'ORIGEN', 'registrado_por' => 'REGISTRADO POR',
            'estado' => 'ESTADO DE VÍNCULO', 'en_votantes' => 'EN VOTANTES', 'fecha_registro' => 'FECHA DE REGISTRO',
        ];
        $filename = 'asistentes-'.(Str::slug(Str::limit($event->nombre, 60, '')) ?: 'evento').'.xlsx';

        return Excel::download(new GenericExport($rows, $columns, 'Asistentes', $labels), $filename);
    }

    public function store(EventRequest $request): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $this->authorizeGeo($data['municipio_id']);
        $this->assertDateOrder($data, null);
        $own = $this->campaign()->ownId();

        $event = DB::transaction(function () use ($data, $own, $request) {
            $event = new CampaignEvent($this->eventAttributes($data));
            $event->forceFill([
                'campaign_id' => $own,
                'status' => 'borrador',
                'public_token' => Str::random(43),
                'created_by' => $request->user()->id,
            ])->save();
            $this->syncLists($event, $data);

            return $event;
        });

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Evento creado.', 'data' => $this->detail($event->fresh())], 201);
        }

        return redirect("/eventos/{$event->id}")->with('success', 'Evento creado.');
    }

    public function update(EventRequest $request, string $id): JsonResponse|RedirectResponse
    {
        $event = $this->findEvent($id, true);
        $data = $request->validated();
        if (isset($data['municipio_id'])) {
            $this->authorizeGeo($data['municipio_id']);
        }
        $this->assertDateOrder($data, $event);

        DB::transaction(function () use ($event, $data) {
            $event->fill($this->eventAttributes($data))->save();
            $this->syncLists($event, $data);
        });

        return $this->done($request, 'Evento actualizado.', $this->detail($event->fresh()));
    }

    public function destroy(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $event = $this->findEvent($id, true);
        if (EventAttendee::where('event_id', $event->id)->exists()) {
            throw ValidationException::withMessages(['evento' => 'El evento ya tiene asistentes: cancélelo en lugar de borrarlo.']);
        }
        $event->delete();

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Evento borrado.']);
        }

        return redirect('/eventos')->with('success', 'Evento borrado.');
    }

    public function open(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $event = $this->findEvent($id, true);
        $this->assertTransition($event, ['borrador', 'cerrado'], 'Solo se puede abrir un evento en borrador o cerrado.');

        $event->forceFill(['status' => 'abierto'])->save();

        return $this->statusDone($request, $event, 'Evento abierto: el QR ya recibe registros.');
    }

    public function close(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $event = $this->findEvent($id, true);
        $this->assertTransition($event, ['abierto'], 'Solo se puede cerrar un evento abierto.');
        $event->forceFill(['status' => 'cerrado'])->save();

        return $this->statusDone($request, $event, 'Evento cerrado: el QR ya no recibe registros.');
    }

    public function cancel(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $event = $this->findEvent($id, true);
        $this->assertTransition($event, ['borrador', 'abierto', 'cerrado'], 'El evento ya está cancelado.');
        $event->forceFill(['status' => 'cancelado'])->save();

        return $this->statusDone($request, $event, 'Evento cancelado.');
    }

    public function regenerateToken(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $event = $this->findEvent($id, true);
        $event->forceFill(['public_token' => Str::random(43)])->save();

        return $this->statusDone($request, $event, 'Se generó un QR nuevo: el anterior ya no funciona.');
    }

    public function operador(Request $request, string $id): Response
    {
        $event = $this->findEvent($id, true);

        return Inertia::render('Eventos/Operador', [
            'evento' => array_merge($this->summary($event), [
                'public_url' => $this->publicUrl($event),
                'accepts_registrations' => $event->acceptsOperatorRegistrations(),
            ]),
            'registrados' => EventAttendee::where('event_id', $event->id)->count(),
            'mis_registros' => EventAttendee::where('event_id', $event->id)->where('registered_by', $request->user()->id)
                ->orderByDesc('created_at')->limit(20)->get(['id', 'nombres', 'apellidos', 'created_at'])
                ->map(fn ($a) => ['id' => $a->id, 'nombres' => $a->nombres, 'apellidos' => $a->apellidos, 'created_at' => $this->iso($a->created_at)]),
            'client_uuid' => (string) Str::uuid(),
        ]);
    }

    // Printable sign-in sheet: 12 numbered rows per page, a QR with the event id and page number on each page.
    public function planilla(Request $request, string $id): HttpResponse
    {
        $event = $this->findEvent($id);
        $start = $event->starts_at?->copy()->setTimezone(self::TZ);
        $filename = 'planilla-'.(Str::slug(Str::limit($event->nombre, 60, '')) ?: 'evento')
            .($start ? '-'.$start->format('Y-m-d') : '').'.pdf';

        // The installed app probes with HEAD before navigating: same access checks, no rendering and no audit.
        if ($request->isMethod('HEAD')) {
            return response('', 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename='.$filename,
            ]);
        }

        $paginas = self::planillaPages($request->query('paginas'));
        ini_set('memory_limit', '256M');

        $pages = array_map(fn (int $page) => [
            'numero' => $page,
            'qr' => PlanillaQr::png(PlanillaQr::payload($event->id, $page)),
        ], range(1, $paginas));

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'EVENTO_PLANILLA_DESCARGADA',
            'entity_type' => 'evento',
            'entity_id' => $event->id,
            'metadata' => ['route' => 'eventos.planilla', 'paginas' => $paginas],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return Pdf::loadView('eventos.planilla', [
            'evento' => $event,
            'pages' => $pages,
            'total' => $paginas,
            'filas' => self::PLANILLA_ROWS,
            'cuando' => $this->planillaWhen($event),
        ])->setPaper('letter', 'landscape')
            ->setOption('isRemoteEnabled', false)
            ->setOption('isPhpEnabled', false)
            ->setOption('isFontSubsettingEnabled', true)
            ->download($filename);
    }

    // ?paginas= defaults to 3 and is clamped to 1..20.
    public static function planillaPages($value): int
    {
        $pages = filter_var($value, FILTER_VALIDATE_INT);

        return $pages === false ? 3 : max(1, min(self::PLANILLA_MAX_PAGES, $pages));
    }

    private function planillaWhen(CampaignEvent $event): ?string
    {
        if (! $event->starts_at) {
            return null;
        }
        $start = $event->starts_at->copy()->setTimezone(self::TZ)->locale('es');
        $end = $event->ends_at?->copy()->setTimezone(self::TZ)->locale('es');
        $text = $start->translatedFormat('l j \d\e F \d\e Y, g:i a');
        if ($end) {
            $text .= ' – '.($end->isSameDay($start) ? $end->translatedFormat('g:i a') : $end->translatedFormat('l j \d\e F, g:i a'));
        }

        return $text;
    }

    public function registerAttendee(OperatorAttendeeRequest $request, string $id, RegisterAttendee $register): JsonResponse
    {
        $event = $this->findEvent($id, true);
        if (! $event->acceptsOperatorRegistrations()) {
            return response()->json(['success' => false, 'message' => 'El evento ya no recibe registros.'], 410);
        }

        $result = $register->handle($event, $request->validated(), 'operador', $request->user(), $request->ip(), $request->userAgent());
        if ($result['conflict']) {
            return response()->json(['success' => false, 'message' => 'Ese registro pertenece a otro evento.'], 409);
        }

        $payload = ['id' => $result['attendee']->id, 'client_uuid' => $result['attendee']->client_uuid, 'duplicate' => ! $result['created']];

        return $result['created']
            ? response()->json(['success' => true, 'message' => 'Asistente registrado.', 'data' => $payload], 201)
            : response()->json(['success' => true, 'message' => 'Esta persona ya estaba registrada en el evento.', 'data' => $payload]);
    }

    public function approve(AttendeeIdsRequest $request, string $id, ApproveAttendees $approve): JsonResponse|RedirectResponse
    {
        $event = $this->findEvent($id, true);
        $ids = $this->attendeeIds($event, $request->validated('ids'));
        $counts = $approve->handle($event, $ids, $request->user());

        $message = "Aprobados: {$counts['aprobados']}. Vinculados a una ficha existente: {$counts['vinculados']}."
            .($counts['omitidos'] ? " Omitidos (no estaban pendientes): {$counts['omitidos']}." : '');

        return $this->done($request, $message, $counts);
    }

    public function discard(AttendeeIdsRequest $request, string $id): JsonResponse|RedirectResponse
    {
        $event = $this->findEvent($id, true);
        $ids = $this->attendeeIds($event, $request->validated('ids'));
        $descartados = EventAttendee::where('event_id', $event->id)->whereIn('id', $ids)
            ->where('link_status', 'pendiente')->update(['link_status' => 'descartado', 'updated_at' => now()]);
        $counts = ['descartados' => $descartados, 'omitidos' => count($ids) - $descartados];

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'EVENTO_ASISTENTES_DESCARTADOS',
            'entity_type' => 'evento',
            'entity_id' => $event->id,
            'metadata' => ['attendee_ids' => $ids, ...$counts],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return $this->done($request, "Descartados: {$descartados}.", $counts);
    }

    // A type for the own campaign; a visible active one with the same name (ignoring case and accents) is returned instead.
    public function storeType(EventTypeRequest $request): JsonResponse
    {
        $own = $this->campaign()->ownId();
        if ($own === null) {
            throw ValidationException::withMessages(['nombre' => 'Su usuario no tiene una campaña asignada.']);
        }
        $nombre = $request->validated('nombre');
        $key = $this->typeKey($nombre);

        [$type, $created] = DB::transaction(function () use ($own, $nombre, $key) {
            DB::table('campaigns')->where('id', $own)->lockForUpdate()->first(['id']);
            $existing = EventType::where('is_active', true)
                ->where(fn ($q) => $q->whereNull('campaign_id')->orWhere('campaign_id', $own))
                ->orderByRaw('campaign_id IS NULL DESC')->orderBy('created_at')
                ->get(['id', 'nombre'])
                ->first(fn ($t) => $this->typeKey($t->nombre) === $key);
            if ($existing) {
                return [$existing, false];
            }
            $type = new EventType(['nombre' => $nombre, 'is_active' => true]);
            $type->forceFill(['campaign_id' => $own])->save();

            return [$type, true];
        });

        request()->attributes->set(AuditActivity::AFTER_STATE, ['event_type_id' => $type->id, 'nombre' => $type->nombre, 'creado' => $created]);

        return response()->json([
            'success' => true,
            'message' => $created ? 'Tipo de evento creado.' : 'Ese tipo de evento ya existía.',
            'created' => $created,
            'data' => ['id' => $type->id, 'nombre' => $type->nombre, 'usable' => true, 'editable' => $this->canManageType($type->campaign_id)],
        ], $created ? 201 : 200);
    }

    // Renames a type of the own campaign (a global one only the superadmin); the name cannot repeat another usable active type.
    public function updateType(EventTypeRequest $request, string $id): JsonResponse
    {
        $type = $this->manageableType($id);
        $before = ['event_type_id' => $type->id, 'nombre' => $type->nombre];
        $nombre = $request->validated('nombre');
        $key = $this->typeKey($nombre);

        DB::transaction(function () use ($type, $nombre, $key) {
            if ($type->campaign_id !== null) {
                DB::table('campaigns')->where('id', $type->campaign_id)->lockForUpdate()->first(['id']);
            }
            EventType::whereKey($type->id)->lockForUpdate()->first(['id']);
            $duplicate = EventType::where('is_active', true)->whereKeyNot($type->id)
                ->when($type->campaign_id !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('campaign_id')->orWhere('campaign_id', $type->campaign_id)))
                ->get(['nombre'])
                ->contains(fn ($t) => $this->typeKey($t->nombre) === $key);
            if ($duplicate) {
                throw ValidationException::withMessages(['nombre' => 'Ya existe un tipo con ese nombre.']);
            }
            $type->forceFill(['nombre' => $nombre])->save();
        });

        request()->attributes->set(AuditActivity::BEFORE_STATE, $before);
        request()->attributes->set(AuditActivity::AFTER_STATE, ['event_type_id' => $type->id, 'nombre' => $type->nombre]);

        return response()->json([
            'success' => true,
            'message' => 'Tipo de evento actualizado.',
            'data' => ['id' => $type->id, 'nombre' => $type->nombre, 'usable' => true, 'editable' => true],
        ]);
    }

    // Deletes an unused type; one that any event uses (even a deleted one) is deactivated so those events keep it.
    public function destroyType(Request $request, string $id): JsonResponse
    {
        $type = $this->manageableType($id);

        $deleted = DB::transaction(function () use ($type) {
            EventType::whereKey($type->id)->lockForUpdate()->first(['id']);
            if (CampaignEvent::withTrashed()->where('event_type_id', $type->id)->exists()) {
                $type->forceFill(['is_active' => false])->save();

                return false;
            }
            $type->delete();

            return true;
        });

        $request->attributes->set(AuditActivity::BEFORE_STATE, ['event_type_id' => $type->id, 'nombre' => $type->nombre, 'is_active' => true]);
        $request->attributes->set(AuditActivity::AFTER_STATE, ['event_type_id' => $type->id, 'resultado' => $deleted ? 'borrado' : 'desactivado']);

        return response()->json([
            'success' => true,
            'deleted' => $deleted,
            'deactivated' => ! $deleted,
            'message' => $deleted ? 'Tipo de evento eliminado.' : 'Se desactivó: ya hay eventos con este tipo.',
        ]);
    }

    // Lideres of the own campaign (and territory) to pick an organizer.
    public function searchOrganizers(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $like = '%'.$this->escapeLike(Str::lower($q)).'%';
        $cedula = '%'.$this->escapeLike(Cedula::normalize($q)).'%';
        $rows = $this->scopeToTerritory($this->ownLideres())
            ->where('is_active', true)
            ->where(fn ($w) => $w->whereRaw('LOWER(nombre) LIKE ?', [$like])->orWhere('cedula', 'like', $cedula))
            ->orderBy('nombre')->limit(10)
            ->get(['id', 'nombre', 'cedula', 'telefono', 'municipio']);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    // Electoral persons (shared, read-only data) to pick a figure, with their latest candidacy so namesakes can be told apart.
    public function searchFigures(Request $request): JsonResponse
    {
        $q = Str::upper(Str::ascii(trim((string) $request->query('q', ''))));
        if (mb_strlen($q) < 3) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $persons = DB::table('persons')
            ->whereNull('deleted_at')->whereNull('merged_into_id')
            ->where('normalized_name', 'like', '%'.$this->escapeLike($q).'%')
            ->orderBy('full_name')->limit(10)
            ->get(['id', 'full_name']);

        $latest = DB::table('candidacies as c')
            ->join('contests as con', 'c.contest_id', '=', 'con.id')
            ->join('electoral_events as ee', 'con.electoral_event_id', '=', 'ee.id')
            ->leftJoin('offices as o', 'con.office_id', '=', 'o.id')
            ->leftJoin('corporations as co', 'con.corporation_id', '=', 'co.id')
            ->leftJoin('geographic_units as g', 'con.geographic_unit_id', '=', 'g.id')
            ->whereIn('c.person_id', $persons->pluck('id'))
            ->orderByDesc('ee.election_date')
            ->get(['c.person_id', 'o.name as office', 'co.name as corporation', 'con.name as contest', 'ee.election_date', 'g.canonical_name as lugar'])
            ->unique('person_id')
            ->keyBy('person_id');

        $rows = $persons->map(function ($p) use ($latest) {
            $c = $latest[$p->id] ?? null;

            return [
                'id' => $p->id,
                'nombre' => $p->full_name,
                'ultimo_cargo' => $c ? ($c->office ?? $c->corporation ?? $c->contest) : null,
                'ultimo_anio' => $c ? (int) substr((string) $c->election_date, 0, 4) : null,
                'ultimo_lugar' => $c?->lugar,
            ];
        });

        return response()->json(['success' => true, 'data' => $rows]);
    }

    private function findEvent(string $id, bool $write = false): CampaignEvent
    {
        abort_unless(Str::isUuid($id), 404);
        $event = CampaignEvent::with(['type', 'municipio', 'provincia', 'campaign', 'barrios'])->findOrFail($id);
        $this->authorizeCampaign($event->campaign_id, $write);
        $this->authorizeGeo($event->municipio_id);

        return $event;
    }

    private function eventAttributes(array $data): array
    {
        $attributes = collect($data)->only(['nombre', 'descripcion', 'event_type_id', 'sitio_encuentro', 'municipio_id'])->all();
        foreach (['starts_at', 'ends_at', 'registration_closes_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field] === null ? null : Carbon::parse($data[$field], self::TZ)->utc();
            }
        }
        if (isset($data['municipio_id'])) {
            $attributes['provincia_id'] = DB::table('geographic_units')->where('id', $data['municipio_id'])->value('parent_id');
        }

        return $attributes;
    }

    // A PUT that only moves starts_at (or only ends_at) is compared with what is stored.
    private function assertDateOrder(array $data, ?CampaignEvent $event): void
    {
        $start = array_key_exists('starts_at', $data) ? Carbon::parse($data['starts_at'], self::TZ) : $event?->starts_at;
        foreach (['ends_at' => 'La hora de fin no puede ser antes del inicio.', 'registration_closes_at' => 'El cierre del registro no puede ser antes del inicio.'] as $field => $message) {
            $value = array_key_exists($field, $data)
                ? ($data[$field] === null ? null : Carbon::parse($data[$field], self::TZ))
                : $event?->{$field};
            if ($start && $value && $value->lt($start)) {
                throw ValidationException::withMessages([$field => $message]);
            }
        }
    }

    private function syncLists(CampaignEvent $event, array $data): void
    {
        if (array_key_exists('barrios', $data)) {
            $event->barrios()->delete();
            collect($data['barrios'])
                ->map(fn ($b) => trim(preg_replace('/\s+/u', ' ', $b)))
                ->filter()
                ->unique(fn ($b) => AttendeeInput::barrioKey($b))
                ->each(fn ($b) => $event->barrios()->create(['nombre' => $b, 'nombre_normalizado' => AttendeeInput::barrioKey($b)]));
        }

        if (array_key_exists('organizadores', $data)) {
            $liderIds = collect($data['organizadores'])->pluck('lider_id')->filter()->unique()->values();
            if ($liderIds->isNotEmpty()) {
                $found = $this->scopeToTerritory($this->ownLideres())->where('is_active', true)->whereIn('id', $liderIds)->count();
                if ($found !== $liderIds->count()) {
                    throw ValidationException::withMessages(['organizadores' => 'Un organizador elegido no es un líder activo de su campaña y territorio.']);
                }
            }
            $event->organizers()->delete();
            foreach ($data['organizadores'] as $o) {
                $event->organizers()->create([
                    'lider_id' => $o['lider_id'] ?? null,
                    'nombre' => trim($o['nombre']),
                    'cedula' => Cedula::normalize($o['cedula'] ?? null),
                    'telefono' => $o['telefono'] ?? null,
                    'rol' => $o['rol'] ?? null,
                ]);
            }
        }

        if (array_key_exists('figuras', $data)) {
            $event->figures()->delete();
            foreach ($data['figuras'] as $f) {
                $event->figures()->create(['person_id' => $f['person_id'] ?? null, 'nombre' => trim($f['nombre']), 'cargo' => $f['cargo'] ?? null]);
            }
        }
    }

    private function assertTransition(CampaignEvent $event, array $from, string $message): void
    {
        if (! in_array($event->status, $from, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    // Every id must be an attendee of this event.
    private function attendeeIds(CampaignEvent $event, array $ids): array
    {
        $found = EventAttendee::where('event_id', $event->id)->whereIn('id', $ids)->count();
        if ($found !== count($ids)) {
            throw ValidationException::withMessages(['ids' => 'Algún asistente seleccionado no pertenece a este evento.']);
        }

        return array_values($ids);
    }

    private function done(Request $request, string $message, array $data): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => $message, 'data' => $data]);
        }

        return back()->with('success', $message);
    }

    private function statusDone(Request $request, CampaignEvent $event, string $message): JsonResponse|RedirectResponse
    {
        return $this->done($request, $message, [
            'status' => $event->status,
            'public_url' => $this->publicUrl($event),
            'public_open' => $this->publicOpen($event),
        ]);
    }

    private function summary(CampaignEvent $e): array
    {
        return [
            'id' => $e->id,
            'nombre' => $e->nombre,
            'status' => $e->status,
            'tipo' => $e->type ? ['id' => $e->type->id, 'nombre' => $e->type->nombre] : null,
            'starts_at' => $this->iso($e->starts_at),
            'ends_at' => $this->iso($e->ends_at),
            'registration_closes_at' => $this->iso($e->registration_closes_at),
            'sitio_encuentro' => $e->sitio_encuentro,
            'provincia' => $e->provincia ? ['id' => $e->provincia->id, 'nombre' => Provincias::display($e->provincia->canonical_name)] : null,
            'municipio' => $e->municipio ? ['id' => $e->municipio->id, 'nombre' => $e->municipio->canonical_name] : null,
            'barrios' => $e->barrios->pluck('nombre')->values()->all(),
            'campaign' => $e->campaign ? ['id' => $e->campaign->id, 'nombre' => $e->campaign->name] : null,
            'editable' => $this->canManage() && $this->campaign()->isOwn($e->campaign_id),
        ];
    }

    private function detail(CampaignEvent $e): array
    {
        $e->loadMissing(['type', 'municipio', 'provincia', 'campaign', 'barrios', 'organizers', 'figures', 'creator:id,name']);

        return $this->summary($e) + [
            'descripcion' => $e->descripcion,
            'consent_text_id' => $e->consent_text_id,
            'public_url' => ($this->canManage() || $this->canRegister()) ? $this->publicUrl($e) : null,
            'public_open' => $this->publicOpen($e),
            'organizadores' => $e->organizers->map(fn ($o) => $o->only(['id', 'lider_id', 'nombre', 'cedula', 'telefono', 'rol']))->values()->all(),
            'figuras' => $e->figures->map(fn ($f) => $f->only(['id', 'person_id', 'nombre', 'cargo']))->values()->all(),
            'created_by' => $e->creator ? ['id' => $e->creator->id, 'name' => $e->creator->name] : null,
            'created_at' => $this->iso($e->created_at),
        ];
    }

    private function attendee(EventAttendee $a): array
    {
        return [
            'id' => $a->id,
            'nombres' => $a->nombres,
            'apellidos' => $a->apellidos,
            'cedula' => $a->cedula,
            'celular' => $a->celular,
            'fijo' => $a->fijo,
            'fecha_nacimiento' => $a->fecha_nacimiento?->toDateString(),
            'direccion' => $a->direccion,
            'barrio' => $a->barrio,
            'nota' => $a->nota,
            'source' => $a->source,
            'link_status' => $a->link_status,
            'lider_id' => $a->lider_id,
            'registered_by' => $a->registeredBy ? ['id' => $a->registeredBy->id, 'name' => $a->registeredBy->name] : null,
            'created_at' => $this->iso($a->created_at),
        ];
    }

    private function attendeeFilters(Request $request): array
    {
        return [
            'link_status' => in_array($request->query('link_status'), EventAttendee::LINK_STATUSES, true) ? $request->query('link_status') : null,
            'source' => in_array($request->query('source'), ['qr', 'operador', 'excel', 'ocr'], true) ? $request->query('source') : null,
            'q' => $this->textOrNull($request->query('q')),
        ];
    }

    private function attendeesQuery(CampaignEvent $event, array $filters)
    {
        return EventAttendee::where('event_id', $event->id)->with('registeredBy:id,name')
            ->when($filters['link_status'], fn ($q, $v) => $q->where('link_status', $v))
            ->when($filters['source'], fn ($q, $v) => $q->where('source', $v))
            ->when($filters['q'], function ($q, $v) {
                $q->where(fn ($w) => $this->whereContains($w, "nombres || ' ' || apellidos", $v)
                    ->orWhere('cedula', 'like', '%'.$this->escapeLike(Cedula::normalize($v)).'%'));
            })
            ->orderByDesc('created_at')->orderBy('id');
    }

    // Cargo of the linked ficha and whether it is a registered lider (not a ficha created from an event), only for
    // users who may see Votantes or the Mapa and only inside their territory. [lider id => ['cargo', 'lider']].
    private function linkedFichas($attendees): array
    {
        $user = request()->user();
        $ids = $attendees->pluck('lider_id')->filter()->unique()->values()->all();
        if ($ids === [] || ! ($user?->hasPermission('view.votantes') || $user?->hasPermission('view.mapa'))) {
            return [];
        }

        return $this->scopeToTerritory(DB::table('lideres')->whereIn('id', $ids))->get(['id', 'cargo', 'tipo'])
            ->mapWithKeys(fn ($l) => [$l->id => ['cargo' => $l->cargo, 'lider' => $l->tipo !== ApproveAttendees::TIPO]])->all();
    }

    private function kpis(CampaignEvent $event): array
    {
        $byStatus = EventAttendee::where('event_id', $event->id)->groupBy('link_status')
            ->select('link_status', DB::raw('count(*) as total'))->pluck('total', 'link_status');
        $bySource = EventAttendee::where('event_id', $event->id)->groupBy('source')
            ->select('source', DB::raw('count(*) as total'))->pluck('total', 'source');

        return [
            'total' => (int) $byStatus->sum(),
            'vinculados' => (int) ($byStatus['vinculado'] ?? 0),
            'pendientes' => (int) ($byStatus['pendiente'] ?? 0),
            'aprobados' => (int) ($byStatus['aprobado'] ?? 0),
            'descartados' => (int) ($byStatus['descartado'] ?? 0),
            'qr' => (int) ($bySource['qr'] ?? 0),
            'operador' => (int) ($bySource['operador'] ?? 0),
            'excel' => (int) ($bySource['excel'] ?? 0),
            'ocr' => (int) ($bySource['ocr'] ?? 0),
        ];
    }

    public static function publicOpen(CampaignEvent $event): bool
    {
        return ! $event->trashed()
            && $event->status === 'abierto'
            && now()->lt($event->publicClosesAt());
    }

    private function publicUrl(CampaignEvent $event): string
    {
        return route('eventos.publico.show', $event->public_token);
    }

    // Provinces with their municipios, limited to the user's territory.
    private function provincias(): array
    {
        $municipios = $this->scopeToTerritory(DB::table('geographic_units')->where('type', 'municipality'), 'id')
            ->orderBy('canonical_name')->get(['id', 'canonical_name as name', 'parent_id'])->groupBy('parent_id');

        return DB::table('geographic_units')->where('type', 'province')->orderBy('canonical_name')->get(['id', 'canonical_name'])
            ->filter(fn ($p) => $municipios->has($p->id))
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => Provincias::display($p->canonical_name),
                'municipios' => $municipios[$p->id]->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values()->all(),
            ])
            ->values()->all();
    }

    private function tipos(): array
    {
        $visible = $this->campaign()->visibleIds();
        $own = $this->campaign()->ownId();

        return EventType::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('campaign_id')->when($visible !== null, fn ($q) => $q->orWhereIn('campaign_id', $visible)))
            ->orderBy('nombre')->get(['id', 'nombre', 'campaign_id'])
            ->map(fn ($t) => [
                'id' => $t->id,
                'nombre' => $t->nombre,
                'usable' => $t->campaign_id === null || $t->campaign_id === $own,
                'editable' => $this->canManageType($t->campaign_id),
            ])
            ->all();
    }

    // Active type the user may rename or delete: one of the own campaign, or a global one for the superadmin.
    private function manageableType(string $id): EventType
    {
        $type = EventType::where('is_active', true)->find($id);
        abort_unless($type && ($type->campaign_id === null || $this->campaign()->isVisible($type->campaign_id)), 404);
        abort_unless($this->canManageType($type->campaign_id), 403, 'No puede modificar este tipo de evento.');

        return $type;
    }

    private function canManageType(?string $campaignId): bool
    {
        if ($campaignId === null) {
            return $this->campaign()->isSuperadmin();
        }
        $own = $this->campaign()->ownId();

        return $own !== null && $campaignId === $own;
    }

    private function typeKey(string $nombre): string
    {
        return Str::lower(Str::squish(Str::ascii($nombre)));
    }

    private function canManage(): bool
    {
        return (bool) request()->user()?->hasPermission('manage.eventos');
    }

    private function canView(): bool
    {
        return $this->canManage() || (bool) request()->user()?->hasPermission('view.eventos');
    }

    private function canRegister(): bool
    {
        return $this->canManage() || (bool) request()->user()?->hasPermission('register.asistentes');
    }

    private function iso($date): ?string
    {
        return $date ? Carbon::parse($date)->setTimezone(self::TZ)->toIso8601String() : null;
    }

    // Case- and accent-insensitive "contains" on a column expression: unaccent on PostgreSQL, LOWER elsewhere (SQLite tests).
    private function whereContains($query, string $expression, string $value)
    {
        $like = '%'.$this->escapeLike(Str::lower($value)).'%';
        if (DB::connection()->getDriverName() === 'pgsql') {
            return $query->whereRaw("unaccent(LOWER({$expression})) LIKE unaccent(?)", [$like]);
        }

        return $query->whereRaw("LOWER({$expression}) LIKE ?", [$like]);
    }

    private function escapeLike(?string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $value);
    }

    private function uuidOrNull($value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }

    private function textOrNull($value): ?string
    {
        return is_string($value) && trim($value) !== '' ? Str::limit(trim($value), 100, '') : null;
    }

    private function dateOrNull($value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) ? $value : null;
    }
}
