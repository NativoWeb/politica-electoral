<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Http\Requests\Eventos\ActaFileRequest;
use App\Http\Requests\Eventos\ActaRequest;
use App\Http\Requests\Eventos\ActaSuggestRequest;
use App\Models\AuditLog;
use App\Models\CampaignEvent;
use App\Models\EventActa;
use App\Models\EventAttendee;
use App\Models\EventCompromiso;
use App\Services\Eventos\Actas\ActaDocxReader;
use App\Services\Eventos\Actas\ActaDocxWriter;
use App\Services\Eventos\Actas\ActaFileException;
use App\Services\Eventos\Actas\ActaFiles;
use App\Services\Eventos\Actas\ActaFormat;
use App\Services\Eventos\Actas\ActaService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

// "Acta de reunión" of an event (docs/eventos-api.md §14): seen with view.eventos, written with manage.eventos in the
// own campaign and territory (route middleware + findEvent/AuthorizesEventActa).
class EventoActaController extends Controller
{
    use ScopesTerritory;

    public const ASISTENTES_LIMIT = 3000;

    public function __construct(private ActaService $service) {}

    // GET /eventos/{id}/actas
    public function index(string $id): JsonResponse
    {
        $event = $this->findEvent($id);
        $actas = EventActa::where('event_id', $event->id)->with('creator:id,name')
            ->withCount(['compromisos', 'compromisos as abiertos_count' => fn ($q) => $q->whereIn('estado', EventCompromiso::ABIERTOS)])
            ->orderByDesc('created_at')->get()
            ->map(fn (EventActa $a) => [
                'id' => $a->id,
                'titulo' => $a->titulo,
                'fecha' => $a->fecha?->toDateString(),
                'compromisos' => (int) $a->compromisos_count,
                'abiertos' => (int) $a->abiertos_count,
                'archivo' => $a->archivo_path !== null,
                'estado' => $a->estado,
                'created_by' => $a->creator?->name,
            ]);

        return response()->json(['success' => true, 'data' => $actas]);
    }

    // GET /eventos/{id}/actas/nueva
    public function create(string $id): Response
    {
        $event = $this->findEvent($id, true);

        return $this->page($event, null);
    }

    // GET /eventos/{id}/actas/{acta}
    public function show(string $id, string $acta): Response
    {
        $event = $this->findEvent($id);

        return $this->page($event, $this->findActa($event, $acta));
    }

    // POST /eventos/{id}/actas/leer: prefill from a .docx; a PDF or a photo is only attached later (formulario a mano).
    public function read(ActaFileRequest $request, string $id, ActaDocxReader $reader): JsonResponse
    {
        $file = $request->file('archivo');
        try {
            $mime = ActaFiles::mime($file);
            if ($mime !== ActaFiles::DOCX) {
                return response()->json(['success' => true, 'data' => ['tipo' => 'adjunto', 'acta' => null,
                    'message' => 'Se guardará como archivo adjunto del acta. Llene el formulario a mano.']]);
            }
            $acta = $reader->read((string) $file->getRealPath());
        } catch (ActaFileException $e) {
            throw ValidationException::withMessages(['archivo' => $e->getMessage()]);
        }

        return response()->json(['success' => true, 'data' => ['tipo' => 'docx', 'acta' => $acta,
            'message' => 'Revise lo que se leyó del documento antes de guardar.']]);
    }

    // POST /eventos/{id}/actas/sugerencias
    public function suggest(ActaSuggestRequest $request, string $id): JsonResponse
    {
        $event = $this->findEvent($id, true);
        $data = $request->validated();

        return response()->json(['success' => true, 'data' => $this->service->suggest(
            $event, $data['participantes'] ?? [], $data['responsables'] ?? [],
        )]);
    }

