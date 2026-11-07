<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Http\Requests\Eventos\ImportConfirmRequest;
use App\Http\Requests\Eventos\ImportMappingRequest;
use App\Http\Requests\Eventos\ImportRowRequest;
use App\Http\Requests\Eventos\ImportUploadRequest;
use App\Models\AuditLog;
use App\Models\CampaignEvent;
use App\Models\EventImport;
use App\Models\EventImportRow;
use App\Services\Eventos\AttendeeSheetReader;
use App\Services\Eventos\AttendeeTemplate;
use App\Services\Eventos\EventImportService;
use App\Services\Eventos\ImportColumns;
use App\Services\Eventos\Ocr\OcrImportService;
use App\Services\Eventos\Ocr\OcrSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

// Eventos especiales, phase 3: attendees from an Excel/CSV file (contract in docs/eventos-api.md §11).
// Same isolation as the rest of the module: manage.eventos (route), own campaign (403 for a visible subcampaign,
// 404 for an invisible one) and the user's territory (403). The review, corrections, confirmation and discard of an
// OCR load (photos of the printed sheet read with AI, §13) go through these same routes, with its two switches.
class EventoImportController extends Controller
{
    use ScopesTerritory;

    public const ROWS_PER_PAGE = 50;

    public const FILTERS = ['nuevas', 'plataforma', 'error', 'no_autorizo', 'duplicada', 'en_evento', 'omitidas', 'sugerida', 'sin_ficha'];

    public function __construct(private EventImportService $imports) {}

    public function template(Request $request, string $id): HttpResponse
    {
        $event = $this->findEvent($id);
        self::raiseMemoryLimit();
        $content = (new AttendeeTemplate)->build($event);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'EVENTO_PLANTILLA_EXCEL_DESCARGADA',
            'entity_type' => 'evento',
            'entity_id' => $event->id,
            'metadata' => ['route' => 'eventos.importar.plantilla'],
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

    public function show(Request $request, string $id, string $importId): Response|\Symfony\Component\HttpFoundation\Response
    {
        $event = $this->findEvent($id);
        $import = $this->findImport($event, $importId);
        if ($import->isOcr()) {
            return Inertia::location("/eventos/{$event->id}/importar/ocr/{$import->id}");
        }
        $filter = in_array($request->query('estado'), self::FILTERS, true) ? $request->query('estado') : null;

        return $this->page($event, $import, $filter, $request);
    }

    public function sheet(Request $request, string $id, string $importId): JsonResponse|RedirectResponse
    {
        $event = $this->findEvent($id);
        $import = $this->findImport($event, $importId);
        abort_if($import->isOcr(), 404);
        $this->assertEditable($import);
        $data = $request->validate(['hoja' => ['required', 'string', 'max:100']], ['hoja.required' => 'Elija la hoja del archivo.']);
        $this->limits();
        try {
            $this->imports->changeSheet($import, $event, $data['hoja'], $request->user());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['hoja' => $e->getMessage()]);
        }
        $import->refresh();

        return $this->done($request, 'Hoja «'.$import->sheet_name.'»: '.self::plural($import->total_rows, 'fila', 'filas').'. Revise las columnas.', [
            'hoja' => $import->sheet_name, 'filas' => $import->total_rows,
        ]);
    }

    public function mapping(ImportMappingRequest $request, string $id, string $importId): JsonResponse|RedirectResponse
    {
        $event = $this->findEvent($id);
        $import = $this->findImport($event, $importId);
        abort_if($import->isOcr(), 404);
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
            throw ValidationException::withMessages(['fila' => $import->isOcr() ? 'Primero termine de leer las páginas.' : 'Primero revise las columnas del archivo.']);
        }
        abort_unless(Str::isUuid($rowId), 404);
        $row = EventImportRow::where('import_id', $import->id)->findOrFail($rowId);

        $suggestion = $request->validated('sugerencia');
        try {
            $row = $this->imports->updateRow($import, $event, $row, $request->values(), $request->validated('decision'), $suggestion);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([$suggestion ? 'sugerencia' : 'decision' => $e->getMessage()]);
        }
        if ($suggestion) {
            // Only which row and the answer: never the name, phone or the suggested person.
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'EVENTO_IMPORTACION_SUGERENCIA',
                'entity_type' => 'evento_importacion',
                'entity_id' => $import->id,
                'metadata' => ['event_id' => $event->id, 'fila' => $row->row_number, 'resultado' => $suggestion],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }
        $message = match ($suggestion) {
            'confirmar' => 'Listo: la asistencia quedará en el historial de esa persona.',
            'rechazar' => 'Listo: queda solo en la lista del evento.',
            default => $row->errors ? 'Fila guardada: todavía tiene datos por corregir.' : 'Fila guardada.',
        };

