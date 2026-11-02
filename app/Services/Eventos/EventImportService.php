<?php

namespace App\Services\Eventos;

use App\Actions\Eventos\RegisterAttendee;
use App\Models\AuditLog;
use App\Models\CampaignEvent;
use App\Models\ConsentText;
use App\Models\EventAttendee;
use App\Models\EventImport;
use App\Models\EventImportRow;
use App\Models\User;
use App\Services\Eventos\Ocr\OcrSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use RuntimeException;

// Excel/CSV attendee imports of an event: upload to staging, map the columns, validate every row, correct cells in
// the preview and confirm. Same rule as the QR and the operator: only a person who authorized the data processing is
// registered, so a row is importable only with SÍ in "Autorizó", and confirming requires the operator's declaration
// that the signed sheets exist. Confirming registers only the valid rows the operator kept, through RegisterAttendee
// (source 'excel', import_id), in transactions of BATCH rows and with a client_uuid derived from (import, row):
// confirming again creates nothing new. Everything runs synchronously (no queue worker in production) within MAX_ROWS.
class EventImportService
{
    public const BATCH = 200;

    public const DISK = 'local';

    // eventos:limpiar-importaciones: unconfirmed imports are deleted after this many days...
    public const UNCONFIRMED_DAYS = 7;

    // ...and the cells (raw/mapped) of confirmed ones are emptied after this many days.
    public const CONFIRMED_DAYS = 30;

    // Page size of purge(); it walks by id (lazyById), so deleting while iterating never skips imports.
    public static int $purgeChunk = 200;

    public function __construct(
        private AttendeeSheetReader $reader,
        private ImportRowValidator $validator,
        private RegisterAttendee $register,
    ) {}

