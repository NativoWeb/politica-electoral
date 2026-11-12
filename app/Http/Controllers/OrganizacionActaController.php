<?php

namespace App\Http\Controllers;

use App\Actions\Eventos\DeleteConfirmedActa;
use App\Http\Requests\Empresarios\OrganizacionActaFileRequest;
use App\Http\Requests\Empresarios\OrganizacionActaRequest;
use App\Http\Requests\Empresarios\OrganizacionActaSuggestRequest;
use App\Models\AuditLog;
use App\Models\EventActa;
use App\Models\EventCompromiso;
use App\Models\Organizacion;
use App\Services\Empresarios\OrganizacionScope;
use App\Services\Eventos\Actas\ActaDocxReader;
use App\Services\Eventos\Actas\ActaDocxWriter;
use App\Services\Eventos\Actas\ActaFileException;
use App\Services\Eventos\Actas\ActaFiles;
use App\Services\Eventos\Actas\ActaPresenter;
use App\Services\Eventos\Actas\ActaService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Actas de reunión of an organization (docs/empresarios.md): the same module as the events' (§14 of eventos-api.md):
// borrador → confirmada, Word/PDF, .docx reading, compromisos. Seen with view.empresarios, written with
// manage.empresarios in the own campaign and territory. Confirming makes every participant a ficha in Votantes and,
// if it was not one, a member of the organization (rol 'otro'); there are no attendees.
class OrganizacionActaController extends Controller
{
    public function __construct(private ActaService $service, private OrganizacionScope $scope) {}

    // GET /empresarios/{id}/actas
    public function index(string $id): JsonResponse
    {
        $org = $this->scope->find($id);
        $actas = EventActa::where('organizacion_id', $org->id)->with('creator:id,name')
            ->withCount(['compromisos', 'compromisos as abiertos_count' => fn ($q) => $q->whereIn('estado', EventCompromiso::ABIERTOS)])
            ->orderByDesc('created_at')->get()
            ->map(fn (EventActa $a) => [
                'id' => $a->id, 'titulo' => $a->titulo, 'fecha' => $a->fecha?->toDateString(),
                'compromisos' => (int) $a->compromisos_count, 'abiertos' => (int) $a->abiertos_count,
                'archivo' => $a->archivo_path !== null, 'estado' => $a->estado, 'created_by' => $a->creator?->name,
            ]);

        return response()->json(['success' => true, 'data' => $actas]);
    }

    // GET /empresarios/{id}/actas/nueva
    public function create(string $id): Response
    {
        return $this->page($this->scope->find($id, true), null);
    }

    // GET /empresarios/{id}/actas/{acta}
    public function show(string $id, string $acta): Response
    {
        $org = $this->scope->find($id);

        return $this->page($org, $this->findActa($org, $acta));
    }

