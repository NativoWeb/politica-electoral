<?php

namespace App\Http\Controllers;

use App\Exports\GenericExport;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Http\Requests\Empresarios\MiembroRequest;
use App\Http\Requests\Empresarios\OrganizacionRequest;
use App\Models\AuditLog;
use App\Models\Organizacion;
use App\Models\OrganizacionMiembro;
use App\Models\OrgCatalogo;
use App\Services\Empresarios\OrganizacionListing;
use App\Services\Empresarios\OrganizacionScope;
use App\Services\Empresarios\OrganizacionService;
use App\Support\Cedula;
use App\Support\Nit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

// Empresarios y gremios (docs/empresarios.md): seen with view.empresarios, written with manage.empresarios in the own
// campaign and the user's territory (routes + OrganizacionScope). Audit: ids, counts and field names only.
class OrganizacionController extends Controller
{
    use ScopesTerritory;

    public const SCREEN_LIMIT = 2000;

    public const EXPORT_LIMIT = 20000;

    public const PDF_LIMIT = 500;

    public const EXPORT_COLUMNS = ['tipo', 'nombre', 'nit', 'representante', 'representante_cedula', 'representante_celular', 'telefono', 'correo',
        'sitio_web', 'sector_economico', 'sector', 'departamento', 'provincia', 'municipio', 'direccion', 'barrio', 'empleados', 'miembros',
        'gremios', 'observaciones', 'estado'];

    public const EXPORT_LABELS = ['tipo' => 'TIPO', 'nombre' => 'NOMBRE / RAZÓN SOCIAL', 'nit' => 'NIT', 'representante' => 'REPRESENTANTE LEGAL',
        'representante_cedula' => 'CÉDULA REPRESENTANTE', 'representante_celular' => 'CELULAR REPRESENTANTE', 'telefono' => 'TELÉFONO',
        'correo' => 'CORREO', 'sitio_web' => 'SITIO WEB', 'sector_economico' => 'SECTOR ECONÓMICO', 'sector' => 'SECTOR',
        'departamento' => 'DEPARTAMENTO', 'provincia' => 'PROVINCIA', 'municipio' => 'MUNICIPIO', 'direccion' => 'DIRECCIÓN', 'barrio' => 'BARRIO/VEREDA',
        'empleados' => 'EMPLEADOS', 'miembros' => 'MIEMBROS', 'gremios' => 'GREMIOS', 'observaciones' => 'OBSERVACIONES', 'estado' => 'ESTADO'];

    public function __construct(private OrganizacionScope $scope, private OrganizacionListing $listing, private OrganizacionService $service) {}

    // GET /empresarios
    public function index(Request $request): Response
    {
        $filters = $this->listing->filters($request);
        $rows = $this->listing->rows($filters, self::SCREEN_LIMIT + 1);
        $truncated = count($rows) > self::SCREEN_LIMIT;

        return Inertia::render('Empresarios/Index', [
            'data' => array_slice($rows, 0, self::SCREEN_LIMIT),
            'truncated' => $truncated,
            'filters' => $filters,
            'municipios' => $this->listing->municipios(),
            'provincias' => $this->listing->provincias(),
            'catalogos' => $this->catalogos(),
            'gremios' => $this->gremioOptions(),
            'roles' => OrganizacionListing::roles(),
            'rangos' => Organizacion::RANGOS,
            'can' => ['manage' => OrganizacionScope::canManage($request->user()), 'shared_catalog' => $this->campaign()->isSuperadmin()],
        ]);
    }

    // GET /empresarios/{id}: the ficha.
    public function show(string $id): JsonResponse
    {
        $org = $this->scope->find($id);

        return response()->json(['success' => true, 'data' => $this->ficha($org)]);
    }

    // POST /empresarios
    public function store(OrganizacionRequest $request): JsonResponse
    {
        abort_if($this->campaign()->ownId() === null, 403);
        [$org, $changed] = $this->service->save(null, $request->organizacion(), $request->user());
        $this->audit($request, 'ORGANIZACION_CREADA', $org->id, ['tipo' => $org->tipo, 'campos' => $changed]);

        return response()->json(['success' => true, 'message' => Organizacion::TIPOS[$org->tipo].' creada.', 'data' => $this->ficha($org->fresh())], 201);
    }