    // Returns ['import' => EventImport, 'repeated' => bool]. repeated = this same file (sha256) was already uploaded
    // to the event: nothing new is stored and the previous import is returned. Throws RuntimeException for the user.
    public function upload(CampaignEvent $event, UploadedFile $file, User $user): array
    {
        $sha = hash_file('sha256', $file->getRealPath());
        $previous = EventImport::where('event_id', $event->id)->where('file_sha256', $sha)->first();
        if ($previous) {
            return ['import' => $previous, 'repeated' => true];
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $sheet = $this->reader->read($file->getRealPath(), $extension);
        $mapping = ImportColumns::detect($sheet['headers'], $this->savedMapping($event->campaign_id));

        $id = (string) Str::uuid();
        $path = $file->storeAs("eventos/importaciones/{$event->id}", "{$id}.{$extension}", self::DISK);
        if ($path === false) {
            throw new RuntimeException('No se pudo guardar el archivo. Intente de nuevo.');
        }

        try {
            $import = DB::transaction(function () use ($id, $event, $file, $path, $sha, $sheet, $mapping, $user) {
                $import = (new EventImport)->forceFill([
                    'id' => $id,
                    'event_id' => $event->id,
                    'campaign_id' => $event->campaign_id,
                    'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                    'file_path' => $path,
                    'file_sha256' => $sha,
                    'header_row' => $sheet['header_row'],
                    'headers' => $sheet['headers'],
                    'column_mapping' => $mapping,
                    'status' => 'subido',
                    'total_rows' => count($sheet['rows']),
                    'created_by' => $user->id,
                ]);
                $import->save();

                $now = now();
                foreach (array_chunk($sheet['rows'], 500) as $chunk) {
                    DB::table('event_import_rows')->insert(array_map(fn ($row) => [
                        'id' => (string) Str::uuid(),
                        'import_id' => $id,
                        'row_number' => $row['row_number'],
                        'raw' => json_encode((object) $row['cells'], JSON_UNESCAPED_UNICODE),
                        'status' => 'pendiente',
                        'decision' => 'omitir',
                        'edited' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $chunk));
                }

                return $import;
            });
        } catch (UniqueConstraintViolationException) {
            // The same file uploaded twice at the same time.
            Storage::disk(self::DISK)->delete($path);

            return ['import' => EventImport::where('event_id', $event->id)->where('file_sha256', $sha)->firstOrFail(), 'repeated' => true];
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($path);
            throw $e;
        }

        $this->audit($user, 'EVENTO_IMPORTACION_SUBIDA', $import, ['filas' => $import->total_rows]);

        return ['import' => $import, 'repeated' => false];
    }

    // Applies the column mapping to every row (corrections made before are replaced) and validates them.
    public function applyMapping(EventImport $import, CampaignEvent $event, array $mapping, User $user): void
    {
        $mapping = array_merge(array_fill_keys(array_keys(ImportColumns::FIELDS), null), $mapping);

        DB::transaction(function () use ($import, $event, $mapping) {
            $import->forceFill([
                'column_mapping' => $mapping,
                'status' => 'revisado',
                'counts' => null,
            ])->save();

            // One upsert per 500 rows instead of one UPDATE per row; status back to pendiente so classify() resets decisions.
            $now = now();
            DB::table('event_import_rows')->where('import_id', $import->id)->select(['id', 'row_number', 'raw'])
                ->chunkById(500, function ($rows) use ($mapping, $import, $now) {
                    $values = [];
                    foreach ($rows as $row) {
                        $mapped = $this->validator->map(json_decode($row->raw, true) ?? [], $mapping);
                        $errors = $this->validator->validate($mapped)['errors'];
                        $values[] = [
                            'id' => $row->id, 'import_id' => $import->id, 'row_number' => $row->row_number, 'raw' => $row->raw,
                            'mapped' => json_encode($mapped, JSON_UNESCAPED_UNICODE),
                            'errors' => $errors ? json_encode($errors, JSON_UNESCAPED_UNICODE) : null,
                            'status' => 'pendiente', 'decision' => 'omitir', 'edited' => false, 'created_at' => $now, 'updated_at' => $now,
                        ];
                    }
                    DB::table('event_import_rows')->upsert($values, ['id'], ['mapped', 'errors', 'status', 'decision', 'edited', 'updated_at']);
                });

            $this->classify($import, $event);
        });

        $this->audit($user, 'EVENTO_IMPORTACION_COLUMNAS', $import, ['campos' => array_keys(array_filter($mapping, fn ($v) => $v !== null))]);
    }

    // Corrects cells of one row and/or changes its decision ('importar' or 'omitir'). Returns the fresh row.
    public function updateRow(EventImport $import, CampaignEvent $event, EventImportRow $row, array $values, ?string $decision): EventImportRow
    {
        return DB::transaction(function () use ($import, $event, $row, $values, $decision) {
            if ($values !== []) {
                $mapped = array_merge($row->mapped ?? [], $values);
                $errors = $this->validator->validate($mapped)['errors'];
                $fill = ['mapped' => $mapped, 'errors' => $errors ?: null, 'edited' => true];
                if ($row->confidence !== null) {
                    // A cell a person corrected while looking at the photo is no longer doubtful (OCR rows).
                    $fill['confidence'] = array_merge($row->confidence, array_fill_keys(array_keys($values), 1.0));
                }
                $row->forceFill($fill)->save();
                $this->classify($import, $event);
                $row->refresh();
                // A doubtful cédula corrected by a person: the row goes back to its default decision (import).
                if (isset($values['cedula']) && $row->confidence !== null && $row->status === 'valida' && $row->decision === 'omitir' && $decision === null) {
                    $row->forceFill(['decision' => $row->match_lider_id ? 'vincular' : 'crear'])->save();
                    $this->recount($import);
                }
            }

            if ($decision !== null) {
                $importable = $row->status === 'valida';
                if ($decision === 'importar' && ! $importable) {
                    throw new RuntimeException('Esta fila no se puede importar: '.$this->statusReason($row->status));
                }
                $row->forceFill(['decision' => $decision === 'importar' ? ($row->match_lider_id ? 'vincular' : 'crear') : 'omitir'])->save();
                $this->recount($import);
            }

            return $row->refresh();
        });
    }

    // Registers the importable rows, all of them with consent over the campaign's APPROVED text (the caller already
    // required the declaration).
    // The import keeps the first confirmation (who, when, text); every imported row keeps its own. The consent proof
    // (data_consents.accepted_at, ip, user agent) is the moment and the browser of this confirmation, not the day the
    // person signed the paper sheet. Returns ['nuevos', 'ya_estaban']. Throws RuntimeException without a consent text.
    public function confirm(EventImport $import, CampaignEvent $event, User $user, ?string $ip, ?string $userAgent): array
    {
        $consent = ConsentText::currentFor($event->campaign_id);
        if (! $consent?->approved) {
            throw new RuntimeException('Falta el texto de autorización aprobado por la campaña; no se puede cargar Excel hasta tenerlo.');
        }
        if ($import->status !== 'confirmado') {
            $import->forceFill([
                'status' => 'confirmado',
                'consent_text_id' => $consent->id,
                'consent_declared' => true,
                'confirmed_by' => $user->id,
                'confirmed_at' => now(),
            ])->save();
        }

        $now = now();
        $result = ['nuevos' => 0, 'ya_estaban' => 0];
        EventImportRow::where('import_id', $import->id)->where('status', 'valida')->where('decision', '!=', 'omitir')
            ->whereNull('attendee_id')
            ->chunkById(self::BATCH, function ($rows) use ($import, $event, $consent, $user, $ip, $userAgent, $now, &$result) {
                DB::transaction(function () use ($rows, $import, $event, $consent, $user, $ip, $userAgent, $now, &$result) {
                    $items = [];
                    foreach ($rows as $row) {
                        $data = ImportRowValidator::data($row->mapped ?? []);
                        if (! $data['autoriza']) {
                            continue;
                        }
                        $data['client_uuid'] = self::clientUuid($import->id, $row->row_number);
                        $items[$row->id] = $data;
                    }

                    $linked = [];
                    $source = $import->isOcr() ? 'ocr' : 'excel';
                    $outcomes = $this->register->handleMany($event, $consent, $items, $source, $user, $ip, $userAgent, 'operador', $import->id);
                    foreach ($outcomes as $rowId => $outcome) {
                        if ($outcome['conflict'] || $outcome['attendee_id'] === null) {
                            continue;
                        }
                        $linked[$rowId] = $outcome['attendee_id'];
                        $result[$outcome['created'] ? 'nuevos' : 'ya_estaban']++;
                    }
                    $this->linkAttendees($linked, $user->id, $now);
                });
            });

        $this->recount($import);
        $this->audit($user, 'EVENTO_IMPORTACION_CONFIRMADA', $import, $result + ['declaro_planillas' => true, 'consent_text_id' => $consent->id, 'tipo' => $import->kind ?? 'excel']);

        return $result;
    }

    // [import row id => attendee id] in one UPDATE, with the declaration and who confirmed each row.
    private function linkAttendees(array $linked, string $userId, $now): void
    {
        if ($linked === []) {
            return;
        }
        $value = DB::getDriverName() === 'pgsql' ? 'CAST(? AS uuid)' : '?';
        $cases = [];
        $bindings = [];
        foreach ($linked as $rowId => $attendeeId) {
            $cases[] = "WHEN ? THEN {$value}";
            array_push($bindings, $rowId, $attendeeId);
        }
        $placeholders = implode(', ', array_fill(0, count($linked), '?'));
        DB::update(
            'UPDATE event_import_rows SET attendee_id = CASE id '.implode(' ', $cases).' END, declared = ?, confirmed_by = ?, confirmed_at = ?, '
                ."updated_at = ? WHERE id IN ({$placeholders})",
            [...$bindings, true, $userId, $now, $now, ...array_keys($linked)],
        );
    }

    // Retention of the staging (personal data): unconfirmed imports older than UNCONFIRMED_DAYS are deleted with their
    // file (the photos of an OCR load); confirmed ones older than CONFIRMED_DAYS lose the cells of their rows
    // (raw/mapped), keeping row number, status, decision, attendee, declaration, who/when, the file and its sha256 (the
    // photos of a confirmed OCR load are kept: they are the evidence of the signed authorizations, §13).
    // Returns ['descartadas', 'vaciadas'].
    public function purge(): array
    {
        $result = ['descartadas' => 0, 'vaciadas' => 0];
        EventImport::where('status', '!=', 'confirmado')->where('created_at', '<', now()->subDays(self::UNCONFIRMED_DAYS))
            ->lazyById(self::$purgeChunk)->each(function (EventImport $import) use (&$result) {
                DB::transaction(fn () => $import->delete());
                self::deleteFiles($import);
                $result['descartadas']++;
            });

        EventImport::where('status', 'confirmado')->whereNull('staging_purged_at')
            ->where('confirmed_at', '<', now()->subDays(self::CONFIRMED_DAYS))
            ->lazyById(self::$purgeChunk)->each(function (EventImport $import) use (&$result) {
                DB::transaction(function () use ($import) {
                    DB::table('event_import_rows')->where('import_id', $import->id)
                        ->update(['raw' => null, 'mapped' => null, 'updated_at' => now()]);
                    $import->forceFill(['staging_purged_at' => now()])->save();
                });
                $result['vaciadas']++;
            });

        if ($result['descartadas'] || $result['vaciadas']) {
            AuditLog::create([
                'user_id' => null,
                'action' => 'EVENTO_IMPORTACIONES_LIMPIEZA',
                'entity_type' => 'evento_importacion',
                'metadata' => $result + ['dias_sin_confirmar' => self::UNCONFIRMED_DAYS, 'dias_confirmadas' => self::CONFIRMED_DAYS],
            ]);
        }

        return $result;
    }

    public function discard(EventImport $import, User $user): void
    {
        $this->audit($user, 'EVENTO_IMPORTACION_DESCARTADA', $import, ['filas' => $import->total_rows]);
        DB::transaction(fn () => $import->delete());
        self::deleteFiles($import);
    }

    // An Excel load has one file; an OCR load (§13) a folder with its photos.
    public static function deleteFiles(EventImport $import): void
    {
        $import->isOcr()
            ? Storage::disk(self::DISK)->deleteDirectory($import->file_path)
            : Storage::disk(self::DISK)->delete($import->file_path);
    }

    // Deterministic per (import, row): a retried or repeated confirmation finds the attendee it already created.
    public static function clientUuid(string $importId, int $rowNumber): string
    {
        return Uuid::uuid5($importId, 'fila-'.$rowNumber)->toString();
    }

    // Statuses of every row after the cells changed: no_autorizo ("Autorizó" is not SÍ: never importable, it cannot be
    // fixed in the preview), error, duplicada (same cédula on an earlier importable row), en_evento (already
    // registered in the event) or valida, with the lider of the campaign it matches. The decision
    // goes back to its default (valid → import, the rest → skip) only for rows whose status or match changed, so a
    // "No importar" chosen on a valid row survives corrections of other rows.
    public function classify(EventImport $import, CampaignEvent $event): void
    {
        $rows = EventImportRow::where('import_id', $import->id)->orderBy('row_number')
            ->get(['id', 'row_number', 'mapped', 'errors', 'status', 'decision', 'match_lider_id', 'attendee_id', 'confidence']);
        $threshold = OcrSettings::threshold();

        $cedulas = [];
        $authorized = [];
        foreach ($rows as $row) {
            $authorized[$row->id] = ImportRowValidator::yesNo($row->mapped['autoriza'] ?? null) === true;
            $cedulas[$row->id] = $row->errors || ! $authorized[$row->id] ? null : ImportRowValidator::cedulaOf($row->mapped ?? []);
        }
        $known = array_values(array_unique(array_filter($cedulas)));

        $inEvent = [];
        $lideres = [];
        foreach (array_chunk($known, 1000) as $chunk) {
            $inEvent += array_flip(EventAttendee::where('event_id', $event->id)->whereIn('cedula', $chunk)->pluck('cedula')->all());
            $lideres += DB::table('lideres')->where('campaign_id', $event->campaign_id)->whereIn('cedula', $chunk)
                ->pluck('id', 'cedula')->all();
        }

        $seen = [];
        $updates = [];
        foreach ($rows as $row) {
            $value = $cedulas[$row->id];
            $match = $value !== null ? ($lideres[$value] ?? null) : null;
            if (! $authorized[$row->id] && ! $row->attendee_id) {
                $status = 'no_autorizo';
            } elseif ($row->errors) {
                $status = 'error';
            } elseif (isset($seen[$value])) {
                $status = 'duplicada';
            } elseif (isset($inEvent[$value]) && ! $row->attendee_id) {
                $status = 'en_evento';
            } else {
                $status = 'valida';
            }
            if ($value !== null) {
                $seen[$value] = true;
            }

            $toImport = $status === 'valida' ? ($match ? 'vincular' : 'crear') : 'omitir';
            // An OCR row whose cédula the AI was not sure of starts as "No importar" until a person corrects it (its
            // confidence becomes 1) or ticks "Importar" by hand, which a later classification keeps.
            $doubtful = $row->confidence !== null && ($row->confidence['cedula'] ?? 0) < $threshold;
            $default = $doubtful ? 'omitir' : $toImport;
            $changed = $status !== $row->status || $match !== $row->match_lider_id;
            $decision = match (true) {
                $changed => $default,
                $row->decision === 'omitir' => 'omitir',
                default => $toImport,
            };
            if ($status !== $row->status || $match !== $row->match_lider_id || $decision !== $row->decision) {
                $updates["{$status}|{$decision}|{$match}"][] = $row->id;
            }
        }

        // Rows that end up the same are updated together (all the unmatched valid rows in one statement).
        foreach ($updates as $key => $ids) {
            [$status, $decision, $match] = explode('|', $key);
            foreach (array_chunk($ids, 1000) as $chunk) {
                DB::table('event_import_rows')->whereIn('id', $chunk)
                    ->update(['status' => $status, 'match_lider_id' => $match ?: null, 'decision' => $decision, 'updated_at' => now()]);
            }
        }

        $this->recount($import);
    }

    public function recount(EventImport $import): array
    {
        $rows = DB::table('event_import_rows')->where('import_id', $import->id)
            ->selectRaw('status, decision, CASE WHEN match_lider_id IS NULL THEN 0 ELSE 1 END AS plataforma, CASE WHEN attendee_id IS NULL THEN 0 ELSE 1 END AS registrada, COUNT(*) AS total')
            ->groupBy('status', 'decision', 'plataforma', 'registrada')->get();

        $sum = fn (callable $filter) => (int) $rows->filter($filter)->sum('total');
        $counts = [
            'total' => $sum(fn () => true),
            'validas' => $sum(fn ($r) => $r->status === 'valida'),
            'en_plataforma' => $sum(fn ($r) => $r->status === 'valida' && (int) $r->plataforma === 1),
            'nuevas' => $sum(fn ($r) => $r->status === 'valida' && (int) $r->plataforma === 0),
            'errores' => $sum(fn ($r) => $r->status === 'error'),
            'no_autorizaron' => $sum(fn ($r) => $r->status === 'no_autorizo'),
            'duplicadas' => $sum(fn ($r) => $r->status === 'duplicada'),
            'en_evento' => $sum(fn ($r) => $r->status === 'en_evento'),
            'a_importar' => $sum(fn ($r) => $r->status === 'valida' && $r->decision !== 'omitir' && (int) $r->registrada === 0),
            'omitidas' => $sum(fn ($r) => $r->status === 'valida' && $r->decision === 'omitir'),
            'importadas' => $sum(fn ($r) => (int) $r->registrada === 1),
        ];
        $counts['autorizaron'] = $counts['total'] - $counts['no_autorizaron'];
        $import->forceFill(['counts' => $counts])->save();

        return $counts;
    }

    // [normalized header => field] of the latest import of the campaign whose columns were reviewed.
    public function savedMapping(string $campaignId): array
    {
        $last = EventImport::where('campaign_id', $campaignId)->whereIn('status', ['revisado', 'confirmado'])
            ->whereNotNull('column_mapping')->orderByDesc('updated_at')->first(['headers', 'column_mapping']);
        if (! $last) {
            return [];
        }

        $saved = [];
        foreach ($last->column_mapping as $field => $index) {
            $header = $index !== null ? ($last->headers[$index] ?? null) : null;
            if ($header !== null && ! str_starts_with($header, 'Columna ')) {
                $saved[ImportColumns::normalize($header)] = $field;
            }
        }

        return $saved;
    }

    public function statusReason(string $status): string
    {
        return match ($status) {
            'error' => 'tiene datos por corregir.',
            'no_autorizo' => 'en «Autorizó» no dice SÍ: la persona no autorizó el tratamiento de sus datos.',
            'duplicada' => 'la cédula ya aparece en una fila anterior del archivo.',
            'en_evento' => 'esta persona ya está registrada en el evento.',
            default => 'revise la fila.',
        };
    }

    private function audit(User $user, string $action, EventImport $import, array $metadata): void
    {
        AuditLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'entity_type' => 'evento_importacion',
            'entity_id' => $import->id,
            'metadata' => ['event_id' => $import->event_id, 'sha256' => $import->file_sha256, ...$metadata],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
