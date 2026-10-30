<?php

namespace App\Http\Controllers;

use App\Actions\Votantes\AddContacto;
use App\Actions\Votantes\CreateVotante;
use App\Actions\Votantes\DeactivateVotante;
use App\Actions\Votantes\UpdateVotante;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\Lider;
use App\Models\LiderContacto;
use App\Models\LiderInfoPolitica;
use App\Services\CachedQueries;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class VotanteController extends Controller
{
    use ScopesTerritory;

    private function escapeLike(string $value): string
    {
        return str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $value);
    }

    public function index(Request $request)
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

        // Estado filter (activos by default)
        $estado = $request->input('estado', 'activos');
        if ($estado === 'activos' || $estado === null) {
            $query->where('is_active', true);
        } elseif ($estado === 'inactivos') {
            $query->where('is_active', false);
        }

        // Territory scope restriction
        $user = $request->user();
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
            $query->where('municipio', $municipio);
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

        if ($request->has('gran_elector') && $request->input('gran_elector') !== null) {
            $query->where('gran_elector', filter_var($request->input('gran_elector'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->has('militante') && $request->input('militante') !== null) {
            $query->where('militante', filter_var($request->input('militante'), FILTER_VALIDATE_BOOLEAN));
        }

        $paginated = $query->orderBy('nombre')->paginate(20)->withQueryString();

        // Counters also respect territory scope and estado filter
        $counterBase = Lider::query();
        if ($estado === 'activos' || $estado === null) {
            $counterBase->where('is_active', true);
        } elseif ($estado === 'inactivos') {
            $counterBase->where('is_active', false);
        }
        $this->scopeToTerritory($counterBase);
        if ($campana) {
            $counterBase->where('campaign_id', $campana);
        }
        $total = (clone $counterBase)->count();
        $granElectorCount = (clone $counterBase)->where('gran_elector', true)->count();
        // A referente's cédula only refers inside the referente's own campaign.
        $conReferidos = (clone $counterBase)->whereExists(function ($q) {
            $this->scopeToTerritory($q, 'r.geographic_unit_id');
            $q->selectRaw('1')
                ->from('lideres as r')
                ->whereColumn('r.referente_documento', 'lideres.cedula')
                ->whereColumn('r.campaign_id', 'lideres.campaign_id')
                ->where('r.referente_documento', '!=', '')
                ->where('r.is_active', true);
        })->count();

        $municipios = CachedQueries::allMunicipios();
        $allowedGeoIds = $user?->allowedGeoIds();
        if ($allowedGeoIds) {
            $municipios = array_values(array_filter($municipios, fn ($m) => in_array($m['id'], $allowedGeoIds)));
        }

        return Inertia::render('Votantes', [
            'votantes' => $paginated,
            'counters' => [
                'total' => $total,
                'gran_elector' => $granElectorCount,
                'con_referidos' => $conReferidos,
            ],
            'municipios' => $municipios,
            'filters' => array_merge(
                $request->only(['search', 'municipio', 'partido', 'nivel_confianza', 'verificado', 'gran_elector', 'militante', 'estado']),
                $campana ? ['campana' => $campana] : [],
            ),
        ]);
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
            $referidos = $this->scopeToTerritory(Lider::where('referente_documento', $lider->cedula))
                ->where('campaign_id', $lider->campaign_id)
                ->where('is_active', true)
                ->select('id', 'nombre', 'cedula', 'municipio', 'telefono', 'nivel_confianza', 'partido')
                ->orderBy('nombre')
                ->get()
                ->toArray();
            $referidosCount = count($referidos);
        }

        return response()->json([
            'success' => true,
            'data' => array_merge($lider->toArray(), [
                'nexos' => $nexos,
                'referidos' => $referidos,
                'referidos_count' => $referidosCount,
            ]),
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

        $referidos = $this->scopeToTerritory(Lider::where('referente_documento', $lider->cedula))
            ->where('campaign_id', $lider->campaign_id)
            ->where('is_active', true)
            ->select('id', 'nombre', 'cedula', 'municipio', 'telefono', 'nivel_confianza', 'partido')
            ->orderBy('nombre')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $referidos,
            'total' => $referidos->count(),
        ]);
    }

    public function store(Request $request, CreateVotante $createVotante)
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:255',
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
            'nivel_confianza' => 'nullable|string|max:30',
            'convenio' => 'nullable|string|max:200',
            'escolaridad' => 'nullable|string|max:50',
            'tipo_hoja_vida' => 'nullable|string|max:50',
            'fecha_registro_hv' => 'nullable|date',
            'facebook' => 'nullable|string|max:255',
            'twitter' => 'nullable|string|max:255',
            'instagram' => 'nullable|string|max:255',
        ]);

        $lider = $createVotante->handle($data);

        return response()->json(['success' => true, 'message' => 'Votante creado.', 'id' => $lider->id], 201);
    }

    public function update(Request $request, string $id, UpdateVotante $updateVotante)
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $data = $request->validate([
            'nombre' => 'nullable|string|max:255',
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
            'nivel_confianza' => 'nullable|string|max:30',
            'convenio' => 'nullable|string|max:200',
            'escolaridad' => 'nullable|string|max:50',
            'tipo_hoja_vida' => 'nullable|string|max:50',
            'fecha_registro_hv' => 'nullable|date',
            'facebook' => 'nullable|string|max:255',
            'twitter' => 'nullable|string|max:255',
            'instagram' => 'nullable|string|max:255',
        ]);

        $updateVotante->handle($id, $data);

        return response()->json(array_filter([
            'success' => true, 'message' => 'Votante actualizado.', 'warning' => $updateVotante->warning,
        ], fn ($v) => $v !== null));
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
            'tipo' => 'required|string|max:30',
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
            'tipo' => 'nullable|string|max:30',
            'valor' => 'nullable|string|max:200',
            'principal' => 'nullable|boolean',
            'estado' => 'nullable|string|max:20',
        ]);

        $contacto->update($data);

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
