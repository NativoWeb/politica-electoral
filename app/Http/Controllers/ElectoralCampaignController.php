<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Requests\CampanasElectorales\ElectoralCampaignRequest;
use App\Http\Requests\CampanasElectorales\MetasRequest;
use App\Models\AuditLog;
use App\Models\ElectoralCampaign;
use App\Models\ElectoralCampaignTerritory;
use App\Services\CampanasElectorales\DetalleCampana;
use App\Services\CampanasElectorales\ElectoralCampaignAccess;
use App\Services\CampanasElectorales\MetasInvalidas;
use App\Services\CampanasElectorales\MetasService;
use App\Services\CampanasElectorales\PlantillaMetas;
use App\Services\Import\TextNormalizer;
use App\Support\CurrentCampaign;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Campañas electorales (docs/campanas-electorales.md), behind `feature:campanas`. Who sees and who changes a campaign
// is decided by ElectoralCampaignAccess; the routes only check view/manage.campanas.
class ElectoralCampaignController extends Controller
{
    public const DUPLICADA = 'Ya tienes una campaña activa con ese nombre.';

    private const ACCIONES = [
        'electoral_campaign.created' => 'Campaña creada',
        'electoral_campaign.updated' => 'Campaña editada',
        'electoral_campaign.archived' => 'Campaña archivada',
        'electoral_campaign.reactivated' => 'Campaña reactivada',
        'electoral_campaign.goals.updated' => 'Metas guardadas',
        'electoral_campaign.goals.imported' => 'Metas cargadas desde Excel',
    ];

    private const CAMPOS = [
        'name' => 'Nombre', 'cargo' => 'Cargo', 'election_year' => 'Año', 'election_date' => 'Fecha de la elección',
        'candidate_person_id' => 'Candidato', 'candidate_name' => 'Candidato', 'political_organization_id' => 'Partido',
        'scope_level' => 'Alcance', 'territorio' => 'Territorio', 'factor_votos' => 'Factor de votos',
        'factor_registro' => 'Factor de registro', 'votos_por_lider' => 'Votos por líder',
        'votos_meta' => 'Votos meta', 'lideres_meta' => 'Líderes meta', 'votantes_meta' => 'Votantes meta',
    ];

    public function __construct(
        private readonly ElectoralCampaignAccess $access,
        private readonly DetalleCampana $detalle,
    ) {}

    private function campaign(): CurrentCampaign
    {
        return $this->access->campaign();
    }

    private static function wantsData(Request $request): bool
    {
        return $request->wantsJson() && ! $request->header('X-Inertia');
    }

    // GET /campanas-electorales
    public function index(Request $request): Response|JsonResponse
    {
        $archivadas = $request->boolean('archivadas');
        $max = (int) config('campanas_electorales.listado_max');
        $campanas = $this->access->query()
            ->with(['grupo:id,name', 'candidate:id,full_name', 'politicalOrganization:id,canonical_name'])
            ->where('status', $archivadas ? ElectoralCampaign::ARCHIVED : ElectoralCampaign::ACTIVE)
            ->orderByDesc('election_year')->orderBy('name')->orderBy('id')
            ->limit($max + 1)
            ->get()
            ->filter(fn (ElectoralCampaign $c) => $this->access->crossesTerritory($c));

        $data = [
            'campanas' => $this->detalle->tarjetas($campanas->take($max)),
            'filtros' => ['archivadas' => $archivadas],
            'can' => ['manage' => $this->access->canManage() && $this->access->allowedMunicipios() === null],
            'createBlocked' => $this->campaign()->createBlockedMessage(),
            'truncated' => $campanas->count() > $max,
        ];

        return self::wantsData($request)
            ? response()->json(['success' => true, 'data' => $data])
            : Inertia::render('CampanasElectorales/Lista', $data);
    }

    // GET /campanas-electorales/nueva
    public function create(): Response
    {
        $this->denyTerritoryUser();

        return Inertia::render('CampanasElectorales/Formulario', [
            'campana' => null,
            'opciones' => $this->detalle->opciones(),
            'puedeBuscarCandidatos' => $this->campaign()->seesElectoralResults(),
            'createBlocked' => $this->campaign()->createBlockedMessage(),
        ]);
    }