    // PUT /empresarios/{id}
    public function update(OrganizacionRequest $request, string $id): JsonResponse
    {
        $org = $this->scope->find($id, true);
        [$org, $changed] = $this->service->save($org, $request->organizacion(), $request->user());
        $this->audit($request, 'ORGANIZACION_ACTUALIZADA', $org->id, ['campos' => $changed]);

        return response()->json(['success' => true, 'message' => 'Cambios guardados.', 'data' => $this->ficha($org->fresh())]);
    }

    // DELETE /empresarios/{id}: soft delete. Its members, affiliations and actas stay in the database (not shown);
    // the fichas of Votantes are never touched.
    public function destroy(Request $request, string $id): JsonResponse
    {
        $org = $this->scope->find($id, true);
        $org->forceFill(['updated_by' => $request->user()->id])->save();
        $org->delete();
        $this->audit($request, 'ORGANIZACION_BORRADA', $org->id, ['tipo' => $org->tipo]);

        return response()->json(['success' => true, 'message' => Organizacion::TIPOS[$org->tipo].' eliminada.']);
    }

    // POST /empresarios/{id}/miembros
    public function storeMember(MiembroRequest $request, string $id): JsonResponse
    {
        $org = $this->scope->find($id, true);
        [$miembro, $created] = $this->service->addMember($org, $request->validated(), $request->user());
        $this->audit($request, 'ORGANIZACION_MIEMBRO_AGREGADO', $org->id, ['miembro_id' => $miembro->id, 'lider_id' => $miembro->lider_id, 'rol' => $miembro->rol, 'ficha_creada' => $created]);

        return response()->json(['success' => true, 'message' => $created ? 'Miembro agregado: se creó su ficha en Votantes.' : 'Miembro agregado.',
            'data' => $this->ficha($org)], 201);
    }

    // PUT /empresarios/miembros/{miembro}
    public function updateMember(MiembroRequest $request, string $miembro): JsonResponse
    {
        [$m, $org] = $this->findMember($miembro);
        $this->service->updateMember($m, $request->validated());
        $this->audit($request, 'ORGANIZACION_MIEMBRO_ACTUALIZADO', $org->id, ['miembro_id' => $m->id, 'rol' => $m->rol]);

        return response()->json(['success' => true, 'message' => 'Miembro actualizado.', 'data' => $this->ficha($org)]);
    }

    // DELETE /empresarios/miembros/{miembro}: the membership only, never the ficha.
    public function destroyMember(Request $request, string $miembro): JsonResponse
    {
        [$m, $org] = $this->findMember($miembro);
        if ($m->rol === 'representante_legal' && $org->representante_lider_id === $m->lider_id) {
            $org->forceFill(['representante_lider_id' => null])->save();
        }
        $m->delete();
        $this->audit($request, 'ORGANIZACION_MIEMBRO_QUITADO', $org->id, ['miembro_id' => $m->id, 'lider_id' => $m->lider_id]);

        return response()->json(['success' => true, 'message' => 'Miembro quitado (su ficha en Votantes no cambia).', 'data' => $this->ficha($org)]);
    }

