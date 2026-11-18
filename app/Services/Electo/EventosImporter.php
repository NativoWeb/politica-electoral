<?php

namespace App\Services\Electo;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

// eventos.csv + eventoasistentes.csv -> events (cerrados) + event_organizers + event_attendees (source 'electo',
// vinculado to the ficha). An Electo event on the same day and municipio as an event the campaign already has is that
// same event (Barrancabermeja 18-09-2026): it is not created again and its attendance is not imported twice.
// client_uuid is a UUID v5 of the Electo id, so the attendance is idempotent even without the lineage table.
class EventosImporter
{
    public const ENTIDAD_EVENTO = 'evento';

    public const ENTIDAD_ASISTENCIA = 'evento_asistente';

    public const EVENT_TYPE = 'Importado de Electo';

    // Namespace of the v5 client_uuid of the imported attendance.
    public const UUID_NAMESPACE = '6f1c3a52-6b7e-5d0e-9a1f-3e1c0e5a7d21';

    private ?string $typeId = null;

    public function __construct(private readonly ImportContext $ctx) {}

    public function run(): void
    {
        foreach ($this->ctx->batches('eventos') as $rows) {
            $this->ctx->transaction(fn () => $this->eventos($rows));
        }
        $this->ctx->info('  eventos listos');

        $asistentes = iterator_to_array(CsvReader::rows($this->ctx->file('eventoasistentes')), false);
        $names = $this->votanteNames(array_map(fn ($r) => Normalizer::int($r['fkvotante']), $asistentes));
        foreach (array_chunk($asistentes, $this->ctx->batchSize) as $rows) {
            $this->ctx->transaction(fn () => $this->asistentes($rows, $names));
        }
        $this->ctx->info('  asistentes listos');
    }

    private function eventType(): string
    {
        return $this->typeId ??= DB::table('event_types')->where('campaign_id', $this->ctx->campaignId)->where('nombre', self::EVENT_TYPE)->value('id')
            ?? tap((string) Str::uuid(), fn ($id) => DB::table('event_types')->insert([
                // Inactive: imported history only, it is not offered when creating new events.
                'id' => $id, 'campaign_id' => $this->ctx->campaignId, 'nombre' => self::EVENT_TYPE, 'is_active' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]));
    }

