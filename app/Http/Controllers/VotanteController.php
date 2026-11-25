<?php

namespace App\Http\Controllers;

use App\Actions\Votantes\AddContacto;
use App\Actions\Votantes\CreateVotante;
use App\Actions\Votantes\DeactivateVotante;
use App\Actions\Votantes\ResolveElectoDiferencia;
use App\Actions\Votantes\UpdateVotante;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\Lider;
use App\Models\LiderContacto;
use App\Models\LiderInfoPolitica;
use App\Rules\NombreSinNumeros;
use App\Rules\OpcionDeCatalogo;
use App\Services\CachedQueries;
use App\Services\Eventos\EventScope;
use App\Services\Geo\ComunaCatalog;
use App\Support\EsLider;
use App\Support\MigracionFlags;
use App\Support\NombreFicha;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class VotanteController extends Controller
{
    use ScopesTerritory;

    // Referidos listed in a ficha: a referente from Electo has thousands; the count is always the real one.
    public const REFERIDOS_LIMIT = 200;

    public const SIN_COMUNA = 'sin_comuna';

    private array $eventFilters = [];

    private function escapeLike(string $value): string
    {
        // The backslash first: escaping it after % and _ would double the escapes just added.
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    // The Votantes listing with the screen's filters and scope, unpaginated: the screen and its export share it.
    // Without $cardFilters it leaves out the filters of the KPI cards (gran_elector, con_referidos, es_lider): the cards
    // count on that base, so their numbers do not change when one of them is pressed.
    public function listing(Request $request, bool $cardFilters = true): Builder
    {
        $referidosCount = $this->scopeToTerritory(DB::table('lideres as r'), 'r.geographic_unit_id')
            ->selectRaw('count(*)')
            ->whereNotNull('r.referente_documento')
            ->where('r.referente_documento', '!=', '')
            ->where('r.is_active', true)
            ->whereColumn('r.referente_documento', 'lideres.cedula')
            ->whereColumn('r.campaign_id', 'lideres.campaign_id');
        $query = Lider::query()
            ->addSelect(['lideres.*'])
            ->addSelect(['referidos_count' => $referidosCount]);
        if (EventScope::canSeeAttendees($request->user())) {
            $query->addSelect(['eventos_count' => app(EventScope::class)->attendedCount('lideres.id')]);
        }
        $this->applyEventFilters($query, $request);

        // Electo import: pending data, differences and the fichas imported inactive (these replace the estado filter).
        $importadosInactivos = MigracionFlags::applyFilters($query, $request->only(['por_completar', 'diferencias', 'importados_inactivos']));

        // Estado filter (activos by default)
        $estado = $request->input('estado', 'activos');
        if ($importadosInactivos) {
            $estado = 'inactivos';
        }
        if ($estado === 'activos' || $estado === null) {
            $query->where('is_active', true);
        } elseif ($estado === 'inactivos') {
            $query->where('is_active', false);
        }

        // Territory scope restriction
        $this->scopeToTerritory($query, 'lideres.geographic_unit_id');

        $campana = $this->campaign()->filterId($request->input('campana'));
        if ($campana) {
            $query->where('lideres.campaign_id', $campana);
        }

        if ($search = $request->input('search')) {
            $escaped = $this->escapeLike($search);
            $query->where(function ($q) use ($escaped) {
                $q->where('nombre', 'ilike', "%{$escaped}%")
                    ->orWhere('cedula', 'like', "%{$escaped}%");
            });
        }

        if ($municipio = $request->input('municipio')) {
            // The ficha's geographic unit decides (as in Reportes: «BUCARAMANGA» typed in capitals is still Bucaramanga);
            // the text keeps the fichas that have no unit.
            $unitIds = DB::table('geographic_units')->where('type', 'municipality')->where('canonical_name', $municipio)->pluck('id')->all();
            $query->where(fn ($q) => $q->where('municipio', $municipio)
                ->when($unitIds !== [], fn ($q) => $q->orWhereIn('lideres.geographic_unit_id', $unitIds)));

            // Comuna only inside a chosen municipio: «Sin comuna» alone would mean every other municipio too.
            $comuna = $request->input('comuna');
            if ($comuna === self::SIN_COMUNA) {
                $query->whereNull('lideres.comuna_id');
            } elseif (is_string($comuna) && Str::isUuid($comuna)) {
                $query->where('lideres.comuna_id', $comuna);
            }
        }

        if ($partido = $request->input('partido')) {
            $escaped = $this->escapeLike($partido);
            $query->where('partido', 'ilike', "%{$escaped}%");
        }

        if ($nivelConfianza = $request->input('nivel_confianza')) {
            $query->where('nivel_confianza', $nivelConfianza);
        }

        if ($request->has('verificado') && $request->input('verificado') !== null) {
            $query->where('verificado', filter_var($request->input('verificado'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($cardFilters && $request->has('gran_elector') && $request->input('gran_elector') !== null) {
            $query->where('gran_elector', filter_var($request->input('gran_elector'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($cardFilters && filter_var($request->input('con_referidos'), FILTER_VALIDATE_BOOLEAN)) {
            $query->whereExists(fn ($q) => $this->referidosExist($q));
        }

        if ($request->has('militante') && $request->input('militante') !== null) {
            $query->where('militante', filter_var($request->input('militante'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($cardFilters && EsLider::activo($request->user()) && filter_var($request->input('es_lider'), FILTER_VALIDATE_BOOLEAN)) {
            $query->where('lideres.es_lider', true);
        }

        if (filter_var($request->input('nombre_con_numeros'), FILTER_VALIDATE_BOOLEAN)) {
            NombreFicha::whereConNumeros($query, 'lideres.nombre');
        }

        return $query;
    }

    public function index(Request $request)
    {
        $campana = $this->campaign()->filterId($request->input('campana'));
        $user = $request->user();
        $esLider = EsLider::activo($user);
        $paginated = $this->listing($request)->orderBy('nombre')->orderBy('lideres.id')->paginate(20)->withQueryString()
            ->through(fn (Lider $l) => ($esLider ? $l : $l->makeHidden('es_lider'))->setAttribute('tipo', EventScope::visibleTipo($l->tipo, $user)));

        // Counters count the rows of the listing with every active filter, territory and campaign, except the two
        // card filters: Total is the base, and each card is the base with its own filter (what the table shows
        // when that card alone is pressed).
        $counterBase = $this->listing($request, false);
        $total = (clone $counterBase)->count();
        $granElectorCount = (clone $counterBase)->where('gran_elector', true)->count();
        $conReferidos = (clone $counterBase)->whereExists(fn ($q) => $this->referidosExist($q))->count();

        $municipios = CachedQueries::filterMunicipios($this->campaign()->visibleIds());
        $allowedGeoIds = $user?->allowedGeoIds();
        if ($allowedGeoIds) {
            $municipios = array_values(array_filter($municipios, fn ($m) => in_array($m['id'], $allowedGeoIds)));
        }

        $canSeeEventos = EventScope::canSeeAttendees($user);
        [$evento, $anyEvent] = $this->eventFilters($request);
        $comunasPorMunicipio = $this->comunasPorMunicipio($municipios);

        return Inertia::render('Votantes', [
            'votantes' => $paginated,
            'counters' => [
                'total' => $total,
                'gran_elector' => $granElectorCount,
                'con_referidos' => $conReferidos,
            ] + ($esLider ? ['es_lider' => (clone $counterBase)->where('lideres.es_lider', true)->count()] : []),
            'municipios' => $municipios,
            'comunasPorMunicipio' => $comunasPorMunicipio,
            'filters' => array_merge(
                $request->only(['search', 'municipio', 'comuna', 'partido', 'nivel_confianza', 'verificado', 'gran_elector', 'militante', 'estado',
                    'por_completar', 'diferencias', 'importados_inactivos']),
                filter_var($request->input('nombre_con_numeros'), FILTER_VALIDATE_BOOLEAN) ? ['nombre_con_numeros' => '1'] : [],
                $esLider && filter_var($request->input('es_lider'), FILTER_VALIDATE_BOOLEAN) ? ['es_lider' => '1'] : [],
                filter_var($request->input('con_referidos'), FILTER_VALIDATE_BOOLEAN) ? ['con_referidos' => '1'] : [],
                $campana ? ['campana' => $campana] : [],
                $evento ? ['evento' => $evento] : [],
                $anyEvent ? ['asistentes_eventos' => '1'] : [],
            ),
            'canViewEventos' => $canSeeEventos,
            'eventos' => $canSeeEventos ? app(EventScope::class)->options() : [],
            'campanasActivas' => $esLider,
        ]);
    }

    // {municipio name => [{id, nombre, tipo}]} for the municipios of the filter that have comunas (the municipio filter
    // is by name). Most have none: one query finds the few that do.
    private function comunasPorMunicipio(array $municipios): array
    {
        $ids = array_column($municipios, 'id');
        if (! $ids) {
            return [];
        }
        $conComunas = array_flip(DB::table('geographic_units')->whereIn('type', ComunaCatalog::TYPES)
            ->whereIn('parent_id', $ids)->distinct()->pluck('parent_id')->all());
        $catalog = app(ComunaCatalog::class);
        $out = [];
        foreach ($municipios as $m) {
            if (isset($conComunas[$m['id']])) {
                $out[$m['name']] = array_map(fn ($c) => ['id' => $c['id'], 'nombre' => $c['nombre'], 'tipo' => $c['tipo']], $catalog->comunasOf($m['id']));
            }
        }

        return $out;
    }

    // Someone active refers to this lider: their referente_documento is the lider's cédula, inside the same campaign
    // (a referente's cédula only refers inside its own campaign) and the user's territory.
    private function referidosExist($q): void
    {
        $this->scopeToTerritory($q, 'r.geographic_unit_id');
        $q->selectRaw('1')
            ->from('lideres as r')
            ->whereColumn('r.referente_documento', 'lideres.cedula')
            ->whereColumn('r.campaign_id', 'lideres.campaign_id')
            ->where('r.referente_documento', '!=', '')
            ->where('r.is_active', true);
    }

    // [visible event id | null, any attendance]: only for who can see event attendees; anything else is ignored.
    // Resolved once per request (listing, counters and props all use it).
    private function eventFilters(Request $request): array
    {
        $key = spl_object_id($request);
        if (isset($this->eventFilters[$key])) {
            return $this->eventFilters[$key];
        }
        if (! EventScope::canSeeAttendees($request->user())) {
            return $this->eventFilters[$key] = [null, false];
        }
        $evento = app(EventScope::class)->visibleEventId($request->input('evento'));

        return $this->eventFilters[$key] = [$evento, ! $evento && filter_var($request->input('asistentes_eventos'), FILTER_VALIDATE_BOOLEAN)];
    }

    // "Asistió al evento…" or "Asistentes a eventos (cualquiera)": attendance through event_attendees.lider_id.
    private function applyEventFilters(Builder $query, Request $request): void
    {
        [$evento, $anyEvent] = $this->eventFilters($request);
        if ($evento || $anyEvent) {
            $query->whereExists(fn ($q) => app(EventScope::class)->attendanceOf($q, 'lideres.id', $evento));
        }
    }

    public function show(string $id)
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $lider = Lider::with(['contactos', 'infoPolitica'])->find($id);

        if (! $lider) {
            return response()->json(['success' => false, 'message' => 'No encontrado.'], 404);
        }
        $this->authorizeLiderRow($lider);
        if (! EsLider::activo()) {
            $lider->makeHidden('es_lider');
        }

        $nexos = $this->campaign()->scope(DB::table('nexos_familiares'))
            ->where('person_id', $id)
            ->orderBy('nombre')
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'nombre' => $n->nombre,
                'parentesco' => $n->parentesco,
                'cargo' => $n->cargo,
                'edad' => $n->edad,
                'gustos' => $n->gustos,
                'observaciones' => $n->observaciones,
                'cedula' => $n->cedula ?? null,
                'telefono' => $n->telefono ?? null,
            ])->toArray();

        $referidos = [];
        $referidosCount = 0;
        if ($lider->cedula) {
            $referidosQuery = $this->referidosQuery($lider);
            $referidos = (clone $referidosQuery)
                ->select('id', 'nombre', 'cedula', 'municipio', 'telefono', 'nivel_confianza', 'partido')
                ->orderBy('nombre')
                ->limit(self::REFERIDOS_LIMIT)
                ->get()
                ->toArray();
            $referidosCount = count($referidos) < self::REFERIDOS_LIMIT ? count($referidos) : $referidosQuery->count();
        }

        return response()->json([
            'success' => true,
            'data' => array_merge($lider->toArray(), [
                'migracion' => MigracionFlags::forFicha($lider),
                'tipo' => EventScope::visibleTipo($lider->tipo, request()->user()),
                'nexos' => $nexos,
                'referidos' => $referidos,
                'referidos_count' => $referidosCount,
            ], app(ComunaCatalog::class)->fichaPayload($lider->geographic_unit_id, $lider->comuna_id, $lider->comuna_source)),
        ]);
    }

    public function referidos(string $id)
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        // Missing and not visible answer the same.
        $lider = Lider::find($id);
        if (! $lider) {
            return response()->json(['success' => false, 'message' => 'No encontrado.'], 404);
        }
        $this->authorizeLiderRow($lider);
        if (! $lider->cedula) {
            return response()->json(['success' => true, 'data' => [], 'total' => 0]);
        }

        // ?offset= pages through a referente with thousands of referidos.
        $offset = max(0, min((int) request()->query('offset', 0), 1000000));
        $referidosQuery = $this->referidosQuery($lider);
        $referidos = (clone $referidosQuery)
            ->select('id', 'nombre', 'cedula', 'municipio', 'telefono', 'nivel_confianza', 'partido')
            ->orderBy('nombre')->orderBy('id')
            ->offset($offset)
            ->limit(self::REFERIDOS_LIMIT)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $referidos,
            'total' => $offset === 0 && $referidos->count() < self::REFERIDOS_LIMIT ? $referidos->count() : $referidosQuery->count(),
        ]);
    }

    private function referidosQuery(Lider $lider): Builder
    {
        return $this->scopeToTerritory(Lider::where('referente_documento', $lider->cedula))
            ->where('campaign_id', $lider->campaign_id)
            ->where('is_active', true);
    }

    public function store(Request $request, CreateVotante $createVotante)
    {
        $data = $request->validate(OpcionDeCatalogo::enReglas([
            'nombre' => ['required', 'string', 'max:255', new NombreSinNumeros],
            'municipio_id' => 'required|uuid|exists:geographic_units,id',
            'cargo' => 'nullable|string|max:255',
            'tipo' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:20',
            'cedula' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'observacion' => 'nullable|string|max:1000',
            'partido' => 'nullable|string|max:255',
            'direccion' => 'nullable|string|max:255',
            'barrio' => 'nullable|string|max:100',
            'zona' => 'nullable|in:rural,urbana',
            'profesion' => 'nullable|string|max:255',
            // New fields
            'tipo_documento' => 'nullable|string|max:20',
            'fecha_expedicion_doc' => 'nullable|date',
            'genero' => 'nullable|string|max:20',
            'fecha_nacimiento' => 'nullable|date',
            'estado_civil' => 'nullable|string|max:30',
            'referente_documento' => 'nullable|string|max:20',
            'referente_nombre' => 'nullable|string|max:150',
            'referente_apellido' => 'nullable|string|max:150',
            'departamento_votacion' => 'nullable|string|max:100',
            'municipio_votacion' => 'nullable|string|max:100',
            'puesto_votacion' => 'nullable|string|max:200',
            'direccion_puesto' => 'nullable|string|max:300',
            'mesa_votacion' => 'nullable|string|max:20',
            'militante' => 'nullable|boolean',
            'autoriza_datos' => 'nullable|boolean',
            'verificado' => 'nullable|boolean',
            'fallecido' => 'nullable|boolean',
            'empresario' => 'nullable|boolean',
            'reservista' => 'nullable|boolean',
            'funcionario' => 'nullable|boolean',
            'exfuncionario' => 'nullable|boolean',
            'gran_elector' => 'nullable|boolean',
            'es_lider' => 'nullable|boolean',
            'nivel_confianza' => 'nullable|string|max:30',
            'convenio' => 'nullable|string|max:200',
            'escolaridad' => 'nullable|string|max:50',
            'tipo_hoja_vida' => 'nullable|string|max:50',
            'fecha_registro_hv' => 'nullable|date',
            'facebook' => 'nullable|string|max:255',
            'twitter' => 'nullable|string|max:255',
            'instagram' => 'nullable|string|max:255',
        ]));

        $lider = $createVotante->handle(EsLider::filtrar($data));

        return response()->json(['success' => true, 'message' => 'Votante creado.', 'id' => $lider->id], 201);
    }

    public function update(Request $request, string $id, UpdateVotante $updateVotante)
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $data = $request->validate(OpcionDeCatalogo::enReglas([
            'nombre' => ['nullable', 'string', 'max:255', new NombreSinNumeros($this->scopeToTerritory($this->visibleLideres())->where('id', $id)->value('nombre'))],
            'cargo' => 'nullable|string|max:255',
            'tipo' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:20',
            'cedula' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'observacion' => 'nullable|string|max:1000',
            'partido' => 'nullable|string|max:255',
            'direccion' => 'nullable|string|max:255',
            'barrio' => 'nullable|string|max:100',
            'zona' => 'nullable|in:rural,urbana',
            'profesion' => 'nullable|string|max:255',
            'destacado' => 'nullable|boolean',
            'votos' => 'nullable|integer|min:0',
            // New fields
            'tipo_documento' => 'nullable|string|max:20',
            'fecha_expedicion_doc' => 'nullable|date',
            'genero' => 'nullable|string|max:20',
            'fecha_nacimiento' => 'nullable|date',
            'foto' => 'nullable|string|max:500',
            'estado_civil' => 'nullable|string|max:30',
            'referente_documento' => 'nullable|string|max:20',
            'referente_nombre' => 'nullable|string|max:150',
            'referente_apellido' => 'nullable|string|max:150',
            'departamento_votacion' => 'nullable|string|max:100',
            'municipio_votacion' => 'nullable|string|max:100',
            'puesto_votacion' => 'nullable|string|max:200',
            'direccion_puesto' => 'nullable|string|max:300',
            'mesa_votacion' => 'nullable|string|max:20',
            'militante' => 'nullable|boolean',
            'autoriza_datos' => 'nullable|boolean',
            'verificado' => 'nullable|boolean',
            'fallecido' => 'nullable|boolean',
            'empresario' => 'nullable|boolean',
            'reservista' => 'nullable|boolean',
            'funcionario' => 'nullable|boolean',
            'exfuncionario' => 'nullable|boolean',
            'gran_elector' => 'nullable|boolean',
            'es_lider' => 'nullable|boolean',
            'nivel_confianza' => 'nullable|string|max:30',
            'convenio' => 'nullable|string|max:200',
            'escolaridad' => 'nullable|string|max:50',
            'tipo_hoja_vida' => 'nullable|string|max:50',
            'fecha_registro_hv' => 'nullable|date',
            'facebook' => 'nullable|string|max:255',
            'twitter' => 'nullable|string|max:255',
            'instagram' => 'nullable|string|max:255',
        ], DB::table('lideres')->where('id', $id)->get()));

        $updateVotante->handle($id, EsLider::filtrar($data));

        return response()->json(array_filter([
            'success' => true, 'message' => 'Votante actualizado.', 'warning' => $updateVotante->warning,
        ], fn ($v) => $v !== null));
    }

    // "Dejar el de aquí" / "Usar el de Electo" on a difference of the ficha's notice (Mapa and Votantes).
    public function resolverDiferenciaElecto(Request $request, string $id, string $campo, ResolveElectoDiferencia $resolve)
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }
        $data = $request->validate(['accion' => 'required|string|in:'.implode(',', ResolveElectoDiferencia::ACCIONES)]);

        [$resuelta, $valor] = $resolve->handle($id, $campo, $data['accion']);

        return response()->json([
            'success' => true,
            'message' => ! $resuelta ? 'Esta diferencia ya estaba resuelta.' : ($valor !== null ? 'Se usó el dato de Electo.' : 'Se dejó el dato de aquí.'),
            'campo' => $campo,
            'valor' => $valor,
            // The notice as the ficha shows it now: null when nothing is left.
            'migracion' => $resolve->aviso($id),
            'warning' => $resolve->warning,
        ]);
    }

    public function destroy(string $id, DeactivateVotante $deactivateVotante)
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $deactivateVotante->handle($id);

        return response()->json(['success' => true, 'message' => 'Votante desactivado.']);
    }

    // --- Contactos CRUD ---

    public function storeContacto(Request $request, string $liderId, AddContacto $addContacto)
    {
        if (! Str::isUuid($liderId)) {
            abort(404);
        }

        $lider = $addContacto->authorizeFor($liderId);

        $data = $request->validate([
            'tipo' => ['required', 'string', 'max:30', new OpcionDeCatalogo('tipo_contacto')],
            'valor' => 'required|string|max:200',
            'principal' => 'nullable|boolean',
            'estado' => 'nullable|string|max:20',
        ]);

        $contacto = $addContacto->handle($lider, $data);

        return response()->json(['success' => true, 'message' => 'Contacto creado.', 'data' => $contacto], 201);
    }

    public function updateContacto(Request $request, string $id)
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $contacto = LiderContacto::find($id);
        if (! $contacto) {
            return response()->json(['success' => false, 'message' => 'Contacto no encontrado.'], 404);
        }
        $this->authorizeLiderWrite($contacto->lider_id);

        $data = $request->validate([
            'tipo' => ['nullable', 'string', 'max:30', new OpcionDeCatalogo('tipo_contacto', [$contacto->tipo])],
            'valor' => 'nullable|string|max:200',
            'principal' => 'nullable|boolean',
            'estado' => 'nullable|string|max:20',
        ]);

        $contacto->update($data);
        if (in_array($contacto->tipo, MigracionFlags::PHONE_CONTACTS, true)) {
            MigracionFlags::clearCompleted($contacto->lider_id);
        }

        return response()->json(['success' => true, 'message' => 'Contacto actualizado.', 'data' => $contacto->fresh()]);
    }

    public function destroyContacto(string $id)
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $contacto = LiderContacto::find($id);
        if (! $contacto) {
            return response()->json(['success' => false, 'message' => 'Contacto no encontrado.'], 404);
        }
        $this->authorizeLiderWrite($contacto->lider_id);

        $contacto->delete();

        return response()->json(['success' => true, 'message' => 'Contacto eliminado.']);
    }

    // --- Info Politica CRUD ---

    public function storeInfoPolitica(Request $request, string $liderId)
    {
        if (! Str::isUuid($liderId)) {
            abort(404);
        }

        $lider = Lider::find($liderId);
        if (! $lider) {
            return response()->json(['success' => false, 'message' => 'Lider no encontrado.'], 404);
        }
        $this->authorizeLiderRow($lider, true);

        $data = $request->validate([
            'cargo_politico' => 'nullable|string|max:200',
            'departamento' => 'nullable|string|max:100',
            'municipio' => 'nullable|string|max:100',
            'partido' => 'nullable|string|max:200',
            'aliado' => 'nullable|string|max:200',
            'votos' => 'nullable|integer|min:0',
            'observacion' => 'nullable|string|max:1000',
            'estado' => 'nullable|string|max:20',
        ]);

        $info = $lider->infoPolitica()->create([
            'cargo_politico' => $data['cargo_politico'] ?? null,
            'departamento' => $data['departamento'] ?? null,
            'municipio' => $data['municipio'] ?? null,
            'partido' => $data['partido'] ?? null,
            'aliado' => $data['aliado'] ?? null,
            'votos' => $data['votos'] ?? 0,
            'observacion' => $data['observacion'] ?? null,
            'estado' => $data['estado'] ?? 'activo',
        ]);

        return response()->json(['success' => true, 'message' => 'Info politica creada.', 'data' => $info], 201);
    }

    public function updateInfoPolitica(Request $request, string $id)
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $info = LiderInfoPolitica::find($id);
        if (! $info) {
            return response()->json(['success' => false, 'message' => 'Info politica no encontrada.'], 404);
        }
        $this->authorizeLiderWrite($info->lider_id);

        $data = $request->validate([
            'cargo_politico' => 'nullable|string|max:200',
            'departamento' => 'nullable|string|max:100',
            'municipio' => 'nullable|string|max:100',
            'partido' => 'nullable|string|max:200',
            'aliado' => 'nullable|string|max:200',
            'votos' => 'nullable|integer|min:0',
            'observacion' => 'nullable|string|max:1000',
            'estado' => 'nullable|string|max:20',
        ]);

        $info->update($data);

        return response()->json(['success' => true, 'message' => 'Info politica actualizada.', 'data' => $info->fresh()]);
    }

    public function destroyInfoPolitica(string $id)
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $info = LiderInfoPolitica::find($id);
        if (! $info) {
            return response()->json(['success' => false, 'message' => 'Info politica no encontrada.'], 404);
        }
        $this->authorizeLiderWrite($info->lider_id);

        $info->delete();

        return response()->json(['success' => true, 'message' => 'Info politica eliminada.']);
    }
}
