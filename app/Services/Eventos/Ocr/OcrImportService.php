<?php

namespace App\Services\Eventos\Ocr;

use App\Models\AuditLog;
use App\Models\CampaignEvent;
use App\Models\EventImport;
use App\Models\EventImportPage;
use App\Models\User;
use App\Services\Eventos\EventImportService;
use App\Services\Eventos\ImportRowValidator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Photos of the printed sign-in sheet read with AI (docs/eventos-api.md §13), on top of the Excel staging (§11): an
// event_imports row of kind 'ocr' holds the photographed pages (event_import_pages, private disk); each page is read in
// its own request (processNext) and its rows go to event_import_rows exactly as an Excel row after its column mapping
// (mapped, errors) plus the page and the confidence per field. When no page is left, the rows are classified and the
// load waits in 'revisado' for the human review; confirming is the Excel confirmation (EventImportService::confirm)
// with source 'ocr'. Since 2026-11-07 neither an "Autorizo" box nor the signature decides whether a row is imported
// (the signature is only read as information). Nothing reaches event_attendees before that confirmation. The audit never stores what was read: pages, model, tokens and estimated cost.
class OcrImportService
{
    public const DIR = 'eventos/ocr';

    public const DISK = EventImportService::DISK;

    // Paid calls to the API per page; after that the page is refused (a new photo or the operator mode).
    public const MAX_CALLS_PER_PAGE = 3;

    public const TOO_MANY_CALLS = 'Esta página ya se intentó leer 3 veces: tome otra foto y súbala en una carga nueva, o registre a las personas en el modo operador.';

    public function __construct(
        private OcrClient $client,
        private ImportRowValidator $validator,
        private EventImportService $imports,
    ) {}

    // Adds one uploaded file (a photo, or a PDF with one page per sheet page) to a load, creating it when $import is
    // null. Returns ['import' => EventImport, 'pages' => pages added]. Throws OcrException with the reason.
    public function addFile(CampaignEvent $event, ?EventImport $import, UploadedFile $file, User $user): array
    {
        $path = $file->getRealPath();
        $mime = PageImage::mime($path);
        $sha = hash_file('sha256', $path);
        $name = Str::limit($file->getClientOriginalName(), 250, '');

        $previous = EventImportPage::where('event_id', $event->id)->where('file_sha256', $sha)->first(['original_name', 'created_at']);
        if ($previous) {
            throw new OcrException('Esta foto ya se subió a este evento ('.$previous->original_name.'): no se vuelve a leer.');
        }

        $filePages = $mime === 'application/pdf' ? PageImage::pdfCheck((string) file_get_contents($path)) : 1;
        $units = $filePages;
        $max = (int) config('eventos.ocr.max_pages');
        $current = $import ? EventImportPage::where('import_id', $import->id)->count() : 0;
        if ($current + $units > $max) {
            throw new OcrException("Una carga puede tener máximo {$max} páginas. Termine esta y haga otra carga con el resto.");
        }

        $id = $import?->id ?? (string) Str::uuid();
        $sequence = $current + 1;
        $stored = $file->storeAs(self::DIR."/{$event->id}/{$id}", $sequence.'.'.PageImage::MIMES[$mime], self::DISK);
        if ($stored === false) {
            throw new OcrException('No se pudo guardar el archivo. Intente de nuevo.');
        }

        try {
            $import = DB::transaction(function () use ($import, $id, $event, $user, $stored, $sha, $mime, $name, $filePages, $units, $sequence) {
                if ($import === null) {
                    $import = (new EventImport)->forceFill([
                        'id' => $id,
                        'event_id' => $event->id,
                        'campaign_id' => $event->campaign_id,
                        'kind' => 'ocr',
                        'original_name' => $name,
                        'file_path' => self::DIR."/{$event->id}/{$id}",
                        // A load has many files: its own hash keeps (event_id, file_sha256) unique without meaning.
                        'file_sha256' => hash('sha256', 'ocr:'.$id),
                        'header_row' => 0,
                        'headers' => [],
                        'status' => 'subido',
                        'total_rows' => 0,
                        'created_by' => $user->id,
                    ]);
                    $import->save();
                }
                for ($page = 1; $page <= $units; $page++) {
                    $this->newPage($import, $event, $sequence + $page - 1, $name, $stored, $sha, $mime, $page, $filePages);
                }

                return $import;
            });
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($stored);
            if ($e instanceof UniqueConstraintViolationException) {
                throw new OcrException('Esta foto ya se subió a este evento: no se vuelve a leer.');
            }
            throw $e;
        }

        $this->audit($user, 'EVENTO_OCR_ARCHIVO_SUBIDO', $import, [
            'tipo' => $mime === 'application/pdf' ? 'pdf' : 'foto', 'paginas' => $units, 'sha256_archivo' => $sha,
        ]);

        return ['import' => $import, 'pages' => $units];
    }

