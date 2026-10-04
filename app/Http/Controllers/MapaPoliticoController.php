<?php

namespace App\Http\Controllers;

use App\Models\Lider;
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

        if ($munId) {
            $q->where('geographic_unit_id', $munId);
        } elseif ($provinciaIds) {
            $q->whereIn('geographic_unit_id', $provinciaIds);
        }

        return $q;
    }

    // #7: Cached cargos por tipo
    private function cargosPorTipo(): array
    {
        $liderCargos = DB::table('lideres')->select('cargo')->distinct()->pluck('cargo')
            ->flatMap(fn ($c) => array_map('trim', explode(',', $c)))->unique()->filter()->sort()->values()->toArray();

        return [
            'alcaldia' => ['Alcalde Electo', 'Candidato Alcaldía'],
            'concejo' => ['Concejal Electo', 'Candidato Concejo'],
            'lideres' => $liderCargos,
            'directorio' => $liderCargos,
            'senado' => ['Senador', 'Candidato Senado'],
            'camara' => ['Representante', 'Candidato Cámara'],
            'asamblea' => ['Diputado', 'Candidato Asamblea'],
            'todos' => collect(['Alcalde Electo', 'Candidato Alcaldía', 'Concejal Electo', 'Candidato Concejo', 'Senador', 'Candidato Senado', 'Representante', 'Candidato Cámara', 'Diputado', 'Candidato Asamblea'])
                ->merge($liderCargos)->unique()->sort()->values()->toArray(),
        ];
    }

    public function index(Request $request)
    {
        $munId = $request->input('municipio');
        $tipoRaw = $request->input('tipo', 'todos');
        $tipos = $tipoRaw ? explode(',', $tipoRaw) : ['todos'];
        $tipo = $tipoRaw; // keep for backward compat
        $tipoMatch = fn ($key) => in_array('todos', $tipos) || in_array($key, $tipos);
        $cargoRaw = $request->input('cargo');
        $cargos = $cargoRaw ? explode(',', $cargoRaw) : [];
        $cargo = $cargoRaw;
        $search = $request->input('search');
        $partido = $request->input('partido');
        $barrioRaw = $request->input('barrio');
        $barrios_filter = $barrioRaw ? explode(',', $barrioRaw) : [];
        $barrio = $barrioRaw;
        $destacado = $request->input('destacado');
        $profesion = $request->input('profesion');

        // #3: Escape search for ILIKE
        $searchEscaped = $search ? $this->escapeLike($search) : null;
        $barrioEscaped = $barrio ? $this->escapeLike($barrio) : null;

        $areaMetropolitana = ['Bucaramanga', 'Floridablanca', 'Piedecuesta', 'Girón', 'Rionegro', 'Lebrija'];
        $municipios = CachedQueries::allMunicipios();

        // Territory scope: restrict municipios for users with territory_scope
        $user = $request->user();
        $allowedGeoIds = $user?->allowedGeoIds();
        if ($allowedGeoIds) {
            $municipios = array_values(array_filter($municipios, fn ($m) => in_array($m['id'], $allowedGeoIds)));
            if ($munId && ! in_array($munId, $allowedGeoIds)) {
                $munId = null;
            }
        }

        $provincias = collect($municipios)->pluck('provincia')->unique()->filter()->sort()->values()->toArray();
        array_unshift($provincias, 'Área Metropolitana');
        $provincias = array_unique($provincias);

        $provincia = $request->input('provincia');
        $provinciaIds = null;
        if ($provincia && ! $munId) {
            if ($provincia === 'Área Metropolitana') {
                $provinciaIds = collect($municipios)->filter(fn ($m) => in_array($m['name'], $areaMetropolitana))->pluck('id')->toArray();
            } else {
                $provinciaIds = collect($municipios)->filter(fn ($m) => ($m['provincia'] ?? '') === $provincia)->pluck('id')->toArray();
            }
        }

        // If user has territory restriction and no geo filter set, force filter by allowed IDs
        if ($allowedGeoIds && ! $munId && ! $provinciaIds) {
            $provinciaIds = $allowedGeoIds;
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
        $noGeoFilter = ! $munId && ! $provinciaIds && ! $searchEscaped && ! $barrioEscaped;

        // === ALCALDIA ===
        if ($tipoMatch('alcaldia')) {
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

            if ($munId) {
                $query->where('con.geographic_unit_id', $munId);
            } elseif ($provinciaIds) {
                $query->whereIn('con.geographic_unit_id', $provinciaIds);
            }
            if ($searchEscaped) {
                $query->where('p.full_name', 'ilike', "%{$searchEscaped}%");
            }
            if ($noGeoFilter) {
                $query->where('c.outcome', 'elected');
            }

            $data = array_merge($data, $query->orderByDesc('res.votos')->get()->map(fn ($r) => (array) $r)->toArray());
        }

        // === CONCEJO ===
        $showConcejo = $tipoMatch('concejo')
            || (in_array('lideres', $tipos) && $cargo && stripos($cargo, 'CONCEJAL') !== false);
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

            if ($munId) {
                $query->where('con.geographic_unit_id', $munId);
            } elseif ($provinciaIds) {
                $query->whereIn('con.geographic_unit_id', $provinciaIds);
            }
            if ($searchEscaped) {
                $query->where('p.full_name', 'ilike', "%{$searchEscaped}%");
            }
            if (! empty($cargos)) {
                $query->whereIn(DB::raw("CASE WHEN c.outcome = 'elected' THEN 'Concejal Electo' ELSE 'Candidato Concejo' END"), $cargos);
            }
            if ($noGeoFilter) {
                $query->where('c.outcome', 'elected');
            }

            $data = array_merge($data, $query->orderByDesc('res.votos')->get()->map(fn ($r) => (array) $r)->toArray());
        }

        // === LIDERES ===
        if ($tipoMatch('lideres')) {
            $query = DB::table('lideres')
                ->select(
                    'id', 'nombre', 'municipio', 'provincia',
                    'partido', DB::raw('NULL as outcome'), DB::raw('NULL as tipo_aval'),
                    'votos', DB::raw("'Líderes' as tipo_registro"),
                    'cargo', 'telefono', 'email', 'observacion', 'barrio', 'direccion', 'zona', 'destacado', 'profesion', 'cedula'
                );

            if ($munId) {
                $query->where('geographic_unit_id', $munId);
            } elseif ($provinciaIds) {
                $query->whereIn('geographic_unit_id', $provinciaIds);
            }
            if ($searchEscaped) {
                $query->where(function ($q) use ($searchEscaped) {
                    $q->where('nombre', 'ilike', "%{$searchEscaped}%")->orWhere('cedula', 'like', "%{$searchEscaped}%");
                });
            }
            if (! empty($cargos)) {
                $query->where(function ($q) use ($cargos) {
                    foreach ($cargos as $c) {
                        $q->orWhere('cargo', 'ilike', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $c).'%');
                    }
                });
            }
            if (! empty($barrios_filter)) {
                $query->where(function ($q) use ($barrios_filter) {
                    foreach ($barrios_filter as $b) {
                        $q->orWhere('barrio', 'ilike', '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($b)).'%');
                    }
                });
            }
            if ($noGeoFilter) {
                $query->limit(200);
            }

            $data = array_merge($data, $query->orderBy('nombre')->get()->map(fn ($r) => (array) $r)->toArray());
        }

        // === DIRECTORIO MUNICIPAL ===
        if ($tipoMatch('directorio')) {
            $query = DB::table('lideres')
                ->where('cargo', 'ilike', '%Directorio Municipal%')
                ->select(
                    'id', 'nombre', 'municipio', 'provincia',
                    'partido', DB::raw('NULL as outcome'), DB::raw('NULL as tipo_aval'),
                    'votos', DB::raw("'Directorio Municipal' as tipo_registro"),
                    'cargo', 'telefono', 'email', 'observacion', 'barrio', 'direccion', 'zona', 'destacado', 'profesion', 'cedula'
                );

            if ($munId) {
                $query->where('geographic_unit_id', $munId);
            } elseif ($provinciaIds) {
                $query->whereIn('geographic_unit_id', $provinciaIds);
            }
            if ($searchEscaped) {
                $query->where(function ($q) use ($searchEscaped) {
                    $q->where('nombre', 'ilike', "%{$searchEscaped}%")->orWhere('cedula', 'like', "%{$searchEscaped}%");
                });
            }
            if (! empty($cargos)) {
                $query->where(function ($q) use ($cargos) {
                    foreach ($cargos as $c) {
                        $q->orWhere('cargo', 'ilike', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $c).'%');
                    }
                });
            }
            if (! empty($barrios_filter)) {
                $query->where(function ($q) use ($barrios_filter) {
                    foreach ($barrios_filter as $b) {
                        $q->orWhere('barrio', 'ilike', '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($b)).'%');
                    }
                });
            }
            if ($noGeoFilter) {
                $query->limit(200);
            }

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
            if ($tipoMatch($tipoKey)) {
                $corpId = $this->corporationId($info['corp']);
                if (! $corpId) {
                    continue;
                }

                $resSubQuery = DB::table('electoral_results')
                    ->whereIn('metric_type', ['votes', 'nominal_votes'])->where('value', '>', 0);
                if ($munId) {
                    $resSubQuery->where('geographic_unit_id', $munId);
                } elseif ($provinciaIds) {
                    $resSubQuery->whereIn('geographic_unit_id', $provinciaIds);
                }

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
                        DB::raw('NULL as provincia'), 'po.canonical_name as partido',
                        DB::raw('NULL as outcome'), DB::raw('NULL as tipo_aval'),
                        DB::raw('res.votos')
                    )
                    ->selectRaw('? as tipo_registro', [$label])
                    ->selectRaw('? as cargo', [$label]);

                if ($searchEscaped) {
                    $query->where('p.full_name', 'ilike', "%{$searchEscaped}%");
                }

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
        if (! empty($relevantMunicipios)) {
            $lideresAll = DB::table('lideres')
                ->select('nombre', 'municipio', 'telefono', 'email', 'barrio', 'direccion', 'zona', 'destacado', 'profesion', 'cedula', 'cargo as lider_cargo')
                ->whereIn('municipio', $relevantMunicipios)
                ->get();
            foreach ($lideresAll as $l) {
                $key = mb_strtoupper(trim($l->nombre)).'|'.mb_strtoupper(trim($l->municipio));
                $lideresLookup[$key] = $l;
            }
        }

        foreach ($data as &$row) {
            if (! isset($row['barrio'])) {
                $row['barrio'] = null;
            }
            if (! isset($row['direccion'])) {
                $row['direccion'] = null;
            }
            if (! isset($row['zona'])) {
                $row['zona'] = null;
            }
            if (($row['tipo_registro'] ?? '') === 'Líderes') {
                continue;
            }

            $key = mb_strtoupper(trim($row['nombre'] ?? '')).'|'.mb_strtoupper(trim($row['municipio'] ?? ''));
            $lider = $lideresLookup[$key] ?? null;
            if ($lider) {
                if (empty($row['telefono'])) {
                    $row['telefono'] = $lider->telefono;
                }
                if (empty($row['email'])) {
                    $row['email'] = $lider->email;
                }
                if (empty($row['barrio'])) {
                    $row['barrio'] = $lider->barrio ?? null;
                }
                if (empty($row['direccion'])) {
                    $row['direccion'] = $lider->direccion ?? null;
                }
                if (empty($row['zona'])) {
                    $row['zona'] = $lider->zona ?? null;
                }
                $row['destacado'] = (bool) ($lider->destacado ?? false);
                if (empty($row['profesion'])) {
                    $row['profesion'] = $lider->profesion ?? null;
                }
                if (empty($row['cedula'])) {
                    $row['cedula'] = $lider->cedula ?? null;
                }
                // Unify: if líder has a cargo, add it to tipo_registro
                $liderCargo = $lider->lider_cargo ?? null;
                if ($liderCargo && $liderCargo !== 'Líder' && ! str_contains($row['tipo_registro'] ?? '', 'Líderes')) {
                    $row['tipo_registro'] = ($row['tipo_registro'] ?? '').', Líderes';
                }
            }
        }
        unset($row);

        // === FILTRO POR DESTACADO ===
        if ($destacado) {
            $data = array_values(array_filter($data, fn ($row) => ! empty($row['destacado'])));
        }

        // === FILTRO POR PROFESION ===
        if ($profesion) {
            $data = array_values(array_filter($data, fn ($row) => ($row['profesion'] ?? '') === $profesion));
        }

        // Unify duplicates
        $unified = [];
        foreach ($data as $row) {
            $key = strtoupper(trim($row['nombre'] ?? '')).'|'.strtoupper(trim($row['municipio'] ?? ''));
            if (isset($unified[$key])) {
                $existing = $unified[$key]['tipo_registro'];
                $new = $row['tipo_registro'] ?? '';
                if ($new && ! str_contains($existing, $new)) {
                    $unified[$key]['tipo_registro'] = $existing.', '.$new;
                }
                $existingCargo = $unified[$key]['cargo'] ?? '';
                $newCargo = $row['cargo'] ?? '';
                if ($newCargo && ! str_contains($existingCargo, $newCargo)) {
                    $unified[$key]['cargo'] = $existingCargo ? $existingCargo.', '.$newCargo : $newCargo;
                }
                if (($row['votos'] ?? 0) > ($unified[$key]['votos'] ?? 0)) {
                    $unified[$key]['votos'] = $row['votos'];
                }
                if (! empty($row['telefono']) && empty($unified[$key]['telefono'])) {
                    $unified[$key]['telefono'] = $row['telefono'];
                }
                if (! empty($row['email']) && empty($unified[$key]['email'])) {
                    $unified[$key]['email'] = $row['email'];
                }
                if (! empty($row['partido']) && empty($unified[$key]['partido'])) {
                    $unified[$key]['partido'] = $row['partido'];
                }
                if (($row['outcome'] ?? '') === 'elected') {
                    $unified[$key]['outcome'] = 'elected';
                }
                if (! empty($row['barrio']) && empty($unified[$key]['barrio'])) {
                    $unified[$key]['barrio'] = $row['barrio'];
                }
                if (! empty($row['direccion']) && empty($unified[$key]['direccion'])) {
                    $unified[$key]['direccion'] = $row['direccion'];
                }
                if (! empty($row['zona']) && empty($unified[$key]['zona'])) {
                    $unified[$key]['zona'] = $row['zona'];
                }
            } else {
                $unified[$key] = $row;
            }
        }
        $data = array_values($unified);

        // Filtrar por barrio (post-unificación)
        if (! empty($barrios_filter)) {
            $data = array_values(array_filter($data, function ($row) use ($barrios_filter) {
                if (empty($row['barrio'])) {
                    return false;
                }
                foreach ($barrios_filter as $b) {
                    if (stripos($row['barrio'], trim($b)) !== false) {
                        return true;
                    }
                }

                return false;
            }));
        }

        $cargosDisponibles = collect($data)->pluck('cargo')->filter()->flatMap(fn ($c) => array_map('trim', explode(',', $c)))->unique()->filter()->sort()->values()->toArray();

        $barriosQuery = DB::table('lideres')->select('barrio')->distinct()->whereNotNull('barrio')->where('barrio', '!=', '');
        if ($munId) {
            $barriosQuery->where('geographic_unit_id', $munId);
        }
        $barrios = $barriosQuery->pluck('barrio')->sort()->values()->toArray();

        $sectionCounts = collect($data)->groupBy('tipo_registro')->map(fn ($items) => count($items))->toArray();

        // Party totals (votos de partido, no de candidato) for Cámara and Senado
        $partyTotalsQuery = DB::table('electoral_results as er')
            ->join('political_organizations as po', 'er.organization_id', '=', 'po.id')
            ->join('contests as c', 'er.contest_id', '=', 'c.id')
            ->leftJoin('corporations as corp', 'c.corporation_id', '=', 'corp.id')
            ->whereNull('er.candidacy_id')
            ->whereNotNull('er.organization_id');

        if ($munId) {
            $partyTotalsQuery->where('er.geographic_unit_id', $munId)
                ->select('po.canonical_name as partido', 'er.value as votos', 'corp.name as corporacion');
        } else {
            // General: sum across all municipios
            $partyTotalsQuery->select('po.canonical_name as partido', DB::raw('SUM(er.value) as votos'), 'corp.name as corporacion')
                ->groupBy('po.canonical_name', 'corp.name');
        }

        $partyTotals = $partyTotalsQuery
            ->orderBy('corp.name')
            ->orderByDesc('votos')
            ->get()
            ->groupBy('corporacion')
            ->map(fn ($items) => $items->map(fn ($r) => ['partido' => $r->partido, 'votos' => (int) $r->votos])->values()->toArray())
            ->toArray();

        return Inertia::render('MapaPolitico', [
            'data' => array_values($data),
            'municipios' => $municipios,
            'provincias' => $provincias,
            'municipioInfo' => $municipioInfo,
            'cargosDisponibles' => $cargosDisponibles,
            'cargosPorTipo' => $this->cargosPorTipo(),
            'barrios' => $barrios,
            'sectionCounts' => $sectionCounts,
            'partyTotals' => $partyTotals,
            'filters' => $request->only(['municipio', 'tipo', 'cargo', 'search', 'provincia', 'partido', 'barrio', 'destacado', 'profesion']),
        ]);
    }

    public function storeLider(Request $request)
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:255',
            'municipio_id' => 'required|uuid|exists:geographic_units,id',
            'cargo' => 'nullable|string|max:255',
            'tipos' => 'nullable|array',
            'provincia' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'cedula' => 'nullable|string|max:20',
            'profesion' => 'nullable|string|max:255',
            'direccion' => 'nullable|string|max:255',
            'barrio' => 'nullable|string|max:100',
            'zona' => 'nullable|in:rural,urbana',
            'observacion' => 'nullable|string|max:1000',
            // Extended fields
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

        $insertData = [
            'id' => Str::uuid(),
            'nombre' => $data['nombre'],
            'municipio' => $mun->canonical_name ?? '',
            'provincia' => $mun->provincia ?? '',
            'cargo' => $data['cargo'] ?? '',
            'tipo' => 'directorio',
            'telefono' => $data['telefono'] ?? null,
            'email' => $data['email'] ?? null,
            'cedula' => $data['cedula'] ?? null,
            'profesion' => $data['profesion'] ?? null,
            'direccion' => $data['direccion'] ?? null,
            'barrio' => $data['barrio'] ?? null,
            'zona' => $data['zona'] ?? null,
            'observacion' => $data['observacion'] ?? null,
            'geographic_unit_id' => $data['municipio_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // Extended fields
        $extendedFields = [
            'tipo_documento', 'fecha_expedicion_doc', 'genero', 'fecha_nacimiento',
            'estado_civil', 'referente_documento', 'referente_nombre', 'referente_apellido',
            'departamento_votacion', 'municipio_votacion', 'puesto_votacion', 'direccion_puesto', 'mesa_votacion',
            'nivel_confianza', 'convenio', 'escolaridad', 'tipo_hoja_vida', 'fecha_registro_hv',
            'facebook', 'twitter', 'instagram',
        ];
        foreach ($extendedFields as $field) {
            if ($field === 'nivel_confianza') {
                $insertData[$field] = $data[$field] ?? 'sin_llamar';
            } else {
                $insertData[$field] = $data[$field] ?? null;
            }
        }
        $booleanFields = ['militante', 'autoriza_datos', 'verificado', 'fallecido', 'empresario', 'reservista', 'funcionario', 'exfuncionario', 'gran_elector'];
        foreach ($booleanFields as $field) {
            $insertData[$field] = $data[$field] ?? false;
        }

        DB::table('lideres')->insert($insertData);

        Cache::forget('cargos_por_tipo');

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => "Líder «{$data['nombre']}» creado."]);
        }

        return redirect()->route('mapa-politico', [
            'municipio' => $data['municipio_id'],
            'tipo' => 'lideres',
            'search' => $data['nombre'],
        ])->with('success', "Líder «{$data['nombre']}» creado.");
    }

    public function persona(string $id)
    {
        // #5: Validate UUID format
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $person = DB::table('persons')->where('id', $id)->first();
        $source = 'persona';
        $liderModel = null;

        if (! $person) {
            $liderModel = Lider::with(['contactos', 'infoPolitica'])->find($id);
            $person = $liderModel;
            $source = 'lider';
        }

        abort_if(! $person, 404);

        $nexos = DB::table('nexos_familiares')
            ->where('person_id', $id)
            ->orderBy('nombre')
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id, 'nombre' => $n->nombre, 'parentesco' => $n->parentesco,
                'cargo' => $n->cargo, 'edad' => $n->edad, 'gustos' => $n->gustos,
                'observaciones' => $n->observaciones,
                'cedula' => $n->cedula ?? null, 'telefono' => $n->telefono ?? null,
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
                    if (mb_strlen($part) >= 3) {
                        $query->where('nombre', 'ilike', '%'.$this->escapeLike($part).'%');
                    }
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

        $partidos = Cache::remember('all_partidos', 3600, fn () => DB::table('political_organizations')->orderBy('canonical_name')->pluck('canonical_name')->toArray()
        );

        $response = [
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
            'profesion' => $bestLider->profesion ?? null,
            'cedula' => $bestLider->cedula ?? $person->cedula ?? null,
            'tipo_registro' => $source === 'lider' ? 'Líderes' : null,
            'votos' => $source === 'lider'
                ? (int) ($bestLider->votos ?? 0)
                : (int) DB::table('electoral_results as er')
                    ->join('candidacies as c', 'er.candidacy_id', '=', 'c.id')
                    ->where('c.person_id', $id)
                    ->where('er.metric_type', 'votes')
                    ->sum('er.value'),
            'nexos' => $nexos,
        ];

        // Include extended lider fields when source is lider
        if ($source === 'lider' && $liderModel) {
            $response['tipo_documento'] = $liderModel->tipo_documento;
            $response['fecha_expedicion_doc'] = $liderModel->fecha_expedicion_doc;
            $response['genero'] = $liderModel->genero;
            $response['fecha_nacimiento'] = $liderModel->fecha_nacimiento;
            $response['foto'] = $liderModel->foto;
            $response['estado_civil'] = $liderModel->estado_civil;
            $response['referente_documento'] = $liderModel->referente_documento;
            $response['referente_nombre'] = $liderModel->referente_nombre;
            $response['referente_apellido'] = $liderModel->referente_apellido;
            $response['departamento_votacion'] = $liderModel->departamento_votacion;
            $response['municipio_votacion'] = $liderModel->municipio_votacion;
            $response['puesto_votacion'] = $liderModel->puesto_votacion;
            $response['direccion_puesto'] = $liderModel->direccion_puesto;
            $response['mesa_votacion'] = $liderModel->mesa_votacion;
            $response['militante'] = $liderModel->militante;
            $response['autoriza_datos'] = $liderModel->autoriza_datos;
            $response['verificado'] = $liderModel->verificado;
            $response['fallecido'] = $liderModel->fallecido;
            $response['empresario'] = $liderModel->empresario;
            $response['reservista'] = $liderModel->reservista;
            $response['funcionario'] = $liderModel->funcionario;
            $response['exfuncionario'] = $liderModel->exfuncionario;
            $response['gran_elector'] = $liderModel->gran_elector;
            $response['nivel_confianza'] = $liderModel->nivel_confianza;
            $response['convenio'] = $liderModel->convenio;
            $response['escolaridad'] = $liderModel->escolaridad;
            $response['tipo_hoja_vida'] = $liderModel->tipo_hoja_vida;
            $response['fecha_registro_hv'] = $liderModel->fecha_registro_hv;
            $response['facebook'] = $liderModel->facebook;
            $response['twitter'] = $liderModel->twitter;
            $response['instagram'] = $liderModel->instagram;
            $response['contactos'] = $liderModel->contactos;
            $response['info_politica'] = $liderModel->infoPolitica;

            if ($liderModel->cedula) {
                $referidos = Lider::where('referente_documento', $liderModel->cedula)
                    ->select('id', 'nombre', 'cedula', 'municipio', 'telefono')
                    ->orderBy('nombre')
                    ->get()
                    ->toArray();
                $response['referidos'] = $referidos;
                $response['referidos_count'] = count($referidos);
            } else {
                $response['referidos'] = [];
                $response['referidos_count'] = 0;
            }
        }

        return response()->json($response);
    }

    public function updatePersona(Request $request, string $id)
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

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
            'profesion' => 'nullable|string|max:255',
            'cedula' => 'nullable|string|max:20',
            'votos' => 'nullable|integer|min:0',
            // Extended fields
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

        // #15: Wrap in transaction to prevent race conditions
        DB::transaction(function () use ($id, $data) {
            $lider = DB::table('lideres')->where('id', $id)->first();

            if (! $lider) {
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
                    if (! $lider) {
                        $lider = DB::table('lideres')->where('nombre', 'ilike', $person->full_name)->first();
                    }

                    // #14: Create lider only when user explicitly edits — with correct municipio
                    if (! $lider) {
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
                            'telefono' => $data['telefono'] ?? null,
                            'email' => $data['email'] ?? null,
                            'direccion' => $data['direccion'] ?? null,
                            'barrio' => $data['barrio'] ?? null,
                            'zona' => $data['zona'] ?? null,
                            'observacion' => $data['observacion'] ?? null,
                            'cedula' => $data['cedula'] ?? null,
                            'profesion' => $data['profesion'] ?? null,
                            'destacado' => $data['destacado'] ?? false,
                            'votos' => $data['votos'] ?? 0,
                            'nivel_confianza' => $data['nivel_confianza'] ?? 'sin_llamar',
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
                    'telefono' => array_key_exists('telefono', $data) ? $data['telefono'] : $lider->telefono,
                    'email' => array_key_exists('email', $data) ? $data['email'] : $lider->email,
                    'cargo' => array_key_exists('cargo', $data) ? $data['cargo'] : $lider->cargo,
                    'observacion' => array_key_exists('observacion', $data) ? $data['observacion'] : $lider->observacion,
                    'direccion' => array_key_exists('direccion', $data) ? $data['direccion'] : $lider->direccion,
                    'barrio' => array_key_exists('barrio', $data) ? $data['barrio'] : $lider->barrio,
                    'zona' => array_key_exists('zona', $data) ? $data['zona'] : $lider->zona,
                    'destacado' => array_key_exists('destacado', $data) ? (bool) $data['destacado'] : ($lider->destacado ?? false),
                    'profesion' => array_key_exists('profesion', $data) ? $data['profesion'] : $lider->profesion,
                    'cedula' => array_key_exists('cedula', $data) ? $data['cedula'] : $lider->cedula,
                    'votos' => array_key_exists('votos', $data) ? (int) ($data['votos'] ?? 0) : ($lider->votos ?? 0),
                    'updated_at' => now(),
                ];
                if ($partidoSent) {
                    $updateData['partido'] = $partidoValue;
                }

                // Extended fields — only update when explicitly sent
                $extendedFields = [
                    'tipo_documento', 'fecha_expedicion_doc', 'genero', 'fecha_nacimiento', 'foto',
                    'estado_civil', 'referente_documento', 'referente_nombre', 'referente_apellido',
                    'departamento_votacion', 'municipio_votacion', 'puesto_votacion', 'direccion_puesto', 'mesa_votacion',
                    'militante', 'autoriza_datos', 'verificado', 'fallecido', 'empresario', 'reservista',
                    'funcionario', 'exfuncionario', 'gran_elector', 'nivel_confianza', 'convenio',
                    'escolaridad', 'tipo_hoja_vida', 'fecha_registro_hv',
                    'facebook', 'twitter', 'instagram',
                ];
                foreach ($extendedFields as $field) {
                    if (array_key_exists($field, $data)) {
                        $value = $data[$field];
                        if ($field === 'nivel_confianza' && ($value === null || $value === '')) {
                            $value = 'sin_llamar';
                        }
                        $updateData[$field] = $value;
                    }
                }

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
        if (! Str::isUuid($personId)) {
            abort(404);
        }

        // #16: Verify personId exists in either persons OR lideres
        $exists = DB::table('persons')->where('id', $personId)->exists()
            || DB::table('lideres')->where('id', $personId)->exists();
        abort_if(! $exists, 404);

        $data = $request->validate([
            'nombre' => 'required|string|max:255',
            'parentesco' => 'nullable|string|max:50',
            'cargo' => 'nullable|string|max:100',
            'edad' => 'nullable|integer|min:0|max:120',
            'gustos' => 'nullable|string|max:500',
            'observaciones' => 'nullable|string|max:500',
            'cedula' => 'nullable|string|max:20',
            'telefono' => 'nullable|string|max:20',
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
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $data = $request->validate([
            'nombre' => 'required|string|max:255',
            'parentesco' => 'nullable|string|max:50',
            'cargo' => 'nullable|string|max:100',
            'edad' => 'nullable|integer|min:0|max:120',
            'gustos' => 'nullable|string|max:500',
            'observaciones' => 'nullable|string|max:500',
            'cedula' => 'nullable|string|max:20',
            'telefono' => 'nullable|string|max:20',
        ]);

        DB::table('nexos_familiares')->where('id', $id)->update([...$data, 'updated_at' => now()]);

        return response()->json(['success' => true, 'message' => 'Nexo actualizado.']);
    }

    public function destroyNexo(Request $request, string $id)
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        DB::table('nexos_familiares')->where('id', $id)->delete();

        return response()->json(['success' => true, 'message' => 'Nexo eliminado.']);
    }

    public function destroyLider(Request $request, string $id)
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        // Delete nexos
        DB::table('nexos_familiares')->where('person_id', $id)->delete();

        // Try lideres first
        $affected = DB::table('lideres')->where('id', $id)->delete();

        if ($affected === 0) {
            // Try finding líder by person name match
            $person = DB::table('persons')->where('id', $id)->first();
            if ($person) {
                DB::table('lideres')->where('nombre', 'ilike', $person->full_name)->delete();
            }
        }

        return response()->json(['success' => true, 'message' => 'Persona eliminada.']);
    }

    public function searchLideres(Request $request)
    {
        $q = trim($request->input('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['data' => []]);
        }

        $excludeId = $request->input('exclude_id');

        $query = DB::table('lideres')
            ->where(function ($qb) use ($q) {
                $qb->where('nombre', 'ilike', "%{$q}%")
                    ->orWhere('cedula', 'like', "%{$q}%");
            })
            ->select('id', 'nombre', 'cedula', 'municipio', 'telefono')
            ->orderBy('nombre')
            ->limit(10);

        if ($excludeId && Str::isUuid($excludeId)) {
            $query->where('id', '!=', $excludeId);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function addReferido(Request $request, string $id)
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $data = $request->validate([
            'lider_id' => 'required|uuid',
        ]);

        $referente = DB::table('lideres')->where('id', $id)->first();
        if (! $referente || ! $referente->cedula) {
            return response()->json(['success' => false, 'message' => 'El referente debe tener cédula registrada.'], 422);
        }

        $referido = DB::table('lideres')->where('id', $data['lider_id'])->first();
        if (! $referido) {
            return response()->json(['success' => false, 'message' => 'Persona no encontrada.'], 404);
        }

        $nameParts = explode(' ', $referente->nombre, 2);

        DB::table('lideres')->where('id', $data['lider_id'])->update([
            'referente_documento' => $referente->cedula,
            'referente_nombre' => $nameParts[0] ?? '',
            'referente_apellido' => $nameParts[1] ?? '',
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Referido agregado.']);
    }

    public function removeReferido(Request $request, string $id, string $referidoId)
    {
        if (! Str::isUuid($id) || ! Str::isUuid($referidoId)) {
            abort(404);
        }

        DB::table('lideres')->where('id', $referidoId)->update([
            'referente_documento' => null,
            'referente_nombre' => null,
            'referente_apellido' => null,
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Referido eliminado.']);
    }
}
