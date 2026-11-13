<?php

namespace App\Http\Controllers;

use App\Exports\GenericExport;
use App\Exports\SheetsExport;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\AuditLog;
use App\Services\Eventos\EventScope;
use App\Services\Eventos\TerritoryView;
use App\Support\Provincias;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

// Eventos especiales, phase 4 (docs/eventos-api.md §12): the events of a person's ficha and the territory view.
// Both need view.eventos or manage.eventos (route) and only reach events of visible campaigns inside the territory.
class EventoIntegracionController extends Controller
{
    use ScopesTerritory;

    public const SOURCES = ['qr' => 'QR', 'operador' => 'Operador', 'excel' => 'Excel', 'ocr' => 'Planilla leída con IA', 'acta' => 'Acta de reunión'];

    public const LINK_STATUSES = [
        'vinculado' => 'Ya estaba en Votantes', 'pendiente' => 'Pendiente de aprobar', 'aprobado' => 'Aprobado (en Votantes)',
        'descartado' => 'Descartado', 'sin_cedula' => 'Sin cédula', 'sin_autorizacion' => 'Pendiente de aprobar',
    ];

    private ?array $campaignNames = null;

    public function __construct(private EventScope $events, private TerritoryView $territory) {}

    // GET /eventos/persona/{liderId}: events the lider attended (event_attendees.lider_id) or organized (event_organizers.lider_id).
    public function persona(string $liderId): JsonResponse
    {
        abort_unless(Str::isUuid($liderId), 404);
        $lider = $this->visibleLideres()->where('id', $liderId)->first(['id', 'campaign_id', 'geographic_unit_id']);
        abort_unless($lider, 404);
        $this->authorizeLiderRow($lider);

        $columns = ['ev.id', 'ev.nombre', 'ev.status', 'ev.starts_at', 'ev.campaign_id', 't.nombre as tipo', 'm.canonical_name as municipio'];
        $base = fn () => $this->events->events()
            ->leftJoin('event_types as t', 't.id', '=', 'ev.event_type_id')
            ->leftJoin('geographic_units as m', 'm.id', '=', 'ev.municipio_id');

        $asistio = $base()->join('event_attendees as ea', 'ea.event_id', '=', 'ev.id')->where('ea.lider_id', $liderId)
            ->get([...$columns, 'ea.source', 'ea.link_status', 'ea.barrio'])
            ->map(fn ($e) => $this->personaEvent($e, 'asistente', 'Asistente', $e->source));
        $organizo = $base()->join('event_organizers as eo', 'eo.event_id', '=', 'ev.id')->where('eo.lider_id', $liderId)
            ->get([...$columns, 'eo.rol'])
            ->map(fn ($e) => $this->personaEvent($e, 'organizador', $e->rol ? "Organizador ({$e->rol})" : 'Organizador', null));

        $items = $asistio->concat($organizo)->sortByDesc('starts_at')->values();
        $barrios = DB::table('event_barrios')->whereIn('event_id', $items->pluck('id')->unique())
            ->orderBy('nombre')->get(['event_id', 'nombre'])->groupBy('event_id');

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $items->pluck('id')->unique()->count(),
                'asistio' => $asistio->count(),
                'organizo' => $organizo->count(),
                'eventos' => $items->map(fn ($e) => $e + [
                    'barrios' => ($barrios[$e['id']] ?? collect())->pluck('nombre')->values()->all(),
                ])->all(),
            ],
        ]);
    }

    // GET /eventos/territorio?municipio=&barrio=&page=&pagina_lideres=
    public function territorio(Request $request): Response
    {
        [$municipio, $barrio, $key] = $this->territoryFilters($request);
        $data = null;
        if ($municipio) {
            $data = [
                'kpis' => $this->territory->kpis($municipio->id, $key),
                'lideres' => $this->territory->lideres($municipio->id, $key)->paginate(25, ['*'], 'pagina_lideres')->withQueryString()
                    ->through(fn ($l) => (array) $l),
                'eventos' => $this->territory->eventos($municipio->id, $key)->limit(200)->get()
                    ->map(fn ($e) => $this->territoryEvent($e))->all(),
                'asistentes' => $this->territory->asistentes($municipio->id, $key)->paginate(50)->withQueryString()
                    ->through(fn ($a) => $this->territoryAttendee($a)),
            ];
        }

        return Inertia::render('Eventos/Territorio', [
            'filters' => ['municipio' => $municipio?->id, 'barrio' => $barrio],
            'municipio' => $municipio ? ['id' => $municipio->id, 'nombre' => $municipio->canonical_name, 'provincia' => Provincias::display($municipio->provincia)] : null,
            'provincias' => $this->provincias(),
            'barrios' => $municipio ? $this->territory->barrios($municipio->id) : [],
            'resultado' => $data,
        ]);
    }

    // GET /eventos/territorio/excel?municipio=&barrio=: the same three lists as the screen, unpaginated.
    public function territorioExcel(Request $request): BinaryFileResponse
    {
        [$municipio, $barrio, $key] = $this->territoryFilters($request);
        abort_unless($municipio, 404);
        EventoImportController::raiseMemoryLimit();

        $lideres = $this->territory->lideres($municipio->id, $key)->get()->map(fn ($l) => [
            'nombre' => $l->nombre, 'cedula' => $l->cedula, 'telefono' => $l->telefono, 'barrio' => $l->barrio,
            'cargo' => $l->cargo, 'partido' => $l->partido, 'campana' => $this->campaignName($l->campaign_id),
        ])->all();
        $eventos = $this->territory->eventos($municipio->id, $key)->get()->map(fn ($e) => [
            'nombre' => $e->nombre, 'tipo' => $e->tipo, 'fecha' => $this->date($e->starts_at, true),
            'estado' => ucfirst($e->status), 'asistentes' => (int) $e->asistentes,
            'asistentes_barrio' => isset($e->asistentes_barrio) ? (int) $e->asistentes_barrio : null,
            'campana' => $this->campaignName($e->campaign_id),
        ])->all();
        $asistentes = $this->territory->asistentes($municipio->id, $key)->get()->map(fn ($a) => [
            'nombres' => $a->nombres, 'apellidos' => $a->apellidos, 'cedula' => $a->cedula, 'celular' => $a->celular,
            'barrio' => $a->barrio, 'evento' => $a->evento, 'fecha' => $this->date($a->starts_at, true),
            'origen' => self::SOURCES[$a->source] ?? $a->source, 'estado' => self::LINK_STATUSES[$a->link_status] ?? $a->link_status,
            'campana' => $this->campaignName($a->campaign_id),
        ])->all();

        $eventoColumns = ['nombre', 'tipo', 'fecha', 'estado', 'asistentes', ...($key ? ['asistentes_barrio'] : []), 'campana'];
        $labels = [
            'nombre' => 'NOMBRE', 'cedula' => 'CÉDULA', 'telefono' => 'TELÉFONO', 'barrio' => 'BARRIO', 'cargo' => 'CARGO',
            'partido' => 'PARTIDO', 'campana' => 'GRUPO POLÍTICO', 'tipo' => 'TIPO', 'fecha' => 'FECHA', 'estado' => 'ESTADO',
            'asistentes' => 'ASISTENTES', 'asistentes_barrio' => 'ASISTENTES DEL BARRIO', 'nombres' => 'NOMBRES',
            'apellidos' => 'APELLIDOS', 'celular' => 'CELULAR', 'evento' => 'EVENTO', 'origen' => 'ORIGEN',
        ];
        $multiple = count($this->campaign()->visibleIds() ?? []) > 1;
        $drop = fn (array $cols) => $multiple ? $cols : array_values(array_diff($cols, ['campana']));

        $sheets = [
            new GenericExport($lideres, $drop(['nombre', 'cedula', 'telefono', 'barrio', 'cargo', 'partido', 'campana']), 'Líderes', $labels),
            new GenericExport($eventos, $drop($eventoColumns), 'Eventos', $labels),
            new GenericExport($asistentes, $drop(['nombres', 'apellidos', 'cedula', 'celular', 'barrio', 'evento', 'fecha', 'origen', 'estado', 'campana']), 'Asistentes', $labels),
        ];

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'EVENTOS_TERRITORIO_EXPORTADO',
            'entity_type' => 'evento',
            'entity_id' => null,
            'metadata' => [
                'route' => 'eventos.territorio.excel', 'municipio_id' => $municipio->id, 'barrio' => $key !== null,
                'lideres' => count($lideres), 'eventos' => count($eventos), 'asistentes' => count($asistentes),
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $name = 'territorio-'.(Str::slug($municipio->canonical_name.($barrio ? ' '.$barrio : '')) ?: 'municipio').'.xlsx';

        return Excel::download(new SheetsExport($sheets), $name);
    }

    // [municipio row | null, barrio as typed | null, barrio key | null]. A municipio outside the territory is a 403.
    private function territoryFilters(Request $request): array
    {
        $id = $request->query('municipio');
        $municipio = is_string($id) && Str::isUuid($id)
            ? DB::table('geographic_units as g')->leftJoin('geographic_units as p', 'p.id', '=', 'g.parent_id')
                ->where('g.id', $id)->where('g.type', 'municipality')
                ->first(['g.id', 'g.canonical_name', 'p.canonical_name as provincia'])
            : null;
        if ($municipio) {
            $this->authorizeGeo($municipio->id);
        }
        $barrio = is_string($request->query('barrio')) ? trim(preg_replace('/\s+/u', ' ', $request->query('barrio'))) : '';
        $barrio = $municipio && $barrio !== '' ? Str::limit($barrio, 120, '') : null;

        return [$municipio, $barrio, TerritoryView::key($barrio)];
    }

    private function personaEvent(object $e, string $participacion, string $rol, ?string $source): array
    {
        return [
            'id' => $e->id,
            'nombre' => $e->nombre,
            'tipo' => $e->tipo,
            'status' => $e->status,
            'starts_at' => EventScope::iso($e->starts_at),
            'municipio' => $e->municipio,
            'campaign_id' => $e->campaign_id,
            'participacion' => $participacion,
            'rol' => $rol,
            'origen' => $source,
            'url' => "/eventos/{$e->id}",
        ];
    }

    private function territoryEvent(object $e): array
    {
        return [
            'id' => $e->id,
            'nombre' => $e->nombre,
            'tipo' => $e->tipo,
            'status' => $e->status,
            'starts_at' => EventScope::iso($e->starts_at),
            'campaign_id' => $e->campaign_id,
            'asistentes' => (int) $e->asistentes,
            'asistentes_barrio' => isset($e->asistentes_barrio) ? (int) $e->asistentes_barrio : null,
            'url' => "/eventos/{$e->id}",
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
            'evento' => ['id' => $a->event_id, 'nombre' => $a->evento, 'starts_at' => EventScope::iso($a->starts_at)],
            'campaign_id' => $a->campaign_id,
        ];
    }

    private function campaignName(?string $id): ?string
    {
        $this->campaignNames ??= DB::table('campaigns')->pluck('name', 'id')->all();

        return $id ? ($this->campaignNames[$id] ?? null) : null;
    }

    private function date($value, bool $withTime = false): ?string
    {
        return $value ? Carbon::parse($value, 'UTC')->setTimezone(EventoController::TZ)->format($withTime ? 'd/m/Y g:i a' : 'd/m/Y') : null;
    }

    // Provinces with their municipios inside the user's territory (same shape as Eventos/Index).
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
}
