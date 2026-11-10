<?php

namespace App\Http\Controllers\Api\Agent;

use App\Exports\GenericExport;
use App\Http\Controllers\Concerns\DeliversAgentContactData;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Http\Controllers\Controller;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\MapaPoliticoController;
use App\Http\Requests\Agent\AgentListMapaRequest;
use App\Models\Lider;
use App\Services\Agent\AgentContactQuota;
use App\Services\CachedQueries;
use App\Services\Eventos\EventScope;
use App\Support\ContactDataPolicy;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

// Read API of the WhatsApp agent over the Mapa Político rows, acting as the token owner. rows() hands out the contact
// data of anyone and copies a lider's onto electoral rows by name, so nothing personal is read from it: cédula, phone,
// email, address and notes come only from the one Lider a row resolves to, and only if that person authorized them.
class AgentMapaQueryController extends Controller
{
    use DeliversAgentContactData, ScopesTerritory;

    private const LIDER_COLUMNS = ['id', 'nombre', 'municipio', 'geographic_unit_id', 'campaign_id', 'is_active',
        'autoriza_datos', 'cedula', 'telefono', 'email', 'direccion', 'observacion', 'barrio', 'zona', 'profesion'];

    private const XLSX_COLUMNS = ['nombre', 'municipio', 'provincia', 'tipo', 'cargo', 'partido', 'votos', 'barrio', 'zona',
        'profesion', 'destacado', 'datos_autorizados', 'cedula', 'telefono', 'email', 'direccion', 'observacion'];

    private const PDF_COLUMNS = ['nombre', 'municipio', 'tipo', 'cargo', 'partido', 'votos', 'barrio', 'telefono', 'observacion'];

    private const PDF_MEMORY_BYTES = 256 * 1024 * 1024;

    private const PDF_SECONDS = 120;

    public function __construct(private readonly AgentContactQuota $quota) {}

