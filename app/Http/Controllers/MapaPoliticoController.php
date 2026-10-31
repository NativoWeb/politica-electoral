<?php

namespace App\Http\Controllers;

use App\Actions\Votantes\AddNexo;
use App\Actions\Votantes\AddReferido;
use App\Actions\Votantes\FindPossibleDuplicates;
use App\Actions\Votantes\MergeLideres;
use App\Actions\Votantes\UpdateVotante;
use App\Http\Controllers\Concerns\FiltersByCargo;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\Campaign;
use App\Models\CargoCategory;
use App\Models\Lider;
use App\Services\CachedQueries;
use App\Support\Cedula;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class MapaPoliticoController extends Controller
{
    use FiltersByCargo, ScopesTerritory;

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

    // Cámara candidates endorsed by a party that ran inside a coalition list; votes are reported under the list name.
    private const CAMARA_LIST_BY_PARTY = [
        'Alianza Verde' => 'Verde / En Marcha',
        'Nuevo Liberalismo' => 'Mira / Nuevo Liberal',
    ];

    private function renamePerson(string $personId, string $fullName): void
    {
        UpdateVotante::renamePerson($personId, $fullName);
    }

    // The single lider with the candidate's exact name in a municipio where they ran, inside the user's territory.
    // The user's own campaign decides first; a subcampaign's lider is used only when the own campaign has none.
    private function linkedLiderId(object $person): ?string
    {
        $find = fn ($lideres) => $this->scopeToTerritory($lideres, 'l.geographic_unit_id')
            ->join('contests as con', 'con.geographic_unit_id', '=', 'l.geographic_unit_id')
            ->join('candidacies as c', function ($j) use ($person) {
                $j->on('c.contest_id', '=', 'con.id')->where('c.person_id', '=', $person->id);
            })
            ->whereRaw('LOWER(l.nombre) = LOWER(?)', [$person->full_name])
            ->distinct()
            ->pluck('l.id');

        $ids = $find($this->ownLideres('l'));
        if ($ids->isEmpty()) {
            $ids = $find($this->visibleLideres('l'));
        }

        return $ids->count() === 1 ? $ids->first() : null;
    }

    private function candidatePartido(string $personId): ?string
    {
        return DB::table('candidacies as c')
            ->join('candidacy_endorsements as ce', fn ($j) => $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true))
            ->join('political_organizations as po', 'ce.organization_id', '=', 'po.id')
            ->where('c.person_id', $personId)
            ->value('po.canonical_name');
    }

    private function candidateVotos(string $personId): int
    {
        return (int) DB::table('electoral_results as er')
            ->join('candidacies as c', 'er.candidacy_id', '=', 'c.id')
            ->where('c.person_id', $personId)
            ->where('er.metric_type', 'votes')
            ->sum('er.value');
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
        $catalogCargos = CargoCategory::active()->pluck('name')->toArray();
        $liderCargos = collect($catalogCargos)
            ->merge($this->visibleLideres()->select('cargo')->distinct()->pluck('cargo')
                ->flatMap(fn ($c) => array_map('trim', explode(',', $c)))->unique()->filter())
            ->unique()->values()->toArray();

        return [
            'alcaldia' => ['Alcalde Electo', 'Candidato Alcaldía'],
            'concejo' => ['Concejal', 'Candidato Concejo'],
            'lideres' => $liderCargos,
            'nuevos_lideres' => $liderCargos,
            'directorio' => $liderCargos,
            'senado' => ['Senador', 'Candidato Senado'],
            'camara' => ['Representante', 'Candidato Cámara'],
            'asamblea' => ['Diputado', 'Candidato Asamblea'],
            'todos' => collect(['Alcalde Electo', 'Candidato Alcaldía', 'Concejal', 'Candidato Concejo', 'Senador', 'Candidato Senado', 'Representante', 'Candidato Cámara', 'Diputado', 'Candidato Asamblea'])
                ->merge($liderCargos)->unique()->sort()->values()->toArray(),
        ];
    }

    public function index(Request $request)
    {
        [
            'data' => $data, 'municipios' => $municipios, 'provincias' => $provincias, 'municipioInfo' => $municipioInfo,
            'munId' => $munId, 'provinciaIds' => $provinciaIds, 'partido' => $partido, 'campana' => $campana,
            'applyEstado' => $applyEstado,
        ] = $this->rows($request);

        $cargosDisponibles = collect($data)->pluck('cargo')->filter()->flatMap(fn ($c) => array_map('trim', explode(',', $c)))->unique()->filter()->sort()->values()->toArray();

        $barriosQuery = $this->visibleLideres();
        $applyEstado($barriosQuery);
        $barriosQuery->select('barrio')->distinct()->whereNotNull('barrio')->where('barrio', '!=', '');
        if ($munId) {
            $barriosQuery->where('geographic_unit_id', $munId);
        } else {
            $this->scopeToTerritory($barriosQuery);
        }
        $barrios = $barriosQuery->pluck('barrio')->sort()->values()->toArray();

        // A row with several tipos counts once in each of its sections, as the page groups them.
        $sectionCounts = collect($data)->flatMap(fn ($row) => self::tipoLabels($row['tipo_registro'] ?? ''))->countBy()->toArray();

        $partyTotals = $this->partyTotalsWithLista($munId, $provinciaIds);
        if ($partido) {
            $partyTotals = array_map(fn ($list) => array_values(array_filter($list, fn ($p) => $p['partido'] === $partido)), $partyTotals);
        }

        return Inertia::render('MapaPolitico', [
            'data' => array_values($data),
            'municipios' => $municipios,
            'provincias' => $provincias,
            'municipioInfo' => $municipioInfo,
            'cargosDisponibles' => $cargosDisponibles,
            'cargosPorTipo' => $this->cargosPorTipo(),
            'cargoCategorias' => CargoCategory::active()->get(['id', 'name', 'display_order', 'is_highlighted']),
            'barrios' => $barrios,
            'sectionCounts' => $sectionCounts,
            'partyTotals' => $partyTotals,
            'filters' => array_merge(
                $request->only(['municipio', 'tipo', 'cargo', 'search', 'provincia', 'partido', 'barrio', 'destacado', 'profesion', 'estado']),
                $campana ? ['campana' => $campana] : [],
            ),
        ]);
    }

    private static function tipoLabels(string $tipos): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', $tipos)), fn ($t) => $t !== '')));
    }

    private static function mergeTipos(string ...$tipos): string
    {
        return implode(', ', self::tipoLabels(implode(',', $tipos)));
    }

    // The municipio a row belongs to: its municipality id, or its name (without accents) resolved to one.
    // Electoral rows and lideres of the same municipio match even when the lider wrote "Malaga" for "Málaga";
    // rows of no municipality (Senado/Cámara/Asamblea as "Santander") fall back to the plain name.
    // $municipios: municipality id => true, plus normalized name => id.
    private static function municipioKey(array $row, array $municipios): string
    {
        $geoId = $row['geographic_unit_id'] ?? null;
        if ($geoId && ($municipios[$geoId] ?? null) === true) {
            return $geoId;
        }
        $name = Str::lower(Str::ascii(trim((string) ($row['municipio'] ?? ''))));
        $id = $municipios[$name] ?? null;

        return is_string($id) ? $id : 'name:'.$name;
    }

    // The unified rows of the Mapa Político listing. The Excel/PDF export uses the same rows with $forExport,
    // which only drops the listing's presentation caps (200 lideres per section, elected-only without filters).
    public function rows(Request $request, bool $forExport = false): array
    {
        $munId = $request->input('municipio');
        $tipoRaw = $request->input('tipo', 'todos');
        $tipos = $tipoRaw ? explode(',', $tipoRaw) : ['todos'];
        $tipo = $tipoRaw; // keep for backward compat
        $tipoMatch = fn ($key) => in_array('todos', $tipos) || in_array($key, $tipos);
        $cargoRaw = $request->input('cargo');
        $cargos = $this->parseCargos($cargoRaw);
        $cargo = $cargoRaw;
        $search = $request->input('search');
        $partido = $request->input('partido');
        $barrioRaw = $request->input('barrio');
        $barrios_filter = $barrioRaw ? explode(',', $barrioRaw) : [];
        $barrio = $barrioRaw;
        $destacado = $request->input('destacado');
        $profesion = $request->input('profesion');
        $estado = $request->input('estado', 'activos');
        // Filtering by campaign shows only that campaign's lideres: shared electoral rows belong to no campaign.
        $campana = $this->campaign()->filterId($request->input('campana'));
        $electoral = $campana === null;

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

        // Only the provinces of the municipios the user can see ("Area Metropolitana" is a real province now).
        $provincias = collect($municipios)->pluck('provincia')->unique()->filter()->sort()->values()->toArray();

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
        if ($electoral && $tipoMatch('alcaldia')) {
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
                    'p.id', 'p.full_name as nombre', 'g.canonical_name as municipio', 'con.geographic_unit_id',
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
            if (! empty($cargos)) {
                $query->whereIn(DB::raw("CASE WHEN c.outcome = 'elected' THEN 'Alcalde Electo' ELSE 'Candidato Alcaldía' END"), $cargos);
            }
            if ($noGeoFilter && empty($cargos) && ! $forExport) {
                $query->where('c.outcome', 'elected');
            }

            $data = array_merge($data, $query->orderByDesc('res.votos')->get()->map(fn ($r) => (array) $r)->toArray());
        }

        // === CONCEJO ===
        $showConcejo = $electoral && ($tipoMatch('concejo')
            || (in_array('lideres', $tipos) && $cargo && stripos($cargo, 'CONCEJAL') !== false));
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
                    'p.id', 'p.full_name as nombre', 'g.canonical_name as municipio', 'con.geographic_unit_id',
                    'prov.canonical_name as provincia', 'po.canonical_name as partido',
                    'c.outcome', 'ce.endorsement_type as tipo_aval',
                    DB::raw('COALESCE(res.votos, 0) as votos'),
                    DB::raw("'Concejo' as tipo_registro"),
                    DB::raw("CASE WHEN c.outcome = 'elected' THEN 'Concejal' ELSE 'Candidato Concejo' END as cargo")
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
                $query->whereIn(DB::raw("CASE WHEN c.outcome = 'elected' THEN 'Concejal' ELSE 'Candidato Concejo' END"), $cargos);
            }
            if ($noGeoFilter && empty($cargos) && ! $forExport) {
                $query->where('c.outcome', 'elected');
            }

            $data = array_merge($data, $query->orderByDesc('res.votos')->get()->map(fn ($r) => (array) $r)->toArray());
        }

        $applyEstado = function ($query) use ($estado) {
            if ($estado === 'inactivos') {
                $query->where('is_active', false);
            } elseif ($estado !== 'todos') {
                $query->where('is_active', true);
            }
            return $query;
        };

        // === LIDERES ===
        if ($tipoMatch('lideres')) {
            $query = $this->visibleLideres();
            $applyEstado($query);
            if ($campana) {
                $query->where('campaign_id', $campana);
            }
            $query->where(function ($q) {
                    $q->where('tipo', '!=', 'nuevo_lider')->orWhereNull('tipo');
                })
                ->select(
                    'id', 'nombre', 'municipio', 'geographic_unit_id', 'provincia',
                    'partido', DB::raw('NULL as outcome'), DB::raw('NULL as tipo_aval'),
                    'votos', DB::raw("'Líderes' as tipo_registro"),
                    'cargo', 'telefono', 'email', 'observacion', 'barrio', 'direccion', 'zona', 'destacado', 'profesion', 'cedula', 'campaign_id'
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
                $this->whereCargoIn($query, $cargos);
            }
            if (! empty($barrios_filter)) {
                $query->where(function ($q) use ($barrios_filter) {
                    foreach ($barrios_filter as $b) {
                        $q->orWhere('barrio', 'ilike', '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($b)).'%');
                    }
                });
            }
            if ($noGeoFilter && empty($cargos) && ! $forExport) {
                $query->limit(200);
            }

            $data = array_merge($data, $query->orderBy('nombre')->get()->map(fn ($r) => (array) $r)->toArray());
        }

        // === NUEVOS LIDERES ===
        if ($tipoMatch('nuevos_lideres')) {
            $query = $this->visibleLideres();
            $applyEstado($query);
            if ($campana) {
                $query->where('campaign_id', $campana);
            }
            $query->where('tipo', 'nuevo_lider')
                ->select(
                    'id', 'nombre', 'municipio', 'geographic_unit_id', 'provincia',
                    'partido', DB::raw('NULL as outcome'), DB::raw('NULL as tipo_aval'),
                    'votos', DB::raw("'Nuevos Líderes' as tipo_registro"),
                    'cargo', 'telefono', 'email', 'observacion', 'barrio', 'direccion', 'zona', 'destacado', 'profesion', 'cedula', 'campaign_id'
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
                $this->whereCargoIn($query, $cargos);
            }
            if (! empty($barrios_filter)) {
                $query->where(function ($q) use ($barrios_filter) {
                    foreach ($barrios_filter as $b) {
                        $q->orWhere('barrio', 'ilike', '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($b)).'%');
                    }
                });
            }
            if ($noGeoFilter && empty($cargos) && ! $forExport) {
                $query->limit(200);
            }

            $data = array_merge($data, $query->orderBy('nombre')->get()->map(fn ($r) => (array) $r)->toArray());
        }

        // === DIRECTORIO MUNICIPAL ===
        if ($tipoMatch('directorio')) {
            $query = $this->visibleLideres();
            $applyEstado($query);
            if ($campana) {
                $query->where('campaign_id', $campana);
            }
            $this->whereCargoIn($query, ['Directorio Municipal']);
            $query->select(
                    'id', 'nombre', 'municipio', 'geographic_unit_id', 'provincia',
                    'partido', DB::raw('NULL as outcome'), DB::raw('NULL as tipo_aval'),
                    'votos', DB::raw("'Directorio Municipal' as tipo_registro"),
                    'cargo', 'telefono', 'email', 'observacion', 'barrio', 'direccion', 'zona', 'destacado', 'profesion', 'cedula', 'campaign_id'
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
                $this->whereCargoIn($query, $cargos);
            }
            if (! empty($barrios_filter)) {
                $query->where(function ($q) use ($barrios_filter) {
                    foreach ($barrios_filter as $b) {
                        $q->orWhere('barrio', 'ilike', '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($b)).'%');
                    }
                });
            }
            if ($noGeoFilter && empty($cargos) && ! $forExport) {
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
            if ($electoral && $tipoMatch($tipoKey)) {
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
                        'c.outcome', DB::raw('NULL as tipo_aval'),
                        DB::raw('res.votos')
                    )
                    ->selectRaw('? as tipo_registro', [$label])
                    ->selectRaw("CASE WHEN c.outcome = 'elected' THEN ? ELSE ? END as cargo", array_values(self::CORP_CARGOS[$info['corp']]));

                if ($searchEscaped) {
                    $query->where('p.full_name', 'ilike', "%{$searchEscaped}%");
                }
                [$electo, $candidato] = array_values(self::CORP_CARGOS[$info['corp']]);
                $this->whereOutcomeCargoIn($query, $electo, $candidato, $cargos);

                $rows = $query->orderByDesc('res.votos')->limit(30)->get()->map(fn ($r) => (array) $r)->toArray();
                if ($tipoKey === 'camara') {
                    foreach ($rows as &$row) {
                        $row['partido'] = self::CAMARA_LIST_BY_PARTY[$row['partido'] ?? ''] ?? $row['partido'];
                    }
                    unset($row);
                }
                $data = array_merge($data, $rows);
            }
        }

        // === FILTRO POR PARTIDO ===
        if ($partido) {
            $data = array_values(array_filter($data, fn ($row) => ($row['partido'] ?? '') === $partido));
        }

        // #6: Enrich non-líder rows — only load líderes for relevant municipios. Only the own campaign's lideres:
        // the electoral row unifies under the own campaign, a subcampaign's lider keeps its own row.
        $municipioIds = [];
        foreach (CachedQueries::allMunicipios() as $m) {
            $municipioIds[$m['id']] = true;
            $municipioIds[Str::lower(Str::ascii(trim($m['name'])))] = $m['id'];
        }
        $relevantMunicipios = array_unique(array_filter(array_column($data, 'municipio')));
        $relevantGeoIds = array_unique(array_filter(array_column($data, 'geographic_unit_id')));
        $lideresLookup = [];
        if (! empty($relevantMunicipios)) {
            $lideresAllQuery = $this->ownLideres();
            $applyEstado($lideresAllQuery);
            $lideresAll = $lideresAllQuery
                ->select('nombre', 'municipio', 'geographic_unit_id', 'telefono', 'email', 'barrio', 'direccion', 'zona', 'destacado', 'profesion', 'cedula', 'cargo as lider_cargo', 'campaign_id')
                ->where(fn ($q) => $q->whereIn('municipio', $relevantMunicipios)->orWhereIn('geographic_unit_id', $relevantGeoIds))
                ->get();
            foreach ($lideresAll as $l) {
                $lideresLookup[mb_strtoupper(trim($l->nombre)).'|'.self::municipioKey((array) $l, $municipioIds)] ??= $l;
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
            // A lider row (Líderes, Nuevos Líderes, Directorio Municipal) would only find itself in the lookup.
            if (array_key_exists('campaign_id', $row)) {
                continue;
            }

            $key = mb_strtoupper(trim($row['nombre'] ?? '')).'|'.self::municipioKey($row, $municipioIds);
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
                if ($liderCargo && $liderCargo !== 'Líder') {
                    $row['tipo_registro'] = self::mergeTipos($row['tipo_registro'] ?? '', 'Líderes');
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

        // Unify duplicates. Lideres of different campaigns never merge; a shared electoral row (no campaign)
        // merges with the own campaign's lider of the same name and municipio.
        $unified = [];
        $ownCampaignId = $this->campaign()->ownId() ?? '';
        // Two lider records with different cédulas are different people, even with the same name and municipio.
        $liderOfKey = [];
        foreach ($data as $row) {
            $key = mb_strtoupper(trim($row['nombre'] ?? '')).'|'.self::municipioKey($row, $municipioIds)
                .'|'.($row['campaign_id'] ?? $ownCampaignId);
            if (array_key_exists('campaign_id', $row) && ! empty($row['id'])) {
                $cedula = trim((string) ($row['cedula'] ?? ''));
                $first = $liderOfKey[$key] ?? null;
                if ($first && $first['id'] !== $row['id'] && $cedula !== '' && $first['cedula'] !== '' && $first['cedula'] !== $cedula) {
                    $key .= '|'.$row['id'];
                }
                $liderOfKey[$key] ??= ['id' => $row['id'], 'cedula' => $cedula];
            }
            if (isset($unified[$key])) {
                $unified[$key]['tipo_registro'] = self::mergeTipos($unified[$key]['tipo_registro'] ?? '', $row['tipo_registro'] ?? '');
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
                if (! empty($row['campaign_id']) && empty($unified[$key]['campaign_id'])) {
                    $unified[$key]['campaign_id'] = $row['campaign_id'];
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

        return compact('data', 'municipios', 'provincias', 'municipioInfo', 'munId', 'provinciaIds', 'partido', 'campana', 'applyEstado');
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
            // Reusing an electoral person's id would make authorizePersonOrLider treat it as this lider.
            'client_id' => 'nullable|uuid|unique:persons,id',
        ]);
        $data = Cedula::normalizeFields($data);
        $this->authorizeGeo($data['municipio_id']);

        // Offline creates carry the id generated on the device: a repeated send must not create a second lider.
        $clientId = $data['client_id'] ?? null;
        $respond = function (string $message) use ($request, $data) {
            if ($request->wantsJson()) {
                return response()->json(['success' => true, 'message' => $message]);
            }

            return redirect()->route('mapa-politico', [
                'municipio' => $data['municipio_id'],
                'tipo' => 'lideres',
                'search' => $data['nombre'],
            ])->with('success', $message);
        };
        $alreadyCreated = fn () => $clientId && $this->ownLideres()->where('id', $clientId)->exists();
        if ($alreadyCreated()) {
            return $respond('Este líder ya estaba registrado.');
        }

        // The lider goes to the user's own campaign, where its cédula has to be unique.
        $cedula = $data['cedula'] ?? null;
        if ($cedula !== null && $cedula !== '') {
            $existente = $this->ownLideres()->where('cedula', $cedula)->first();
            if ($existente) {
                return response()->json([
                    'success' => false,
                    'message' => $this->duplicateCedulaMessage($cedula, $existente),
                ], 422);
            }
        }

        $mun = DB::table('geographic_units as g')
            ->leftJoin('geographic_units as prov', 'g.parent_id', '=', 'prov.id')
            ->where('g.id', $data['municipio_id'])
            ->select('g.canonical_name', 'prov.canonical_name as provincia')
            ->first();

        $insertData = [
            'id' => $clientId ?? (string) Str::uuid(),
            'campaign_id' => $this->campaign()->ownId() ?? Campaign::DEFAULT_ID,
            'nombre' => $data['nombre'],
            'municipio' => $mun->canonical_name ?? '',
            'provincia' => $mun->provincia ?? '',
            'cargo' => $data['cargo'] ?? '',
            'tipo' => in_array('Nuevos Líderes', $data['tipos'] ?? []) ? 'nuevo_lider' : 'directorio',
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

        try {
            DB::table('lideres')->insert($insertData);
        } catch (UniqueConstraintViolationException $e) {
            // Two tabs sent the same offline create at once: the other request already stored it.
            if ($alreadyCreated()) {
                return $respond('Este líder ya estaba registrado.');
            }
            $existente = $cedula ? $this->ownLideres()->where('cedula', $cedula)->first() : null;
            if ($existente) {
                return response()->json(['success' => false, 'message' => $this->duplicateCedulaMessage($cedula, $existente)], 422);
            }
            throw $e;
        }

        Cache::forget('cargos_por_tipo');

        return $respond("Líder «{$data['nombre']}» creado.");
    }

    public function persona(string $id)
    {
        // #5: Validate UUID format
        if (! Str::isUuid($id)) {
            abort(404);
        }
        $this->authorizePersonOrLider($id);

        $person = DB::table('persons')->where('id', $id)->first();
        $source = 'persona';
        $liderModel = null;

        // A candidate who already has a lider record in their municipio opens as that lider, so the
        // panel shows and saves the lider fields (historial politico, contactos, datos personales).
        if ($person && ($linkedLiderId = $this->linkedLiderId($person))) {
            return $this->persona($linkedLiderId);
        }

        if (! $person) {
            $liderModel = Lider::with(['contactos', 'infoPolitica'])->find($id);
            $person = $liderModel;
            $source = 'lider';
        }

        abort_if(! $person, 404);

        $nexoOwners = [$id];
        $candidatePersonId = null;
        if ($source === 'lider') {
            $linkedPersons = app(UpdateVotante::class)->linkedPersonIds($person);
            $nexoOwners = array_merge($nexoOwners, $linkedPersons->all());
            $candidatePersonId = $linkedPersons->count() === 1 ? $linkedPersons->first() : null;
        }
        $nexos = $this->campaign()->scope(DB::table('nexos_familiares'))
            ->whereIn('person_id', $nexoOwners)
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
            $personMunicipio = $this->scopeToTerritory(DB::table('candidacies as c'), 'con.geographic_unit_id')
                ->join('contests as con', 'c.contest_id', '=', 'con.id')
                ->join('geographic_units as g', 'con.geographic_unit_id', '=', 'g.id')
                ->where('c.person_id', $id)
                ->value('g.canonical_name');

            $lideres = fn () => $this->ownFirst($this->scopeToTerritory($this->visibleLideres()));
            if ($personMunicipio) {
                $liderRows = $lideres()
                    ->where('nombre', 'ilike', $person->full_name)
                    ->where('municipio', 'ilike', $personMunicipio)
                    ->get();
            }
            if ($liderRows->isEmpty()) {
                $liderRows = $lideres()->where('nombre', 'ilike', $person->full_name)->get();
            }
            if ($liderRows->isEmpty() && $personMunicipio) {
                $nameParts = explode(' ', $person->full_name);
                $query = $lideres()->where('municipio', 'ilike', $personMunicipio);
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
            $partido = $this->candidatePartido($id);
        } elseif ($source === 'lider') {
            $partido = $person->partido ?? null;
        }

        $partidos = Cache::remember('all_partidos', 3600, fn () => DB::table('political_organizations')->orderBy('canonical_name')->pluck('canonical_name')->toArray()
        );

        $response = [
            'id' => $id,
            'source' => $source,
            // Only a lider belongs to a campaign; shared electoral persons have none.
            'campaign_id' => $source === 'lider' ? $person->campaign_id : null,
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
            'tipo_registro' => $source === 'lider'
                ? (($person->tipo ?? '') === 'nuevo_lider' ? 'Nuevos Líderes' : 'Líderes')
                : null,
            'votos' => $source === 'lider'
                ? (int) ($bestLider->votos ?? 0)
                : $this->candidateVotos($id),
            'nexos' => $nexos,
            // Read-only: the candidacy this lider is linked to. Never copied into the editable partido/votos.
            'partido_candidatura' => $candidatePersonId ? $this->candidatePartido($candidatePersonId) : null,
            'votos_candidatura' => $candidatePersonId ? $this->candidateVotos($candidatePersonId) : null,
        ];

        $liderId = $source === 'lider' ? $id : ($bestLider->id ?? null);
        if ($liderId) {
            $response['municipios_influencia'] = DB::table('lider_municipios_influencia')
                ->where('lider_id', $liderId)
                ->orderBy('municipio_name')
                ->get()
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'geographic_unit_id' => $r->geographic_unit_id,
                    'municipio_name' => $r->municipio_name,
                    'provincia_name' => $r->provincia_name,
                    'nota' => $r->nota,
                ])->toArray();
        } else {
            $response['municipios_influencia'] = [];
        }

        // Include extended lider fields when source is lider
        if ($source === 'lider' && $liderModel) {
            $response['is_active'] = $liderModel->is_active ?? true;
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
                $referidos = $this->scopeToTerritory(Lider::where('referente_documento', $liderModel->cedula))
                    ->where('campaign_id', $liderModel->campaign_id)
                    ->where('is_active', true)
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
        $this->authorizePersonOrLider($id, true);
        // Electoral persons and their endorsements are shared by every campaign: only the platform edits
        // them. Any other campaign only updates (or creates) its own lider for that person.
        $canEditElectoral = $this->campaign()->canEditElectoral();

        $data = $request->validate([
            'nombre' => 'sometimes|required|string|max:255',
            'telefono' => 'nullable|string|max:20',
            'email' => 'nullable|string|max:255',
            'cargo' => 'nullable|string|max:100',
            'tipos' => 'nullable|array',
            'partido' => 'nullable|string|max:255',
            'observacion' => 'nullable|string|max:1000',
            'municipio' => 'nullable|string|max:100',
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
        $data = Cedula::normalizeFields(UpdateVotante::withoutNulls($data));

        $cedula = $data['cedula'] ?? null;
        if ($cedula !== null && $cedula !== '') {
            $currentLider = DB::table('lideres')->where('id', $id)->first();
            $currentCedula = $currentLider?->cedula;

            if (! $currentLider) {
                $person = DB::table('persons')->where('id', $id)->first();
                if ($person) {
                    $linkedLider = $this->scopeToTerritory($this->ownLideres())
                        ->where('nombre', 'ilike', $person->full_name)
                        ->first();
                    $currentCedula = $linkedLider?->cedula;
                    $currentLider = $linkedLider;
                }
            }

            // The form resends the cédula on every save; an unchanged one (even if stored with dots) is left as is.
            if ($cedula === Cedula::normalize($currentCedula ?? '')) {
                unset($data['cedula']);
            } else {
                $excludeIds = [$id];
                if ($currentLider && $currentLider->id !== $id) {
                    $excludeIds[] = $currentLider->id;
                }
                $existente = $this->ownLideres()
                    ->where('cedula', $cedula)
                    ->whereNotIn('id', $excludeIds)
                    ->first();
                if ($existente) {
                    $response = ['success' => false, 'message' => $this->duplicateCedulaMessage($cedula, $existente)];
                    // Same person entered twice: the panel offers to merge it into the record being edited.
                    $allowed = $this->allowedGeoIds();
                    if ($currentLider && $currentLider->id === $id && ($allowed === null || in_array($existente->geographic_unit_id, $allowed, true))) {
                        $response['duplicado'] = [
                            'id' => $existente->id,
                            'nombre' => $existente->nombre,
                            'municipio' => $existente->municipio,
                            'cargo' => $existente->cargo,
                            'telefono' => $existente->telefono,
                        ];
                    }

                    return response()->json($response, 422);
                }
            }
        }

        // #15: Wrap in transaction to prevent race conditions
        $nuevoNombre = isset($data['nombre']) ? UpdateVotante::normalizeName($data['nombre']) : null;
        if ($nuevoNombre !== null) {
            $actual = DB::table('lideres')->where('id', $id)->value('nombre')
                ?? DB::table('persons')->where('id', $id)->value('full_name');
            if ($actual !== null && $nuevoNombre === UpdateVotante::normalizeName($actual)) {
                $nuevoNombre = null;
            }
        }

        DB::transaction(function () use ($id, $data, $nuevoNombre, $canEditElectoral) {
            $lider = DB::table('lideres')->where('id', $id)->first();
            // A namesake found only by the global fallback may be another person: never rename it.
            $liderVinculado = (bool) $lider;
            $personaARenombrar = null;

            if ($lider && $nuevoNombre && $lider->geographic_unit_id && $canEditElectoral) {
                // Merged rows keep the lider id: rename the electoral person of the same municipio too.
                $candidatos = app(UpdateVotante::class)->linkedPersonIds($lider);
                if ($candidatos->count() === 1) {
                    $personaARenombrar = $candidatos->first();
                }
            }

            if (! $lider) {
                $person = DB::table('persons')->where('id', $id)->first();
                if ($person) {
                    $personaARenombrar = $canEditElectoral ? $person->id : null;
                    $personMunicipio = $this->scopeToTerritory(DB::table('candidacies as c'), 'con.geographic_unit_id')
                        ->join('contests as con', 'c.contest_id', '=', 'con.id')
                        ->join('geographic_units as g', 'con.geographic_unit_id', '=', 'g.id')
                        ->where('c.person_id', $id)
                        ->select('g.canonical_name', 'g.id as geo_id')
                        ->first();

                    $munName = $personMunicipio->canonical_name ?? '';
                    $geoId = $personMunicipio->geo_id ?? null;

                    if ($munName) {
                        $lider = $this->scopeToTerritory($this->ownLideres())
                            ->where('nombre', 'ilike', $person->full_name)
                            ->where('municipio', 'ilike', $munName)
                            ->first();
                        $liderVinculado = (bool) $lider;
                    }
                    if (! $lider) {
                        $lider = $this->scopeToTerritory($this->ownLideres())->where('nombre', 'ilike', $person->full_name)->first();
                    }

                    // #14: Create lider only when user explicitly edits — with correct municipio
                    if (! $lider) {
                        $this->authorizeGeo($geoId);
                        $provName = $geoId ? DB::table('geographic_units as g')
                            ->join('geographic_units as prov', 'g.parent_id', '=', 'prov.id')
                            ->where('g.id', $geoId)->value('prov.canonical_name') : '';

                        DB::table('lideres')->insert([
                            'id' => Str::uuid(),
                            'campaign_id' => $this->campaign()->ownId() ?? Campaign::DEFAULT_ID,
                            'nombre' => $nuevoNombre ?? $person->full_name,
                            'municipio' => $munName,
                            'provincia' => $provName ?? '',
                            'geographic_unit_id' => $geoId,
                            'cargo' => $data['cargo'] ?? '',
                            'partido' => $data['partido'] ?? null,
                            'tipo' => in_array('Nuevos Líderes', $data['tipos'] ?? []) ? 'nuevo_lider' : 'directorio',
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
                    'municipio' => array_key_exists('municipio', $data) ? $data['municipio'] : $lider->municipio,
                    'destacado' => array_key_exists('destacado', $data) ? (bool) $data['destacado'] : ($lider->destacado ?? false),
                    'profesion' => array_key_exists('profesion', $data) ? $data['profesion'] : $lider->profesion,
                    'cedula' => array_key_exists('cedula', $data) ? $data['cedula'] : $lider->cedula,
                    'votos' => array_key_exists('votos', $data) ? (int) ($data['votos'] ?? 0) : ($lider->votos ?? 0),
                    'updated_at' => now(),
                ];
                if ($partidoSent) {
                    $updateData['partido'] = $partidoValue;
                }
                if ($nuevoNombre && $liderVinculado) {
                    $updateData['nombre'] = $nuevoNombre;
                }
                if (array_key_exists('municipio', $data) && $data['municipio'] && $data['municipio'] !== ($lider->municipio ?? '')) {
                    $geo = DB::table('geographic_units')
                        ->where('type', 'municipality')
                        ->where('canonical_name', $data['municipio'])
                        ->first();
                    if ($geo) {
                        $this->authorizeGeo($geo->id);
                        $updateData['geographic_unit_id'] = $geo->id;
                        $provName = DB::table('geographic_units')->where('id', $geo->parent_id)->value('canonical_name');
                        $updateData['provincia'] = $provName ?? '';
                    }
                }
                if (array_key_exists('tipos', $data)) {
                    $updateData['tipo'] = in_array('Nuevos Líderes', $data['tipos'] ?? []) ? 'nuevo_lider' : 'directorio';
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

                $this->authorizeGeo($lider->geographic_unit_id);
                DB::table('lideres')->where('id', $lider->id)->update($updateData);
            }

            if ($nuevoNombre && $personaARenombrar) {
                $this->renamePerson($personaARenombrar, $nuevoNombre);
            }

            // Update partido via candidacy endorsement
            // Only when editing the electoral person itself: from a lider, partido is the current affiliation
            // (lideres.partido) and must never rewrite the party a past candidacy ran with.
            $candidacyId = $canEditElectoral ? DB::table('candidacies')->where('person_id', $id)->value('id') : null;
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

    public function storeNexo(Request $request, string $personId, AddNexo $addNexo)
    {
        if (! Str::isUuid($personId)) {
            abort(404);
        }

        $addNexo->authorizeFor($personId);

        $data = $request->validate([
            'nombre' => 'required|string|max:255',
            'parentesco' => 'nullable|string|max:50',
            'cargo' => 'nullable|string|max:100',
            'edad' => 'nullable|integer|min:0|max:120',
            'gustos' => 'nullable|string|max:500',
            'observaciones' => 'nullable|string|max:500',
            'cedula' => 'nullable|string|max:20',
            'telefono' => 'nullable|string|max:20',
            'client_id' => 'nullable|uuid',
        ]);

        $addNexo->handle($personId, $data);

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
        $this->authorizeNexoWrite($id);

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
        $this->authorizeNexoWrite($id);

        DB::table('nexos_familiares')->where('id', $id)->delete();

        return response()->json(['success' => true, 'message' => 'Nexo eliminado.']);
    }

    // A nexo belongs to the campaign that wrote it, even when its owner is a shared electoral person.
    private function authorizeNexoWrite(string $id): void
    {
        $nexo = DB::table('nexos_familiares')->where('id', $id)->first(['person_id', 'campaign_id']);
        abort_if(! $nexo, 404);
        $this->authorizeCampaign($nexo->campaign_id, true);
        $this->authorizePersonOrLider($nexo->person_id);
    }

    public function destroyLider(Request $request, string $id)
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }
        $this->authorizePersonOrLider($id, true);

        $affected = DB::table('lideres')->where('id', $id)->update(['is_active' => false, 'updated_at' => now()]);

        if ($affected === 0) {
            $person = DB::table('persons')->where('id', $id)->first();
            if ($person) {
                $this->scopeToTerritory($this->ownLideres()->where('nombre', 'ilike', $this->escapeLike($person->full_name)))
                    ->update(['is_active' => false, 'updated_at' => now()]);
            }
        }

        return response()->json(['success' => true, 'message' => 'Persona desactivada.']);
    }

    public function toggleLider(string $id)
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $lider = DB::table('lideres')->where('id', $id)->first();
        if (! $lider) {
            abort(404);
        }
        $this->authorizeLiderRow($lider, true);

        $newState = ! $lider->is_active;
        DB::table('lideres')->where('id', $id)->update(['is_active' => $newState, 'updated_at' => now()]);

        return response()->json([
            'success' => true,
            'is_active' => $newState,
            'message' => $newState ? 'Persona activada.' : 'Persona desactivada.',
        ]);
    }

    public function searchLideres(Request $request)
    {
        $q = trim($request->input('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['data' => []]);
        }

        $excludeId = $request->input('exclude_id');

        // Only used to pick referidos, which must belong to the user's own campaign.
        $qe = $this->escapeLike($q);
        $query = $this->scopeToTerritory($this->ownLideres())
            ->where('is_active', true)
            ->where(function ($qb) use ($qe) {
                $qb->where('nombre', 'ilike', "%{$qe}%")
                    ->orWhere('cedula', 'like', "%{$qe}%");
            })
            ->select('id', 'nombre', 'cedula', 'municipio', 'telefono')
            ->orderBy('nombre')
            ->limit(10);

        if ($excludeId && Str::isUuid($excludeId)) {
            $query->where('id', '!=', $excludeId);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function posiblesDuplicados(string $id, FindPossibleDuplicates $find)
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        return response()->json(['data' => $find->handle($id)]);
    }

    // Folds a duplicate lider (same person entered twice) into the one being edited.
    public function mergeLider(Request $request, string $id, MergeLideres $merge)
    {
        $data = $request->validate([
            'duplicado_id' => 'required|uuid',
            // The cédula typed in the form; it may correct the one the edited record had.
            'cedula' => 'nullable|string|max:20',
        ]);
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $lider = $merge->handle($id, $data['duplicado_id'], Cedula::normalize($data['cedula'] ?? null));

        return response()->json(['success' => true, 'message' => 'Registros unificados.', 'lider' => $lider]);
    }

    public function addReferido(Request $request, string $id, AddReferido $addReferido)
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $data = $request->validate([
            'lider_id' => 'required|uuid',
        ]);

        $addReferido->handle($id, $data['lider_id']);

        return response()->json(['success' => true, 'message' => 'Referido agregado.']);
    }

    public function removeReferido(Request $request, string $id, string $referidoId)
    {
        if (! Str::isUuid($id) || ! Str::isUuid($referidoId)) {
            abort(404);
        }
        $referente = DB::table('lideres')->where('id', $id)->first(['cedula', 'geographic_unit_id', 'campaign_id']);
        $referido = DB::table('lideres')->where('id', $referidoId)->first(['referente_documento', 'geographic_unit_id', 'campaign_id']);
        abort_if(! $referente || ! $referido, 404);
        $this->authorizeLiderRow($referente, true);
        $this->authorizeLiderRow($referido, true);
        abort_if(! $referente->cedula || $referido->referente_documento !== $referente->cedula
            || $referido->campaign_id !== $referente->campaign_id, 404);

        DB::table('lideres')->where('id', $referidoId)->update([
            'referente_documento' => null,
            'referente_nombre' => null,
            'referente_apellido' => null,
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Referido eliminado.']);
    }

    public function storeMunicipioInfluencia(Request $request, string $id)
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $lider = DB::table('lideres')->where('id', $id)->first();
        abort_if(! $lider, 404);
        $this->authorizeLiderRow($lider, true);

        $data = $request->validate([
            'geographic_unit_id' => 'required|uuid|exists:geographic_units,id',
            'nota' => 'nullable|string|max:500',
        ]);

        $existing = DB::table('lider_municipios_influencia')
            ->where('lider_id', $id)
            ->where('geographic_unit_id', $data['geographic_unit_id'])
            ->exists();

        if ($existing) {
            return response()->json(['success' => false, 'message' => 'Este municipio ya está agregado.'], 422);
        }

        $geo = DB::table('geographic_units')->where('id', $data['geographic_unit_id'])->first();
        $provName = $geo->parent_id ? DB::table('geographic_units')->where('id', $geo->parent_id)->value('canonical_name') : null;

        DB::table('lider_municipios_influencia')->insert([
            'id' => Str::uuid(),
            'lider_id' => $id,
            'geographic_unit_id' => $data['geographic_unit_id'],
            'municipio_name' => $geo->canonical_name,
            'provincia_name' => $provName,
            'nota' => $data['nota'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $influencia = DB::table('lider_municipios_influencia')
            ->where('lider_id', $id)
            ->orderBy('municipio_name')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'geographic_unit_id' => $r->geographic_unit_id,
                'municipio_name' => $r->municipio_name,
                'provincia_name' => $r->provincia_name,
                'nota' => $r->nota,
            ])->toArray();

        return response()->json(['success' => true, 'message' => 'Municipio de influencia agregado.', 'municipios_influencia' => $influencia]);
    }

    public function destroyMunicipioInfluencia(string $id, string $influenciaId)
    {
        if (! Str::isUuid($id) || ! Str::isUuid($influenciaId)) {
            abort(404);
        }
        $this->authorizeLiderWrite($id);

        $deleted = DB::table('lider_municipios_influencia')
            ->where('id', $influenciaId)
            ->where('lider_id', $id)
            ->delete();

        abort_if(! $deleted, 404);

        return response()->json(['success' => true, 'message' => 'Municipio de influencia eliminado.']);
    }

    // Asamblea party rows are list-only votes; Senado/Cámara party rows are "TOTAL PARTIDO" (list + candidates).
    // Senado only has some candidates imported per municipio, so its list share cannot be derived.
    private function partyTotalsWithLista(?string $munId, ?array $provinciaIds): array
    {
        $partyRowIsTotal = ['Senado' => true, 'Cámara de Representantes' => true, 'Asamblea' => false];
        $listaDerivable = ['Senado' => false, 'Cámara de Representantes' => true, 'Asamblea' => true];
        $corps = array_keys($partyRowIsTotal);

        $scope = function ($q) use ($munId, $provinciaIds) {
            if ($munId) {
                $q->where('er.geographic_unit_id', $munId);
            } elseif ($provinciaIds) {
                $q->whereIn('er.geographic_unit_id', $provinciaIds);
            }

            return $q;
        };

        $partyRows = $scope(DB::table('electoral_results as er')
            ->join('contests as con', 'er.contest_id', '=', 'con.id')
            ->join('corporations as corp', 'con.corporation_id', '=', 'corp.id')
            ->join('political_organizations as po', 'er.organization_id', '=', 'po.id')
            ->whereNull('er.candidacy_id')
            ->whereIn('corp.name', $corps))
            ->groupBy('corp.name', 'po.canonical_name')
            ->select('corp.name as corp', 'po.canonical_name as partido', DB::raw('SUM(er.value) as votos'))
            ->get();

        $candidateRows = $scope(DB::table('electoral_results as er')
            ->join('candidacies as ca', 'er.candidacy_id', '=', 'ca.id')
            ->join('contests as con', 'ca.contest_id', '=', 'con.id')
            ->join('corporations as corp', 'con.corporation_id', '=', 'corp.id')
            ->join('candidacy_endorsements as ce', fn ($j) => $j->on('ce.candidacy_id', '=', 'ca.id')->where('ce.is_primary', true))
            ->join('political_organizations as po', 'ce.organization_id', '=', 'po.id')
            ->whereIn('er.metric_type', ['votes', 'nominal_votes'])
            ->whereIn('corp.name', $corps))
            ->groupBy('corp.name', 'po.canonical_name')
            ->select('corp.name as corp', 'po.canonical_name as partido', DB::raw('SUM(er.value) as votos'))
            ->get();

        $acc = [];
        foreach ($partyRows as $r) {
            $acc[$r->corp][$r->partido]['party'] = (int) $r->votos;
        }
        foreach ($candidateRows as $r) {
            $partido = $r->corp === 'Cámara de Representantes' ? (self::CAMARA_LIST_BY_PARTY[$r->partido] ?? $r->partido) : $r->partido;
            $acc[$r->corp][$partido]['cand'] = ($acc[$r->corp][$partido]['cand'] ?? 0) + (int) $r->votos;
        }

        $result = [];
        foreach ($acc as $corp => $parties) {
            $list = [];
            foreach ($parties as $partido => $v) {
                $party = $v['party'] ?? 0;
                $cand = $v['cand'] ?? 0;
                if ($partyRowIsTotal[$corp]) {
                    // A party row below its candidates' votes is bad source data; trust the candidates then.
                    $total = max($party, $cand);
                    $lista = ($listaDerivable[$corp] && $cand > 0 && $party >= $cand) ? $party - $cand : 0;
                } else {
                    $total = $party + $cand;
                    $lista = $party;
                }
                if ($total <= 0) {
                    continue;
                }
                $list[] = ['partido' => $partido, 'votos' => $total, 'lista' => $lista, 'candidatos' => $cand];
            }
            usort($list, fn ($a, $b) => $b['votos'] <=> $a['votos']);
            $result[$corp] = $list;
        }

        return $result;
    }
}