    // Reads the next pending page (one per request, so a slow page never stacks with others) and, when none is left
    // and none failed, classifies the rows and leaves the load in 'revisado'. $skipErrors = continue without the pages
    // that failed. Returns ['progress' => progress(), 'error' => ?string (why this page failed), 'busy' => bool].
    public function processNext(EventImport $import, CampaignEvent $event, User $user, bool $skipErrors = false): array
    {
        $lock = Cache::lock('eventos-ocr|'.$import->id, self::lockSeconds());
        if (! $lock->get()) {
            return ['progress' => $this->progress($import), 'error' => null, 'busy' => true];
        }

        try {
            $import->refresh();
            $error = null;
            if ($import->status === 'subido') {
                $page = EventImportPage::where('import_id', $import->id)->where('status', 'pendiente')->orderBy('sequence')->first();
                if ($page) {
                    $error = $this->read($page, $import, $event, $user);
                }
                $left = EventImportPage::where('import_id', $import->id)
                    ->whereIn('status', $skipErrors ? ['pendiente'] : ['pendiente', 'error'])->exists();
                if (! $left) {
                    $this->finish($import, $event, $user);
                }
            }

            return ['progress' => $this->progress($import->refresh()), 'error' => $error, 'busy' => false];
        } finally {
            $lock->release();
        }
    }

    // Longer than one API call (the SDK does not retry: maxRetries 0) plus the QR, the image and the database work.
    public static function lockSeconds(): int
    {
        return (int) config('eventos.ocr.timeout') + 120;
    }

    // Pages that failed go back to pending so the next processNext reads them again.
    public function retryErrors(EventImport $import): int
    {
        return EventImportPage::where('import_id', $import->id)->where('status', 'error')
            ->update(['status' => 'pendiente', 'message' => null, 'updated_at' => now()]);
    }

    public function progress(EventImport $import): array
    {
        $pages = EventImportPage::where('import_id', $import->id)->orderBy('sequence')
            ->get(['id', 'sequence', 'status', 'message', 'original_name', 'page_in_file', 'rows_found', 'cost_usd']);
        $count = fn (string $status) => $pages->where('status', $status)->count();

        return [
            'status' => $import->status,
            'total' => $pages->count(),
            'pendientes' => $count('pendiente'),
            'procesadas' => $count('procesada'),
            'rechazadas' => $count('rechazada'),
            'errores' => $count('error'),
            'filas' => (int) $pages->sum('rows_found'),
            'costo_usd' => round((float) $pages->sum('cost_usd'), 4),
            'listo' => $import->status !== 'subido',
        ];
    }

    // After a confirmation: what the AI read of the people who did NOT authorize is emptied (data minimization), and a
    // file none of whose pages was read (another event, not a sheet, failed) is deleted: it backs no authorization. The
    // pages that were read stay as the evidence of the consent (§13).
    public function afterConfirm(EventImport $import): void
    {
        DB::table('event_import_rows')->where('import_id', $import->id)->where('status', 'no_autorizo')
            ->update(['raw' => null, 'mapped' => null, 'updated_at' => now()]);

        $pages = EventImportPage::where('import_id', $import->id)->get(['file_path', 'status']);
        foreach ($pages->groupBy('file_path') as $path => $group) {
            if ($group->where('status', 'procesada')->isEmpty()) {
                Storage::disk(self::DISK)->delete($path);
            }
        }
    }

    // [field => value] of a staging row from what the AI read: the same text fields as an Excel row (cleaned the
    // same way) and whether the row was signed ("firma", information only).
    public static function mapped(array $values, array $confidence): array
    {
        return [
            'nombres' => ImportRowValidator::text($values['nombres']),
            'apellidos' => ImportRowValidator::text($values['apellidos']),
            'cedula' => ImportRowValidator::number($values['cedula']),
            'celular' => ImportRowValidator::number($values['celular']),
            'fijo' => ImportRowValidator::number($values['fijo']),
            'fecha_nacimiento' => ImportRowValidator::date($values['fecha_nacimiento']),
            'direccion' => ImportRowValidator::text($values['direccion']),
            'barrio' => ImportRowValidator::text($values['barrio']),
            'firma' => ($values['firma_presente'] ?? false) ? 'SÍ' : 'NO',
        ];
    }

