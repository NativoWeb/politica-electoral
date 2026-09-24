<?php

namespace App\Http\Controllers;

use App\Services\CachedQueries;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class MapaPoliticoController extends Controller
{
    // #3: Escape LIKE wildcards to prevent wildcard injection
    private function escapeLike(string $value): string
    {
        return str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $value);
    }

    // #7/#8: Cache static IDs that never change
    private function officeId(string $name): ?string
    {
        return Cache::remember("office_id:{$name}", 3600, fn () => DB::table('offices')->where('name', $name)->value('id'));
    }

    private function corporationId(string $name): ?string
    {
        return Cache::remember("corp_id:{$name}", 3600, fn () => DB::table('corporations')->where('name', $name)->value('id'));
    }

    // #8: Reusable votes subquery
    private function votesSubQuery(?string $munId = null, ?array $provinciaIds = null)
    {
        $q = DB::table('electoral_results')
            ->whereIn('metric_type', ['votes', 'nominal_votes'])
            ->select('candidacy_id', DB::raw('SUM(value) as votos'))
            ->groupBy('candidacy_id');

        if ($munId) $q->where('geographic_unit_id', $munId);
        elseif ($provinciaIds) $q->whereIn('geographic_unit_id', $provinciaIds);

        return $q;
    }

    // #7: Cached cargos por tipo
    private function cargosPorTipo(): array
    {
        return Cache::remember('cargos_por_tipo', 300, fn () => [
            'alcaldia' => ['Alcalde Electo', 'Candidato Alcaldía'],
            'concejo' => ['Concejal Electo', 'Candidato Concejo'],
            'lideres' => DB::table('lideres')->select('cargo')->distinct()->pluck('cargo')->sort()->values()->toArray(),
            'todos' => collect()
                ->merge(['Alcalde Electo', 'Candidato Alcaldía', 'Concejal Electo', 'Candidato Concejo'])
                ->merge(DB::table('lideres')->select('cargo')->distinct()->pluck('cargo'))
                ->unique()->sort()->values()->toArray(),
        ]);
    }

    public function index(Request $request)
    {
        $munId = $request->input('municipio');
        $tipo = $request->input('tipo', 'todos');
        $cargoRaw = $request->input('cargo');
        $cargos = $cargoRaw ? explode(',', $cargoRaw) : [];
        $cargo = $cargoRaw;
        $search = $request->input('search');
        $partido = $request->input('partido');
        $barrio = $request->input('barrio');

        // #3: Escape search for ILIKE
        $searchEscaped = $search ? $this->escapeLike($search) : null;
        $barrioEscaped = $barrio ? $this->escapeLike($barrio) : null;

        $areaMetropolitana = ['Bucaramanga', 'Floridablanca', 'Piedecuesta', 'Girón', 'Rionegro', 'Lebrija'];
        $municipios = CachedQueries::allMunicipios();

        $provincias = collect($municipios)->pluck('provincia')->unique()->filter()->sort()->values()->toArray();
        array_unshift($provincias, 'Área Metropolitana');
        $provincias = array_unique($provincias);

        $provincia = $request->input('provincia');
        $provinciaIds = null;
        if ($provincia && !$munId) {
            if ($provincia === 'Área Metropolitana') {
                $provinciaIds = collect($municipios)->filter(fn ($m) => in_array($m['name'], $areaMetropolitana))->pluck('id')->toArray();
            } else {
                $provinciaIds = collect($municipios)->filter(fn ($m) => ($m['provincia'] ?? '') === $provincia)->pluck('id')->toArray();
            }
        }

        $municipioInfo = null;
        if ($munId) {
            $municipioInfo = DB::table('geographic_units as g')
                ->leftJoin('geographic_units as prov', 'g.parent_id', '=', 'prov.id')
                ->where('g.id', $munId)
                ->select('g.id', 'g.canonical_name as name', 'prov.canonical_name as provincia')
                ->first();
        }

        $data = [];
        $noGeoFilter = !$munId && !$provinciaIds && !$searchEscaped && !$barrioEscaped;

        // === ALCALDIA ===
        if ($tipo === 'todos' || $tipo === 'alcaldia') {
            $alcaldiaOffice = $this->officeId('Alcaldía');
            $query = DB::table('candidacies as c')
                ->join('contests as con', 'c.contest_id', '=', 'con.id')
                ->join('persons as p', 'c.person_id', '=', 'p.id')
                ->where('con.office_id', $alcaldiaOffice)
                ->leftJoin('candidacy_endorsements as ce', fn ($j) => $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true))
                ->leftJoin('political_organizations as po', 'ce.organization_id', '=', 'po.id')
                ->leftJoin('geographic_units as g', 'con.geographic_unit_id', '=', 'g.id')
                ->leftJoin('geographic_units as prov', 'g.parent_id', '=', 'prov.id')
                ->leftJoinSub($this->votesSubQuery($munId, $provinciaIds), 'res', 'res.candidacy_id', '=', 'c.id')
                ->select(
                    'p.id', 'p.full_name as nombre', 'g.canonical_name as municipio',
                    'prov.canonical_name as provincia', 'po.canonical_name as partido',
                    'c.outcome', 'ce.endorsement_type as tipo_aval',
                    DB::raw('COALESCE(res.votos, 0) as votos'),
                    DB::raw("'Alcaldía' as tipo_registro"),
                    DB::raw("CASE WHEN c.outcome = 'elected' THEN 'Alcalde Electo' ELSE 'Candidato Alcaldía' END as cargo")
                );

            if ($munId) $query->where('con.geographic_unit_id', $munId);
            elseif ($provinciaIds) $query->whereIn('con.geographic_unit_id', $provinciaIds);
            if ($searchEscaped) $query->where('p.full_name', 'ilike', "%{$searchEscaped}%");
            if ($noGeoFilter) $query->where('c.outcome', 'elected');

            $data = array_merge($data, $query->orderByDesc('res.votos')->get()->map(fn ($r) => (array) $r)->toArray());
        }

        // === CONCEJO ===
        $showConcejo = $tipo === 'todos' || $tipo === 'concejo'
            || ($tipo === 'lideres' && $cargo && stripos($cargo, 'CONCEJAL') !== false);
        if ($showConcejo) {
            $concejoCorp = $this->corporationId('Concejo');
            $query = DB::table('candidacies as c')
                ->join('contests as con', 'c.contest_id', '=', 'con.id')
                ->join('persons as p', 'c.person_id', '=', 'p.id')
                ->where('con.corporation_id', $concejoCorp)
                ->leftJoin('candidacy_endorsements as ce', fn ($j) => $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true))
                ->leftJoin('political_organizations as po', 'ce.organization_id', '=', 'po.id')
                ->leftJoin('geographic_units as g', 'con.geographic_unit_id', '=', 'g.id')
                ->leftJoin('geographic_units as prov', 'g.parent_id', '=', 'prov.id')
                ->leftJoinSub($this->votesSubQuery($munId, $provinciaIds), 'res', 'res.candidacy_id', '=', 'c.id')
                ->select(
                    'p.id', 'p.full_name as nombre', 'g.canonical_name as municipio',
                    'prov.canonical_name as provincia', 'po.canonical_name as partido',
                    'c.outcome', 'ce.endorsement_type as tipo_aval',
                    DB::raw('COALESCE(res.votos, 0) as votos'),
                    DB::raw("'Concejo' as tipo_registro"),
                    DB::raw("CASE WHEN c.outcome = 'elected' THEN 'Concejal Electo' ELSE 'Candidato Concejo' END as cargo")
                );

            if ($munId) $query->where('con.geographic_unit_id', $munId);
            elseif ($provinciaIds) $query->whereIn('con.geographic_unit_id', $provinciaIds);
            if ($searchEscaped) $query->where('p.full_name', 'ilike', "%{$searchEscaped}%");
            if (!empty($cargos)) $query->whereIn(DB::raw("CASE WHEN c.outcome = 'elected' THEN 'Concejal Electo' ELSE 'Candidato Concejo' END"), $cargos);
            if ($noGeoFilter) $query->where('c.outcome', 'elected');

            $data = array_merge($data, $query->orderByDesc('res.votos')->get()->map(fn ($r) => (array) $r)->toArray());
        }

        // === LIDERES ===
        if ($tipo === 'todos' || $tipo === 'lideres') {
            $query = DB::table('lideres')
                ->where('cargo', '!=', 'Directorio Municipal')
                ->select(
                    'id', 'nombre', 'municipio', 'provincia',
                    'partido', DB::raw('NULL as outcome'), DB::raw('NULL as tipo_aval'),
                    DB::raw('0 as votos'), DB::raw("'Líderes' as tipo_registro"),
                    'cargo', 'telefono', 'email', 'observacion', 'barrio', 'direccion', 'zona', 'destacado'
                );

            if ($munId) $query->where('geographic_unit_id', $munId);
            elseif ($provinciaIds) $query->whereIn('geographic_unit_id', $provinciaIds);
            if ($searchEscaped) $query->where('nombre', 'ilike', "%{$searchEscaped}%");
            if (!empty($cargos)) $query->whereIn('cargo', $cargos);
            if ($barrioEscaped) $query->where('barrio', 'ilike', "%{$barrioEscaped}%");
            if ($noGeoFilter) $query->limit(200);

            $data = array_merge($data, $query->orderBy('nombre')->get()->map(fn ($r) => (array) $r)->toArray());
        }

        // === DIRECTORIO MUNICIPAL ===
        if ($tipo === 'todos' || $tipo === 'directorio') {
            $query = DB::table('lideres')
                ->where('cargo', 'Directorio Municipal')
                ->select(
                    'id', 'nombre', 'municipio', 'provincia',
                    'partido', DB::raw('NULL as outcome'), DB::raw('NULL as tipo_aval'),
                    DB::raw('0 as votos'), DB::raw("'Directorio Municipal' as tipo_registro"),
                    'cargo', 'telefono', 'email', 'observacion', 'barrio', 'direccion', 'zona', 'destacado'
                );

            if ($munId) $query->where('geographic_unit_id', $munId);
            elseif ($provinciaIds) $query->whereIn('geographic_unit_id', $provinciaIds);
            if ($searchEscaped) $query->where('nombre', 'ilike', "%{$searchEscaped}%");
            if (!empty($cargos)) $query->whereIn('cargo', $cargos);
            if ($barrioEscaped) $query->where('barrio', 'ilike', "%{$barrioEscaped}%");
            if ($noGeoFilter) $query->limit(200);

            $data = array_merge($data, $query->orderBy('nombre')->get()->map(fn ($r) => (array) $r)->toArray());
        }

        // === SENADO / CAMARA / ASAMBLEA ===
        // #1/#2: Use safe label map instead of interpolation in DB::raw
        $corpMap = [
            'senado' => ['corp' => 'Senado', 'label' => 'Senado'],
            'camara' => ['corp' => 'Cámara de Representantes', 'label' => 'Cámara'],
            'asamblea' => ['corp' => 'Asamblea', 'label' => 'Asamblea'],
        ];
        foreach ($corpMap as $tipoKey => $info) {
            if ($tipo === $tipoKey || $tipo === 'todos') {
                $corpId = $this->corporationId($info['corp']);
                if (!$corpId) continue;

                $resSubQuery = DB::table('electoral_results')
                    ->whereIn('metric_type', ['votes', 'nominal_votes'])->where('value', '>', 0);
                if ($munId) $resSubQuery->where('geographic_unit_id', $munId);
                elseif ($provinciaIds) $resSubQuery->whereIn('geographic_unit_id', $provinciaIds);

                $label = $info['label']; // Safe: from hardcoded map, never user input

                $query = DB::table('candidacies as c')
                    ->join('persons as p', 'c.person_id', '=', 'p.id')
                    ->join('contests as con', 'c.contest_id', '=', 'con.id')
                    ->where('con.corporation_id', $corpId)
                    ->leftJoin('candidacy_endorsements as ce', fn ($j) => $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true))
                    ->leftJoin('political_organizations as po', 'ce.organization_id', '=', 'po.id')
                    ->joinSub(
                        $resSubQuery->select('candidacy_id', DB::raw('SUM(value) as votos'))->groupBy('candidacy_id'),
                        'res', 'res.candidacy_id', '=', 'c.id'
                    )
                    ->select(
                        'p.id', 'p.full_name as nombre', DB::raw("'Santander' as municipio"),
                        DB::raw("NULL as provincia"), 'po.canonical_name as partido',
                        DB::raw("NULL as outcome"), DB::raw("NULL as tipo_aval"),
                        DB::raw('res.votos')
                    )
                    ->selectRaw("? as tipo_registro", [$label])
                    ->selectRaw("? as cargo", [$label]);

                if ($searchEscaped) $query->where('p.full_name', 'ilike', "%{$searchEscaped}%");

                $data = array_merge($data, $query->orderByDesc('res.votos')->limit(30)->get()->map(fn ($r) => (array) $r)->toArray());
            }
        }

        // === FILTRO POR PARTIDO ===
        if ($partido) {
            $data = array_values(array_filter($data, fn ($row) => ($row['partido'] ?? '') === $partido));
        }

        // #6: Enrich non-líder rows — only load líderes for relevant municipios
        $relevantMunicipios = array_unique(array_filter(array_column($data, 'municipio')));
        $lideresLookup = [];
        if (!empty($relevantMunicipios)) {
            $lideresAll = DB::table('lideres')
                ->select('nombre', 'municipio', 'telefono', 'email', 'barrio', 'direccion', 'zona', 'destacado')
                ->whereIn('municipio', $relevantMunicipios)
                ->get();
            foreach ($lideresAll as $l) {
                $key = mb_strtoupper(trim($l->nombre)) . '|' . mb_strtoupper(trim($l->municipio));
                $lideresLookup[$key] = $l;
            }
        }

        foreach ($data as &$row) {
            if (!isset($row['barrio'])) $row['barrio'] = null;
            if (!isset($row['direccion'])) $row['direccion'] = null;
            if (!isset($row['zona'])) $row['zona'] = null;
            if (($row['tipo_registro'] ?? '') === 'Líderes') continue;

            $key = mb_strtoupper(trim($row['nombre'] ?? '')) . '|' . mb_strtoupper(trim($row['municipio'] ?? ''));
            $lider = $lideresLookup[$key] ?? null;
            if ($lider) {
                if (empty($row['telefono'])) $row['telefono'] = $lider->telefono;
                if (empty($row['email'])) $row['email'] = $lider->email;
                if (empty($row['barrio'])) $row['barrio'] = $lider->barrio ?? null;
                if (empty($row['direccion'])) $row['direccion'] = $lider->direccion ?? null;
                if (empty($row['zona'])) $row['zona'] = $lider->zona ?? null;
                $row['destacado'] = (bool) ($lider->destacado ?? false);
            }
        }
        unset($row);

        // Unify duplicates
        $unified = [];
        foreach ($data as $row) {
            $key = strtoupper(trim($row['nombre'] ?? '')) . '|' . strtoupper(trim($row['municipio'] ?? ''));
            if (isset($unified[$key])) {
                $existing = $unified[$key]['tipo_registro'];
                $new = $row['tipo_registro'] ?? '';
                if ($new && !str_contains($existing, $new)) $unified[$key]['tipo_registro'] = $existing . ', ' . $new;
                $existingCargo = $unified[$key]['cargo'] ?? '';
                $newCargo = $row['cargo'] ?? '';
                if ($newCargo && !str_contains($existingCargo, $newCargo)) $unified[$key]['cargo'] = $existingCargo ? $existingCargo . ', ' . $newCargo : $newCargo;
                if (($row['votos'] ?? 0) > ($unified[$key]['votos'] ?? 0)) $unified[$key]['votos'] = $row['votos'];
                if (!empty($row['telefono']) && empty($unified[$key]['telefono'])) $unified[$key]['telefono'] = $row['telefono'];
                if (!empty($row['email']) && empty($unified[$key]['email'])) $unified[$key]['email'] = $row['email'];
                if (!empty($row['partido']) && empty($unified[$key]['partido'])) $unified[$key]['partido'] = $row['partido'];
                if (($row['outcome'] ?? '') === 'elected') $unified[$key]['outcome'] = 'elected';
                if (!empty($row['barrio']) && empty($unified[$key]['barrio'])) $unified[$key]['barrio'] = $row['barrio'];
                if (!empty($row['direccion']) && empty($unified[$key]['direccion'])) $unified[$key]['direccion'] = $row['direccion'];
                if (!empty($row['zona']) && empty($unified[$key]['zona'])) $unified[$key]['zona'] = $row['zona'];
            } else {
                $unified[$key] = $row;
            }
        }
        $data = array_values($unified);

        // Filtrar por barrio (post-unificación)
        if ($barrioEscaped) {
            $data = array_values(array_filter($data, fn ($row) => !empty($row['barrio']) && stripos($row['barrio'], $barrio) !== false));
        }

        $cargosDisponibles = collect($data)->pluck('cargo')->unique()->filter()->sort()->values()->toArray();

        $barriosQuery = DB::table('lideres')->select('barrio')->distinct()->whereNotNull('barrio')->where('barrio', '!=', '');
        if ($munId) $barriosQuery->where('geographic_unit_id', $munId);
        $barrios = $barriosQuery->pluck('barrio')->sort()->values()->toArray();

        $sectionCounts = collect($data)->groupBy('tipo_registro')->map(fn ($items) => count($items))->toArray();

        return Inertia::render('MapaPolitico', [
            'data' => array_values($data),
            'municipios' => $municipios,
            'provincias' => $provincias,
            'municipioInfo' => $municipioInfo,
            'cargosDisponibles' => $cargosDisponibles,
            'cargosPorTipo' => $this->cargosPorTipo(),
            'barrios' => $barrios,
            'sectionCounts' => $sectionCounts,
            'filters' => $request->only(['municipio', 'tipo', 'cargo', 'search', 'provincia', 'partido', 'barrio']),
        ]);
    }

    public function storeLider(Request $request)
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:255',
            'municipio_id' => 'required|uuid|exists:geographic_units,id',
            'cargo' => 'required|string|max:100',
            'telefono' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'direccion' => 'nullable|string|max:255',
            'barrio' => 'nullable|string|max:100',
            'zona' => 'nullable|in:rural,urbana',
            'observacion' => 'nullable|string|max:1000',
        ]);

        $mun = DB::table('geographic_units as g')
            ->leftJoin('geographic_units as prov', 'g.parent_id', '=', 'prov.id')
            ->where('g.id', $data['municipio_id'])
            ->select('g.canonical_name', 'prov.canonical_name as provincia')
            ->first();

        DB::table('lideres')->insert([
            'id' => Str::uuid(),
            'nombre' => $data['nombre'],
            'municipio' => $mun->canonical_name ?? '',
            'provincia' => $mun->provincia ?? '',
            'cargo' => $data['cargo'],
            'tipo' => 'directorio',
            'telefono' => $data['telefono'],
            'email' => $data['email'],
            'direccion' => $data['direccion'] ?? null,
            'barrio' => $data['barrio'] ?? null,
            'zona' => $data['zona'] ?? null,
            'observacion' => $data['observacion'],
            'geographic_unit_id' => $data['municipio_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Cache::forget('cargos_por_tipo');

        return redirect()->route('mapa-politico', [
            'municipio' => $data['municipio_id'],
            'tipo' => 'lideres',
            'search' => $data['nombre'],
        ])->with('success', "Líder «{$data['nombre']}» creado.");
    }

    public function persona(string $id)
    {
        // #5: Validate UUID format
        if (!Str::isUuid($id)) abort(404);

        $person = DB::table('persons')->where('id', $id)->first();
        $source = 'persona';

        if (!$person) {
            $person = DB::table('lideres')->where('id', $id)->first();
            $source = 'lider';
        }

        abort_if(!$person, 404);

        $nexos = DB::table('nexos_familiares')
            ->where('person_id', $id)
            ->orderBy('nombre')
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id, 'nombre' => $n->nombre, 'parentesco' => $n->parentesco,
                'cargo' => $n->cargo, 'edad' => $n->edad, 'gustos' => $n->gustos,
                'observaciones' => $n->observaciones,
            ])->toArray();

        $liderRows = collect();
        $personMunicipio = null;
        if ($source === 'persona') {
            $personMunicipio = DB::table('candidacies as c')
                ->join('contests as con', 'c.contest_id', '=', 'con.id')
                ->join('geographic_units as g', 'con.geographic_unit_id', '=', 'g.id')
                ->where('c.person_id', $id)
                ->value('g.canonical_name');

            if ($personMunicipio) {
                $liderRows = DB::table('lideres')
                    ->where('nombre', 'ilike', $person->full_name)
                    ->where('municipio', 'ilike', $personMunicipio)
                    ->get();
            }
            if ($liderRows->isEmpty()) {
                $liderRows = DB::table('lideres')->where('nombre', 'ilike', $person->full_name)->get();
            }
            if ($liderRows->isEmpty() && $personMunicipio) {
                $nameParts = explode(' ', $person->full_name);
                $query = DB::table('lideres')->where('municipio', 'ilike', $personMunicipio);
                foreach ($nameParts as $part) {
                    if (mb_strlen($part) >= 3) $query->where('nombre', 'ilike', '%' . $this->escapeLike($part) . '%');
                }
                $liderRows = $query->get();
            }
        }

        $bestLider = $source === 'lider' ? $person : $liderRows->first();

        $partido = null;
        if ($source === 'persona') {
            $partido = DB::table('candidacies as c')
                ->join('candidacy_endorsements as ce', fn ($j) => $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true))
                ->join('political_organizations as po', 'ce.organization_id', '=', 'po.id')
                ->where('c.person_id', $id)
                ->value('po.canonical_name');
        } elseif ($source === 'lider') {
            $partido = $person->partido ?? null;
        }

        $partidos = Cache::remember('all_partidos', 3600, fn () =>
            DB::table('political_organizations')->orderBy('canonical_name')->pluck('canonical_name')->toArray()
        );

        return response()->json([
            'id' => $id,
            'source' => $source,
            'nombre' => $source === 'persona' ? $person->full_name : $person->nombre,
            'telefono' => $bestLider->telefono ?? null,
            'email' => $bestLider->email ?? null,
            'municipio' => $source === 'lider' ? ($person->municipio ?? null) : ($personMunicipio ?? $bestLider->municipio ?? null),
            'cargo' => $bestLider->cargo ?? null,
            'observacion' => $bestLider->observacion ?? null,
            'partido' => $partido,
            'partidos' => $partidos,
            'cargos' => $source === 'lider'
                ? [$person->cargo]
                : $liderRows->pluck('cargo')->unique()->values()->toArray(),
            'direccion' => $bestLider->direccion ?? null,
            'barrio' => $bestLider->barrio ?? null,
            'zona' => $bestLider->zona ?? null,
            'destacado' => (bool) ($bestLider->destacado ?? false),
            'nexos' => $nexos,
        ]);
    }

    public function updatePersona(Request $request, string $id)
    {
        if (!Str::isUuid($id)) abort(404);

        $data = $request->validate([
            'telefono' => 'nullable|string|max:20',
            'email' => 'nullable|string|max:255',
            'cargo' => 'nullable|string|max:100',
            'partido' => 'nullable|string|max:255',
            'observacion' => 'nullable|string|max:1000',
            'direccion' => 'nullable|string|max:255',
            'barrio' => 'nullable|string|max:100',
            'zona' => 'nullable|in:rural,urbana',
            'destacado' => 'nullable|boolean',
        ]);

        // #15: Wrap in transaction to prevent race conditions
        DB::transaction(function () use ($id, $data, $request) {
            $lider = DB::table('lideres')->where('id', $id)->first();

            if (!$lider) {
                $person = DB::table('persons')->where('id', $id)->first();
                if ($person) {
                    $personMunicipio = DB::table('candidacies as c')
                        ->join('contests as con', 'c.contest_id', '=', 'con.id')
                        ->join('geographic_units as g', 'con.geographic_unit_id', '=', 'g.id')
                        ->where('c.person_id', $id)
                        ->select('g.canonical_name', 'g.id as geo_id')
                        ->first();

                    $munName = $personMunicipio->canonical_name ?? '';
                    $geoId = $personMunicipio->geo_id ?? null;

                    if ($munName) {
                        $lider = DB::table('lideres')
                            ->where('nombre', 'ilike', $person->full_name)
                            ->where('municipio', 'ilike', $munName)
                            ->first();
                    }
                    if (!$lider) {
                        $lider = DB::table('lideres')->where('nombre', 'ilike', $person->full_name)->first();
                    }

                    // #14: Create lider only when user explicitly edits — with correct municipio
                    if (!$lider) {
                        $provName = $geoId ? DB::table('geographic_units as g')
                            ->join('geographic_units as prov', 'g.parent_id', '=', 'prov.id')
                            ->where('g.id', $geoId)->value('prov.canonical_name') : '';

                        DB::table('lideres')->insert([
                            'id' => Str::uuid(),
                            'nombre' => $person->full_name,
                            'municipio' => $munName,
                            'provincia' => $provName ?? '',
                            'geographic_unit_id' => $geoId,
                            'cargo' => $data['cargo'] ?? '',
                            'partido' => $data['partido'] ?? null,
                            'tipo' => 'directorio',
                            'telefono' => $data['telefono'],
                            'email' => $data['email'],
                            'direccion' => $data['direccion'] ?? null,
                            'barrio' => $data['barrio'] ?? null,
                            'zona' => $data['zona'] ?? null,
                            'observacion' => $data['observacion'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        // Don't return — continue to update candidacy endorsement below
                    }
                }
            }

            // partido: use array_key_exists to distinguish "not sent" from "sent as empty/null"
            $partidoSent = array_key_exists('partido', $data);
            $partidoValue = $data['partido'] ?? null;

            if ($lider) {
                $updateData = [
                    'telefono' => $data['telefono'] ?? $lider->telefono,
                    'email' => $data['email'] ?? $lider->email,
                    'cargo' => $data['cargo'] ?? $lider->cargo,
                    'observacion' => $data['observacion'] ?? $lider->observacion,
                    'direccion' => $data['direccion'] ?? $lider->direccion ?? null,
                    'barrio' => $data['barrio'] ?? $lider->barrio ?? null,
                    'zona' => $data['zona'] ?? $lider->zona ?? null,
                    'destacado' => $data['destacado'] ?? $lider->destacado ?? false,
                    'updated_at' => now(),
                ];
                if ($partidoSent) $updateData['partido'] = $partidoValue;
                DB::table('lideres')->where('id', $lider->id)->update($updateData);
            }

            // Update partido via candidacy endorsement
            $candidacyId = DB::table('candidacies')->where('person_id', $id)->value('id');
            if ($candidacyId && $partidoSent) {
                if ($partidoValue) {
                    // Assign party
                    $orgId = DB::table('political_organizations')->where('canonical_name', $partidoValue)->value('id');
                    if ($orgId) {
                        $existing = DB::table('candidacy_endorsements')
                            ->where('candidacy_id', $candidacyId)
                            ->where('is_primary', true)
                            ->first();

                        if ($existing) {
                            DB::table('candidacy_endorsements')
                                ->where('id', $existing->id)
                                ->update(['organization_id' => $orgId, 'updated_at' => now()]);
                        } else {
                            DB::table('candidacy_endorsements')->insert([
                                'id' => Str::uuid(),
                                'candidacy_id' => $candidacyId,
                                'organization_id' => $orgId,
                                'is_primary' => true,
                                'endorsement_type' => 'endorsement',
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                } else {
                    // Remove party (user selected "Sin partido")
                    DB::table('candidacy_endorsements')
                        ->where('candidacy_id', $candidacyId)
                        ->where('is_primary', true)
                        ->delete();
                }
            }
        });

        if ($request->wantsJson() || $request->header('Accept') === 'application/json') {
            return response()->json(['success' => true, 'message' => 'Datos actualizados.']);
        }
        return back()->with('success', 'Datos actualizados.');
    }

    public function storeNexo(Request $request, string $personId)
    {
        if (!Str::isUuid($personId)) abort(404);

        // #16: Verify personId exists in either persons OR lideres
        $exists = DB::table('persons')->where('id', $personId)->exists()
            || DB::table('lideres')->where('id', $personId)->exists();
        abort_if(!$exists, 404);

        $data = $request->validate([
            'nombre' => 'required|string|max:255',
            'parentesco' => 'nullable|string|max:50',
            'cargo' => 'nullable|string|max:100',
            'edad' => 'nullable|integer|min:0|max:120',
            'gustos' => 'nullable|string|max:500',
            'observaciones' => 'nullable|string|max:500',
        ]);

        DB::table('nexos_familiares')->insert([
            'id' => Str::uuid(),
            'person_id' => $personId,
            ...$data,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Nexo familiar agregado.']);
        }
        return back()->with('success', 'Nexo familiar agregado.');
    }

    public function updateNexo(Request $request, string $id)
    {
        if (!Str::isUuid($id)) abort(404);

        $data = $request->validate([
            'nombre' => 'required|string|max:255',
            'parentesco' => 'nullable|string|max:50',
            'cargo' => 'nullable|string|max:100',
            'edad' => 'nullable|integer|min:0|max:120',
            'gustos' => 'nullable|string|max:500',
            'observaciones' => 'nullable|string|max:500',
        ]);

        $affected = DB::table('nexos_familiares')->where('id', $id)->update([...$data, 'updated_at' => now()]);
        abort_if($affected === 0, 404);

        return back()->with('success', 'Nexo actualizado.');
    }

    public function destroyNexo(Request $request, string $id)
    {
        if (!Str::isUuid($id)) abort(404);

        $affected = DB::table('nexos_familiares')->where('id', $id)->delete();
        abort_if($affected === 0, 404);

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Nexo eliminado.']);
        }
        return back()->with('success', 'Nexo eliminado.');
    }
}