    // POST /campanas-electorales
    public function store(ElectoralCampaignRequest $request): JsonResponse
    {
        $this->denyTerritoryUser();
        $own = $this->campaign()->ownId();
        abort_if($own === null, 403);
        [$data, $territorio] = $request->datos($this->access, $own);
        $user = $request->user();

        try {
            $c = DB::transaction(function () use ($data, $territorio, $own, $user) {
                $c = new ElectoralCampaign($data);
                $c->forceFill(['campaign_id' => $own, 'status' => ElectoralCampaign::ACTIVE, 'created_by' => $user->id, 'updated_by' => $user->id])->save();
                $this->syncTerritorio($c, $territorio);
                $this->audit('electoral_campaign.created', $c, ['cargo' => $c->cargo, 'election_year' => $c->election_year,
                    'scope_level' => $c->scope_level, 'territorio' => $territorio]);

                return $c;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => self::DUPLICADA]);
        }

        return response()->json(['success' => true, 'message' => 'Campaña creada.', 'data' => ['campana' => $this->detalle->tarjeta($c->fresh())]], 201);
    }

    // GET /campanas-electorales/{id}
    public function show(Request $request, string $id): Response|JsonResponse
    {
        $data = $this->detalle->detalle($this->access->find($id));

        return self::wantsData($request)
            ? response()->json(['success' => true, 'data' => $data])
            : Inertia::render('CampanasElectorales/Detalle', $data);
    }

    // GET /campanas-electorales/{id}/editar
    public function edit(string $id): Response|RedirectResponse
    {
        $c = $this->access->find($id, true);
        if (! $c->isActive()) {
            return redirect()->route('campanas-electorales.show', $c->id)->with('error', ElectoralCampaignAccess::ARCHIVADA);
        }

        return Inertia::render('CampanasElectorales/Formulario', [
            'campana' => $this->detalle->formulario($c),
            'opciones' => $this->detalle->opciones($c->political_organization_id),
            'puedeBuscarCandidatos' => $this->campaign()->seesElectoralResults(),
            'createBlocked' => null,
        ]);
    }

