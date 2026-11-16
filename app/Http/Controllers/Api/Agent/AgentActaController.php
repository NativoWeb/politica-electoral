<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Concerns\DeliversAgentContactData;
use App\Http\Controllers\Controller;
use App\Http\Controllers\EventoActaController;
use App\Http\Controllers\EventoCompromisoController;
use App\Http\Controllers\OrganizacionActaController;
use App\Http\Requests\Agent\AgentListActasRequest;
use App\Http\Requests\Agent\AgentListCompromisosRequest;
use App\Models\EventActa;
use App\Models\EventCompromiso;
use App\Services\Agent\AgentContactQuota;
use App\Services\Agent\AuthorizedContacts;
use App\Services\Empresarios\OrganizacionScope;
use App\Services\Eventos\Actas\ActaPresenter;
use App\Services\Eventos\Actas\CompromisoListing;
use App\Services\Eventos\EventScope;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

// Read-only access of the WhatsApp agent to the actas de reunión and their compromisos, as the token owner: events'
// with view.eventos, organizations' with view.empresarios, visible campaigns and territory (the web's own lookups).
// An acta lists several persons: their cédula and phone only if they are a lider who authorized their data. Nothing
// here writes; deliveries are audited with ids and counts and those with contact data count against the daily quota.
class AgentActaController extends Controller
{
    use DeliversAgentContactData;

    // What the audit rows of this request are about: an acta, or one compromiso (its id as entity_id).
    private string $entityType = 'acta';

    public function __construct(
        private readonly AgentContactQuota $quota,
        private readonly EventScope $events,
        private readonly OrganizacionScope $orgs,
        private readonly CompromisoListing $listing,
    ) {}