    public function index(AgentListMapaRequest $request): JsonResponse
    {
        $filters = $this->guardedFilters($request);
        ['rows' => $all, 'multiFicha' => $multiFicha] = $this->mapaRows($request, $filters);

        $total = count($all);
        if ($request->soloConteo()) {
            return $this->countResponse($request, $filters, $all);
        }

        $perPage = $request->perPage();
        $slice = array_values(array_slice($all, ($request->page() - 1) * $perPage, $perPage));
        $resueltos = $this->resolvedLideres($slice, $filters, $multiFicha);
        $autorizados = $this->authorizedOnly($resueltos);

        $counts = ['total' => $total, 'entregadas' => count($slice), 'con_datos' => count($autorizados),
            'page' => $request->page(), 'per_page' => $perPage];
        if ($blocked = $this->overQuota($request, 'agent.mapa.list', $filters, $counts)) {
            return $blocked;
        }

        $data = array_map(fn (array $row, int $i) => $this->row($row, $resueltos[$i] ?? null), $slice, array_keys($slice));
        $this->deliver($request, 'agent.mapa.list', $filters, $counts);

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'total' => $total,
                'page' => $request->page(),
                'per_page' => $perPage,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'con_datos' => count($autorizados),
                'cupo_restante' => $this->quota->remaining($request->user()->id),
                'secciones' => MapaPoliticoController::sectionCounts($all),
            ],
        ]);
    }

    public function export(AgentListMapaRequest $request): Response
    {
        $filters = $this->guardedFilters($request);
        $formato = $request->formato();
        $max = max(1, (int) config('agent.export_max_rows', 1000));
        if ($formato === 'pdf') {
            $this->allowPdfRendering();
            $max = min($max, ExportController::PDF_MAX_ROWS);
        }

        ['rows' => $all, 'municipioInfo' => $municipioInfo, 'multiFicha' => $multiFicha] = $this->mapaRows($request, $filters);
        $total = count($all);
        $rows = array_values(array_slice($all, 0, $max));
        $resueltos = $this->resolvedLideres($rows, $filters, $multiFicha);
        $autorizados = $this->authorizedOnly($resueltos);

        // More rows with contact data than the quota left today: the file stops before the first one over it.
        $remaining = $this->quota->remaining($request->user()->id);
        $quotaTruncated = $remaining > 0 && count($autorizados) > $remaining;
        if ($quotaTruncated) {
            $rows = $this->cutToQuota($rows, $autorizados, $remaining);
            $autorizados = array_intersect_key($autorizados, $rows);
        }

        $conDatos = count($autorizados);
        $counts = ['total' => $total, 'entregadas' => count($rows), 'con_datos' => $conDatos, 'max_filas' => $max,
            'recortado_por_cupo' => $quotaTruncated, 'formato' => $formato];
        if ($blocked = $this->overQuota($request, 'agent.mapa.export', $filters, $counts)) {
            return $blocked;
        }

        $data = array_map(fn (array $row, int $i) => $this->exportRow($this->row($row, $resueltos[$i] ?? null)), $rows, array_keys($rows));
        try {
            $filename = 'mapa_politico_'.Carbon::now('America/Bogota')->format('Ymd_His').'.'.$formato;
            $response = $formato === 'pdf'
                ? $this->pdf($data, $this->title($filters, $municipioInfo), $total, $total > $max, $quotaTruncated, $filename)
                : $this->xlsx($data, $filename);
        } catch (Throwable $e) {
            $this->quota->release($request->user()->id, $conDatos);

            throw $e;
        }
        $this->deliver($request, 'agent.mapa.export', $filters, $counts);

        $response->headers->set('X-Total-Count', (string) $total);
        $response->headers->set('X-Export-Truncated', $total > $max ? 'true' : 'false');
        $response->headers->set('X-Quota-Truncated', $quotaTruncated ? 'true' : 'false');
        $response->headers->set('X-Rows-Delivered', (string) count($data));
        $response->headers->set('X-Rows-With-Data', (string) $conDatos);

        return $response;
    }

    // Room for a 500-row PDF, as ExportController::pdf gives it. Only ever raises the limits this process already has.
    private function allowPdfRendering(): void
    {
        $memory = ini_parse_quantity((string) ini_get('memory_limit'));
        if ($memory !== -1 && $memory < self::PDF_MEMORY_BYTES) {
            ini_set('memory_limit', (string) self::PDF_MEMORY_BYTES);
        }
        if ((int) ini_get('max_execution_time') !== 0) {
            set_time_limit(self::PDF_SECONDS);
        }
    }

    protected function auditEntityType(): string
    {
        return 'mapa_politico';
    }

    // The rows in order up to the last one that still fits $quota rows with contact data, keeping their indexes.
    private function cutToQuota(array $rows, array $autorizados, int $quota): array
    {
        $withData = 0;
        $kept = [];
        foreach ($rows as $i => $row) {
            if (isset($autorizados[$i]) && ++$withData > $quota) {
                break;
            }
            $kept[$i] = $row;
        }

        return $kept;
    }

    // How many rows match, without any row: costs no contact quota.
    private function countResponse(Request $request, array $filters, array $all): JsonResponse
    {
        $this->audit($request, 'agent.mapa.count', $filters, ['total' => count($all)]);

        return response()->json([
            'success' => true,
            'data' => [],
            'meta' => [
                'total' => count($all),
                'secciones' => MapaPoliticoController::sectionCounts($all),
                'cupo_restante' => $this->quota->remaining($request->user()->id),
            ],
        ]);
    }

    private function guardedFilters(AgentListMapaRequest $request): array
    {
        $filters = $this->withoutForbiddenTipo($request, $request->filters());
        $this->denyOutsideTerritoryFilters($filters);

        return $filters;
    }

    // rows() turns a lone attendee tipo into "todos" for who may not see attendees: here that is refused, and said
    // without naming them. Sent along with other tipos it is simply dropped.
    private function withoutForbiddenTipo(Request $request, array $filters): array
    {
        $tipos = explode(',', $filters['tipo'] ?? '');
        if (! in_array(MapaPoliticoController::ASISTENTES_EVENTO, $tipos, true) || EventScope::canSeeAttendees($request->user())) {
            return $filters;
        }

        $rest = array_values(array_diff($tipos, [MapaPoliticoController::ASISTENTES_EVENTO]));
        abort_if($rest === [], 403, 'No tienes permiso para esta consulta.');
        $filters['tipo'] = implode(',', $rest);

        return $filters;
    }

    // MapaPoliticoController::rows() with the agent's filter names and always its full result, plus what the web
    // cannot express.
    private function mapaRows(Request $request, array $filters): array
    {
        $web = Request::create('/mapa-politico', 'GET', array_filter([
            'municipio' => $filters['municipio_id'] ?? null,
            'provincia' => $filters['provincia'] ?? null,
            'tipo' => $filters['tipo'] ?? null,
            'cargo' => $filters['cargo'] ?? null,
            'search' => $filters['q'] ?? null,
            'partido' => $filters['partido'] ?? null,
            'barrio' => $filters['barrio'] ?? null,
            'destacado' => ($filters['destacado'] ?? false) ? '1' : null,
            'profesion' => $filters['profesion'] ?? null,
            'estado' => $filters['estado'] ?? null,
            'campana' => $filters['campana'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));
        $web->setUserResolver(fn () => $request->user());

        $result = app(MapaPoliticoController::class)->rows($web, forExport: true);
        $rows = $result['data'];
        $multiFicha = array_flip($result['multiFicha']);

        // The web has no "only the non-featured" filter: destacado=0 would mean no filter there.
        if (($filters['destacado'] ?? null) === false) {
            $rows = array_values(array_filter($rows, fn (array $row) => empty($row['destacado'])));
        }
        if (! empty($filters['q'])) {
            $rows = $this->withoutCedulaOnlyMatches($rows, $filters, $multiFicha);
        }
        // barrio and profesion are only known for a row that resolves to one ficha: a row kept by a namesake's value
        // would tell where that person lives without being able to show it.
        if (! empty($filters['barrio']) || ! empty($filters['profesion'])) {
            $rows = array_values(array_intersect_key($rows, $this->resolvedLideres($rows, $filters, $multiFicha)));
        }

        return ['rows' => $rows, 'municipioInfo' => $result['municipioInfo'], 'multiFicha' => $multiFicha];
    }

    // rows() ignores a municipio or province out of the territory and answers with the whole territory instead.
    private function denyOutsideTerritoryFilters(array $filters): void
    {
        $allowed = $this->allowedGeoIds();
        if ($allowed === null) {
            return;
        }
        $municipioId = $filters['municipio_id'] ?? null;
        if ($municipioId && ! in_array($municipioId, $allowed, true)) {
            abort(403, 'Ese municipio está fuera de tu territorio.');
        }

        $provincia = $filters['provincia'] ?? null;
        if ($provincia && ! $municipioId) {
            $visible = collect(CachedQueries::allMunicipios())->filter(fn ($m) => in_array($m['id'], $allowed, true));
            $inProvincia = $provincia === MapaPoliticoController::AREA_METROPOLITANA
                ? $visible->whereIn('name', MapaPoliticoController::AREA_METROPOLITANA_MUNICIPIOS)
                : $visible->where('provincia', $provincia);
            abort_if($inProvincia->isEmpty(), 403, 'Esa provincia está fuera de tu territorio.');
        }
    }

    // A row that matches only by cédula stays only if that person authorized their data: otherwise the answer would
    // confirm that the cédula is in the database.
    private function withoutCedulaOnlyMatches(array $rows, array $filters, array $multiFicha): array
    {
        $q = $filters['q'];
        $byCedulaOnly = array_filter($rows, fn (array $row) => mb_stripos((string) ($row['nombre'] ?? ''), $q) === false);
        if ($byCedulaOnly === []) {
            return $rows;
        }

        $autorizados = $this->authorizedOnly($this->resolvedLideres($byCedulaOnly, $filters, $multiFicha));

        return array_values(array_filter(
            $rows,
            fn (array $row, int $i) => ! isset($byCedulaOnly[$i]) || isset($autorizados[$i]),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    private function authorizedOnly(array $resueltos): array
    {
        return array_filter($resueltos, fn (Lider $l) => ContactDataPolicy::authorized($l));
    }

    // Row index => the one visible Lider that row is. A row without an entry (a namesake, several fichas merged by
    // rows(), nobody) shows nothing personal.
    private function resolvedLideres(array $rows, array $filters, array $multiFicha): array
    {
        $byId = $this->lideresById($rows);
        $index = MapaPoliticoController::municipioIndex();
        $pending = array_filter($rows, fn (array $row) => ! $byId->has($row['id'] ?? ''));
        $byPerson = $this->ownLideresByPerson($pending, $index);

        $resueltos = [];
        foreach ($rows as $i => $row) {
            if (isset($multiFicha[$row['id'] ?? ''])) {
                continue;
            }
            $lider = $byId->get($row['id'] ?? '');
            if (! $lider) {
                $matches = $byPerson->get(MapaPoliticoController::personKey($row, $index), collect());
                $lider = $matches->count() === 1 ? $matches->first() : null;
            }
            if ($lider && $this->visible($lider, $filters)) {
                $resueltos[$i] = $lider;
            }
        }

        return $resueltos;
    }

    private function lideresById(array $rows): Collection
    {
        return collect($rows)->pluck('id')->filter()->unique()->chunk(1000)
            ->flatMap(fn (Collection $ids) => Lider::whereIn('id', $ids->values()->all())->get(self::LIDER_COLUMNS))
            ->keyBy('id');
    }

    // The own campaign's lideres by person, every one of them (active or not) so that a namesake makes the row
    // ambiguous. By name as well as by municipio: a ficha may have the municipio written another way and no geo id.
    private function ownLideresByPerson(array $rows, array $index): Collection
    {
        if ($rows === []) {
            return collect();
        }

        $names = array_values(array_unique(array_map(fn (array $row) => mb_strtoupper(trim((string) ($row['nombre'] ?? ''))), $rows)));
        $municipios = array_values(array_unique(array_filter(array_column($rows, 'municipio'))));
        $geoIds = array_values(array_unique(array_filter(array_column($rows, 'geographic_unit_id'))));
        $own = $this->campaign()->ownId();

        return Lider::query()
            ->when($own !== null, fn ($q) => $q->where('campaign_id', $own))
            ->where(fn ($q) => $q->whereIn(DB::raw('UPPER(TRIM(nombre))'), $names)
                ->orWhereIn('municipio', $municipios)
                ->orWhereIn('geographic_unit_id', $geoIds))
            ->get(self::LIDER_COLUMNS)
            ->groupBy(fn (Lider $l) => MapaPoliticoController::personKey($l->only(['nombre', 'municipio', 'geographic_unit_id']), $index));
    }

    // Territory and estado as the listing applies them to a lider's own row; an electoral row reaches here without them.
    private function visible(Lider $lider, array $filters): bool
    {
        $allowed = $this->allowedGeoIds();
        if ($allowed !== null && ! in_array($lider->geographic_unit_id, $allowed, true)) {
            return false;
        }
        $estado = $filters['estado'] ?? 'activos';

        return $estado === 'todos' || $lider->is_active === ($estado === 'activos');
    }

    // barrio, zona and profesion only of the resolved Lider; contact data only if that person authorized it.
    private function row(array $row, ?Lider $lider): array
    {
        $autorizado = $lider !== null && ContactDataPolicy::authorized($lider);
        $out = [
            'id' => $row['id'] ?? null,
            'nombre' => $row['nombre'] ?? null,
            'municipio' => $row['municipio'] ?? null,
            'municipio_id' => $row['geographic_unit_id'] ?? null,
            'provincia' => $row['provincia'] ?? null,
            'tipo_registro' => $row['tipo_registro'] ?? null,
            'cargo' => $row['cargo'] ?? null,
            'partido' => $row['partido'] ?? null,
            'outcome' => $row['outcome'] ?? null,
            'votos' => (int) ($row['votos'] ?? 0),
            'destacado' => ! empty($row['destacado']),
            'datos_autorizados' => $autorizado,
        ];
        if ($lider) {
            foreach (['barrio', 'zona', 'profesion'] as $field) {
                $out[$field] = $lider->{$field};
            }
        }
        if ($autorizado) {
            foreach (['cedula', 'telefono', 'email', 'direccion', 'observacion'] as $field) {
                $out[$field] = $lider->{$field};
            }
        }

        return $out;
    }

    private function exportRow(array $row): array
    {
        $row['tipo'] = $row['tipo_registro'];
        foreach (['destacado', 'datos_autorizados'] as $flag) {
            $row[$flag] = $row[$flag] ? 'Sí' : 'No';
        }

        return $row;
    }

    private function title(array $filters, ?object $municipioInfo): string
    {
        $lugar = $municipioInfo?->name ?? ($filters['provincia'] ?? null);

        return 'Mapa Político'.($lugar ? " - {$lugar}" : '');
    }

    private function xlsx(array $data, string $filename): Response
    {
        return Excel::download(new GenericExport($data, self::XLSX_COLUMNS, 'Mapa Político', [
            'barrio' => 'BARRIO', 'zona' => 'ZONA', 'profesion' => 'PROFESIÓN', 'destacado' => 'DESTACADO',
            'datos_autorizados' => 'AUTORIZA DATOS', 'cedula' => 'CÉDULA', 'direccion' => 'DIRECCIÓN',
        ]), $filename);
    }

    private function pdf(array $data, string $title, int $total, bool $truncated, bool $quotaTruncated, string $filename): Response
    {
        return Pdf::loadView('exports.table', [
            'data' => $data,
            'columns' => self::PDF_COLUMNS,
            'title' => $title,
            'date' => Carbon::now('America/Bogota')->format('d/m/Y H:i'),
            'truncated' => $truncated || $quotaTruncated,
            'totalCount' => $total,
            'aviso' => $quotaTruncated ? 'El archivo se recortó por el límite diario de datos de contacto.' : null,
        ])->setPaper('a4', 'landscape')
            ->setOption('isRemoteEnabled', false)
            ->setOption('isPhpEnabled', false)
            ->download($filename);
    }
}
