<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Http\Requests\Eventos\ImportConfirmRequest;
use App\Http\Requests\Eventos\ImportMappingRequest;
use App\Http\Requests\Eventos\ImportRowRequest;
use App\Http\Requests\Eventos\ImportUploadRequest;
use App\Models\AuditLog;
use App\Models\CampaignEvent;
use App\Models\ConsentText;
use App\Models\EventImport;
use App\Models\EventImportRow;
use App\Services\Eventos\AttendeeSheetReader;
use App\Services\Eventos\AttendeeTemplate;
use App\Services\Eventos\EventImportService;
use App\Services\Eventos\ImportColumns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

// Eventos especiales, phase 3: attendees from an Excel/CSV file (contract in docs/eventos-api.md §11).
// Same isolation as the rest of the module: manage.eventos (route), own campaign (403 for a visible subcampaign,
// 404 for an invisible one) and the user's territory (403).
class EventoImportController extends Controller
{
    use ScopesTerritory;

    public const ROWS_PER_PAGE = 50;

    // Everything imported keeps a data authorization, so (as the public QR) a load needs the campaign's approved text.
    public const NEEDS_APPROVED_TEXT = 'Falta el texto de autorización aprobado por la campaña; no se puede cargar Excel hasta tenerlo.';

    public const FILTERS = ['nuevas', 'plataforma', 'error', 'no_autorizo', 'duplicada', 'en_evento', 'omitidas'];

    public function __construct(private EventImportService $imports) {}

    public function template(Request $request, string $id): HttpResponse
    {
        $event = $this->findEvent($id);
        $consent = ConsentText::currentFor($event->campaign_id);
        $this->raiseMemoryLimit();
        $content = (new AttendeeTemplate)->build($event, $consent);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'EVENTO_PLANTILLA_EXCEL_DESCARGADA',
            'entity_type' => 'evento',
            'entity_id' => $event->id,
            'metadata' => ['route' => 'eventos.importar.plantilla', 'consent_text_id' => $consent?->id],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $filename = 'plantilla-asistentes-'.(Str::slug(Str::limit($event->nombre, 60, '')) ?: 'evento').'.xlsx';

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function index(string $id): Response
    {
        $event = $this->findEvent($id);

        return $this->page($event, null, null, null);
    }

    public function store(ImportUploadRequest $request, string $id): JsonResponse|RedirectResponse
    {
        $event = $this->findEvent($id);
        $this->assertAcceptsImports($event);
        $this->assertApprovedText($event);
        $this->limits();

        try {
            $result = $this->imports->upload($event, $request->file('archivo'), $request->user());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['archivo' => $e->getMessage()]);
        }
        $import = $result['import'];

        $message = match (true) {
            $result['repeated'] && $import->status === 'confirmado' => 'Este archivo ya fue cargado en este evento. No se volvió a importar.',
            $result['repeated'] => 'Este archivo ya estaba subido: puede seguir donde lo dejó.',
            default => 'Archivo recibido: '.self::plural($import->total_rows, 'fila', 'filas').'. Revise las columnas.',
        };

        if ($request->wantsJson()) {
            return response()->json([
                'success' => ! $result['repeated'],
                'message' => $message,
                'data' => ['id' => $import->id, 'status' => $import->status, 'repetido' => $result['repeated'], 'filas' => $import->total_rows],
            ], $result['repeated'] ? 200 : 201);
        }

        return redirect("/eventos/{$event->id}/importar/{$import->id}")->with($result['repeated'] ? 'error' : 'success', $message);
    }

    public function show(Request $request, string $id, string $importId): Response
    {
        $event = $this->findEvent($id);
        $import = $this->findImport($event, $importId);
        $filter = in_array($request->query('estado'), self::FILTERS, true) ? $request->query('estado') : null;

        return $this->page($event, $import, $filter, $request);
    }

    public function mapping(ImportMappingRequest $request, string $id, string $importId): JsonResponse|RedirectResponse
    {
        $event = $this->findEvent($id);
        $import = $this->findImport($event, $importId);
        $this->assertEditable($import);
        $mapping = $request->mapping();
        foreach ($mapping as $index) {
            if ($index !== null && $index >= count($import->headers)) {
                throw ValidationException::withMessages(['columnas' => 'Elija una columna que exista en el archivo.']);
            }
        }
        $this->limits();
        $this->imports->applyMapping($import, $event, $mapping, $request->user());

        return $this->done($request, 'Filas revisadas. Corrija lo que esté en rojo y confirme.', ['counts' => $import->fresh()->counts]);
    }