    private function eventos(array $rows): void
    {
        $done = $this->ctx->lineage(self::ENTIDAD_EVENTO, array_map(fn ($r) => Normalizer::int($r['id']), $rows));
        $organizers = $this->ctx->lineage(PersonasImporter::ENTIDAD, array_map(fn ($r) => Normalizer::int($r['fkorganiza']), $rows));
        $lineage = [];
        foreach ($rows as $row) {
            $id = Normalizer::int($row['id']);
            if ($id === null || isset($done[$id])) {
                $this->ctx->report->count(self::ENTIDAD_EVENTO, $id === null ? 'omitido:sin_id' : 'ya_importado');

                continue;
            }
            $starts = Normalizer::dateTime($row['fechahorainicial']);
            $code = Normalizer::divipola($row['fkmunicipio']);
            $municipio = $this->ctx->municipio($code);
            if ($starts === null || $municipio === null) {
                if ($municipio === null && $code !== null) {
                    $this->ctx->report->municipio('eventos', $code);
                }
                $lineage[] = [$id, null, 'omitido', $starts === null ? 'sin_fecha' : 'sin_municipio'];

                continue;
            }
            $existing = DB::table('events')->where('campaign_id', $this->ctx->campaignId)->whereNull('deleted_at')
                ->where(fn ($q) => $q->whereNull('origen')->orWhere('origen', '<>', ImportContext::ORIGEN))
                ->where('municipio_id', $municipio['id'])->whereDate('starts_at', substr($starts, 0, 10))->value('id');
            if ($existing) {
                $lineage[] = [$id, $existing, 'fusionado', 'evento_existente'];

                continue;
            }

            $localId = (string) Str::uuid();
            [$descripcion] = Normalizer::text($row['observacion']);
            // Neither the barrio nor the event type catalog came: codes kept, barrio pending for the team.
            $codes = array_filter(['barrio' => trim($row['fkbarrio']), 'tipo_evento' => trim($row['fkeventotipo'])], fn ($v) => $v !== '' && $v !== '0');
            $pending = isset($codes['barrio']) ? ['barrio'] : [];
            foreach ($pending as $field) {
                $this->ctx->report->porCompletar('events', $field);
            }
            $created = Normalizer::dateTime($row['createdat']) ?? now()->toDateTimeString();
            DB::table('events')->insert([
                'id' => $localId, 'campaign_id' => $this->ctx->campaignId, 'event_type_id' => $this->eventType(),
                'nombre' => Normalizer::singleLine($row['detalle'], 200) ?? mb_substr('Evento Electo '.substr($starts, 0, 10).' '.$municipio['name'], 0, 200),
                'descripcion' => $descripcion, 'starts_at' => $starts, 'ends_at' => Normalizer::dateTime($row['fechahorafinal']),
                'sitio_encuentro' => Normalizer::singleLine($row['direccion'], 255) ?? 'Sin dato',
                'provincia_id' => $this->provinciaId($municipio['id']), 'municipio_id' => $municipio['id'],
                'status' => 'cerrado', 'public_token' => Str::random(43), 'registration_closes_at' => null, 'consent_text_id' => null,
                'created_by' => null, 'origen' => ImportContext::ORIGEN, 'origen_id' => $id,
                'migracion_flags' => ImportContext::json(['electo_codigos' => $codes, 'por_completar' => $pending]),
                'created_at' => $created, 'updated_at' => Normalizer::dateTime($row['updatedat']) ?? $created,
            ]);
            $organizer = $organizers[Normalizer::int($row['fkorganiza'])] ?? null;
            if ($organizer && ($ficha = DB::table('lideres')->where('id', $organizer)->first(['nombre', 'cedula', 'telefono']))) {
                DB::table('event_organizers')->insert([
                    'id' => (string) Str::uuid(), 'event_id' => $localId, 'lider_id' => $organizer, 'nombre' => mb_substr($ficha->nombre, 0, 200),
                    'cedula' => $ficha->cedula !== null && strlen($ficha->cedula) <= 20 ? $ficha->cedula : null,
                    'telefono' => ImportContext::cut($ficha->telefono, 30), 'rol' => 'Organizador (Electo)', 'created_at' => $created, 'updated_at' => $created,
                ]);
            }
            $lineage[] = [$id, $localId, 'creado', null];
        }
        $this->ctx->record(self::ENTIDAD_EVENTO, $lineage);
    }

    private function provinciaId(string $municipioId): ?string
    {
        return DB::table('geographic_units as m')->join('geographic_units as p', 'p.id', '=', 'm.parent_id')
            ->where('m.id', $municipioId)->where('p.type', 'province')->value('p.id');
    }

    // [electo votante id => [nombres, apellidos]] of the attendees, read again from votantes.csv (lideres keeps one name).
    private function votanteNames(array $ids): array
    {
        $wanted = array_flip(array_filter($ids, fn ($id) => $id !== null));
        $names = [];
        foreach (CsvReader::rows($this->ctx->file('votantes')) as $row) {
            $id = Normalizer::int($row['id']);
            if ($id !== null && isset($wanted[$id])) {
                $names[$id] = [self::upper($row['nombres']), self::upper($row['apellidos'])];
            }
        }

        return $names;
    }

    private static function upper(?string $value): ?string
    {
        $value = Normalizer::singleLine($value, 100);

        return $value === null ? null : mb_strtoupper($value, 'UTF-8');
    }