    public function index(AgentListActasRequest $request): JsonResponse
    {
        $f = $request->filters();
        $user = $request->user();
        $events = EventScope::canSeeAttendees($user) && ($f['origen'] ?? null) !== 'organizacion';
        $orgs = OrganizacionScope::canView($user) && ($f['origen'] ?? null) !== 'evento';

        $page = DB::table('event_actas as a')
            ->leftJoin('events as ev', 'ev.id', '=', 'a.event_id')
            ->leftJoin('organizaciones as o', 'o.id', '=', 'a.organizacion_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.created_by')
            ->where(function ($w) use ($events, $orgs) {
                if ($events) {
                    $w->orWhere(fn ($e) => $this->events->scopeEvents($e->whereNotNull('a.event_id')));
                }
                if ($orgs) {
                    $w->orWhere(fn ($o) => $this->orgs->scope($o->whereNotNull('a.organizacion_id'), 'o'));
                }
                if (! $events && ! $orgs) {
                    $w->whereRaw('1 = 0');
                }
            })
            ->when($f['evento_id'] ?? null, fn ($q, $v) => $q->where('a.event_id', $v))
            ->when($f['organizacion_id'] ?? null, fn ($q, $v) => $q->where('a.organizacion_id', $v))
            ->when($f['estado'] ?? null, fn ($q, $v) => $q->where('a.estado', $v))
            ->when($f['q'] ?? null, fn ($q, $v) => $this->listing->whereContains($q, 'a.titulo', $v))
            ->select(['a.id', 'a.titulo', 'a.fecha', 'a.estado', 'a.municipio', 'a.event_id', 'ev.nombre as evento',
                'a.organizacion_id', 'o.nombre as organizacion', 'u.name as created_by'])
            ->selectSub(DB::table('event_compromisos as c')->whereColumn('c.acta_id', 'a.id')->selectRaw('count(*)'), 'compromisos')
            ->selectSub(DB::table('event_compromisos as c')->whereColumn('c.acta_id', 'a.id')
                ->whereIn('c.estado', EventCompromiso::ABIERTOS)->selectRaw('count(*)'), 'abiertos')
            ->orderByDesc('a.created_at')->orderBy('a.id')
            ->paginate($request->perPage(), ['*'], 'page', $request->page());

        $data = $page->getCollection()->map(fn ($a) => [
            'id' => $a->id,
            'titulo' => $a->titulo,
            'fecha' => $a->fecha ? substr((string) $a->fecha, 0, 10) : null,
            'estado' => $a->estado,
            'origen' => $a->event_id ? 'evento' : 'organizacion',
            'evento' => $a->event_id ? ['id' => $a->event_id, 'nombre' => $a->evento] : null,
            'organizacion' => $a->organizacion_id ? ['id' => $a->organizacion_id, 'nombre' => $a->organizacion] : null,
            'municipio' => $a->municipio,
            'compromisos' => (int) $a->compromisos,
            'abiertos' => (int) $a->abiertos,
            'created_by' => $a->created_by,
        ])->values()->all();

        $this->audit($request, 'agent.actas.list', $this->auditFilters($f), [
            'total' => $page->total(), 'entregadas' => count($data), 'page' => $page->currentPage(), 'per_page' => $page->perPage(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(Request $request, string $acta): JsonResponse
    {
        [$payload, $conDatos] = $this->payload($this->findActa($request, $acta));
        $counts = $this->actaCounts($payload, $conDatos);
        if ($blocked = $this->overQuota($request, 'agent.acta.show', [], $counts, $payload['id'])) {
            return $blocked;
        }
        $this->deliver($request, 'agent.acta.show', [], $counts, $payload['id']);

        return response()->json([
            'success' => true,
            'data' => $payload,
            'meta' => ['con_datos' => $conDatos, 'cupo_restante' => $this->quota->remaining($request->user()->id)],
        ]);
    }

    public function pdf(Request $request, string $acta): Response
    {
        [$payload, $conDatos] = $this->payload($this->findActa($request, $acta));
        $counts = $this->actaCounts($payload, $conDatos);
        if ($blocked = $this->overQuota($request, 'agent.acta.pdf', [], $counts, $payload['id'])) {
            return $blocked;
        }

        try {
            $response = Pdf::loadView('eventos.acta', ['acta' => $payload])->setPaper('letter')
                ->setOption('isRemoteEnabled', false)
                ->setOption('isPhpEnabled', false)
                ->download('acta-'.(Str::slug(Str::limit((string) $payload['titulo'], 60, '')) ?: 'reunion').'.pdf');
        } catch (Throwable $e) {
            $this->quota->release($request->user()->id, $conDatos);

            throw $e;
        }
        $this->deliver($request, 'agent.acta.pdf', [], $counts, $payload['id']);
        $response->headers->set('X-Rows-With-Data', (string) $conDatos);

        return $response;
    }

    public function compromisos(AgentListCompromisosRequest $request): JsonResponse
    {
        $f = $request->listingFilters();
        $page = $this->listing->query($f)
            ->when($request->flag('creadas_por_mi'), fn ($q) => $q->where('a.created_by', $request->user()->id))
            ->paginate($request->perPage(), ['*'], 'page', $request->page());

        $responsables = $this->listing->responsables($page->getCollection()->pluck('id')->all());
        $authorized = AuthorizedContacts::of(array_merge([], ...array_map(fn (array $rs) => array_column($rs, 'lider_id'), array_values($responsables))));
        $conContacto = [];
        $data = $page->getCollection()->map(function ($c) use ($responsables, $authorized, &$conContacto) {
            $row = $this->listing->row($c, array_map(function (array $r) use ($authorized, &$conContacto) {
                $r = AuthorizedContacts::filter($r, $authorized, ['telefono'], 'lider_id', $delivered);
                if ($delivered) {
                    $conContacto[$r['lider_id']] = true;
                }

                return $r;
            }, $responsables[$c->id] ?? []));
            unset($row['editable']);

            return $row;
        })->values()->all();

        $conDatos = count($conContacto);
        $filters = $this->auditFilters($f + ['creadas_por_mi' => $request->flag('creadas_por_mi') ?: null]);
        $counts = ['total' => $page->total(), 'entregadas' => count($data), 'con_datos' => $conDatos, 'page' => $page->currentPage(), 'per_page' => $page->perPage()];
        if ($blocked = $this->overQuota($request, 'agent.compromisos.list', $filters, $counts)) {
            return $blocked;
        }
        $this->deliver($request, 'agent.compromisos.list', $filters, $counts);

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

    // The web's detail of one compromiso (404 when its origin is not visible to the user): a responsable's phone only
    // if that person is a lider who authorized it; an avance's file only by name, it is downloaded on the web.
    public function compromiso(Request $request, string $compromiso): JsonResponse
    {
        $this->entityType = 'compromiso';
        $web = app(EventoCompromisoController::class);
        $data = $web->detail($web->find($compromiso));
        unset($data['editable']);

        $authorized = AuthorizedContacts::of(array_column($data['responsables'], 'lider_id'));
        $conContacto = [];
        $data['responsables'] = array_map(function (array $r) use ($authorized, &$conContacto) {
            $r = AuthorizedContacts::filter($r, $authorized, ['telefono'], 'lider_id', $delivered);
            if ($delivered) {
                $conContacto[$r['lider_id']] = true;
            }

            return $r;
        }, $data['responsables']);
        $data['avances'] = array_map(function (array $a) {
            $a['archivo'] = $a['archivo'] ? ['nombre' => $a['archivo']['nombre']] : null;

            return $a;
        }, $data['avances']);

        $conDatos = count($conContacto);
        $counts = ['origen' => $data['origen'], 'responsables' => count($data['responsables']), 'avances' => count($data['avances']), 'con_datos' => $conDatos];
        if ($blocked = $this->overQuota($request, 'agent.compromiso.show', [], $counts, $data['id'])) {
            return $blocked;
        }
        $this->deliver($request, 'agent.compromiso.show', [], $counts, $data['id']);

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => ['con_datos' => $conDatos, 'cupo_restante' => $this->quota->remaining($request->user()->id)],
        ]);
    }

    protected function auditEntityType(): string
    {
        return $this->entityType;
    }

    // The acta by its id alone: its origin decides the permission (403 without it), then the web's own lookup of that
    // origin applies campaign (404) and territory (403).
    private function findActa(Request $request, string $id): array
    {
        abort_unless(Str::isUuid($id), 404);
        $acta = EventActa::find($id);
        abort_unless($acta !== null, 404);

        if ($acta->event_id) {
            abort_unless(EventScope::canSeeAttendees($request->user()), 403, 'No tienes permiso para esta consulta.');
            $web = app(EventoActaController::class);
            $event = $web->findEvent($acta->event_id);

            return [$web->findActa($event, $id), [
                'origen' => 'evento',
                'evento' => ['id' => $event->id, 'nombre' => $event->nombre, 'municipio' => $event->municipio?->canonical_name],
                'organizacion' => null,
            ]];
        }

        abort_unless(OrganizacionScope::canView($request->user()), 403, 'No tienes permiso para esta consulta.');
        $org = $this->orgs->find((string) $acta->organizacion_id);

        return [app(OrganizacionActaController::class)->findActa($org, $id), [
            'origen' => 'organizacion',
            'evento' => null,
            'organizacion' => ['id' => $org->id, 'nombre' => $org->nombre, 'municipio' => $org->municipio()->value('canonical_name')],
        ]];
    }

    // ActaPresenter::payload with the origin, and each participant's cédula/phone and each responsable's phone only
    // if that person is a lider who authorized their data. [payload, persons with contact data delivered]
    private function payload(array $found): array
    {
        [$acta, $origen] = $found;
        $payload = ActaPresenter::payload($acta) + $origen;

        $ids = array_column($payload['participantes'], 'lider_id');
        foreach ($payload['compromisos'] as $c) {
            $ids = [...$ids, ...array_column($c['responsables'], 'lider_id')];
        }
        $authorized = AuthorizedContacts::of($ids);

        $conContacto = [];
        $keep = function (array $person, array $fields) use ($authorized, &$conContacto) {
            $person = AuthorizedContacts::filter($person, $authorized, $fields, 'lider_id', $delivered);
            if ($delivered) {
                $conContacto[$person['lider_id']] = true;
            }

            return $person;
        };
        $payload['participantes'] = array_map(fn (array $p) => $keep($p, ['cedula', 'telefono']), $payload['participantes']);
        $payload['compromisos'] = array_map(function (array $c) use ($keep) {
            $c['responsables'] = array_map(fn (array $r) => $keep($r, ['telefono']), $c['responsables']);

            return $c;
        }, $payload['compromisos']);

        return [$payload, count($conContacto)];
    }

    private function actaCounts(array $payload, int $conDatos): array
    {
        return [
            'origen' => $payload['origen'],
            'participantes' => count($payload['participantes']),
            'compromisos' => count($payload['compromisos']),
            'con_datos' => $conDatos,
        ];
    }

    // Ids and closed values as they are; free text (a title or a person's name) only as "sent".
    private function auditFilters(array $filters): array
    {
        $filters = array_filter($filters, fn ($v) => $v !== null && $v !== '');
        foreach (['q', 'responsable'] as $text) {
            if (isset($filters[$text])) {
                $filters[$text] = true;
            }
        }

        return $filters;
    }
}