    // POST /eventos/{id}/actas
    public function store(ActaRequest $request, string $id): JsonResponse
    {
        $event = $this->writableEvent($id);
        $acta = $this->service->save($event, null, $request->validated(), $request->user());
        $this->audit($request, 'EVENTO_ACTA_CREADA', $acta, $this->sizes($acta));

        return response()->json(['success' => true, 'message' => 'Borrador guardado.', 'data' => $this->payload($acta)], 201);
    }

    // PUT /eventos/{id}/actas/{acta}
    public function update(ActaRequest $request, string $id, string $acta): JsonResponse
    {
        $event = $this->writableEvent($id);
        $model = $this->findActa($event, $acta);
        $model = $this->service->save($event, $model, $request->validated(), $request->user());
        $this->audit($request, 'EVENTO_ACTA_ACTUALIZADA', $model, $this->sizes($model));

        return response()->json(['success' => true, 'message' => 'Borrador guardado.', 'data' => $this->payload($model)]);
    }

    // POST /eventos/{id}/actas/{acta}/confirmar: writes Votantes and the attendees; the acta becomes read-only.
    public function confirm(Request $request, string $id, string $acta): JsonResponse
    {
        $event = $this->writableEvent($id);
        $model = $this->findActa($event, $acta);
        $counts = $this->service->confirm($event, $model, $request->user());
        $model->refresh();
        $this->audit($request, 'EVENTO_ACTA_CONFIRMADA', $model, $counts);
        Cache::forget(EventoCompromisoController::alertsKey($request));

        return response()->json(['success' => true, 'message' => self::summary($counts), 'data' => $this->payload($model), 'resumen' => $counts]);
    }

    // DELETE /eventos/{id}/actas/{acta}: only a borrador (nothing of it reached Votantes or the attendees).
    public function destroy(Request $request, string $id, string $acta): JsonResponse
    {
        $event = $this->findEvent($id, true);
        $model = $this->findActa($event, $acta);
        $this->service->delete($model);
        $this->audit($request, 'EVENTO_ACTA_BORRADA', $model, []);

        return response()->json(['success' => true, 'message' => 'Borrador del acta borrado.']);
    }

    // POST /eventos/{id}/actas/{acta}/archivo: the original (.docx, PDF or photo), replacing the previous one.
    public function attach(ActaFileRequest $request, string $id, string $acta): JsonResponse
    {
        $event = $this->writableEvent($id);
        $model = $this->findActa($event, $acta);
        ActaService::assertDraft($model);
        try {
            [$path, $mime, $name] = ActaFiles::store($request->file('archivo'), "eventos/actas/{$event->id}");
        } catch (ActaFileException $e) {
            throw ValidationException::withMessages(['archivo' => $e->getMessage()]);
        }
        $old = $model->archivo_path;
        $model->forceFill(['archivo_path' => $path, 'archivo_mime' => $mime, 'archivo_nombre' => $name, 'updated_by' => $request->user()->id])->save();
        ActaFiles::delete($old);
        $this->audit($request, 'EVENTO_ACTA_ARCHIVO', $model, ['tipo' => ActaFiles::EXTENSIONS[$mime], 'reemplazo' => $old !== null]);

        return response()->json(['success' => true, 'message' => 'Archivo del acta guardado.', 'data' => $this->payload($model)]);
    }

    // GET /eventos/{id}/actas/{acta}/archivo
    public function file(Request $request, string $id, string $acta): StreamedResponse
    {
        $event = $this->findEvent($id);
        $model = $this->findActa($event, $acta);
        abort_unless($model->archivo_path && Storage::disk(ActaFiles::DISK)->exists($model->archivo_path), 404);
        $this->audit($request, 'EVENTO_ACTA_DESCARGADA', $model, ['formato' => 'original']);

        return Storage::disk(ActaFiles::DISK)->download($model->archivo_path, ActaFiles::downloadName($model->archivo_nombre, $model->archivo_mime),
            ['Content-Type' => $model->archivo_mime, 'X-Content-Type-Options' => 'nosniff']);
    }