    private function asistentes(array $rows, array $names): void
    {
        $done = $this->ctx->lineage(self::ENTIDAD_ASISTENCIA, array_map(fn ($r) => Normalizer::int($r['id']), $rows));
        $events = $this->eventMap(array_map(fn ($r) => Normalizer::int($r['fkevento']), $rows));
        $lideres = $this->ctx->lineage(PersonasImporter::ENTIDAD, array_map(fn ($r) => Normalizer::int($r['fkvotante']), $rows));
        $fichas = DB::table('lideres')->whereIn('id', array_values(array_unique($lideres)))
            ->get(['id', 'nombre', 'cedula', 'telefono', 'fecha_nacimiento', 'direccion', 'autoriza_datos'])->keyBy('id');
        $taken = [];
        foreach (DB::table('event_attendees')->whereIn('event_id', array_values(array_unique(array_column($events, 'id'))))->get(['event_id', 'cedula', 'lider_id']) as $a) {
            if ($a->cedula !== null) {
                $taken[$a->event_id.'|'.$a->cedula] = true;
            }
            if ($a->lider_id !== null) {
                $taken[$a->event_id.'|'.$a->lider_id] = true;
            }
        }

        $insert = [];
        $lineage = [];
        foreach ($rows as $row) {
            $id = Normalizer::int($row['id']);
            if ($id === null || isset($done[$id])) {
                $this->ctx->report->count(self::ENTIDAD_ASISTENCIA, $id === null ? 'omitido:sin_id' : 'ya_importado');

                continue;
            }
            $event = $events[Normalizer::int($row['fkevento'])] ?? null;
            if ($event === null || $event['accion'] !== 'creado') {
                $lineage[] = [$id, null, 'omitido', $event === null ? 'evento_no_importado' : 'evento_existente'];

                continue;
            }
            $votante = Normalizer::int($row['fkvotante']);
            $ficha = $votante === null ? null : ($fichas[$lideres[$votante] ?? ''] ?? null);
            if ($ficha === null) {
                $lineage[] = [$id, null, 'omitido', $votante === null ? 'sin_votante' : 'persona_no_importada'];

                continue;
            }
            // The same person twice in an event: by cédula (unique index) or, without one, by ficha.
            $cedula = $ficha->cedula !== null && strlen($ficha->cedula) <= 20 ? $ficha->cedula : null;
            if (($cedula !== null && isset($taken[$event['id'].'|'.$cedula])) || isset($taken[$event['id'].'|'.$ficha->id])) {
                $lineage[] = [$id, null, 'omitido', 'asistencia_duplicada'];

                continue;
            }
            if ($cedula !== null) {
                $taken[$event['id'].'|'.$cedula] = true;
            }
            $taken[$event['id'].'|'.$ficha->id] = true;
            [$nombres, $apellidos] = $names[$votante] ?? [null, null];
            $created = Normalizer::dateTime($row['createdat']) ?? now()->toDateTimeString();
            $localId = (string) Str::uuid();
            $insert[] = [
                'id' => $localId, 'event_id' => $event['id'], 'campaign_id' => $this->ctx->campaignId, 'lider_id' => $ficha->id,
                'nombres' => $nombres ?? mb_substr($ficha->nombre, 0, 100), 'apellidos' => $apellidos ?? '',
                'cedula' => $cedula, 'celular' => ImportContext::cut($ficha->telefono, 20), 'fijo' => null,
                'fecha_nacimiento' => $ficha->fecha_nacimiento, 'direccion' => ImportContext::cut($ficha->direccion, 255),
                'barrio' => null, 'barrio_normalizado' => null, 'autoriza_datos' => (bool) $ficha->autoriza_datos, 'consent_id' => null,
                'source' => ImportContext::ORIGEN, 'link_status' => 'vinculado', 'registered_by' => null,
                'client_uuid' => Uuid::uuid5(self::UUID_NAMESPACE, 'eventoasistente-'.$id)->toString(),
                'ip' => null, 'user_agent' => null, 'nota' => Normalizer::singleLine($row['observacion'], 255),
                'origen' => ImportContext::ORIGEN, 'origen_id' => $id,
                'created_at' => $created, 'updated_at' => Normalizer::dateTime($row['updatedat']) ?? $created,
            ];
            $lineage[] = [$id, $localId, 'creado', null];
        }
        foreach (array_chunk($insert, 500) as $chunk) {
            DB::table('event_attendees')->insert($chunk);
        }
        $this->ctx->record(self::ENTIDAD_ASISTENCIA, $lineage);
    }

    // [electo event id => [id, accion]] of the events already mapped (created or matched).
    private function eventMap(array $ids): array
    {
        $map = [];
        foreach (DB::table('electo_import_rows')->where('entidad', self::ENTIDAD_EVENTO)->whereIn('origen_id', array_values(array_unique(array_filter($ids))))
            ->whereIn('accion', ['creado', 'fusionado'])->get(['origen_id', 'local_id', 'accion']) as $r) {
            $map[(int) $r->origen_id] = ['id' => $r->local_id, 'accion' => $r->accion];
        }

        return $map;
    }
}
