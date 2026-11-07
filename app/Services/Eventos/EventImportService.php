<?php

namespace App\Services\Eventos;

use App\Actions\Eventos\RegisterAttendee;
use App\Http\Requests\Eventos\AttendeeInput;
use App\Models\AuditLog;
use App\Models\CampaignEvent;
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
// the preview and confirm. Since 2026-11-07 (decision of the campaign) no data authorization is asked: every Excel
// file is a list (registerList()) and every valid row the operator kept is registered, linked to the ficha it
// matches or pendiente de aprobar. OCR loads and the imports staged before that day keep the full-row flow
// (RegisterAttendee, source 'excel'/'ocr', import_id). Everything runs in transactions of BATCH rows and with a
// client_uuid derived from (import, row): confirming again creates nothing new. Everything runs synchronously (no queue worker in production) within MAX_ROWS.
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
        private AttendeeMatcher $matcher,
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
        $sheets = $this->reader->sheets($file->getRealPath(), $extension);
        $sheetName = self::defaultSheet($sheets, $event);
        $sheet = $this->reader->read($file->getRealPath(), $extension, $sheetName);
        $mapping = ImportColumns::detect($sheet['headers'], $this->savedMapping($event->campaign_id));

        $id = (string) Str::uuid();
        $path = $file->storeAs("eventos/importaciones/{$event->id}", "{$id}.{$extension}", self::DISK);
        if ($path === false) {
            throw new RuntimeException('No se pudo guardar el archivo. Intente de nuevo.');
        }

        try {
            $import = DB::transaction(function () use ($id, $event, $file, $path, $sha, $sheet, $mapping, $user, $sheets, $sheetName) {
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
                    'sheet_name' => count($sheets) > 1 ? $sheetName : null,
                    'sheets' => count($sheets) > 1 ? $sheets : null,
                ]);
                $import->save();
                $this->insertRows($id, $sheet['rows']);

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

    // A workbook with one sheet per jornada: another sheet of the same stored file replaces the rows of this import
    // (back to "subido", columns guessed again). Not once it is confirmed (the caller checks it).
    public function changeSheet(EventImport $import, CampaignEvent $event, string $sheetName, User $user): void
    {
        $names = array_column($import->sheets ?? [], 'nombre');
        if (! in_array($sheetName, $names, true)) {
            throw new RuntimeException('Elija una hoja que exista en el archivo.');
        }
        $extension = strtolower(pathinfo($import->file_path, PATHINFO_EXTENSION));
        $sheet = $this->reader->read(Storage::disk(self::DISK)->path($import->file_path), $extension, $sheetName);
        $mapping = ImportColumns::detect($sheet['headers'], $this->savedMapping($event->campaign_id));

        DB::transaction(function () use ($import, $sheet, $mapping, $sheetName) {
            DB::table('event_import_rows')->where('import_id', $import->id)->delete();
            $import->forceFill([
                'sheet_name' => $sheetName,
                'header_row' => $sheet['header_row'],
                'headers' => $sheet['headers'],
                'column_mapping' => $mapping,
                'status' => 'subido',
                'counts' => null,
                'total_rows' => count($sheet['rows']),
            ])->save();
            $this->insertRows($import->id, $sheet['rows']);
        });

        $this->audit($user, 'EVENTO_IMPORTACION_HOJA', $import, ['filas' => $import->total_rows]);
    }

    // The sheet named like the event's municipio ("GIRÓN" for an event in Girón), otherwise "Asistentes" (the
    // template) or the first one. null with a single sheet (the reader picks it).
    public static function defaultSheet(array $sheets, CampaignEvent $event): ?string
    {
        if (count($sheets) < 2) {
            return null;
        }
        $municipio = ImportColumns::normalize($event->municipio?->canonical_name);
        foreach ($sheets as $sheet) {
            $name = ImportColumns::normalize($sheet['nombre']);
            if ($municipio !== '' && $name !== '' && (str_contains($name, $municipio) || str_contains($municipio, $name))) {
                return $sheet['nombre'];
            }
        }
        foreach ($sheets as $sheet) {
            if (ImportColumns::normalize($sheet['nombre']) === 'asistentes') {
                return $sheet['nombre'];
            }
        }

        return $sheets[0]['nombre'];
    }

    private function insertRows(string $importId, array $rows): void
    {
        $now = now();
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('event_import_rows')->insert(array_map(fn ($row) => [
                'id' => (string) Str::uuid(),
                'import_id' => $importId,
                'row_number' => $row['row_number'],
                'raw' => json_encode((object) $row['cells'], JSON_UNESCAPED_UNICODE),
                'status' => 'pendiente',
                'decision' => 'omitir',
                'edited' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }
    }

    // Applies the column mapping to every row (corrections made before are replaced) and validates them.
    public function applyMapping(EventImport $import, CampaignEvent $event, array $mapping, User $user): void
    {
        $mapping = array_merge(array_fill_keys(array_keys(ImportColumns::FIELDS), null), $mapping);

        $list = $this->isList($import);

        DB::transaction(function () use ($import, $event, $mapping, $list) {
            $import->forceFill([
                'column_mapping' => $mapping,
                'status' => 'revisado',
                'counts' => null,
            ])->save();

            // One upsert per 500 rows instead of one UPDATE per row; status back to pendiente so classify() resets decisions.
            $now = now();
            DB::table('event_import_rows')->where('import_id', $import->id)->select(['id', 'row_number', 'raw'])
                ->chunkById(500, function ($rows) use ($mapping, $import, $now, $list) {
                    $values = [];
                    foreach ($rows as $row) {
                        $mapped = $this->validator->map(json_decode($row->raw, true) ?? [], $mapping);
                        $errors = ($list ? $this->validator->validateList($mapped) : $this->validator->validate($mapped))['errors'];
                        $values[] = [
                            'id' => $row->id, 'import_id' => $import->id, 'row_number' => $row->row_number, 'raw' => $row->raw,
                            'mapped' => json_encode($mapped, JSON_UNESCAPED_UNICODE),
                            'errors' => $errors ? json_encode($errors, JSON_UNESCAPED_UNICODE) : null,
                            'status' => 'pendiente', 'decision' => 'omitir', 'edited' => false, 'created_at' => $now, 'updated_at' => $now,
                            'sin_autorizacion' => $list, 'suggested_lider_id' => null, 'suggestion' => null,
                        ];
                    }
                    DB::table('event_import_rows')->upsert($values, ['id'], ['mapped', 'errors', 'status', 'decision', 'edited', 'updated_at', 'sin_autorizacion', 'suggested_lider_id', 'suggestion']);
                });

            $this->classify($import, $event);
        });

        $this->audit($user, 'EVENTO_IMPORTACION_COLUMNAS', $import, ['campos' => array_keys(array_filter($mapping, fn ($v) => $v !== null)), 'sin_autorizacion' => $list]);
    }

    // Every Excel file is a list (its rows flagged sin_autorizacion, a name kept from before 2026-11-07): only the name
    // is required and people are found by cédula or by phone + name. An OCR load keeps the full-row validation.
    public function isList(EventImport $import): bool
    {
        return ! $import->isOcr();
    }

    // Corrects cells of one row and/or changes its decision ('importar' or 'omitir'). Returns the fresh row.
    // $suggestion ('confirmar' | 'rechazar'): the operator's answer to "¿Es esta persona?" on a row of a list without
    // authorization; confirming links the row to that ficha (history only), rejecting leaves it only in the event.
    public function updateRow(EventImport $import, CampaignEvent $event, EventImportRow $row, array $values, ?string $decision, ?string $suggestion = null): EventImportRow
    {
        return DB::transaction(function () use ($import, $event, $row, $values, $decision, $suggestion) {
            if ($suggestion !== null) {
                if (! $row->sin_autorizacion || ! $row->suggested_lider_id || $values !== []) {
                    throw new RuntimeException('Esta fila no tiene una persona sugerida para confirmar.');
                }
                if ($suggestion === 'confirmar' && ! self::suggestionVisible($row->suggested_lider_id, $event)) {
                    throw new RuntimeException('Esta fila no tiene una persona sugerida para confirmar.');
                }
                $row->forceFill(['suggestion' => $suggestion === 'confirmar' ? 'confirmada' : 'rechazada'])->save();
                $this->classify($import, $event);
                $row->refresh();
            }
            if ($values !== []) {
                $mapped = array_merge($row->mapped ?? [], $values);
                if (array_key_exists('celular', $values)) {
                    $mapped['telefonos'] = null;
                }
                $errors = ($row->sin_autorizacion ? $this->validator->validateList($mapped) : $this->validator->validate($mapped))['errors'];
                $fill = ['mapped' => $mapped, 'errors' => $errors ?: null, 'edited' => true];
                if ($row->sin_autorizacion) {
                    // Another name or phone may be another person: the suggestion is asked again.
                    $fill += ['suggested_lider_id' => null, 'suggestion' => null];
                }
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
                $importable = in_array($row->status, ['valida', 'sin_ficha'], true);
                if ($decision === 'importar' && ! $importable) {
                    throw new RuntimeException('Esta fila no se puede importar: '.$this->statusReason($row->status));
                }
                $row->forceFill(['decision' => $decision === 'importar' ? ($row->match_lider_id ? 'vincular' : 'crear') : 'omitir'])->save();
                $this->recount($import);
            }

            return $row->refresh();
        });
    }

    // Registers the importable rows: list rows through registerList(), full rows (OCR, imports staged before
    // 2026-11-07) through RegisterAttendee. The import keeps the first confirmation (who, when); every imported row
    // keeps its own. Returns ['nuevos', 'ya_estaban', 'historial', 'solo_evento'] (solo_evento = new list rows,
    // pendientes de aprobar).
    public function confirm(EventImport $import, CampaignEvent $event, User $user, ?string $ip, ?string $userAgent): array
    {
        if ($import->status !== 'confirmado') {
            $import->forceFill([
                'status' => 'confirmado',
                'consent_declared' => false,
                'confirmed_by' => $user->id,
                'confirmed_at' => now(),
            ])->save();
        }

        $now = now();
        $result = ['nuevos' => 0, 'ya_estaban' => 0, 'historial' => 0, 'solo_evento' => 0];
        $this->registerList($import, $event, $user, $ip, $userAgent, $now, $result);

        EventImportRow::where('import_id', $import->id)->where('status', 'valida')->where('decision', '!=', 'omitir')
            ->where('sin_autorizacion', false)->whereNull('attendee_id')
            ->chunkById(self::BATCH, function ($rows) use ($import, $event, $user, $ip, $userAgent, $now, &$result) {
                DB::transaction(function () use ($rows, $import, $event, $user, $ip, $userAgent, $now, &$result) {
                    $items = [];
                    foreach ($rows as $row) {
                        $data = ImportRowValidator::data($row->mapped ?? []);
                        $data['client_uuid'] = self::clientUuid($import->id, $row->row_number);
                        $items[$row->id] = $data;
                    }

                    $linked = [];
                    $source = $import->isOcr() ? 'ocr' : 'excel';
                    $outcomes = $this->register->handleMany($event, $items, $source, $user, $ip, $userAgent, $import->id);
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
        $this->audit($user, 'EVENTO_IMPORTACION_CONFIRMADA', $import, $result + ['tipo' => $import->kind ?? 'excel']);

        return $result;
    }

    // Rows of a list: attendees without consent (autoriza_datos false, no data_consents row). A row that matched a
    // ficha (by cédula or confirmed by phone + name) is linked to it (link_status vinculado): only the event's history,
    // the ficha is never created nor changed. The rest stay pendiente de aprobar (ApproveAttendees). Idempotent by
    // client_uuid, by cédula and by lider in the event.
    private function registerList(EventImport $import, CampaignEvent $event, User $user, ?string $ip, ?string $userAgent, $now, array &$result): void
    {
        EventImportRow::where('import_id', $import->id)->where('sin_autorizacion', true)->whereIn('status', ['valida', 'sin_ficha'])
            ->where('decision', '!=', 'omitir')->whereNull('attendee_id')
            ->chunkById(self::BATCH, function ($rows) use ($import, $event, $user, $ip, $userAgent, $now, &$result) {
                DB::transaction(function () use ($rows, $import, $event, $user, $ip, $userAgent, $now, &$result) {
                    $liderIds = $rows->pluck('match_lider_id')->filter()->unique()->values()->all();
                    $ownLideres = array_flip(DB::table('lideres')->where('campaign_id', $event->campaign_id)->whereIn('id', $liderIds)->pluck('id')->all());
                    $linked = [];
                    foreach ($rows as $row) {
                        $data = ImportRowValidator::listData($row->mapped ?? []);
                        $liderId = $row->status === 'valida' && isset($ownLideres[$row->match_lider_id]) ? $row->match_lider_id : null;
                        if ($row->status === 'valida' && $liderId === null) {
                            continue;
                        }
                        $clientUuid = self::clientUuid($import->id, $row->row_number);
                        $previous = EventAttendee::where('client_uuid', $clientUuid)->value('id')
                            ?? ($data['cedula'] ? EventAttendee::where('event_id', $event->id)->where('cedula', $data['cedula'])->value('id') : null)
                            ?? ($liderId ? EventAttendee::where('event_id', $event->id)->where('lider_id', $liderId)->value('id') : null);
                        if ($previous) {
                            $linked[$row->id] = $previous;
                            $result['ya_estaban']++;

                            continue;
                        }
                        $attendee = (new EventAttendee)->forceFill([
                            'event_id' => $event->id,
                            'campaign_id' => $event->campaign_id,
                            'lider_id' => $liderId,
                            'nombres' => mb_substr($data['nombres'] ?? '', 0, 100),
                            'apellidos' => mb_substr($data['apellidos'] ?? '', 0, 100),
                            'cedula' => $data['cedula'] ?: null,
                            'celular' => $data['celular'],
                            'fijo' => $data['fijo'],
                            'fecha_nacimiento' => $data['fecha_nacimiento'],
                            'direccion' => $data['direccion'] ?? null,
                            'barrio' => $data['barrio'] ?? null,
                            'barrio_normalizado' => AttendeeInput::barrioKey($data['barrio'] ?? null),
                            'nota' => $data['nota'],
                            'autoriza_datos' => false,
                            'source' => 'excel',
                            'import_id' => $import->id,
                            'link_status' => $liderId ? 'vinculado' : 'pendiente',
                            'registered_by' => $user->id,
                            'client_uuid' => $clientUuid,
                            'ip' => $ip,
                            'user_agent' => $userAgent,
                        ]);
                        $attendee->save();
                        $linked[$row->id] = $attendee->id;
                        $result['nuevos']++;
                        $result[$liderId ? 'historial' : 'solo_evento']++;
                    }
                    $this->linkAttendees($linked, $user->id, $now, false);
                });
            });
    }

    // [import row id => attendee id] in one UPDATE, with the declaration and who confirmed each row.
    private function linkAttendees(array $linked, string $userId, $now, bool $declared = true): void
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
            [...$bindings, $declared, $userId, $now, $now, ...array_keys($linked)],
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

    // Statuses of every row after the cells changed: error, duplicada (same cédula on an earlier importable row), en_evento (already
    // registered in the event) or valida, with the lider of the campaign it matches. The decision
    // goes back to its default (valid → import, the rest → skip) only for rows whose status or match changed, so a
    // "No importar" chosen on a valid row survives corrections of other rows.
    // Rows of a list (sin_autorizacion): valida = matches a ficha by cédula or by a confirmed
    // suggestion (linked as history), sugerida = same phone and a word of the name as a lider of the event's or the
    // row's municipio (waits for the operator, never linked on its own), sin_ficha = nobody (a new attendee to approve).
    public function classify(EventImport $import, CampaignEvent $event): void
    {
        $rows = EventImportRow::where('import_id', $import->id)->orderBy('row_number')
            ->get(['id', 'row_number', 'mapped', 'errors', 'status', 'decision', 'match_lider_id', 'attendee_id', 'confidence',
                'sin_autorizacion', 'suggested_lider_id', 'suggestion']);
        $threshold = OcrSettings::threshold();

        $cedulas = [];
        foreach ($rows as $row) {
            $cedulas[$row->id] = ! $row->errors ? ImportRowValidator::cedulaOf($row->mapped ?? []) : null;
        }
        $known = array_values(array_unique(array_filter($cedulas)));

        $inEvent = [];
        $lideres = [];
        foreach (array_chunk($known, 1000) as $chunk) {
            foreach (EventAttendee::where('event_id', $event->id)->whereIn('cedula', $chunk)->pluck('cedula') as $cedula) {
                $inEvent[$cedula] = true;
            }
            $lideres += DB::table('lideres')->where('campaign_id', $event->campaign_id)->whereIn('cedula', $chunk)
                ->pluck('id', 'cedula')->all();
        }
        $list = $rows->contains(fn ($r) => $r->sin_autorizacion)
            ? $this->listContext($event, $rows->filter(fn ($r) => $r->sin_autorizacion && ! $r->errors), $cedulas, $lideres)
            : null;

        $seen = [];
        $seenLider = [];
        $seenKey = [];
        $updates = [];
        foreach ($rows as $row) {
            $value = $cedulas[$row->id];
            $match = $value !== null ? ($lideres[$value] ?? null) : null;
            $suggested = $row->suggested_lider_id;
            $suggestion = $row->suggestion;
            if ($row->sin_autorizacion) {
                [$status, $match, $suggested, $suggestion, $key] = $this->listStatus($row, $value, $match, $list, $seen, $seenLider, $seenKey, $inEvent);
                if ($match) {
                    $seenLider[$match] = true;
                }
                if ($key) {
                    $seenKey[$key] = true;
                }
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

            $toImport = match ($status) {
                'valida' => $match ? 'vincular' : 'crear',
                'sin_ficha' => 'crear',
                default => 'omitir',
            };
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
            if ($changed || $decision !== $row->decision || $suggested !== $row->suggested_lider_id || $suggestion !== $row->suggestion) {
                $updates["{$status}|{$decision}|{$match}|{$suggested}|{$suggestion}"][] = $row->id;
            }
        }

        // Rows that end up the same are updated together (all the unmatched valid rows in one statement).
        foreach ($updates as $key => $ids) {
            [$status, $decision, $match, $suggested, $suggestion] = explode('|', $key);
            foreach (array_chunk($ids, 1000) as $chunk) {
                DB::table('event_import_rows')->whereIn('id', $chunk)->update([
                    'status' => $status, 'match_lider_id' => $match ?: null, 'decision' => $decision,
                    'suggested_lider_id' => $suggested ?: null, 'suggestion' => $suggestion ?: null, 'updated_at' => now(),
                ]);
            }
        }

        $this->recount($import);
    }

    // What a list row is: [status, match, suggested lider, suggestion, key of an unlinked person (name|phone)].
    private function listStatus(EventImportRow $row, ?string $cedula, ?string $match, ?array $context, array $seen, array $seenLider, array $seenKey, array $inEvent): array
    {
        $suggested = $row->suggested_lider_id;
        $suggestion = $row->suggestion;
        if ($row->errors) {
            return ['error', null, $suggested, $suggestion, null];
        }
        if ($match === null && $suggestion === 'confirmada' && $suggested) {
            $match = $suggested;
        }
        $key = null;
        if ($match === null) {
            $candidate = $context['suggest'][$row->id] ?? null;
            if ($candidate !== $suggested) {
                [$suggested, $suggestion] = [$candidate, null];
            }
            $key = $context['keys'][$row->id] ?? null;
        }

        $registered = $row->attendee_id !== null;
        $status = match (true) {
            ($cedula && isset($seen[$cedula])) || ($match && isset($seenLider[$match])) || ($key && isset($seenKey[$key])) => 'duplicada',
            ! $registered && (($cedula && isset($inEvent[$cedula])) || ($match && isset($context['lideres_en_evento'][$match]))
                || ($key && isset($context['keys_en_evento'][$key]))) => 'en_evento',
            $match !== null => 'valida',
            $suggested !== null && $suggestion !== 'rechazada' => 'sugerida',
            default => 'sin_ficha',
        };

        return [$status, $match, $suggested, $suggestion, $key];
    }

    // Suggestions show cargo and municipio of a ficha: only for users who may see Votantes or the Mapa.
    public static function canSeeSuggestions(): bool
    {
        $user = request()->user();

        return (bool) ($user?->hasPermission('view.votantes') || $user?->hasPermission('view.mapa'));
    }

    // The suggested lider is of the event's campaign, inside the user's territory, and the user may see fichas.
    public static function suggestionVisible(string $liderId, CampaignEvent $event): bool
    {
        if (! self::canSeeSuggestions()) {
            return false;
        }
        $geo = DB::table('lideres')->where('id', $liderId)->where('campaign_id', $event->campaign_id)->value('geographic_unit_id');
        $allowed = request()->user()?->allowedGeoIds();

        return $geo !== null && ($allowed === null || in_array($geo, $allowed, true));
    }

    // Suggestions by phone + name for the list rows that match nobody by cédula, the lideres already in the event and
    // the name|phone of the event's attendees without ficha (to spot a list loaded twice). Suggestions only come from
    // the event's municipio and the row's (read from its "Municipio" column) when it is inside the user's territory.
    private function listContext(CampaignEvent $event, $rows, array $cedulas, array $lideres): array
    {
        $allowed = request()->user()?->allowedGeoIds();
        $municipios = null;
        $candidates = [];
        $keys = [];
        foreach ($rows as $row) {
            $mapped = $row->mapped ?? [];
            $phones = AttendeeMatcher::phones($mapped['telefonos'] ?? $mapped['celular'] ?? null);
            $tokens = AttendeeMatcher::nameTokens(trim(($mapped['nombres'] ?? '').' '.($mapped['apellidos'] ?? '')));
            if ($phones && $tokens) {
                $sorted = $tokens;
                sort($sorted);
                $keys[$row->id] = implode(' ', $sorted).'|'.$phones[0];
            }
            $cedula = $cedulas[$row->id] ?? null;
            if ($cedula !== null && isset($lideres[$cedula])) {
                continue;
            }
            $geo = [$event->municipio_id];
            if (! empty($mapped['municipio'])) {
                $municipios ??= AttendeeMatcher::municipios();
                $own = $municipios[ImportColumns::normalize($mapped['municipio'])] ?? null;
                if ($own && ($allowed === null || in_array($own, $allowed, true))) {
                    $geo[] = $own;
                }
            }
            $candidates[$row->id] = ['phones' => $phones, 'tokens' => $tokens, 'geo' => array_values(array_unique(array_filter($geo))), 'cedula' => $cedula];
        }

        $keysInEvent = [];
        EventAttendee::where('event_id', $event->id)->whereNull('lider_id')->whereNull('cedula')
            ->get(['nombres', 'apellidos', 'celular'])->each(function ($a) use (&$keysInEvent) {
                $tokens = AttendeeMatcher::nameTokens($a->nombres.' '.$a->apellidos);
                $phones = AttendeeMatcher::phones($a->celular);
                if ($tokens && $phones) {
                    sort($tokens);
                    $keysInEvent[implode(' ', $tokens).'|'.$phones[0]] = true;
                }
            });

        return [
            // Without view.votantes / view.mapa nobody is suggested: the rows stay "solo en la lista del evento".
            'suggest' => self::canSeeSuggestions() ? $this->matcher->suggest($event->campaign_id, $candidates) : [],
            'keys' => $keys,
            'keys_en_evento' => $keysInEvent,
            'lideres_en_evento' => array_flip(EventAttendee::where('event_id', $event->id)->whereNotNull('lider_id')->pluck('lider_id')->all()),
        ];
    }

    public function recount(EventImport $import): array
    {
        $rows = DB::table('event_import_rows')->where('import_id', $import->id)
            ->selectRaw('status, decision, CASE WHEN match_lider_id IS NULL THEN 0 ELSE 1 END AS plataforma, CASE WHEN attendee_id IS NULL THEN 0 ELSE 1 END AS registrada, CASE WHEN sin_autorizacion THEN 1 ELSE 0 END AS lista, COUNT(*) AS total')
            ->groupBy('status', 'decision', 'plataforma', 'registrada', 'lista')->get();
        $importable = fn ($r) => in_array($r->status, ['valida', 'sin_ficha'], true);

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
            'a_importar' => $sum(fn ($r) => $importable($r) && $r->decision !== 'omitir' && (int) $r->registrada === 0),
            'a_importar_con_autorizacion' => $sum(fn ($r) => $r->status === 'valida' && (int) $r->lista === 0 && $r->decision !== 'omitir' && (int) $r->registrada === 0),
            'omitidas' => $sum(fn ($r) => $importable($r) && $r->decision === 'omitir'),
            'importadas' => $sum(fn ($r) => (int) $r->registrada === 1),
            'sin_autorizacion' => $sum(fn ($r) => (int) $r->lista === 1),
            'sugeridas' => $sum(fn ($r) => $r->status === 'sugerida'),
            'sin_ficha' => $sum(fn ($r) => $r->status === 'sin_ficha'),
        ];
        $counts['autorizaron'] = $counts['total'] - $counts['no_autorizaron'] - $counts['sin_autorizacion'];
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
            'sugerida' => 'primero confirme si es la persona que se sugiere.',
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