    // PUT /campanas-electorales/{id}
    public function update(ElectoralCampaignRequest $request, string $id): JsonResponse
    {
        $c = $request->campana;
        $this->access->ensureActive($c);
        [$data, $territorio] = $request->datos($this->access, $c->campaign_id);

        $filas = array_column($this->access->territorio()->filas($data['scope_level'], $territorio), 'id');
        $fuera = $c->goals()->whereNotIn('geographic_unit_id', $filas ?: [''])->exists();
        if ($fuera) {
            throw ValidationException::withMessages(['territorio' => 'Hay metas en territorios que quedarían fuera de la campaña: bórralas antes de cambiar el territorio.']);
        }

        $antesTerritorio = $this->access->territoryIds($c);
        try {
            DB::transaction(function () use ($c, $data, $territorio, $antesTerritorio, $request) {
                $original = $c->only(array_keys($data));
                $c->fill($data)->forceFill(['updated_by' => $request->user()->id]);
                $cambios = [];
                foreach ($c->getDirty() as $campo => $valor) {
                    if ($campo !== 'updated_by') {
                        $cambios[] = ['campo' => $campo, 'antes' => self::plano($original[$campo] ?? null), 'despues' => self::plano($c->{$campo})];
                    }
                }
                $c->save();
                $nuevo = array_values($territorio);
                if (array_diff($nuevo, $antesTerritorio) || array_diff($antesTerritorio, $nuevo)) {
                    $this->syncTerritorio($c, $nuevo);
                    $cambios[] = ['campo' => 'territorio', 'antes' => $antesTerritorio, 'despues' => $nuevo];
                }
                if ($cambios) {
                    $this->audit('electoral_campaign.updated', $c, ['cambios' => $cambios],
                        array_column($cambios, 'antes', 'campo'), array_column($cambios, 'despues', 'campo'));
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => self::DUPLICADA]);
        }

        return response()->json(['success' => true, 'message' => 'Cambios guardados.', 'data' => ['campana' => $this->detalle->tarjeta($c->fresh())]]);
    }

    // POST /campanas-electorales/{id}/archivar
    public function archive(Request $request, string $id): JsonResponse
    {
        $c = $this->access->find($id, true);
        if (! $c->isActive()) {
            throw new BusinessRuleException('La campaña ya está archivada.');
        }
        DB::transaction(function () use ($c, $request) {
            $c->forceFill(['status' => ElectoralCampaign::ARCHIVED, 'archived_at' => now(), 'updated_by' => $request->user()->id])->save();
            $this->audit('electoral_campaign.archived', $c, [], ['status' => ElectoralCampaign::ACTIVE], ['status' => ElectoralCampaign::ARCHIVED]);
        });

        return response()->json(['success' => true, 'message' => 'Campaña archivada.', 'data' => ['campana' => $this->detalle->tarjeta($c->fresh())]]);
    }

    // POST /campanas-electorales/{id}/reactivar
    public function reactivate(Request $request, string $id): JsonResponse
    {
        $c = $this->access->find($id, true);
        if ($c->isActive()) {
            throw new BusinessRuleException('La campaña ya está activa.');
        }
        $duplicada = ElectoralCampaign::query()->where('campaign_id', $c->campaign_id)->where('status', ElectoralCampaign::ACTIVE)
            ->whereRaw('lower(name) = ?', [mb_strtolower($c->name)])->exists();
        $mensaje = 'Ya tienes una campaña activa con ese nombre: cambia el nombre de una de las dos para reactivarla.';
        if ($duplicada) {
            throw new BusinessRuleException($mensaje);
        }
        try {
            DB::transaction(function () use ($c, $request) {
                $c->forceFill(['status' => ElectoralCampaign::ACTIVE, 'archived_at' => null, 'updated_by' => $request->user()->id])->save();
                $this->audit('electoral_campaign.reactivated', $c, [], ['status' => ElectoralCampaign::ARCHIVED], ['status' => ElectoralCampaign::ACTIVE]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleException($mensaje);
        }

        return response()->json(['success' => true, 'message' => 'Campaña reactivada.', 'data' => ['campana' => $this->detalle->tarjeta($c->fresh())]]);
    }

    // PUT /campanas-electorales/{id}/metas
    public function updateGoals(MetasRequest $request, MetasService $metas, string $id): JsonResponse
    {
        $c = $request->campana;
        try {
            $metas->guardar($c, $request->items(), $request->user(), 'electoral_campaign.goals.updated');
        } catch (MetasInvalidas $e) {
            $errores = [];
            foreach ($e->errores as $err) {
                $key = $err['indice'] === null ? 'metas' : ($err['campo'] ? "metas.{$err['indice']}.{$err['campo']}" : "metas.{$err['indice']}");
                $errores[$key][] = $err['mensaje'];
            }
            if (isset($errores['metas']) && count($errores) > 1) {
                $errores['metas'] = array_values(array_unique($errores['metas']));
            }
            throw ValidationException::withMessages($errores);
        }

        return response()->json(['success' => true, 'message' => 'Metas guardadas', 'data' => $this->detalle->detalle($c->fresh())]);
    }

    // GET /campanas-electorales/{id}/metas/plantilla
    public function template(string $id, PlantillaMetas $plantilla): StreamedResponse
    {
        $c = $this->access->find($id);
        if (! $this->access->canWriteGroup($c)) {
            $this->access->authorizeWrite($c);
        }
        $this->audit('electoral_campaign.template_downloaded', $c, []);

        return $plantilla->descargar($c, $this->detalle->detalle($c));
    }

    // POST /campanas-electorales/{id}/metas/importar
    public function import(Request $request, string $id, PlantillaMetas $plantilla, MetasService $metas): JsonResponse
    {
        $c = $this->access->find($id, true);
        $this->access->ensureActive($c);
        $maxKb = (int) config('campanas_electorales.importacion.max_kb');
        $request->validate(['archivo' => ['required', 'file', "max:{$maxKb}", 'extensions:xlsx']], [
            'archivo.uploaded' => 'El archivo pesa más de '.intdiv($maxKb, 1024).' MB.',
            'archivo.required' => 'Elige el archivo de Excel.',
            'archivo.file' => 'Elige el archivo de Excel.',
            'archivo.max' => 'El archivo pesa más de '.intdiv($maxKb, 1024).' MB.',
            'archivo.extensions' => 'Sube el archivo .xlsx de la plantilla.',
        ], ['archivo' => 'archivo de Excel']);
        $filas = $this->access->territorio()->filas($c->scope_level, $this->access->territoryIds($c));

        try {
            $leido = $plantilla->leer($request->file('archivo')->getRealPath(), $filas);
            if ($leido['items'] === []) {
                throw new MetasInvalidas([['indice' => null, 'campo' => null, 'mensaje' => 'El archivo no trae metas para cargar: escribe los números en las columnas de meta.']]);
            }
            $cargadas = $metas->guardar($c, $leido['items'], $request->user(), 'electoral_campaign.goals.imported', ['filas_archivo' => count($leido['items'])]);
        } catch (MetasInvalidas $e) {
            $filaDe = $leido['filas'] ?? [];
            $mensajes = array_values(array_unique(array_map(
                fn ($err) => $err['indice'] !== null && isset($filaDe[$err['indice']]) ? "Fila {$filaDe[$err['indice']]}: {$err['mensaje']}" : $err['mensaje'],
                $e->errores)));

            return response()->json(['success' => false, 'message' => 'No se cargó nada. Corrige estas filas:', 'errors' => ['archivo' => $mensajes]], 422);
        }

        $mensaje = $cargadas === 1 ? 'Se cargó 1 meta' : 'Se cargaron '.number_format($cargadas, 0, ',', '.').' metas';

        return response()->json(['success' => true, 'message' => $mensaje, 'data' => ['cargadas' => $cargadas] + $this->detalle->detalle($c->fresh())]);
    }

    // GET /campanas-electorales/{id}/historial
    public function history(string $id): JsonResponse
    {
        $c = $this->access->find($id);
        $nombres = array_column($this->access->territorio()->filas($c->scope_level, $this->access->territoryIds($c)), 'nombre', 'id');
        $allowed = $this->access->allowedMunicipios();
        $visibles = $allowed === null ? null : array_flip(array_column($this->detalle->filas($c), 'id'));

        $rows = DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.entity_type', 'electoral_campaign')
            ->where('a.entity_id', $c->id)
            ->whereIn('a.action', array_keys(self::ACCIONES))
            ->orderByDesc('a.created_at')->orderByDesc('a.id')
            ->limit((int) config('campanas_electorales.historial_max'))
            ->get(['a.id', 'a.action', 'a.metadata', 'a.created_at', 'u.name as usuario']);

        $data = $rows->map(function ($r) use ($nombres, $visibles): ?array {
            $meta = is_string($r->metadata) ? json_decode($r->metadata, true) : (array) $r->metadata;
            $cambios = null;
            if (isset($meta['cambios']) && is_array($meta['cambios'])) {
                $cambios = [];
                foreach ($meta['cambios'] as $cambio) {
                    $unidad = $cambio['unidad'] ?? null;
                    if ($unidad !== null && $visibles !== null && ! isset($visibles[$unidad])) {
                        continue;
                    }
                    $cambios[] = [
                        'territorio' => $unidad !== null ? ($nombres[$unidad] ?? $cambio['territorio'] ?? null) : null,
                        'campo' => $cambio['campo'] ?? null,
                        'campo_label' => self::CAMPOS[$cambio['campo'] ?? ''] ?? ($cambio['campo'] ?? null),
                        'antes' => $cambio['antes'] ?? null,
                        'despues' => $cambio['despues'] ?? null,
                    ];
                }
                // Every change was outside the user's territory (or none was real): nothing to show.
                if ($cambios === []) {
                    return null;
                }
            }

            return [
                'id' => $r->id,
                'fecha' => $r->created_at ? Carbon::parse($r->created_at)->toIso8601String() : null,
                'usuario' => $r->usuario,
                'accion' => $r->action,
                'accion_label' => self::ACCIONES[$r->action] ?? $r->action,
                'cambios' => $cambios,
            ];
        })->filter()->values()->all();

        return response()->json(['success' => true, 'data' => $data]);
    }

    // GET /campanas-electorales/opciones/candidatos?q=
    public function candidateOptions(Request $request): JsonResponse
    {
        $this->campaign()->abortUnlessSeesElectoralResults();
        $q = TextNormalizer::normalizeName((string) $request->query('q', ''));
        if (mb_strlen($q) < 2 || mb_strlen($q) > 100) {
            return response()->json(['success' => true, 'data' => []]);
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
        $personas = DB::table('persons as p')
            ->whereNull('p.deleted_at')->whereNull('p.merged_into_id')
            ->where('p.normalized_name', 'like', $like)
            ->whereExists(fn ($e) => $e->from('candidacies as c')->whereColumn('c.person_id', 'p.id'))
            ->orderBy('p.full_name')->orderBy('p.id')
            ->limit((int) config('campanas_electorales.candidatos_max'))
            ->get(['p.id', 'p.full_name']);

        $candidaturas = $personas->isEmpty() ? collect() : DB::table('candidacies as c')
            ->join('contests as con', 'con.id', '=', 'c.contest_id')
            ->leftJoin('electoral_events as ev', 'ev.id', '=', 'con.electoral_event_id')
            ->leftJoin('offices as o', 'o.id', '=', 'con.office_id')
            ->leftJoin('corporations as co', 'co.id', '=', 'con.corporation_id')
            ->leftJoin('geographic_units as g', 'g.id', '=', 'con.geographic_unit_id')
            ->leftJoin('candidacy_endorsements as ce', fn ($j) => $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true))
            ->leftJoin('political_organizations as po', 'po.id', '=', 'ce.organization_id')
            ->whereIn('c.person_id', $personas->pluck('id')->all())
            ->orderByDesc('ev.election_date')
            ->get(['c.id', 'c.person_id', 'o.name as office', 'co.name as corporation', 'con.name as contest', 'ev.election_date',
                'g.canonical_name as territorio', 'po.canonical_name as partido'])
            ->unique('id')
            ->groupBy('person_id');

        $data = $personas->map(fn ($p) => [
            'id' => $p->id,
            'nombre' => $p->full_name,
            'candidaturas' => $candidaturas->get($p->id, collect())->map(fn ($c) => [
                'cargo' => $c->office ?? $c->corporation ?? $c->contest,
                'anio' => $c->election_date ? (int) substr((string) $c->election_date, 0, 4) : null,
                'territorio' => $c->territorio ?? 'Nacional',
                'partido' => $c->partido,
            ])->values()->all(),
        ])->values()->all();

        return response()->json(['success' => true, 'data' => $data]);
    }

    private function denyTerritoryUser(): void
    {
        if ($this->access->allowedMunicipios() !== null) {
            abort(403, ElectoralCampaignAccess::CON_TERRITORIO);
        }
    }

    private function syncTerritorio(ElectoralCampaign $c, array $unitIds): void
    {
        ElectoralCampaignTerritory::query()->where('electoral_campaign_id', $c->id)->whereNotIn('geographic_unit_id', $unitIds)->delete();
        $existentes = ElectoralCampaignTerritory::query()->where('electoral_campaign_id', $c->id)->pluck('geographic_unit_id')->all();
        foreach (array_diff($unitIds, $existentes) as $unit) {
            ElectoralCampaignTerritory::create(['electoral_campaign_id' => $c->id, 'geographic_unit_id' => $unit]);
        }
        $c->unsetRelation('territories');
    }

    private function audit(string $action, ElectoralCampaign $c, array $metadata, ?array $before = null, ?array $after = null): void
    {
        AuditLog::create([
            'user_id' => request()->user()?->id,
            'action' => $action,
            'entity_type' => 'electoral_campaign',
            'entity_id' => $c->id,
            'metadata' => ['name' => $c->name] + $metadata,
            'before_state' => $before,
            'after_state' => $after,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    private static function plano(mixed $v): mixed
    {
        return $v instanceof DateTimeInterface ? $v->format('Y-m-d') : $v;
    }
}