    // GET /eventos/{id}/actas/{acta}/acta.docx
    public function word(Request $request, string $id, string $acta, ActaDocxWriter $writer): BinaryFileResponse
    {
        $event = $this->findEvent($id);
        $model = $this->findActa($event, $acta);
        $path = $writer->build($this->payload($model));
        $this->audit($request, 'EVENTO_ACTA_DESCARGADA', $model, ['formato' => 'docx']);

        return response()->download($path, $this->filename($model, 'docx'), ['Content-Type' => ActaFiles::DOCX])->deleteFileAfterSend();
    }

    // GET /eventos/{id}/actas/{acta}/acta.pdf
    public function pdf(Request $request, string $id, string $acta): HttpResponse
    {
        $event = $this->findEvent($id);
        $model = $this->findActa($event, $acta);
        $this->audit($request, 'EVENTO_ACTA_DESCARGADA', $model, ['formato' => 'pdf']);

        return Pdf::loadView('eventos.acta', ['acta' => $this->payload($model)])->setPaper('letter')
            ->download($this->filename($model, 'pdf'));
    }

    public function payload(EventActa $acta): array
    {
        $acta->loadMissing(['participantes', 'compromisos.responsables', 'creator:id,name', 'confirmer:id,name']);
        $acta->compromisos->loadCount('avances');

        return [
            'id' => $acta->id,
            'event_id' => $acta->event_id,
            'titulo' => $acta->titulo,
            'fecha' => $acta->fecha?->toDateString(),
            'municipio' => $acta->municipio,
            'sitio' => $acta->sitio,
            'hora_inicio' => $acta->hora_inicio,
            'hora_fin' => $acta->hora_fin,
            'introduccion' => $acta->introduccion,
            'orden_dia' => $acta->orden_dia ?? [],
            'desarrollo' => $acta->desarrollo ?? [],
            'cierre' => $acta->cierre,
            'estado' => $acta->estado,
            'confirmed_at' => $acta->confirmed_at?->copy()->setTimezone(EventoController::TZ)->toIso8601String(),
            'confirmed_by' => $acta->confirmer?->name,
            'archivo' => $acta->archivo_path ? ['nombre' => ActaFiles::downloadName($acta->archivo_nombre, $acta->archivo_mime), 'tipo' => ActaFiles::EXTENSIONS[$acta->archivo_mime] ?? null] : null,
            'participantes' => $acta->participantes->map(fn ($p) => $p->only(['id', 'nombre', 'perfil', 'municipio', 'telefono', 'cedula', 'lider_id', 'attendee_id', 'sugerencia_rechazada']))->values()->all(),
            'compromisos' => $acta->compromisos->map(fn (EventCompromiso $c) => [
                'id' => $c->id,
                'descripcion' => $c->descripcion,
                'fecha_limite' => $c->fecha_limite?->toDateString(),
                'recordar_el' => $c->recordar_el?->toDateString(),
                'estado' => $c->estado,
                'vencido' => $c->vencido(),
                'avances' => (int) $c->avances_count,
                'responsables' => $c->responsables->map(fn ($r) => $r->only(['id', 'nombre', 'telefono', 'lider_id', 'sugerencia_rechazada']))->values()->all(),
            ])->values()->all(),
            'created_by' => $acta->creator?->name,
            'created_at' => $acta->created_at?->copy()->setTimezone(EventoController::TZ)->toIso8601String(),
        ];
    }

    private function page(CampaignEvent $event, ?EventActa $acta): Response
    {
        $editable = (bool) request()->user()?->hasPermission('manage.eventos') && $this->campaign()->isOwn($event->campaign_id);

        return Inertia::render('Eventos/Acta', [
            'evento' => [
                'id' => $event->id,
                'nombre' => $event->nombre,
                'status' => $event->status,
                'campaign' => $event->campaign ? ['id' => $event->campaign->id, 'nombre' => $event->campaign->name] : null,
                'municipio' => $event->municipio?->canonical_name,
            ],
            'acta' => $acta ? $this->payload($acta) : null,
            'defaults' => $acta ? null : $this->defaults($event),
            'asistentes' => $editable && (! $acta || $acta->isDraft()) ? $this->attendees($event) : [],
            'can' => [
                'edit' => $editable && $event->status !== 'cancelado' && (! $acta || $acta->isDraft()),
                'delete' => $editable && $acta !== null && $acta->isDraft(),
            ],
        ]);
    }