    private function newPage(EventImport $import, CampaignEvent $event, int $sequence, string $name, string $path, string $sha, string $mime, int $pageInFile, ?int $filePages): void
    {
        (new EventImportPage)->forceFill([
            'import_id' => $import->id,
            'event_id' => $event->id,
            'sequence' => $sequence,
            'original_name' => $name,
            'file_path' => $path,
            'file_sha256' => $sha,
            'mime' => $mime,
            'page_in_file' => $pageInFile,
            'file_pages' => $filePages,
            'status' => 'pendiente',
        ])->save();
    }

    // Reads one page. Returns null when it was read (or refused for a known reason), the message when it failed.
    // Every call to the API is counted on the page (api_calls) before it is made and claimed with a conditional
    // update, so two requests can never pay twice for the same page nor store its rows twice.
    private function read(EventImportPage $page, EventImport $import, CampaignEvent $event, User $user): ?string
    {
        $limit = (int) config('eventos.ocr.max_pages_per_day');
        if ($this->callsToday($event->campaign_id) >= $limit) {
            return $this->failed($page, $import, $user, new OcrException("La campaña ya hizo {$limit} lecturas con IA hoy, el máximo diario. Continúe mañana."));
        }

        $content = Storage::disk(self::DISK)->get($page->file_path);
        if ($content === null) {
            return $this->failed($page, $import, $user, new OcrException('El archivo de esta página ya no está guardado. Súbalo de nuevo.'));
        }

        // The image is prepared before the call is claimed: a local failure spends neither an attempt nor the daily cap.
        try {
            [[$apiContent, $apiMime], $qr] = PageImage::prepare($content, $page->mime);
        } catch (OcrException $e) {
            return $this->failed($page, $import, $user, $e);
        }
        unset($content);
        if ($qr !== null && $qr['e'] !== strtolower($event->id)) {
            $this->refuse($page, $import, $user, self::otherEvent($qr['e']), ['qr' => 'otro_evento', 'qr_event_id' => $qr['e'], 'printed_page' => $qr['p']]);

            return null;
        }

        if ($page->api_calls >= self::MAX_CALLS_PER_PAGE) {
            $this->refuse($page, $import, $user, self::TOO_MANY_CALLS, ['qr' => 'demasiados_intentos']);

            return null;
        }
        $claimed = EventImportPage::whereKey($page->id)->where('status', 'pendiente')->where('api_calls', $page->api_calls)
            ->update(['api_calls' => $page->api_calls + 1, 'last_call_at' => now(), 'updated_at' => now()]);
        if ($claimed === 0) {
            return null;
        }
        $page->refresh();

        $started = microtime(true);
        try {
            $result = $this->client->read($apiContent, $apiMime, $page->page_in_file, OcrSettings::model());
        } catch (OcrException $e) {
            return $this->failed($page, $import, $user, $e, (int) round((microtime(true) - $started) * 1000));
        }
        $data = $result->data;
        $usage = $this->usage($page, $result, (int) round((microtime(true) - $started) * 1000));

        if (($data['es_planilla'] ?? true) === false) {
            $this->refuse($page, $import, $user, 'Esta imagen no parece una planilla de asistencia de la plataforma: no se leyó.', $usage + ['qr' => 'no_planilla']);

            return null;
        }

        [$readEvent, $origin] = $qr !== null ? [$qr['e'], 'codigo'] : self::eventFromText($data, $event);
        if ($readEvent !== null && $readEvent !== strtolower($event->id)) {
            $this->refuse($page, $import, $user, self::otherEvent($readEvent), $usage + ['qr' => 'otro_evento', 'qr_event_id' => Str::isUuid($readEvent) ? $readEvent : null, 'qr_origin' => $origin]);

            return null;
        }
        $note = $readEvent === null ? 'No se pudo leer el código del evento en esta página: confirme en la imagen que es de este evento.' : '';

        $rows = PlanillaPrompt::rows($data);
        $printed = is_int($data['pagina'] ?? null) ? $data['pagina'] : ($qr['p'] ?? null);
        $stored = DB::transaction(function () use ($page, $import, $rows, $usage, $readEvent, $origin, $printed, $note) {
            $now = now();
            // Only the request that still finds the page pending stores what it read.
            $updated = EventImportPage::whereKey($page->id)->where('status', 'pendiente')->update($usage + [
                'status' => 'procesada',
                'message' => $note !== '' ? Str::limit($note, 250) : null,
                'qr_event_id' => $readEvent !== null && Str::isUuid($readEvent) ? $readEvent : null,
                'qr_origin' => $readEvent !== null ? $origin : null,
                'printed_page' => $printed,
                'rows_found' => count($rows),
                'attempts' => $page->attempts + 1,
                'processed_at' => $now,
                'updated_at' => $now,
            ]);
            if ($updated === 0) {
                return false;
            }
            $insert = [];
            foreach ($rows as $row) {
                $mapped = self::mapped($row['valores'], $row['confianza']);
                $errors = $this->validator->validate($mapped)['errors'];
                $insert[] = [
                    'id' => (string) Str::uuid(),
                    'import_id' => $import->id,
                    'page_id' => $page->id,
                    'page_row' => $row['numero'],
                    'row_number' => ($page->sequence - 1) * PlanillaPrompt::ROWS + $row['numero'],
                    'raw' => json_encode($row['valores'], JSON_UNESCAPED_UNICODE),
                    'mapped' => json_encode($mapped, JSON_UNESCAPED_UNICODE),
                    'confidence' => json_encode($row['confianza']),
                    'errors' => $errors ? json_encode($errors, JSON_UNESCAPED_UNICODE) : null,
                    'status' => 'pendiente',
                    'decision' => 'omitir',
                    'edited' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($insert !== []) {
                DB::table('event_import_rows')->insert($insert);
            }

            return true;
        });

        $this->auditPage($user, $import, $page, $stored ? 'procesada' : 'descartada_ya_leida', $this->callUsage($result) + [
            'duration_ms' => $usage['duration_ms'],
            'filas' => $stored ? count($rows) : 0, 'qr' => $readEvent === null ? 'ilegible' : 'coincide', 'qr_origen' => $readEvent !== null ? $origin : null,
        ]);

        return null;
    }

    // Calls to the API made today (America/Bogota) for the campaign's pages, whatever their result.
    private function callsToday(string $campaignId): int
    {
        $today = Carbon::now('America/Bogota')->startOfDay()->utc();

        return (int) DB::table('event_import_pages as p')->join('event_imports as i', 'i.id', '=', 'p.import_id')
            ->where('i.campaign_id', $campaignId)->where('p.last_call_at', '>=', $today)->sum('p.api_calls');
    }

    // Page totals after one more paid call: tokens and cost add up over every call made for the page.
    private function usage(EventImportPage $page, OcrResult $result, int $duration): array
    {
        return [
            'model' => Str::limit($result->model, 60, ''),
            'input_tokens' => $page->input_tokens + $result->inputTokens,
            'output_tokens' => $page->output_tokens + $result->outputTokens,
            'cache_write_tokens' => $page->cache_write_tokens + $result->cacheWriteTokens,
            'cache_read_tokens' => $page->cache_read_tokens + $result->cacheReadTokens,
            'cost_usd' => round($page->cost_usd + $result->cost(), 5),
            'duration_ms' => $duration,
        ];
    }

    // What one call used, for the audit row of that call.
    private function callUsage(OcrResult $result): array
    {
        return [
            'model' => $result->model,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'cache_write_tokens' => $result->cacheWriteTokens,
            'cache_read_tokens' => $result->cacheReadTokens,
            'cost_usd' => $result->cost(),
        ];
    }

    // [event id read on the page, origin 'ia'] from the printed trace line (full id) or the 8-character code;
    // [null, null] when neither is legible. A code that matches the event counts as the event's id.
    private static function eventFromText(array $data, CampaignEvent $event): array
    {
        $id = is_string($data['evento_id'] ?? null) ? strtolower(trim($data['evento_id'])) : '';
        if (Str::isUuid($id)) {
            return [$id, 'ia'];
        }
        $code = is_string($data['codigo_evento'] ?? null) ? strtoupper(trim($data['codigo_evento'])) : '';
        if (preg_match('/^[0-9A-F]{8}$/', $code)) {
            return [$code === strtoupper(substr($event->id, 0, 8)) ? strtolower($event->id) : $code, 'ia'];
        }

        return [null, null];
    }

    private static function otherEvent(string $id): string
    {
        return 'Esta página es de otro evento (código '.strtoupper(substr($id, 0, 8)).'): no se leyó. Súbala en la carga de ese evento.';
    }

    private function refuse(EventImportPage $page, EventImport $import, User $user, string $message, array $fields): void
    {
        $result = $fields['qr'] ?? null;
        $page->forceFill(array_intersect_key($fields, array_flip([
            'model', 'input_tokens', 'output_tokens', 'cache_write_tokens', 'cache_read_tokens', 'cost_usd', 'duration_ms', 'qr_event_id', 'printed_page', 'qr_origin',
        ])) + [
            'status' => 'rechazada',
            'message' => Str::limit($message, 250),
            'attempts' => $page->attempts + 1,
            'processed_at' => now(),
        ])->save();

        $audit = ['qr' => $result, 'duration_ms' => $fields['duration_ms'] ?? 0];
        if (isset($fields['model'])) {
            // The tokens of this call only (the page keeps the sum of all its calls).
            $audit += ['model' => $fields['model'], 'cost_usd_pagina' => $fields['cost_usd']];
        }
        $this->auditPage($user, $import, $page, 'rechazada', $audit);
    }

    // A failed page goes to 'error' (retryable by hand) or, after MAX_CALLS_PER_PAGE paid calls, to 'rechazada'. A call
    // that was answered and charged (cut, refused, unreadable) adds its tokens and cost to the page and the audit.
    private function failed(EventImportPage $page, EventImport $import, User $user, OcrException $e, int $duration = 0): string
    {
        $exhausted = $page->api_calls >= self::MAX_CALLS_PER_PAGE;
        $message = $exhausted ? $e->getMessage().' '.self::TOO_MANY_CALLS : $e->getMessage();
        $fill = [
            'status' => $exhausted ? 'rechazada' : 'error',
            'message' => Str::limit($message, 250),
            'attempts' => $page->attempts + 1,
            'duration_ms' => $duration,
        ];
        $audit = ['duration_ms' => $duration, 'reintentable' => $e->retryable && ! $exhausted];
        if ($e->billed !== null) {
            $fill = $this->usage($page, $e->billed, $duration) + $fill;
            $audit += $this->callUsage($e->billed);
        }
        $page->forceFill($fill)->save();
        $this->auditPage($user, $import, $page, $exhausted ? 'rechazada' : 'error', $audit);

        return $message;
    }

    private function finish(EventImport $import, CampaignEvent $event, User $user): void
    {
        DB::transaction(function () use ($import, $event) {
            $import->forceFill([
                'status' => 'revisado',
                'total_rows' => DB::table('event_import_rows')->where('import_id', $import->id)->count(),
            ])->save();
            $this->imports->classify($import, $event);
        });

        $totals = DB::table('event_import_pages')->where('import_id', $import->id)
            ->selectRaw('COUNT(*) AS paginas, SUM(rows_found) AS filas, SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens, '
                .'SUM(cache_write_tokens) AS cache_write_tokens, SUM(cache_read_tokens) AS cache_read_tokens, SUM(cost_usd) AS costo_usd, SUM(duration_ms) AS duracion_ms')
            ->first();
        $this->audit($user, 'EVENTO_OCR_LECTURA_TERMINADA', $import, [
            'paginas' => (int) $totals->paginas,
            'filas' => (int) $totals->filas,
            'modelos' => DB::table('event_import_pages')->where('import_id', $import->id)->whereNotNull('model')->distinct()->pluck('model')->all(),
            'input_tokens' => (int) $totals->input_tokens,
            'output_tokens' => (int) $totals->output_tokens,
            'cache_write_tokens' => (int) $totals->cache_write_tokens,
            'cache_read_tokens' => (int) $totals->cache_read_tokens,
            'costo_usd' => round((float) $totals->costo_usd, 4),
            'duracion_ms' => (int) $totals->duracion_ms,
        ]);
    }

    private function auditPage(User $user, EventImport $import, EventImportPage $page, string $result, array $metadata): void
    {
        $keys = ['model', 'input_tokens', 'output_tokens', 'cache_write_tokens', 'cache_read_tokens', 'cost_usd', 'cost_usd_pagina', 'duration_ms', 'filas', 'qr', 'qr_origen', 'reintentable'];
        $this->audit($user, 'EVENTO_OCR_PAGINA_LEIDA', $import, [
            'pagina' => $page->sequence, 'resultado' => $result,
        ] + array_intersect_key($metadata, array_flip($keys)));
    }

    private function audit(User $user, string $action, EventImport $import, array $metadata): void
    {
        AuditLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'entity_type' => 'evento_importacion',
            'entity_id' => $import->id,
            'metadata' => ['event_id' => $import->event_id, ...$metadata],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
