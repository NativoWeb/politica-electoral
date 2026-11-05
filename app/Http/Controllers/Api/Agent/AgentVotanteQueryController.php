<?php

namespace App\Http\Controllers\Api\Agent;

use App\Exports\GenericExport;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Http\Controllers\Controller;
use App\Http\Controllers\VotanteController;
use App\Http\Requests\Agent\AgentListVotantesRequest;
use App\Models\AuditLog;
use App\Models\Lider;
use App\Models\LiderContacto;
use App\Services\Agent\AgentContactQuota;
use App\Support\ContactDataPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

// Read API of the WhatsApp agent over the Votantes listing, acting as the token owner: same territory, campaign and
// filters as the web screen. Cédula, phone, email and main contacts only for persons with autoriza_datos = true.
// Every delivery is audited (these GETs are not covered by AuditActivity) and counts against AgentContactQuota.
class AgentVotanteQueryController extends Controller
{
    use ScopesTerritory;

    private const FLAGS = ['gran_elector', 'militante', 'verificado', 'empresario', 'reservista', 'funcionario', 'exfuncionario'];

    private const EXPORT_COLUMNS = ['nombre', 'municipio', 'nivel_confianza', 'partido', 'puesto_votacion', 'mesa_votacion',
        'gran_elector', 'militante', 'verificado', 'datos_autorizados', 'cedula', 'telefono', 'email', 'contactos'];

    public function __construct(private readonly AgentContactQuota $quota) {}