    // What the form starts with: the event's data.
    private function defaults(CampaignEvent $event): array
    {
        $start = Carbon::parse($event->starts_at)->setTimezone(EventoController::TZ);
        $end = $event->ends_at ? Carbon::parse($event->ends_at)->setTimezone(EventoController::TZ) : null;
        $municipio = $event->municipio?->canonical_name;

        return [
            'titulo' => Str::limit(Str::upper('Acta reunión '.$event->nombre), 255, ''),
            'fecha' => $start->toDateString(),
            'municipio' => $municipio ? Str::upper($municipio) : null,
            'sitio' => $event->sitio_encuentro,
            'hora_inicio' => $start->format('H:i'),
            'hora_fin' => $end?->format('H:i'),
            'cierre' => $end ? 'Siendo las '.ActaFormat::hour($end->format('H:i')).', se dio por terminada la reunión.' : null,
        ];
    }

    // The event's attendees, to add them as participants with one click.
    private function attendees(CampaignEvent $event): array
    {
        return EventAttendee::where('event_id', $event->id)->orderBy('nombres')->orderBy('apellidos')
            ->limit(self::ASISTENTES_LIMIT)->get(['id', 'nombres', 'apellidos', 'cedula', 'celular', 'lider_id'])
            ->map(fn ($a) => [
                'id' => $a->id,
                'nombre' => trim($a->nombres.' '.$a->apellidos),
                'cedula' => $a->cedula,
                'telefono' => $a->celular,
                'lider_id' => $a->lider_id,
            ])->all();
    }

    private function findEvent(string $id, bool $write = false): CampaignEvent
    {
        abort_unless(Str::isUuid($id), 404);
        $event = CampaignEvent::with(['municipio', 'campaign'])->findOrFail($id);
        $this->authorizeCampaign($event->campaign_id, $write);
        $this->authorizeGeo($event->municipio_id);

        return $event;
    }

    private function writableEvent(string $id): CampaignEvent
    {
        $event = $this->findEvent($id, true);
        if ($event->status === 'cancelado') {
            throw ValidationException::withMessages(['evento' => 'El evento está cancelado: sus actas ya no se pueden cambiar.']);
        }

        return $event;
    }

    private function findActa(CampaignEvent $event, string $acta): EventActa
    {
        abort_unless(Str::isUuid($acta), 404);

        return EventActa::where('event_id', $event->id)->findOrFail($acta);
    }

    private function filename(EventActa $acta, string $extension): string
    {
        return 'acta-'.(Str::slug(Str::limit($acta->titulo, 60, '')) ?: 'reunion').'.'.$extension;
    }

    private function sizes(EventActa $acta): array
    {
        return ['participantes' => $acta->participantes()->count(), 'compromisos' => $acta->compromisos()->count()];
    }

    public static function summary(array $counts): string
    {
        $parts = ['Acta confirmada.'];
        if ($counts['participantes'] > 0) {
            $parts[] = "{$counts['participantes']} participantes: {$counts['vinculados']} ya estaban en Votantes, {$counts['fichas_creadas']} fichas nuevas, {$counts['asistentes_nuevos']} agregados como asistentes del evento.";
        }
        if ($counts['compromisos'] > 0) {
            $parts[] = "{$counts['compromisos']} compromisos.";
        }

        return implode(' ', $parts);
    }

    // Counts and ids only: never names, phones or what the acta says.
    private function audit(Request $request, string $action, EventActa $acta, array $metadata): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'evento',
            'entity_id' => $acta->event_id,
            'metadata' => ['acta_id' => $acta->id, ...$metadata],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
