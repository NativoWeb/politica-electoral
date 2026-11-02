<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Http\Requests\Eventos\OcrUploadRequest;
use App\Models\CampaignEvent;
use App\Models\ConsentText;
use App\Models\EventImport;
use App\Models\EventImportPage;
use App\Models\EventImportRow;
use App\Services\Eventos\Ocr\OcrException;
use App\Services\Eventos\Ocr\OcrImportService;
use App\Services\Eventos\Ocr\OcrSettings;
use App\Services\Eventos\Ocr\PlanillaPrompt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Eventos especiales, phase 5: photos of the printed sign-in sheet read with AI (contract in docs/eventos-api.md §13).
// Route middleware: eventos.ocr (global switch, 404) and manage.eventos. Here: own campaign (403 for a visible
// subcampaign, 404 for an invisible one), territory (403) and the campaign switch campaigns.ocr_enabled (403). The
// review, the corrections, the confirmation and the discard are the Excel routes (EventoImportController), which apply
// the same switches to OCR loads.
class EventoOcrController extends Controller
{
    use ScopesTerritory;

    public const NEEDS_APPROVED_TEXT = 'Falta el texto de autorización aprobado por la campaña; no se pueden leer planillas hasta tenerlo.';

    public function __construct(private OcrImportService $ocr) {}

    public function index(string $id): Response
    {
        $event = $this->findEvent($id);

        return $this->page($event, null, null, null);
    }

    public function show(Request $request, string $id, string $importId): Response
    {
        $event = $this->findEvent($id);
        $import = $this->findImport($event, $importId);
        $filter = in_array($request->query('estado'), EventoImportController::FILTERS, true) ? $request->query('estado') : null;
        $page = filter_var($request->query('pagina'), FILTER_VALIDATE_INT) ?: null;

        return $this->page($event, $import, $filter, $page);
    }

    public function store(OcrUploadRequest $request, string $id): JsonResponse
    {
        $event = $this->findEvent($id);
        $this->assertCanLoad($event);
        $import = null;
        if ($request->filled('importacion')) {
            $import = $this->findImport($event, (string) $request->input('importacion'));
            if ($import->status !== 'subido' || EventImportPage::where('import_id', $import->id)->where('status', '!=', 'pendiente')->exists()) {
                throw ValidationException::withMessages(['foto' => 'Esta carga ya se empezó a leer: haga una carga nueva con estas fotos.']);
            }
        }
        $this->limits();

        try {
            $result = $this->ocr->addFile($event, $import, $request->file('foto'), $request->user());
        } catch (OcrException $e) {
            throw ValidationException::withMessages(['foto' => $e->getMessage()]);
        }
        $total = EventImportPage::where('import_id', $result['import']->id)->count();

        return response()->json([
            'success' => true,
            'message' => $result['pages'] === 1 ? 'Página recibida.' : "Se recibieron {$result['pages']} páginas.",
            'data' => ['id' => $result['import']->id, 'paginas' => $result['pages'], 'total_paginas' => $total],
        ], 201);
    }

    public function process(Request $request, string $id, string $importId): JsonResponse
    {
        $event = $this->findEvent($id);
        $import = $this->findImport($event, $importId);
        if ($import->status === 'subido') {
            $this->assertCanLoad($event);
        }
        $this->limits();

        if ($request->boolean('reintentar')) {
            $this->ocr->retryErrors($import);
        }
        $result = $this->ocr->processNext($import, $event, $request->user(), $request->boolean('continuar'));
        $progress = $result['progress'];

        $message = match (true) {
            $result['busy'] => 'Se está leyendo otra página de esta carga. Espere un momento.',
            $result['error'] !== null => $result['error'],
            $progress['listo'] && $progress['procesadas'] === 0 => 'No se pudo leer ninguna página: revise el aviso de cada página. Nada se registró.',
            $progress['listo'] && $progress['filas'] === 0 => 'Se leyeron las páginas pero no tienen filas escritas. Nada se registró.',
            $progress['listo'] => 'Listo: revise las filas que leyó la IA antes de importarlas.',
            default => "Página leída. Faltan {$progress['pendientes']}.",
        };

        return response()->json([
            'success' => $result['error'] === null,
            'message' => $message,
            'data' => $progress + ['ocupado' => $result['busy']],
        ]);
    }

    // The photo (or PDF) of one page, only through this route (private disk, no public URL), with the same checks.
    // A photo goes with CSP sandbox; a PDF does not, because Chrome refuses to render a PDF under CSP sandbox (its
    // content was checked as a real PDF at upload, and it is served with nosniff and no-store).
    public function file(string $id, string $importId, string $pageId): StreamedResponse
    {
        $event = $this->findEvent($id);
        $import = $this->findImport($event, $importId);
        abort_unless(Str::isUuid($pageId), 404);
        $page = EventImportPage::where('import_id', $import->id)->findOrFail($pageId);
        $disk = Storage::disk(OcrImportService::DISK);
        abort_unless($disk->exists($page->file_path), 404);

        return $disk->response($page->file_path, 'pagina-'.$page->sequence.'.'.pathinfo($page->file_path, PATHINFO_EXTENSION), [
            'Content-Type' => $page->mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ] + ($page->isPdf() ? [] : ['Content-Security-Policy' => 'sandbox']), 'inline');
    }