    public function updateRow(ImportRowRequest $request, string $id, string $importId, string $rowId): JsonResponse|RedirectResponse
    {
        $event = $this->findEvent($id);
        $import = $this->findImport($event, $importId);
        $this->assertEditable($import);
        if ($import->status !== 'revisado') {
            throw ValidationException::withMessages(['fila' => 'Primero revise las columnas del archivo.']);
        }
        abort_unless(Str::isUuid($rowId), 404);
        $row = EventImportRow::where('import_id', $import->id)->findOrFail($rowId);
        if ($row->status === 'no_autorizo') {
            throw ValidationException::withMessages(['fila' => 'Esta fila no se puede cambiar: en «Autorizó» no dice SÍ, la persona no autorizó el tratamiento de sus datos.']);
        }

        try {
            $row = $this->imports->updateRow($import, $event, $row, $request->values(), $request->validated('decision'));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['decision' => $e->getMessage()]);
        }

        return $this->done($request, $row->errors ? 'Fila guardada: todavía tiene datos por corregir.' : 'Fila guardada.', [
            'fila' => $this->row($row), 'counts' => $import->fresh()->counts,
        ]);
    }

    public function confirm(ImportConfirmRequest $request, string $id, string $importId): JsonResponse|RedirectResponse
    {
        $event = $this->findEvent($id);
        $this->assertAcceptsImports($event);
        $import = $this->findImport($event, $importId);
        if ($import->status === 'subido') {
            throw ValidationException::withMessages(['confirmar' => 'Primero revise las columnas del archivo.']);
        }
        $this->assertApprovedText($event);
        $this->limits();

        try {
            $result = $this->imports->confirm($import, $event, $request->user(), $request->ip(), $request->userAgent());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['confirmar' => $e->getMessage()]);
        }

        $message = $result['nuevos'] === 0 && $result['ya_estaban'] === 0
            ? 'No había filas nuevas para registrar: este archivo ya estaba importado.'
            : 'Se registr'.($result['nuevos'] === 1 ? 'ó ' : 'aron ').self::plural($result['nuevos'], 'asistente nuevo', 'asistentes nuevos')
                .', con autorización de datos'
                .($result['ya_estaban'] ? '; '.self::plural($result['ya_estaban'], 'ya estaba', 'ya estaban').' en el evento' : '').'.';

        return $this->done($request, $message, $result);
    }

    public function destroy(Request $request, string $id, string $importId): JsonResponse|RedirectResponse
    {
        $event = $this->findEvent($id);
        $import = $this->findImport($event, $importId);
        $this->assertEditable($import);
        $this->imports->discard($import, $request->user());

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Carga descartada.']);
        }

        return redirect("/eventos/{$event->id}/importar")->with('success', 'Carga descartada: no se registró a nadie.');
    }

    private function page(CampaignEvent $event, ?EventImport $import, ?string $filter, ?Request $request): Response
    {
        $consent = ConsentText::currentFor($event->campaign_id);
        $filas = null;
        if ($import && $import->status !== 'subido') {
            $filas = EventImportRow::where('import_id', $import->id)
                ->when($filter, fn ($q, $f) => match ($f) {
                    'no_autorizo' => $q->where('status', 'no_autorizo'),
                    'nuevas' => $q->where('status', 'valida')->whereNull('match_lider_id'),
                    'plataforma' => $q->where('status', 'valida')->whereNotNull('match_lider_id'),
                    'omitidas' => $q->where('status', 'valida')->where('decision', 'omitir'),
                    default => $q->where('status', $f),
                })
                ->orderBy('row_number')
                ->paginate(self::ROWS_PER_PAGE)->withQueryString()
                ->through(fn (EventImportRow $r) => $this->row($r));
        }

        return Inertia::render('Eventos/Importar', [
            'evento' => [
                'id' => $event->id,
                'nombre' => $event->nombre,
                'status' => $event->status,
                'starts_at' => $event->starts_at?->copy()->setTimezone(EventoController::TZ)->toIso8601String(),
                'municipio' => $event->municipio?->canonical_name,
                'campaign' => $event->campaign ? ['id' => $event->campaign->id, 'nombre' => $event->campaign->name] : null,
                'acepta_cargas' => $event->status !== 'cancelado',
            ],
            'consent' => $consent ? ['id' => $consent->id, 'version' => $consent->version, 'approved' => $consent->approved] : null,
            'importacion' => $import ? $this->importProps($import) : null,
            'muestra' => $import && $import->status === 'subido' ? $this->sample($import) : [],
            'filas' => $filas,
            'filters' => ['estado' => $filter],
            'campos' => ImportColumns::fieldOptions(),
            'limites' => ['filas' => AttendeeSheetReader::MAX_ROWS, 'mb' => ImportUploadRequest::maxMb()],
            'recientes' => EventImport::where('event_id', $event->id)->with('creator:id,name')->orderByDesc('created_at')->limit(10)->get()
                ->map(fn (EventImport $i) => [
                    'id' => $i->id, 'nombre' => $i->original_name, 'status' => $i->status, 'filas' => $i->total_rows,
                    'importadas' => (int) ($i->counts['importadas'] ?? 0), 'created_at' => $this->iso($i->created_at),
                    'created_by' => $i->creator?->name,
                ])->values()->all(),
        ]);
    }

    private function importProps(EventImport $import): array
    {
        return [
            'id' => $import->id,
            'nombre' => $import->original_name,
            'status' => $import->status,
            'filas' => $import->total_rows,
            'fila_encabezados' => $import->header_row,
            'encabezados' => $import->headers,
            'columnas' => $import->column_mapping,
            'counts' => $import->counts,
            'declaro_planillas' => $import->consent_declared,
            'datos_borrados' => $import->staging_purged_at !== null,
            'created_at' => $this->iso($import->created_at),
            'confirmed_at' => $this->iso($import->confirmed_at),
        ];
    }

    // The first rows as read, by column index, so the operator can recognise each column.
    private function sample(EventImport $import): array
    {
        return EventImportRow::where('import_id', $import->id)->orderBy('row_number')->limit(3)->get(['raw'])
            ->map(fn ($r) => array_map(fn ($v) => is_string($v) ? Str::limit($v, 40) : $v, $r->raw ?? []))->values()->all();
    }

    private function row(EventImportRow $r): array
    {
        return [
            'id' => $r->id,
            'fila' => $r->row_number,
            'status' => $r->status,
            'decision' => $r->decision,
            'en_plataforma' => $r->match_lider_id !== null,
            'importada' => $r->attendee_id !== null,
            'corregida' => $r->edited,
            'valores' => $r->mapped ?? [],
            'errores' => $r->errors ?? (object) [],
        ];
    }

    private function findEvent(string $id): CampaignEvent
    {
        abort_unless(Str::isUuid($id), 404);
        $event = CampaignEvent::with(['municipio', 'campaign'])->findOrFail($id);
        $this->authorizeCampaign($event->campaign_id, true);
        $this->authorizeGeo($event->municipio_id);

        return $event;
    }

    private function findImport(CampaignEvent $event, string $importId): EventImport
    {
        abort_unless(Str::isUuid($importId), 404);

        return EventImport::where('event_id', $event->id)->findOrFail($importId);
    }

    private function assertAcceptsImports(CampaignEvent $event): void
    {
        if ($event->status === 'cancelado') {
            throw ValidationException::withMessages(['evento' => 'El evento está cancelado: no se pueden cargar asistentes.']);
        }
    }

    private function assertApprovedText(CampaignEvent $event): void
    {
        if (! ConsentText::currentFor($event->campaign_id)?->approved) {
            throw ValidationException::withMessages(['consent' => self::NEEDS_APPROVED_TEXT]);
        }
    }

    public static function plural(int $n, string $one, string $many): string
    {
        return $n.' '.($n === 1 ? $one : $many);
    }

    private function assertEditable(EventImport $import): void
    {
        if ($import->status === 'confirmado') {
            throw ValidationException::withMessages(['importacion' => 'Esta carga ya fue confirmada: no se puede cambiar.']);
        }
    }

    // 2000 rows are processed in the request (no queue worker in production).
    private function limits(): void
    {
        $this->raiseMemoryLimit();
        set_time_limit(120);
    }

    // 256M unless PHP already allows more (never lowers it).
    private function raiseMemoryLimit(): void
    {
        $current = (string) ini_get('memory_limit');
        if ($current === '-1') {
            return;
        }
        $bytes = (int) $current * match (strtolower(substr($current, -1))) {
            'g' => 1024 * 1024 * 1024, 'm' => 1024 * 1024, 'k' => 1024, default => 1,
        };
        if ($bytes < 256 * 1024 * 1024) {
            ini_set('memory_limit', '256M');
        }
    }

    private function done(Request $request, string $message, array $data): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => $message, 'data' => $data]);
        }

        return back()->with('success', $message);
    }

    private function iso($date): ?string
    {
        return $date ? Carbon::parse($date)->setTimezone(EventoController::TZ)->toIso8601String() : null;
    }
}