    public function index(AgentListVotantesRequest $request): JsonResponse
    {
        $filters = $request->filters();
        $page = $this->query($request, $filters)->paginate($request->perPage(), ['*'], 'page', $request->page());
        $contactos = $this->contactos($page->getCollection());
        $rows = $page->getCollection()->map(fn (Lider $l) => $this->row($l, $contactos))->values()->all();

        $conDatos = $this->withData($page->getCollection());
        $counts = ['total' => $page->total(), 'entregadas' => count($rows), 'con_datos' => $conDatos,
            'page' => $page->currentPage(), 'per_page' => $page->perPage()];
        if ($blocked = $this->overQuota($request, 'agent.votantes.list', $filters, $counts)) {
            return $blocked;
        }

        $this->deliver($request, 'agent.votantes.list', $filters, $counts);

        return response()->json([
            'success' => true,
            'data' => $rows,
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

    public function export(AgentListVotantesRequest $request): Response
    {
        $filters = $request->filters();
        $max = max(1, (int) config('agent.export_max_rows', 1000));
        $query = $this->query($request, $filters);
        $total = (clone $query)->count();
        $lideres = $query->limit($max)->get();
        $contactos = $this->contactos($lideres);

        // More rows with contact data than the quota left today: the file stops before the first one over it.
        $remaining = $this->quota->remaining($request->user()->id);
        $quotaTruncated = $remaining > 0 && $this->withData($lideres) > $remaining;
        if ($quotaTruncated) {
            $lideres = $this->cutToQuota($lideres, $remaining);
        }

        $conDatos = $this->withData($lideres);
        $counts = ['total' => $total, 'entregadas' => $lideres->count(), 'con_datos' => $conDatos, 'max_filas' => $max,
            'recortado_por_cupo' => $quotaTruncated];
        if ($blocked = $this->overQuota($request, 'agent.votantes.export', $filters, $counts)) {
            return $blocked;
        }

        $data = $lideres->map(fn (Lider $l) => $this->exportRow($this->row($l, $contactos)))->all();
        $this->deliver($request, 'agent.votantes.export', $filters, $counts);

        $filename = 'votantes_'.Carbon::now('America/Bogota')->format('Ymd_His').'.xlsx';
        $response = Excel::download(new GenericExport($data, self::EXPORT_COLUMNS, 'Votantes', [
            'nivel_confianza' => 'NIVEL DE CONFIANZA', 'puesto_votacion' => 'PUESTO', 'mesa_votacion' => 'MESA',
            'gran_elector' => 'GRAN ELECTOR', 'militante' => 'MILITANTE', 'verificado' => 'VERIFICADO',
            'datos_autorizados' => 'AUTORIZA DATOS', 'cedula' => 'CÉDULA', 'contactos' => 'OTROS CONTACTOS',
        ]), $filename);
        $response->headers->set('X-Total-Count', (string) $total);
        $response->headers->set('X-Export-Truncated', $total > $max ? 'true' : 'false');
        $response->headers->set('X-Quota-Truncated', $quotaTruncated ? 'true' : 'false');

        return $response;
    }

    // The rows in order up to the last one that still fits $quota rows with contact data.
    private function cutToQuota(Collection $lideres, int $quota): Collection
    {
        $withData = 0;

        return $lideres->takeWhile(function (Lider $l) use (&$withData, $quota) {
            if (! $this->authorized($l)) {
                return true;
            }

            return ++$withData <= $quota;
        })->values();
    }

    // The web listing (VotanteController::listing) with the agent's filter names, plus municipio_id.
    private function query(Request $request, array $filters): Builder
    {
        $municipioId = $filters['municipio_id'] ?? null;
        $allowed = $this->allowedGeoIds();
        if ($municipioId && $allowed !== null && ! in_array($municipioId, $allowed, true)) {
            abort(403, 'Ese municipio está fuera de tu territorio.');
        }

        $bool = fn (string $key) => array_key_exists($key, $filters) ? ($filters[$key] ? '1' : '0') : null;
        $web = Request::create('/votantes', 'GET', array_filter([
            'estado' => $filters['is_active'] ? 'activos' : 'inactivos',
            'nivel_confianza' => $filters['nivel_confianza'] ?? null,
            'gran_elector' => $bool('gran_elector'),
            'militante' => $bool('militante'),
            'verificado' => $bool('verificado'),
        ], fn ($v) => $v !== null && $v !== ''));
        $web->setUserResolver(fn () => $request->user());

        return app(VotanteController::class)->listing($web)
            ->when($municipioId, fn ($q) => $q->where('lideres.geographic_unit_id', $municipioId))
            ->when($filters['q'] ?? null, fn ($q, $term) => $this->search($q, $term))
            ->orderBy('lideres.nombre')
            ->orderBy('lideres.id');
    }

    // The web search (name or part of the cédula), except that a cédula only finds who authorized their data:
    // the agent must not confirm that a given cédula is in the database for someone who did not.
    private function search(Builder $query, string $term): Builder
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
        $like = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return $query->where(fn ($w) => $w->where('lideres.nombre', $like, "%{$escaped}%")
            ->orWhere(fn ($c) => $c->where('lideres.autoriza_datos', true)->where('lideres.cedula', 'like', "%{$escaped}%")));
    }

    private function authorized(Lider $lider): bool
    {
        return ContactDataPolicy::authorized($lider);
    }

    private function withData(Collection $lideres): int
    {
        return $lideres->filter(fn (Lider $l) => $this->authorized($l))->count();
    }

    // Main contacts (principal) of the authorized persons only, by lider id.
    private function contactos(Collection $lideres): Collection
    {
        $ids = $lideres->filter(fn (Lider $l) => $this->authorized($l))->pluck('id');
        if ($ids->isEmpty()) {
            return collect();
        }

        return LiderContacto::whereIn('lider_id', $ids)
            ->where('principal', true)
            ->orderBy('tipo')
            ->get(['lider_id', 'tipo', 'valor', 'estado'])
            ->groupBy('lider_id');
    }

    private function row(Lider $l, Collection $contactos): array
    {
        $row = [
            'id' => $l->id,
            'nombre' => $l->nombre,
            'municipio' => $l->municipio,
            'municipio_id' => $l->geographic_unit_id,
            'nivel_confianza' => $l->nivel_confianza,
            'partido' => $l->partido,
            'puesto_votacion' => $l->puesto_votacion,
            'mesa_votacion' => $l->mesa_votacion,
            'municipio_votacion' => $l->municipio_votacion,
            'is_active' => (bool) $l->is_active,
            'referidos_count' => (int) ($l->referidos_count ?? 0),
        ];
        if ($l->getAttribute('eventos_count') !== null) {
            $row['eventos_count'] = (int) $l->eventos_count;
        }
        foreach (self::FLAGS as $flag) {
            $row[$flag] = (bool) $l->{$flag};
        }
        $row['datos_autorizados'] = $this->authorized($l);

        if ($row['datos_autorizados']) {
            $row['cedula'] = $l->cedula;
            $row['telefono'] = $l->telefono;
            $row['email'] = $l->email;
            $row['contactos'] = ($contactos->get($l->id) ?? collect())
                ->map(fn (LiderContacto $c) => ['tipo' => $c->tipo, 'valor' => $c->valor, 'estado' => $c->estado])
                ->values()->all();
        }

        return $row;
    }

    private function exportRow(array $row): array
    {
        foreach (['gran_elector', 'militante', 'verificado', 'datos_autorizados'] as $flag) {
            $row[$flag] = $row[$flag] ? 'Sí' : 'No';
        }
        $row['nivel_confianza'] = Str::of((string) $row['nivel_confianza'])->replace('_', ' ')->ucfirst()->toString();
        $row['contactos'] = isset($row['contactos']) && $row['contactos'] !== []
            ? collect($row['contactos'])->map(fn ($c) => "{$c['tipo']}: {$c['valor']}")->implode('; ')
            : null;

        return $row;
    }

    // 429 (and an audit row of the refusal) when this delivery would pass the user's daily contact quota.
    private function overQuota(Request $request, string $action, array $filters, array $counts): ?JsonResponse
    {
        if ($this->quota->consume($request->user()->id, $counts['con_datos'])) {
            return null;
        }

        $this->audit($request, $action, $filters, $counts + ['bloqueado' => 'limite_diario']);

        return response()->json([
            'success' => false,
            'message' => 'Alcanzaste el límite diario de datos de contacto.',
            'restantes' => $this->quota->remaining($request->user()->id),
        ], 429);
    }

    // The quota was already reserved: if the audit row cannot be written, nothing is delivered and the quota is given back.
    private function deliver(Request $request, string $action, array $filters, array $counts): void
    {
        try {
            $this->audit($request, $action, $filters, $counts);
        } catch (Throwable $e) {
            $this->quota->release($request->user()->id, $counts['con_datos']);

            throw $e;
        }
    }

    // Counts and the (masked, see config/audit.php) filters only: never the personal data delivered.
    private function audit(Request $request, string $action, array $filters, array $counts): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'votante',
            'entity_id' => null,
            'metadata' => ['filtros' => $filters, ...$counts, 'ruta' => $request->route()?->getName()],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'correlation_id' => $this->correlationId($request),
        ]);
    }

    // Same rule as AuditActivity: only a well-formed UUID is stored.
    private function correlationId(Request $request): ?string
    {
        $value = $request->header('X-Correlation-Id');

        return is_string($value) && Str::isUuid($value) ? strtolower($value) : null;
    }
}
