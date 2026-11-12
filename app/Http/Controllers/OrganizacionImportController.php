<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\AuditLog;
use App\Models\OrganizacionImport;
use App\Models\OrganizacionImportRow;
use App\Services\Empresarios\OrganizacionImportService;
use App\Services\Empresarios\OrganizacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

// Excel load of Empresarios y gremios (docs/empresarios.md): template, upload into staging, review and corrections,
// confirm or discard. manage.empresarios (route) and the own campaign: a load is only seen by its campaign.
class OrganizacionImportController extends Controller
{
    use ScopesTerritory;

    public function __construct(private OrganizacionImportService $imports) {}

    // GET /empresarios/plantilla.xlsx
    public function template(Request $request): HttpResponse
    {
        $this->audit($request, 'ORGANIZACIONES_PLANTILLA_DESCARGADA', null, []);

        return response($this->imports->template(), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="plantilla-empresarios-y-gremios.xlsx"',
            'Cache-Control' => 'no-store',
        ]);
    }

    // POST /empresarios/carga (multipart archivo: .xlsx, .xls or .csv, ≤ 5 MB)
    public function store(Request $request): JsonResponse
    {
        abort_if($this->campaign()->ownId() === null, 403);
        $request->validate(['archivo' => ['required', 'file', 'max:5120', 'mimes:xlsx,xls,csv,txt']], [
            'archivo.required' => 'Elija el archivo de Excel.',
            'archivo.max' => 'El archivo pesa más de 5 MB.',
            'archivo.mimes' => 'El archivo debe ser de Excel (.xlsx o .xls) o .csv.',
        ]);
        $file = $request->file('archivo');
        $extension = strtolower($file->getClientOriginalExtension());
        $extension = in_array($extension, ['xlsx', 'xls', 'csv'], true) ? $extension : 'csv';
        try {
            $import = $this->imports->stage((string) $file->getRealPath(), $extension, $file->getClientOriginalName(), $request->user());
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['archivo' => $e->getMessage()]);
        }
        $this->audit($request, 'ORGANIZACIONES_CARGA_SUBIDA', $import, ['filas' => $import->rows()->count()]);

        return response()->json(['success' => true, 'data' => ['id' => $import->id, 'url' => "/empresarios/carga/{$import->id}"]], 201);
    }

    // GET /empresarios/carga/{import}
    public function show(string $import): Response
    {
        $model = $this->find($import, false);

        return Inertia::render('Empresarios/Importar', $this->props($model));
    }

    // PATCH /empresarios/carga/{import}/filas/{fila} {datos?: {...}, accion?: completar|saltar}
    public function updateRow(Request $request, string $import, string $fila): JsonResponse
    {
        $model = $this->find($import, true);
        abort_unless(Str::isUuid($fila), 404);
        $row = OrganizacionImportRow::where('import_id', $model->id)->findOrFail($fila);
        $fields = array_keys(OrganizacionImportService::COLUMNS);
        $data = $request->validate([
            'datos' => ['nullable', 'array'],
            'datos.*' => ['nullable', 'string', 'max:5000'],
            'accion' => ['nullable', Rule::in(['completar', 'saltar'])],
        ]);
        if (isset($data['datos'])) {
            $row->forceFill(['datos' => array_merge($row->datos, array_intersect_key($data['datos'], array_flip($fields)))]);
        }
        if (isset($data['accion']) && $row->existente_id) {
            $row->forceFill(['accion' => $data['accion']]);
        }
        $row->save();
        $this->imports->evaluate($model);
        $this->audit($request, 'ORGANIZACIONES_CARGA_FILA', $model, ['fila' => $row->row_number, 'campos' => array_keys($data['datos'] ?? []), 'accion' => $data['accion'] ?? null]);

        return response()->json(['success' => true, 'data' => $this->props($model->fresh())]);
    }

    // POST /empresarios/carga/{import}/confirmar
    public function confirm(Request $request, string $import, OrganizacionService $service): JsonResponse
    {
        $model = $this->find($import, true);
        try {
            $counts = $this->imports->confirm($model, $request->user(), $service);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['carga' => $e->getMessage()]);
        }
        $this->audit($request, 'ORGANIZACIONES_IMPORTADAS', $model, $counts);

        return response()->json(['success' => true, 'data' => $counts,
            'message' => "Carga guardada: {$counts['creadas']} nuevas, {$counts['completadas']} completadas, {$counts['saltadas']} saltadas"
                .($counts['con_error'] ? ", {$counts['con_error']} con errores sin guardar" : '').'.']);
    }

    // DELETE /empresarios/carga/{import}: discards a load still in review.
    public function destroy(Request $request, string $import): JsonResponse
    {
        $model = $this->find($import, true);
        $model->rows()->delete();
        $model->forceFill(['estado' => 'descartada'])->save();
        $this->audit($request, 'ORGANIZACIONES_CARGA_DESCARTADA', $model, []);

        return response()->json(['success' => true, 'message' => 'Carga descartada: no se guardó nada.']);
    }

    private function props(OrganizacionImport $import): array
    {
        $names = fn ($id) => $id ? DB::table('organizaciones')->where('id', $id)->value('nombre') : null;
        $rows = $import->rows()->get();

        return [
            'carga' => [
                'id' => $import->id,
                'archivo' => $import->archivo_nombre,
                'estado' => $import->estado,
                'resumen' => $import->resumen,
                'conteo' => [
                    'crear' => $rows->where('accion', 'crear')->count(),
                    'completar' => $rows->where('accion', 'completar')->count(),
                    'saltar' => $rows->where('accion', 'saltar')->count(),
                    'error' => $rows->where('accion', 'error')->count(),
                ],
            ],
            'filas' => $rows->map(fn (OrganizacionImportRow $r) => [
                'id' => $r->id, 'fila' => $r->row_number, 'datos' => $r->datos, 'errores' => $r->errores ?? [],
                'accion' => $r->accion, 'existente' => $r->existente_id ? ['id' => $r->existente_id, 'nombre' => $names($r->existente_id)] : null,
            ])->values()->all(),
            'columnas' => collect(OrganizacionImportService::COLUMNS)->map(fn ($c, $k) => ['key' => $k, 'label' => $c[0]])->values()->all(),
        ];
    }

    // A load of the own campaign, seen by whoever uploaded it or by a user without territory (the rows may hold
    // organizations of any municipio); $write also needs it to be in review.
    private function find(string $id, bool $write): OrganizacionImport
    {
        abort_unless(Str::isUuid($id), 404);
        $import = OrganizacionImport::findOrFail($id);
        abort_unless($import->campaign_id === $this->campaign()->ownId(), 404);
        abort_unless($import->user_id === request()->user()->id || $this->allowedGeoIds() === null, 404);
        if ($write && $import->estado !== 'revision') {
            throw ValidationException::withMessages(['carga' => 'Esta carga ya se confirmó o se descartó.']);
        }

        return $import;
    }

    private function audit(Request $request, string $action, ?OrganizacionImport $import, array $metadata): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'organizacion',
            'entity_id' => null,
            'metadata' => ['carga_id' => $import?->id, ...$metadata],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
