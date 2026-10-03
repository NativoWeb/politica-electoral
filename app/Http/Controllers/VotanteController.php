<?php

namespace App\Http\Controllers;

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
    private function escapeLike(string $value): string
    {
        return str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $value);
    }

    public function index(Request $request)
    {
        $query = Lider::query()
            ->addSelect(['lideres.*'])
            ->addSelect(DB::raw("(SELECT count(*) FROM lideres AS r WHERE r.referente_documento IS NOT NULL AND r.referente_documento != '' AND r.referente_documento = lideres.cedula) as referidos_count"));

        // Territory scope restriction
        $user = $request->user();
        $allowedMunicipios = $user?->allowedMunicipioNames();
        if ($allowedMunicipios) {
            $query->whereIn('municipio', $allowedMunicipios);
        }

        if ($search = $request->input('search')) {
            $escaped = $this->escapeLike($search);
            $query->where(function ($q) use ($escaped) {
                $q->where('nombre', 'ilike', "%{$escaped}%")
                    ->orWhere('cedula', 'like', "%{$escaped}%");
            });
        }

        if ($municipio = $request->input('municipio')) {
            if (! $allowedMunicipios || in_array($municipio, $allowedMunicipios)) {
                $query->where('municipio', $municipio);
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

        if ($request->has('gran_elector') && $request->input('gran_elector') !== null) {
            $query->where('gran_elector', filter_var($request->input('gran_elector'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->has('militante') && $request->input('militante') !== null) {
            $query->where('militante', filter_var($request->input('militante'), FILTER_VALIDATE_BOOLEAN));
        }

        $paginated = $query->orderBy('nombre')->paginate(20)->withQueryString();

        // Counters also respect territory scope
        $counterBase = Lider::query();
        if ($allowedMunicipios) {
            $counterBase->whereIn('municipio', $allowedMunicipios);
        }
        $total = (clone $counterBase)->count();
        $granElectorCount = (clone $counterBase)->where('gran_elector', true)->count();
        $conReferidos = (clone $counterBase)->whereIn('cedula', function ($q) {
            $q->select('referente_documento')
                ->from('lideres')
                ->whereNotNull('referente_documento')
                ->where('referente_documento', '!=', '');
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
            'filters' => $request->only(['search', 'municipio', 'partido', 'nivel_confianza', 'verificado', 'gran_elector', 'militante']),
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

        $nexos = DB::table('nexos_familiares')
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
            $referidos = Lider::where('referente_documento', $lider->cedula)
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

        $lider = Lider::find($id);
        if (! $lider || ! $lider->cedula) {
            return response()->json(['success' => true, 'data' => [], 'total' => 0]);
        }

        $referidos = Lider::where('referente_documento', $lider->cedula)
            ->select('id', 'nombre', 'cedula', 'municipio', 'telefono', 'nivel_confianza', 'partido')
            ->orderBy('nombre')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $referidos,
            'total' => $referidos->count(),
        ]);
    }

    public function store(Request $request)
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

        $mun = DB::table('geographic_units as g')
            ->leftJoin('geographic_units as prov', 'g.parent_id', '=', 'prov.id')
            ->where('g.id', $data['municipio_id'])
            ->select('g.canonical_name', 'prov.canonical_name as provincia')
            ->first();

        $lider = DB::transaction(function () use ($data, $mun) {
            return Lider::create([
                'nombre' => $data['nombre'],
                'municipio' => $mun->canonical_name ?? '',
                'provincia' => $mun->provincia ?? '',
                'geographic_unit_id' => $data['municipio_id'],
                'cargo' => $data['cargo'] ?? '',
                'tipo' => $data['tipo'] ?? 'directorio',
                'telefono' => $data['telefono'] ?? null,
                'cedula' => $data['cedula'] ?? null,
                'email' => $data['email'] ?? null,
                'observacion' => $data['observacion'] ?? null,
                'partido' => $data['partido'] ?? null,
                'direccion' => $data['direccion'] ?? null,
                'barrio' => $data['barrio'] ?? null,
                'zona' => $data['zona'] ?? null,
                'profesion' => $data['profesion'] ?? null,
                'destacado' => false,
                'votos' => 0,
                'tipo_documento' => $data['tipo_documento'] ?? null,
                'fecha_expedicion_doc' => $data['fecha_expedicion_doc'] ?? null,
                'genero' => $data['genero'] ?? null,
                'fecha_nacimiento' => $data['fecha_nacimiento'] ?? null,
                'estado_civil' => $data['estado_civil'] ?? null,
                'referente_documento' => $data['referente_documento'] ?? null,
                'referente_nombre' => $data['referente_nombre'] ?? null,
                'referente_apellido' => $data['referente_apellido'] ?? null,
                'departamento_votacion' => $data['departamento_votacion'] ?? null,
                'municipio_votacion' => $data['municipio_votacion'] ?? null,
                'puesto_votacion' => $data['puesto_votacion'] ?? null,
                'direccion_puesto' => $data['direccion_puesto'] ?? null,
                'mesa_votacion' => $data['mesa_votacion'] ?? null,
                'militante' => $data['militante'] ?? false,
                'autoriza_datos' => $data['autoriza_datos'] ?? false,
                'verificado' => $data['verificado'] ?? false,
                'fallecido' => $data['fallecido'] ?? false,
                'empresario' => $data['empresario'] ?? false,
                'reservista' => $data['reservista'] ?? false,
                'funcionario' => $data['funcionario'] ?? false,
                'exfuncionario' => $data['exfuncionario'] ?? false,
                'gran_elector' => $data['gran_elector'] ?? false,
                'nivel_confianza' => $data['nivel_confianza'] ?? 'sin_llamar',
                'convenio' => $data['convenio'] ?? null,
                'escolaridad' => $data['escolaridad'] ?? null,
                'tipo_hoja_vida' => $data['tipo_hoja_vida'] ?? null,
                'fecha_registro_hv' => $data['fecha_registro_hv'] ?? null,
                'facebook' => $data['facebook'] ?? null,
                'twitter' => $data['twitter'] ?? null,
                'instagram' => $data['instagram'] ?? null,
            ]);
        });

        return response()->json(['success' => true, 'message' => 'Votante creado.', 'id' => $lider->id], 201);
    }

    public function update(Request $request, string $id)
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

        $lider = Lider::find($id);

        if (! $lider) {
            return response()->json(['success' => false, 'message' => 'No encontrado.'], 404);
        }

        DB::transaction(function () use ($lider, $data) {
            $lider->update($data);
        });

        return response()->json(['success' => true, 'message' => 'Votante actualizado.']);
    }

    public function destroy(string $id)
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $lider = Lider::find($id);

        if (! $lider) {
            return response()->json(['success' => false, 'message' => 'No encontrado.'], 404);
        }

        DB::transaction(function () use ($lider) {
            DB::table('nexos_familiares')->where('person_id', $lider->id)->delete();
            $lider->delete();
        });

        return response()->json(['success' => true, 'message' => 'Votante eliminado.']);
    }

    // --- Contactos CRUD ---

    public function storeContacto(Request $request, string $liderId)
    {
        if (! Str::isUuid($liderId)) {
            abort(404);
        }

        $lider = Lider::find($liderId);
        if (! $lider) {
            return response()->json(['success' => false, 'message' => 'Lider no encontrado.'], 404);
        }

        $data = $request->validate([
            'tipo' => 'required|string|max:30',
            'valor' => 'required|string|max:200',
            'principal' => 'nullable|boolean',
            'estado' => 'nullable|string|max:20',
        ]);

        $contacto = $lider->contactos()->create([
            'tipo' => $data['tipo'],
            'valor' => $data['valor'],
            'principal' => $data['principal'] ?? false,
            'estado' => $data['estado'] ?? 'activo',
        ]);

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

        $info->delete();

        return response()->json(['success' => true, 'message' => 'Info politica eliminada.']);
    }
}