    // GET /empresarios/buscar/personas?q=: active fichas of the own campaign in the user's territory.
    public function searchPersonas(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['success' => true, 'data' => []]);
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], Str::lower($q)).'%';
        $cedula = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) Cedula::normalize($q)).'%';
        $rows = $this->scopeToTerritory($this->ownLideres())->where('is_active', true)
            ->where(fn ($w) => $w->whereRaw('LOWER(nombre) LIKE ?', [$like])->orWhere('cedula', 'like', $cedula))
            ->orderBy('nombre')->limit(10)->get(['id', 'nombre', 'cedula', 'telefono', 'municipio', 'cargo']);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    // GET /empresarios/persona/{liderId}: the organizations a ficha belongs to ("Empresas y gremios" in the ficha).
    public function persona(string $liderId): JsonResponse
    {
        abort_unless(Str::isUuid($liderId), 404);
        $lider = $this->visibleLideres()->where('id', $liderId)->first(['id', 'campaign_id', 'geographic_unit_id']);
        abort_unless($lider, 404);
        $this->authorizeLiderRow($lider);

        $items = $this->scope->query('o')->join('organizacion_miembros as om', 'om.organizacion_id', '=', 'o.id')
            ->leftJoin('geographic_units as m', 'm.id', '=', 'o.municipio_id')
            ->where('om.lider_id', $liderId)->orderBy('o.nombre')
            ->get(['o.id', 'o.nombre', 'o.tipo', 'o.campaign_id', 'm.canonical_name as municipio', 'om.rol', 'om.nota'])
            ->map(fn ($r) => [
                'id' => $r->id, 'nombre' => $r->nombre, 'tipo' => $r->tipo, 'tipo_label' => Organizacion::TIPOS[$r->tipo] ?? $r->tipo,
                'municipio' => $r->municipio, 'campaign_id' => $r->campaign_id, 'rol' => $r->rol,
                'rol_label' => OrganizacionMiembro::ROLES[$r->rol] ?? $r->rol, 'nota' => $r->nota,
            ]);

        return response()->json(['success' => true, 'data' => $items]);
    }

    // POST /empresarios/catalogos {tipo, nombre}: a value of the own campaign (a superadmin may add a shared one).
    public function storeCatalog(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(OrgCatalogo::TIPOS))],
            'nombre' => ['required', 'string', 'max:120'],
            'compartido' => ['nullable', 'boolean'],
        ], ['nombre.required' => 'Escriba el nombre.']);
        $campaignId = $request->boolean('compartido') && $this->campaign()->isSuperadmin() ? null : $this->campaign()->ownId();
        $nombre = trim(preg_replace('/\s+/u', ' ', $data['nombre']));
        $this->assertCatalogFree($data['tipo'], $nombre, $campaignId, null);

        $item = (new OrgCatalogo)->forceFill([
            'campaign_id' => $campaignId, 'tipo' => $data['tipo'], 'nombre' => $nombre, 'is_active' => true,
            'orden' => (int) DB::table('org_catalogos')->where('tipo', $data['tipo'])->max('orden') + 1,
        ]);
        $item->save();
        $this->audit($request, 'ORG_CATALOGO_CREADO', null, ['catalogo_id' => $item->id, 'tipo' => $item->tipo]);

        return response()->json(['success' => true, 'message' => 'Valor agregado.', 'data' => $this->catalogos()], 201);
    }

    // PUT /empresarios/catalogos/{catalogo} {nombre, is_active}
    public function updateCatalog(Request $request, string $catalogo): JsonResponse
    {
        abort_unless(Str::isUuid($catalogo), 404);
        $item = OrgCatalogo::findOrFail($catalogo);
        if ($item->campaign_id === null) {
            abort_unless($this->campaign()->isSuperadmin(), 403, 'Los valores iniciales solo los cambia el administrador de la plataforma.');
        } else {
            $this->authorizeCampaign($item->campaign_id, true);
        }
        $data = $request->validate(['nombre' => ['required', 'string', 'max:120'], 'is_active' => ['required', 'boolean']], ['nombre.required' => 'Escriba el nombre.']);
        $nombre = trim(preg_replace('/\s+/u', ' ', $data['nombre']));
        $this->assertCatalogFree($item->tipo, $nombre, $item->campaign_id, $item->id);
        $item->forceFill(['nombre' => $nombre, 'is_active' => (bool) $data['is_active']])->save();
        $this->audit($request, 'ORG_CATALOGO_ACTUALIZADO', null, ['catalogo_id' => $item->id, 'activo' => $item->is_active]);

        return response()->json(['success' => true, 'message' => 'Valor actualizado.', 'data' => $this->catalogos()]);
    }

    // GET /empresarios/exportar.xlsx (same filters as the screen; NIT, cédula and phones as text)
    public function excel(Request $request)
    {
        EventoImportController::raiseMemoryLimit();
        $filters = $this->listing->filters($request);
        $rows = $this->exportRows($this->listing->rows($filters, self::EXPORT_LIMIT));
        $this->audit($request, 'ORGANIZACIONES_EXPORTADAS', null, ['formato' => 'xlsx', 'filas' => count($rows), 'filtros' => array_keys(array_filter($filters))]);

        return Excel::download(new GenericExport($rows, self::EXPORT_COLUMNS, 'Empresarios y gremios', self::EXPORT_LABELS), 'empresarios-y-gremios.xlsx');
    }

    // GET /empresarios/exportar.pdf (at most PDF_LIMIT rows; the Excel has them all)
    public function pdf(Request $request)
    {
        EventoImportController::raiseMemoryLimit();
        $filters = $this->listing->filters($request);
        $all = $this->listing->rows($filters, self::PDF_LIMIT + 1);
        $rows = $this->exportRows(array_slice($all, 0, self::PDF_LIMIT));
        $this->audit($request, 'ORGANIZACIONES_EXPORTADAS', null, ['formato' => 'pdf', 'filas' => count($rows), 'filtros' => array_keys(array_filter($filters))]);

        return Pdf::loadView('exports.table', [
            'data' => $rows,
            'columns' => ['nombre', 'tipo', 'nit', 'municipio', 'sector_economico', 'representante', 'representante_celular', 'miembros'],
            'title' => 'Empresarios y gremios',
            'date' => now('America/Bogota')->format('d/m/Y H:i'),
            'truncated' => count($all) > self::PDF_LIMIT,
            'totalCount' => count($all) > self::PDF_LIMIT ? count($this->listing->rows($filters, self::EXPORT_LIMIT)) : count($rows),
        ])->setPaper('a4', 'landscape')->setOption('isRemoteEnabled', false)->setOption('isPhpEnabled', false)
            ->download('empresarios-y-gremios.pdf');
    }

    private function exportRows(array $rows): array
    {
        return array_map(fn ($r) => [
            ...$r,
            'tipo' => $r['tipo_label'],
            'gremios' => implode(' / ', $r['gremios']),
            'estado' => $r['is_active'] ? 'Activa' : 'Inactiva',
        ], $rows);
    }

    public function ficha(Organizacion $org): array
    {
        $allowed = $this->allowedGeoIds();
        $miembros = DB::table('organizacion_miembros as om')->join('lideres as l', 'l.id', '=', 'om.lider_id')
            ->where('om.organizacion_id', $org->id)->orderBy('l.nombre')
            ->get(['om.id', 'om.lider_id', 'om.rol', 'om.nota', 'l.nombre', 'l.cedula', 'l.telefono', 'l.municipio', 'l.geographic_unit_id', 'l.campaign_id'])
            ->map(function ($m) use ($allowed) {
                // Outside the user's territory only the name and the role are shown.
                $inside = $allowed === null || in_array($m->geographic_unit_id, $allowed, true);

                return [
                    'id' => $m->id, 'lider_id' => $inside ? $m->lider_id : null, 'nombre' => $m->nombre,
                    'cedula' => $inside ? $m->cedula : null, 'telefono' => $inside ? $m->telefono : null, 'municipio' => $m->municipio,
                    'rol' => $m->rol, 'rol_label' => OrganizacionMiembro::ROLES[$m->rol] ?? $m->rol, 'nota' => $m->nota, 'campaign_id' => $m->campaign_id,
                ];
            })->values()->all();
        $related = fn ($query) => $this->scope->scope($query, 'g')->leftJoin('geographic_units as m', 'm.id', '=', 'g.municipio_id')
            ->orderBy('g.nombre')->get(['g.id', 'g.nombre', 'm.canonical_name as municipio'])->map(fn ($g) => (array) $g)->all();
        $geo = $org->municipio_id ? DB::table('geographic_units as m')->leftJoin('geographic_units as p', 'p.id', '=', 'm.parent_id')
            ->where('m.id', $org->municipio_id)->first(['m.canonical_name as municipio', 'p.canonical_name as provincia']) : null;
        $catalog = fn ($id) => $id ? DB::table('org_catalogos')->where('id', $id)->value('nombre') : null;

        return [
            ...$org->only(OrganizacionService::FIELDS),
            'id' => $org->id,
            'campaign_id' => $org->campaign_id,
            'nit_formateado' => Nit::format($org->nit, $org->nit_dv),
            'nit_input' => Nit::format($org->nit, $org->nit_dv),
            'tipo_label' => Organizacion::TIPOS[$org->tipo] ?? $org->tipo,
            'representante_lider_id' => $org->representante_lider_id,
            'municipio' => $geo?->municipio,
            'provincia' => $geo?->provincia,
            'sector_economico' => $catalog($org->sector_economico_id),
            'sector' => $catalog($org->sector_id),
            'miembros' => $miembros,
            'gremios' => $org->tipo === 'empresa'
                ? $related(DB::table('organizaciones as g')->join('organizacion_gremios as og', 'og.gremio_id', '=', 'g.id')->where('og.empresa_id', $org->id)) : [],
            'empresas' => $org->tipo === 'gremio'
                ? $related(DB::table('organizaciones as g')->join('organizacion_gremios as og', 'og.empresa_id', '=', 'g.id')->where('og.gremio_id', $org->id)) : [],
            'can' => ['edit' => $this->scope->editable($org)],
        ];
    }

    // {sector_economico: [{id, nombre, is_active, editable, compartido}], sector: [...]}
    private function catalogos(): array
    {
        $visible = $this->campaign()->visibleIds();
        $own = $this->campaign()->ownId();
        $super = $this->campaign()->isSuperadmin();
        $canManage = OrganizacionScope::canManage(request()->user());

        return DB::table('org_catalogos')
            ->where(fn ($q) => $q->whereNull('campaign_id')->when($visible !== null, fn ($w) => $w->orWhereIn('campaign_id', $visible), fn ($w) => $w->orWhereNotNull('campaign_id')))
            ->orderBy('orden')->orderBy('nombre')->get(['id', 'tipo', 'nombre', 'is_active', 'campaign_id'])
            ->groupBy('tipo')
            ->map(fn ($g) => $g->map(fn ($c) => [
                'id' => $c->id, 'nombre' => $c->nombre, 'is_active' => (bool) $c->is_active, 'compartido' => $c->campaign_id === null,
                'editable' => $canManage && ($c->campaign_id === null ? $super : $c->campaign_id === $own),
            ])->values()->all())
            ->all() + ['sector_economico' => [], 'sector' => []];
    }

    // Visible gremios (filter) with their campaign (the selector of an empresa only offers the own campaign's).
    private function gremioOptions(): array
    {
        return $this->scope->query('o')->where('o.tipo', 'gremio')->orderBy('o.nombre')->limit(1000)
            ->get(['o.id', 'o.nombre', 'o.campaign_id'])->map(fn ($g) => (array) $g)->all();
    }

    private function assertCatalogFree(string $tipo, string $nombre, ?string $campaignId, ?string $exceptId): void
    {
        $exists = DB::table('org_catalogos')->where('tipo', $tipo)->whereRaw('LOWER(nombre) = ?', [Str::lower($nombre)])
            ->where(fn ($q) => $q->whereNull('campaign_id')->when($campaignId, fn ($w, $v) => $w->orWhere('campaign_id', $v)))
            ->when($exceptId, fn ($q, $v) => $q->where('id', '!=', $v))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['nombre' => 'Ese valor ya está en la lista.']);
        }
    }

    // [member, organization] for a write: the organization in the own campaign and territory.
    private function findMember(string $id): array
    {
        abort_unless(Str::isUuid($id), 404);
        $m = OrganizacionMiembro::findOrFail($id);

        return [$m, $this->scope->find($m->organizacion_id, true)];
    }

    private function audit(Request $request, string $action, ?string $orgId, array $metadata): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'organizacion',
            'entity_id' => $orgId,
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