    private function page(CampaignEvent $event, ?EventImport $import, ?string $filter, ?int $current): Response
    {
        $consent = ConsentText::currentFor($event->campaign_id);
        $pages = $import ? EventImportPage::where('import_id', $import->id)->orderBy('sequence')->get() : collect();
        $doubtful = $import ? $this->doubtfulByPage($import) : [];
        $current = $pages->firstWhere('sequence', $current) ?? $pages->firstWhere('status', 'procesada') ?? $pages->first();

        $filas = null;
        if ($import && $import->status !== 'subido' && $current) {
            $filas = EventImportRow::where('import_id', $import->id)->where('page_id', $current->id)
                ->when($filter, fn ($q, $f) => EventoImportController::filterRows($q, $f))
                ->orderBy('row_number')
                ->paginate(PlanillaPrompt::ROWS)->withQueryString()
                ->through(fn (EventImportRow $r) => EventoImportController::rowProps($r));
        }

        return Inertia::render('Eventos/Planillas', [
            'evento' => EventoImportController::eventProps($event),
            'consent' => $consent ? ['id' => $consent->id, 'version' => $consent->version, 'approved' => $consent->approved] : null,
            'ocr' => [
                'listo' => OcrSettings::hasKey(),
                'aviso' => OcrSettings::hasKey() ? null : OcrSettings::MISSING_KEY,
                'umbral' => OcrSettings::threshold(),
                'limites' => ['mb' => OcrUploadRequest::maxMb(), 'paginas' => (int) config('eventos.ocr.max_pages')],
            ],
            'importacion' => $import ? EventoImportController::importProps($import) + ['progreso' => $this->ocr->progress($import)] : null,
            'paginas' => $pages->map(fn (EventImportPage $p) => [
                'id' => $p->id,
                'numero' => $p->sequence,
                'nombre' => $p->original_name,
                'pdf' => $p->isPdf(),
                'pagina_pdf' => $p->page_in_file,
                'pagina_impresa' => $p->printed_page,
                'status' => $p->status,
                'mensaje' => $p->message,
                'filas' => $p->rows_found,
                'dudosas' => $doubtful[$p->id] ?? 0,
                'archivo' => "/eventos/{$event->id}/importar/ocr/{$import->id}/paginas/{$p->id}/archivo",
            ])->values()->all(),
            'pagina_actual' => $current?->sequence,
            'filas' => $filas,
            'filters' => ['estado' => $filter],
            'recientes' => EventImport::where('event_id', $event->id)->where('kind', 'ocr')->with('creator:id,name')
                ->orderByDesc('created_at')->limit(10)->get()
                ->map(fn (EventImport $i) => [
                    'id' => $i->id, 'nombre' => $i->original_name, 'status' => $i->status, 'filas' => $i->total_rows,
                    'importadas' => (int) ($i->counts['importadas'] ?? 0),
                    'paginas' => EventImportPage::where('import_id', $i->id)->count(),
                    'created_at' => $i->created_at?->copy()->setTimezone(EventoController::TZ)->toIso8601String(),
                    'created_by' => $i->creator?->name,
                ])->values()->all(),
        ]);
    }

    // [page id => rows with at least one cell below the threshold] among the rows a person can still act on: not
    // imported and not "no autorizó" (those cannot be corrected, so they are not "to review").
    private function doubtfulByPage(EventImport $import): array
    {
        $threshold = OcrSettings::threshold();
        $counts = [];
        EventImportRow::where('import_id', $import->id)->whereNotNull('confidence')->whereNull('attendee_id')->where('status', '!=', 'no_autorizo')
            ->get(['page_id', 'confidence'])
            ->each(function (EventImportRow $r) use ($threshold, &$counts) {
                $low = array_filter($r->confidence ?? [], fn ($c) => is_numeric($c) && $c < $threshold);
                if ($low !== []) {
                    $counts[$r->page_id] = ($counts[$r->page_id] ?? 0) + 1;
                }
            });

        return $counts;
    }

    private function findEvent(string $id): CampaignEvent
    {
        abort_unless(Str::isUuid($id), 404);
        $event = CampaignEvent::with(['municipio', 'campaign'])->findOrFail($id);
        $this->authorizeCampaign($event->campaign_id, true);
        $this->authorizeGeo($event->municipio_id);
        if (! OcrSettings::enabledFor($event->campaign_id)) {
            abort(403, OcrSettings::DISABLED_FOR_CAMPAIGN);
        }

        return $event;
    }

    private function findImport(CampaignEvent $event, string $importId): EventImport
    {
        abort_unless(Str::isUuid($importId), 404);

        return EventImport::where('event_id', $event->id)->where('kind', 'ocr')->findOrFail($importId);
    }

    // Uploading and reading need what the Excel load needs (event not cancelled, approved text) plus the API key.
    private function assertCanLoad(CampaignEvent $event): void
    {
        if ($event->status === 'cancelado') {
            throw ValidationException::withMessages(['evento' => 'El evento está cancelado: no se pueden cargar asistentes.']);
        }
        if (! ConsentText::currentFor($event->campaign_id)?->approved) {
            throw ValidationException::withMessages(['consent' => self::NEEDS_APPROVED_TEXT]);
        }
        if (! OcrSettings::hasKey()) {
            throw ValidationException::withMessages(['ocr' => OcrSettings::MISSING_KEY]);
        }
    }

    // One page per request: the API call plus the QR decoding of a 1600 px copy (≈100 MB).
    private function limits(): void
    {
        EventoImportController::raiseMemoryLimit();
        set_time_limit((int) config('eventos.ocr.timeout') + 60);
    }
}