        return $this->done($request, $message, [
            'fila' => self::rowProps($row, $this->suggestions(collect([$row]))), 'counts' => $import->fresh()->counts,
        ]);
    }

    public function confirm(ImportConfirmRequest $request, string $id, string $importId): JsonResponse|RedirectResponse
    {
        $event = $this->findEvent($id);
        $this->assertAcceptsImports($event);
        $import = $this->findImport($event, $importId);
        if ($import->status === 'subido') {
            throw ValidationException::withMessages(['confirmar' => $import->isOcr() ? 'Primero termine de leer las páginas.' : 'Primero revise las columnas del archivo.']);
        }
        $this->limits();

        try {
            $result = $this->imports->confirm($import, $event, $request->user(), $request->ip(), $request->userAgent());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['confirmar' => $e->getMessage()]);
        }
        if ($import->isOcr()) {
            app(OcrImportService::class)->afterConfirm($import);
        }

        $parts = array_filter([
            $result['historial'] ? self::plural($result['historial'], 'quedó', 'quedaron').' en el historial de su ficha' : null,
            $result['solo_evento'] ? self::plural($result['solo_evento'], 'quedó pendiente', 'quedaron pendientes').' de aprobar para pasar a Votantes' : null,
            $result['ya_estaban'] ? self::plural($result['ya_estaban'], 'ya estaba', 'ya estaban').' en el evento' : null,
        ]);
        $list = $result['historial'] + $result['solo_evento'];
        $message = match (true) {
            $result['nuevos'] === 0 && $result['ya_estaban'] === 0 => 'No había filas nuevas para registrar: este archivo ya estaba importado.',
            $list > 0 => 'Se registr'.($list === 1 ? 'ó ' : 'aron ').self::plural($list, 'asistente', 'asistentes').': '.implode('; ', $parts).'.',
            default => 'Se registr'.($result['nuevos'] === 1 ? 'ó ' : 'aron ').self::plural($result['nuevos'], 'asistente nuevo', 'asistentes nuevos')
                .($result['ya_estaban'] ? '; '.end($parts) : '').'.',
        };

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

        $back = $import->isOcr() ? "/eventos/{$event->id}/importar/ocr" : "/eventos/{$event->id}/importar";

        return redirect($back)->with('success', 'Carga descartada: no se registró a nadie.');
    }

    private function page(CampaignEvent $event, ?EventImport $import, ?string $filter, ?Request $request): Response
    {
        $filas = null;
        if ($import && $import->status !== 'subido') {
            $filas = EventImportRow::where('import_id', $import->id)
                ->when($filter, fn ($q, $f) => self::filterRows($q, $f))
                ->orderBy('row_number')
                ->paginate(self::ROWS_PER_PAGE)->withQueryString();
            $suggestions = $this->suggestions($filas->getCollection());
            $filas->through(fn (EventImportRow $r) => self::rowProps($r, $suggestions));
        }

        return Inertia::render('Eventos/Importar', [
            'evento' => self::eventProps($event),
            'importacion' => $import ? self::importProps($import) : null,
            'muestra' => $import && $import->status === 'subido' ? $this->sample($import) : [],
            'filas' => $filas,
            'filters' => ['estado' => $filter],
            'campos' => ImportColumns::fieldOptions(),
            'limites' => ['filas' => AttendeeSheetReader::MAX_ROWS, 'mb' => ImportUploadRequest::maxMb()],
            'recientes' => EventImport::where('event_id', $event->id)->where('kind', 'excel')->with('creator:id,name')->orderByDesc('created_at')->limit(10)->get()
                ->map(fn (EventImport $i) => [
                    'id' => $i->id, 'nombre' => $i->original_name, 'status' => $i->status, 'filas' => $i->total_rows,
                    'importadas' => (int) ($i->counts['importadas'] ?? 0), 'created_at' => self::iso($i->created_at),
                    'created_by' => $i->creator?->name,
                ])->values()->all(),
        ]);
    }

    public static function eventProps(CampaignEvent $event): array
    {
        return [
            'id' => $event->id,
            'nombre' => $event->nombre,
            'status' => $event->status,
            'starts_at' => $event->starts_at?->copy()->setTimezone(EventoController::TZ)->toIso8601String(),
            'municipio' => $event->municipio?->canonical_name,
            'campaign' => $event->campaign ? ['id' => $event->campaign->id, 'nombre' => $event->campaign->name] : null,
            'acepta_cargas' => $event->status !== 'cancelado',
        ];
    }

    public static function filterRows($query, string $filter)
    {
        return match ($filter) {
            'no_autorizo' => $query->where('status', 'no_autorizo'),
            'nuevas' => $query->where('status', 'valida')->whereNull('match_lider_id'),
            'plataforma' => $query->where('status', 'valida')->whereNotNull('match_lider_id'),
            'omitidas' => $query->whereIn('status', ['valida', 'sin_ficha'])->where('decision', 'omitir'),
            default => $query->where('status', $filter),
        };
    }

    public static function importProps(EventImport $import): array
    {
        return [
            'id' => $import->id,
            'tipo' => $import->kind,
            'nombre' => $import->original_name,
            'status' => $import->status,
            'filas' => $import->total_rows,
            'fila_encabezados' => $import->header_row,
            'encabezados' => $import->headers,
            'columnas' => $import->column_mapping,
            'counts' => $import->counts,
            'sin_autorizacion' => (int) ($import->counts['sin_autorizacion'] ?? 0) > 0,
            'hoja' => $import->sheet_name,
            'hojas' => $import->sheets ?? [],
            'datos_borrados' => $import->staging_purged_at !== null,
            'created_at' => self::iso($import->created_at),
            'confirmed_at' => self::iso($import->confirmed_at),
        ];
    }

    // The first rows as read, by column index, so the operator can recognise each column.
    private function sample(EventImport $import): array
    {
        return EventImportRow::where('import_id', $import->id)->orderBy('row_number')->limit(3)->get(['raw'])
            ->map(fn ($r) => array_map(fn ($v) => is_string($v) ? Str::limit($v, 40) : $v, $r->raw ?? []))->values()->all();
    }

    // An OCR row also carries its row on the printed page, the AI's confidence per field and, when it is not
    // importable for lack of authorization, why.
    // The ficha suggested by phone + name to each list row ("¿Es esta persona?"): name, municipio and cargo, only
    // of lideres of the event's campaign inside the user's territory. [lider id => props].
    private function suggestions($rows): array
    {
        $ids = collect($rows)->pluck('suggested_lider_id')->filter()->unique()->values()->all();
        if ($ids === [] || ! EventImportService::canSeeSuggestions()) {
            return [];
        }

        return $this->scopeToTerritory(DB::table('lideres')->whereIn('id', $ids))
            ->get(['id', 'nombre', 'municipio', 'cargo'])
            ->mapWithKeys(fn ($l) => [$l->id => ['id' => $l->id, 'nombre' => $l->nombre, 'municipio' => $l->municipio, 'cargo' => $l->cargo]])
            ->all();
    }

    // $suggestions: [lider id => props] of the suggested fichas (list rows without authorization).
    public static function rowProps(EventImportRow $r, array $suggestions = []): array
    {
        $props = [
            'id' => $r->id,
            'fila' => $r->row_number,
            'status' => $r->status,
            'decision' => $r->decision,
            'en_plataforma' => $r->match_lider_id !== null,
            'importada' => $r->attendee_id !== null,
            'corregida' => $r->edited,
            'valores' => $r->mapped ?? [],
            'errores' => $r->errors ?? (object) [],
            'sin_autorizacion' => (bool) $r->sin_autorizacion,
            'sugerencia' => $r->suggested_lider_id && isset($suggestions[$r->suggested_lider_id])
                ? $suggestions[$r->suggested_lider_id] + ['estado' => $r->suggestion]
                : null,
        ];
        if ($r->page_id !== null) {
            $props['fila_pagina'] = $r->page_row;
            $props['confianza'] = $r->confidence ?? (object) [];
        }

        return $props;
    }

    private function findEvent(string $id): CampaignEvent
    {
        abort_unless(Str::isUuid($id), 404);
        $event = CampaignEvent::with(['municipio', 'campaign'])->findOrFail($id);
        $this->authorizeCampaign($event->campaign_id, true);
        $this->authorizeGeo($event->municipio_id);

        return $event;
    }

    // An OCR load answers 404 while the global switch is off and 403 while its campaign's is off.
    private function findImport(CampaignEvent $event, string $importId): EventImport
    {
        abort_unless(Str::isUuid($importId), 404);
        $import = EventImport::where('event_id', $event->id)->findOrFail($importId);
        if ($import->isOcr()) {
            abort_unless(OcrSettings::enabled(), 404);
            abort_unless(OcrSettings::enabledFor($event->campaign_id), 403, OcrSettings::DISABLED_FOR_CAMPAIGN);
        }

        return $import;
    }

    private function assertAcceptsImports(CampaignEvent $event): void
    {
        if ($event->status === 'cancelado') {
            throw ValidationException::withMessages(['evento' => 'El evento está cancelado: no se pueden cargar asistentes.']);
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
        self::raiseMemoryLimit();
        set_time_limit(120);
    }

    // 256M unless PHP already allows more (never lowers it).
    public static function raiseMemoryLimit(): void
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

    private static function iso($date): ?string
    {
        return $date ? Carbon::parse($date)->setTimezone(EventoController::TZ)->toIso8601String() : null;
    }
}