    // POST /empresarios/{id}/actas/leer
    public function read(OrganizacionActaFileRequest $request, string $id, ActaDocxReader $reader): JsonResponse
    {
        $file = $request->file('archivo');
        try {
            if (ActaFiles::mime($file) !== ActaFiles::DOCX) {
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

    // POST /empresarios/{id}/actas/sugerencias
    public function suggest(OrganizacionActaSuggestRequest $request, string $id): JsonResponse
    {
        $org = $this->scope->find($id, true);
        $data = $request->validated();

        return response()->json(['success' => true, 'data' => $this->service->suggest($org, $data['participantes'] ?? [], $data['responsables'] ?? [])]);
    }

    // POST /empresarios/{id}/actas
    public function store(OrganizacionActaRequest $request, string $id): JsonResponse
    {
        $org = $this->scope->find($id, true);
        $acta = $this->service->save($org, null, $request->validated(), $request->user());
        $this->audit($request, 'ORGANIZACION_ACTA_CREADA', $acta, $this->sizes($acta));

        return response()->json(['success' => true, 'message' => 'Borrador guardado.', 'data' => ActaPresenter::payload($acta)], 201);
    }

    // PUT /empresarios/{id}/actas/{acta}
    public function update(OrganizacionActaRequest $request, string $id, string $acta): JsonResponse
    {
        $org = $this->scope->find($id, true);
        $model = $this->service->save($org, $this->findActa($org, $acta), $request->validated(), $request->user());
        $this->audit($request, 'ORGANIZACION_ACTA_ACTUALIZADA', $model, $this->sizes($model));

        return response()->json(['success' => true, 'message' => 'Borrador guardado.', 'data' => ActaPresenter::payload($model)]);
    }

    // POST /empresarios/{id}/actas/{acta}/confirmar
    public function confirm(Request $request, string $id, string $acta): JsonResponse
    {
        $org = $this->scope->find($id, true);
        $model = $this->findActa($org, $acta);
        $counts = $this->service->confirm($org, $model, $request->user());
        $model->refresh();
        $this->audit($request, 'ORGANIZACION_ACTA_CONFIRMADA', $model, $counts);
        Cache::forget(EventoCompromisoController::alertsKey($request));

        return response()->json(['success' => true, 'message' => self::summary($counts), 'data' => ActaPresenter::payload($model), 'resumen' => $counts]);
    }

    // DELETE /empresarios/{id}/actas/{acta}: a borrador with manage.empresarios; a confirmed one also needs delete.actas.
    public function destroy(Request $request, string $id, string $acta, DeleteConfirmedActa $undo): JsonResponse
    {
        $org = $this->scope->find($id, true);
        $model = $this->findActa($org, $acta);
        if (! $model->isDraft()) {
            abort_unless($request->user()->hasPermission('delete.actas'), 403, 'No tiene permiso para eliminar actas confirmadas.');
            $counts = $undo->handle($model, $request->user());
            Cache::forget(EventoCompromisoController::alertsKey($request));

            return response()->json(['success' => true, 'message' => self::deletedSummary($counts), 'data' => $counts]);
        }
        $this->service->delete($model);
        $this->audit($request, 'ORGANIZACION_ACTA_BORRADA', $model, []);

        return response()->json(['success' => true, 'message' => 'Borrador del acta borrado.']);
    }

    // GET /empresarios/{id}/actas/{acta}/eliminar
    public function deletePreview(string $id, string $acta, DeleteConfirmedActa $undo): JsonResponse
    {
        $org = $this->scope->find($id, true);
        $model = $this->findActa($org, $acta);
        if ($model->isDraft()) {
            throw ValidationException::withMessages(['acta' => 'El acta no está confirmada: se borra como borrador.']);
        }

        return response()->json(['success' => true, 'data' => collect($undo->plan($model))->except(['attendee_ids', 'lider_ids', 'miembro_ids'])->all()]);
    }

    // POST /empresarios/{id}/actas/{acta}/archivo
    public function attach(OrganizacionActaFileRequest $request, string $id, string $acta): JsonResponse
    {
        $org = $this->scope->find($id, true);
        $model = $this->findActa($org, $acta);
        ActaService::assertDraft($model);
        try {
            [$path, $mime, $name] = ActaFiles::store($request->file('archivo'), "empresarios/actas/{$org->id}");
        } catch (ActaFileException $e) {
            throw ValidationException::withMessages(['archivo' => $e->getMessage()]);
        }
        $old = $model->archivo_path;
        $model->forceFill(['archivo_path' => $path, 'archivo_mime' => $mime, 'archivo_nombre' => $name, 'updated_by' => $request->user()->id])->save();
        ActaFiles::delete($old);
        $this->audit($request, 'ORGANIZACION_ACTA_ARCHIVO', $model, ['tipo' => ActaFiles::EXTENSIONS[$mime], 'reemplazo' => $old !== null]);

        return response()->json(['success' => true, 'message' => 'Archivo del acta guardado.', 'data' => ActaPresenter::payload($model)]);
    }

    // GET /empresarios/{id}/actas/{acta}/archivo
    public function file(Request $request, string $id, string $acta): StreamedResponse
    {
        $org = $this->scope->find($id);
        $model = $this->findActa($org, $acta);
        abort_unless($model->archivo_path && Storage::disk(ActaFiles::DISK)->exists($model->archivo_path), 404);
        $this->audit($request, 'ORGANIZACION_ACTA_DESCARGADA', $model, ['formato' => 'original']);

        return Storage::disk(ActaFiles::DISK)->download($model->archivo_path, ActaFiles::downloadName($model->archivo_nombre, $model->archivo_mime),
            ['Content-Type' => $model->archivo_mime, 'X-Content-Type-Options' => 'nosniff']);
    }

    // GET /empresarios/{id}/actas/{acta}/acta.docx
    public function word(Request $request, string $id, string $acta, ActaDocxWriter $writer): BinaryFileResponse
    {
        $org = $this->scope->find($id);
        $model = $this->findActa($org, $acta);
        $path = $writer->build(ActaPresenter::payload($model));
        $this->audit($request, 'ORGANIZACION_ACTA_DESCARGADA', $model, ['formato' => 'docx']);

        return response()->download($path, $this->filename($model, 'docx'), ['Content-Type' => ActaFiles::DOCX])->deleteFileAfterSend();
    }

    // GET /empresarios/{id}/actas/{acta}/acta.pdf
    public function pdf(Request $request, string $id, string $acta): HttpResponse
    {
        $org = $this->scope->find($id);
        $model = $this->findActa($org, $acta);
        $this->audit($request, 'ORGANIZACION_ACTA_DESCARGADA', $model, ['formato' => 'pdf']);

        return Pdf::loadView('eventos.acta', ['acta' => ActaPresenter::payload($model)])->setPaper('letter')->download($this->filename($model, 'pdf'));
    }

    // The same page as an event's acta ('Eventos/Acta'), told by `origen` where it lives and what confirming does.
    private function page(Organizacion $org, ?EventActa $acta): Response
    {
        $editable = $this->scope->editable($org) && $org->is_active;
        $municipio = $org->municipio_id ? $org->municipio()->value('canonical_name') : null;

        return Inertia::render('Eventos/Acta', [
            'evento' => [
                'id' => $org->id,
                'nombre' => $org->nombre,
                'status' => $org->is_active ? 'abierto' : 'inactiva',
                'campaign' => ['id' => $org->campaign_id, 'nombre' => $org->campaign?->name],
                'municipio' => $municipio,
            ],
            'origen' => [
                'tipo' => 'organizacion',
                'base' => "/empresarios/{$org->id}/actas",
                'volver' => "/empresarios?abrir={$org->id}",
                'seccion' => ['label' => 'EMPRESARIOS', 'href' => '/empresarios'],
                'buscar' => '/empresarios/buscar/personas',
            ],
            'acta' => $acta ? ActaPresenter::payload($acta) : null,
            'defaults' => $acta ? null : [
                'titulo' => Str::limit(Str::upper('Acta reunión '.$org->nombre), 255, ''),
                'fecha' => now('America/Bogota')->toDateString(),
                'municipio' => $municipio ? Str::upper($municipio) : null,
                'sitio' => $org->direccion,
            ],
            'asistentes' => [],
            'can' => [
                'edit' => $editable && (! $acta || $acta->isDraft()),
                'delete' => $editable && $acta !== null && $acta->isDraft(),
                'delete_confirmed' => $editable && $acta !== null && ! $acta->isDraft() && request()->user()->hasPermission('delete.actas'),
            ],
        ]);
    }

    private function findActa(Organizacion $org, string $acta): EventActa
    {
        abort_unless(Str::isUuid($acta), 404);

        return EventActa::where('organizacion_id', $org->id)->findOrFail($acta);
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
            $parts[] = "{$counts['participantes']} participantes: {$counts['vinculados']} ya estaban en Votantes, {$counts['fichas_creadas']} fichas nuevas, {$counts['miembros_nuevos']} agregados como miembros de la organización.";
        }
        if ($counts['compromisos'] > 0) {
            $parts[] = "{$counts['compromisos']} compromisos.";
        }

        return implode(' ', $parts);
    }

    public static function deletedSummary(array $counts): string
    {
        return "Acta eliminada. Se quitaron {$counts['miembros']} miembros que entraron por el acta y se borraron {$counts['fichas_borrar']} fichas creadas al confirmarla."
            .($counts['fichas_conservar'] ? " Se conservaron {$counts['fichas_conservar']} fichas que ya tienen otra información." : '')
            .($counts['compromisos'] ? " Se eliminaron {$counts['compromisos']} compromisos y {$counts['avances']} avances." : '');
    }

    private function audit(Request $request, string $action, EventActa $acta, array $metadata): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'organizacion',            'entity_id' => $acta->organizacion_id,
            'metadata' => ['acta_id' => $acta->id, ...$metadata],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
