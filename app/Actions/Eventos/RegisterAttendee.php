<?php

namespace App\Actions\Eventos;

use App\Http\Requests\Eventos\AttendeeInput;
use App\Models\AuditLog;
use App\Models\CampaignEvent;
use App\Models\EventAttendee;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The only way an attendee is registered, by the public QR or by an operator. Idempotent by client_uuid and by
// (event, cédula). Everything lands in the EVENT's campaign: the lider lookup filters it explicitly because the
// Lider global scope does not restrict anything without an authenticated user. An existing lider is only linked,
// never modified, and a new person stays "pendiente" until someone approves it (ApproveAttendees).
// Since 2026-11-07 (decision of the campaign) no data authorization is collected: autoriza_datos stays false and no
// data_consents row is written. An Excel/OCR import registers with its own channel and import_id, and audits once
// for the whole file.
class RegisterAttendee
{
    // $data was validated with AttendeeInput (normalized cédula and phones). Returns
    // ['attendee' => EventAttendee|null, 'created' => bool, 'conflict' => bool]; conflict = client_uuid of another event.
    // $options: audit (bool, default true), import_id (Excel import, default null).
    public function handle(CampaignEvent $event, array $data, string $channel, ?User $user, ?string $ip, ?string $userAgent, array $options = []): array
    {
        $audit = $options['audit'] ?? true;
        $importId = $options['import_id'] ?? null;

        $previous = $this->previous($event, $data);
        if ($previous !== null) {
            return $audit ? $this->repeated($event, $previous, $channel, $user, $ip, $userAgent) : $previous;
        }

        $liderId = DB::table('lideres')->where('campaign_id', $event->campaign_id)
            ->where('cedula', $data['cedula'])->value('id');

        try {
            $attendee = DB::transaction(function () use ($event, $data, $channel, $user, $ip, $userAgent, $liderId, $audit, $importId) {
                $attendee = (new EventAttendee)->forceFill([
                    'event_id' => $event->id,
                    'campaign_id' => $event->campaign_id,
                    'lider_id' => $liderId,
                    'nombres' => $data['nombres'],
                    'apellidos' => $data['apellidos'],
                    'cedula' => $data['cedula'],
                    'celular' => $data['celular'],
                    'fijo' => $data['fijo'] ?? null,
                    'fecha_nacimiento' => $data['fecha_nacimiento'] ?? null,
                    'direccion' => $data['direccion'] ?? null,
                    'barrio' => $data['barrio'] ?? null,
                    'barrio_normalizado' => AttendeeInput::barrioKey($data['barrio'] ?? null),
                    'autoriza_datos' => false,
                    'source' => $channel,
                    'import_id' => $importId,
                    'link_status' => $liderId ? 'vinculado' : 'pendiente',
                    'registered_by' => $user?->id,
                    'client_uuid' => $data['client_uuid'],
                    'ip' => $ip,
                    'user_agent' => $userAgent,
                ]);
                $attendee->save();
                if ($audit) {
                    $this->audit($event, $attendee, $channel, 'nuevo', $user, $ip, $userAgent);
                }

                return $attendee;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Same submission or same cédula saved concurrently between the lookup above and the insert.
            $previous = $this->previous($event, $data);
            if ($previous === null) {
                throw $e;
            }

            return $audit ? $this->repeated($event, $previous, $channel, $user, $ip, $userAgent) : $previous;
        }

        return ['attendee' => $attendee, 'created' => true, 'conflict' => false];
    }

    // Many registrations of one event at once (Excel import), with the same rules as handle() but one lookup and one
    // insert for the whole list. $items: [key => validated attendee data]. Items whose client_uuid or cédula is
    // already registered (or repeated inside the list) go through handle(), which returns the previous attendee; if
    // a concurrent registration hits a unique index, the whole list does. Nothing is audited here: the caller audits
    // the batch. Returns [key => ['attendee_id' => ?string, 'created' => bool, 'conflict' => bool]].
    public function handleMany(CampaignEvent $event, array $items, string $channel, ?User $user, ?string $ip, ?string $userAgent, ?string $importId = null): array
    {
        if ($items === []) {
            return [];
        }
        $cedulas = array_values(array_unique(array_map(fn ($data) => $data['cedula'], $items)));
        $taken = array_flip(array_merge(
            EventAttendee::whereIn('client_uuid', array_map(fn ($data) => $data['client_uuid'], $items))->pluck('client_uuid')->all(),
            EventAttendee::where('event_id', $event->id)->whereIn('cedula', $cedulas)->pluck('cedula')->all(),
        ));
        $lideres = DB::table('lideres')->where('campaign_id', $event->campaign_id)->whereIn('cedula', $cedulas)->pluck('id', 'cedula')->all();

        $fresh = [];
        $slow = [];
        foreach ($items as $key => $data) {
            if (isset($taken[$data['client_uuid']]) || isset($taken[$data['cedula']])) {
                $slow[$key] = $data;
            } else {
                $fresh[$key] = $data;
                $taken[$data['cedula']] = true;
            }
        }

        $results = [];
        try {
            $results = DB::transaction(fn () => $this->insertMany($event, $fresh, $lideres, $channel, $user, $ip, $userAgent, $importId));
        } catch (UniqueConstraintViolationException) {
            $slow += $fresh;
        }

        foreach ($slow as $key => $data) {
            $outcome = $this->handle($event, $data, $channel, $user, $ip, $userAgent, ['audit' => false, 'import_id' => $importId]);
            $results[$key] = ['attendee_id' => $outcome['attendee']?->id, 'created' => $outcome['created'], 'conflict' => $outcome['conflict']];
        }

        return $results;
    }

    private function insertMany(CampaignEvent $event, array $items, array $lideres, string $channel, ?User $user, ?string $ip, ?string $userAgent, ?string $importId): array
    {
        $now = now();
        $attendees = [];
        $results = [];
        foreach ($items as $key => $data) {
            $id = (string) Str::uuid();
            $liderId = $lideres[$data['cedula']] ?? null;
            $attendees[] = [
                'id' => $id,
                'event_id' => $event->id,
                'campaign_id' => $event->campaign_id,
                'lider_id' => $liderId,
                'nombres' => $data['nombres'],
                'apellidos' => $data['apellidos'],
                'cedula' => $data['cedula'],
                'celular' => $data['celular'],
                'fijo' => $data['fijo'] ?? null,
                'fecha_nacimiento' => $data['fecha_nacimiento'] ?? null,
                'direccion' => $data['direccion'] ?? null,
                'barrio' => $data['barrio'] ?? null,
                'barrio_normalizado' => AttendeeInput::barrioKey($data['barrio'] ?? null),
                'autoriza_datos' => false,
                'source' => $channel,
                'import_id' => $importId,
                'link_status' => $liderId ? 'vinculado' : 'pendiente',
                'registered_by' => $user?->id,
                'client_uuid' => $data['client_uuid'],
                'ip' => $ip,
                'user_agent' => $userAgent,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $results[$key] = ['attendee_id' => $id, 'created' => true, 'conflict' => false];
        }

        foreach (array_chunk($attendees, 100) as $chunk) {
            DB::table('event_attendees')->insert($chunk);
        }

        return $results;
    }

    private function previous(CampaignEvent $event, array $data): ?array
    {
        $byClient = EventAttendee::where('client_uuid', $data['client_uuid'])->first();
        if ($byClient !== null) {
            return $byClient->event_id === $event->id
                ? ['attendee' => $byClient, 'created' => false, 'conflict' => false]
                : ['attendee' => null, 'created' => false, 'conflict' => true];
        }

        $byCedula = EventAttendee::where('event_id', $event->id)->where('cedula', $data['cedula'])->first();

        return $byCedula ? ['attendee' => $byCedula, 'created' => false, 'conflict' => false] : null;
    }

    private function repeated(CampaignEvent $event, array $previous, string $channel, ?User $user, ?string $ip, ?string $userAgent): array
    {
        if ($previous['attendee'] !== null) {
            $this->audit($event, $previous['attendee'], $channel, 'repetido', $user, $ip, $userAgent);
        }

        return $previous;
    }

    // Never the cédula: only which event, which channel and whether it was a new registration.
    private function audit(CampaignEvent $event, EventAttendee $attendee, string $channel, string $result, ?User $user, ?string $ip, ?string $userAgent): void
    {
        AuditLog::create([
            'user_id' => $user?->id,
            'action' => $channel === 'qr' ? 'EVENTO_ASISTENTE_QR' : 'EVENTO_ASISTENTE_OPERADOR',
            'entity_type' => 'evento_asistente',
            'entity_id' => $attendee->id,
            'metadata' => ['event_id' => $event->id, 'channel' => $channel, 'resultado' => $result],
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);
    }
}
