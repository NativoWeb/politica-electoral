<?php

namespace App\Actions\Eventos;

use App\Http\Requests\Eventos\AttendeeInput;
use App\Models\AuditLog;
use App\Models\CampaignEvent;
use App\Models\ConsentText;
use App\Models\DataConsent;
use App\Models\EventAttendee;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The only way an attendee is registered, by the public QR or by an operator. Idempotent by client_uuid and by
// (event, cédula). Everything lands in the EVENT's campaign: the lider lookup filters it explicitly because the
// Lider global scope does not restrict anything without an authenticated user. An existing lider is only linked,
// never modified, and a new person stays "pendiente" until someone approves it (ApproveAttendees).
// Every registration carries its consent proof in data_consents. An Excel import (only rows marked SÍ and declared
// by the operator) registers with $channel = 'excel', the proof with the channel 'operador' (data_consents only
// allows qr|operador and is not altered), import_id = the import, and audits once for the whole file.
class RegisterAttendee
{
    // $data was validated with AttendeeInput (normalized cédula and phones). Returns
    // ['attendee' => EventAttendee|null, 'created' => bool, 'conflict' => bool]; conflict = client_uuid of another event.
    // $options: consent_channel (default $channel), audit (bool, default true), import_id (Excel import, default null).
    public function handle(CampaignEvent $event, ConsentText $consent, array $data, string $channel, ?User $user, ?string $ip, ?string $userAgent, array $options = []): array
    {
        $consentChannel = $options['consent_channel'] ?? $channel;
        $audit = $options['audit'] ?? true;
        $importId = $options['import_id'] ?? null;

        $previous = $this->previous($event, $data);
        if ($previous !== null && $this->unauthorized($previous, $data)) {
            return $this->authorizeExisting($event, $consent, $previous['attendee'], $data, $channel, $user, $ip, $userAgent, $consentChannel, $audit);
        }
        if ($previous !== null) {
            return $audit ? $this->repeated($event, $previous, $channel, $user, $ip, $userAgent) : $previous;
        }

        $liderId = DB::table('lideres')->where('campaign_id', $event->campaign_id)
            ->where('cedula', $data['cedula'])->value('id');

        try {
            $attendee = DB::transaction(function () use ($event, $consent, $data, $channel, $user, $ip, $userAgent, $liderId, $consentChannel, $audit, $importId) {
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
                    'autoriza_datos' => true,
                    'source' => $channel,
                    'import_id' => $importId,
                    'link_status' => $liderId ? 'vinculado' : 'pendiente',
                    'registered_by' => $user?->id,
                    'client_uuid' => $data['client_uuid'],
                    'ip' => $ip,
                    'user_agent' => $userAgent,
                ]);
                $attendee->save();

                $proof = (new DataConsent)->forceFill([
                    'campaign_id' => $event->campaign_id,
                    'consent_text_id' => $consent->id,
                    'text_sha256' => $consent->sha256,
                    'cedula' => $data['cedula'],
                    'lider_id' => $liderId,
                    'event_id' => $event->id,
                    'attendee_id' => $attendee->id,
                    'channel' => $consentChannel,
                    'ip' => $ip,
                    'user_agent' => $userAgent,
                    'accepted_at' => now(),
                ]);
                $proof->save();

                $attendee->forceFill(['consent_id' => $proof->id])->save();
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
            if ($this->unauthorized($previous, $data)) {
                return $this->authorizeExisting($event, $consent, $previous['attendee'], $data, $channel, $user, $ip, $userAgent, $consentChannel, $audit);
            }

            return $audit ? $this->repeated($event, $previous, $channel, $user, $ip, $userAgent) : $previous;
        }

        return ['attendee' => $attendee, 'created' => true, 'conflict' => false];
    }

    // Many registrations of one event at once (Excel import), with the same rules as handle() but one lookup and one
    // insert per table for the whole list. $items: [key => validated attendee data]. Items whose client_uuid or cédula
    // is already registered (or repeated inside the list) go through handle(), which returns the previous attendee; if
    // a concurrent registration hits a unique index, the whole list does. Nothing is audited here: the caller audits
    // the batch. Returns [key => ['attendee_id' => ?string, 'created' => bool, 'conflict' => bool]].
    public function handleMany(CampaignEvent $event, ConsentText $consent, array $items, string $channel, ?User $user, ?string $ip, ?string $userAgent, string $consentChannel, ?string $importId = null): array
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
            $results = DB::transaction(fn () => $this->insertMany($event, $consent, $fresh, $lideres, $channel, $user, $ip, $userAgent, $consentChannel, $importId));
        } catch (UniqueConstraintViolationException) {
            $slow += $fresh;
        }

        foreach ($slow as $key => $data) {
            $outcome = $this->handle($event, $consent, $data, $channel, $user, $ip, $userAgent, [
                'consent_channel' => $consentChannel, 'audit' => false, 'import_id' => $importId,
            ]);
            $results[$key] = ['attendee_id' => $outcome['attendee']?->id, 'created' => $outcome['created'], 'conflict' => $outcome['conflict']];
        }

        return $results;
    }

    private function insertMany(CampaignEvent $event, ConsentText $consent, array $items, array $lideres, string $channel, ?User $user, ?string $ip, ?string $userAgent, string $consentChannel, ?string $importId): array
    {
        $now = now();
        $attendees = [];
        $proofs = [];
        $results = [];
        foreach ($items as $key => $data) {
            $id = (string) Str::uuid();
            $liderId = $lideres[$data['cedula']] ?? null;
            $consentId = (string) Str::uuid();
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
                'autoriza_datos' => true,
                'consent_id' => $consentId,
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
            $proofs[] = [
                'id' => $consentId,
                'campaign_id' => $event->campaign_id,
                'consent_text_id' => $consent->id,
                'text_sha256' => $consent->sha256,
                'cedula' => $data['cedula'],
                'lider_id' => $liderId,
                'event_id' => $event->id,
                'attendee_id' => $id,
                'channel' => $consentChannel,
                'ip' => $ip,
                'user_agent' => $userAgent,
                'accepted_at' => $now,
            ];
            $results[$key] = ['attendee_id' => $id, 'created' => true, 'conflict' => false];
        }

        foreach (array_chunk($attendees, 100) as $chunk) {
            DB::table('event_attendees')->insert($chunk);
        }
        foreach (array_chunk($proofs, 100) as $chunk) {
            DB::table('data_consents')->insert($chunk);
        }

        return $results;
    }

    // The same cédula already in the event from a list without authorization (another submission): this registration
    // brings the authorization, so that attendee is completed instead of answering "ya estaba".
    private function unauthorized(array $previous, array $data): bool
    {
        $attendee = $previous['attendee'];

        return $attendee !== null && ! $attendee->autoriza_datos && $attendee->client_uuid !== $data['client_uuid'];
    }

    // Adds the consent proof to an attendee of a list without authorization: data_consents row, consent_id,
    // autoriza_datos, and link_status vinculado (it had or now finds its ficha) or pendiente (to approve). Only empty
    // fields are filled with what the person typed; nothing already stored is overwritten.
    private function authorizeExisting(CampaignEvent $event, ConsentText $consent, EventAttendee $found, array $data, string $channel, ?User $user, ?string $ip, ?string $userAgent, string $consentChannel, bool $audit): array
    {
        return DB::transaction(function () use ($event, $consent, $found, $data, $channel, $user, $ip, $userAgent, $consentChannel, $audit) {
            $attendee = EventAttendee::whereKey($found->id)->lockForUpdate()->first();
            if ($attendee->autoriza_datos) {
                return ['attendee' => $attendee, 'created' => false, 'conflict' => false];
            }
            $liderId = $attendee->lider_id ?? DB::table('lideres')->where('campaign_id', $event->campaign_id)
                ->where('cedula', $data['cedula'])->value('id');

            $proof = (new DataConsent)->forceFill([
                'campaign_id' => $event->campaign_id,
                'consent_text_id' => $consent->id,
                'text_sha256' => $consent->sha256,
                'cedula' => $data['cedula'],
                'lider_id' => $liderId,
                'event_id' => $event->id,
                'attendee_id' => $attendee->id,
                'channel' => $consentChannel,
                'ip' => $ip,
                'user_agent' => $userAgent,
                'accepted_at' => now(),
            ]);
            $proof->save();

            $fill = [
                'consent_id' => $proof->id,
                'autoriza_datos' => true,
                'lider_id' => $liderId,
                'link_status' => $liderId ? 'vinculado' : 'pendiente',
            ];
            foreach (['celular', 'fijo', 'fecha_nacimiento', 'direccion', 'barrio'] as $field) {
                if ($attendee->{$field} === null && ($data[$field] ?? null) !== null) {
                    $fill[$field] = $data[$field];
                }
            }
            if (isset($fill['barrio'])) {
                $fill['barrio_normalizado'] = AttendeeInput::barrioKey($fill['barrio']);
            }
            $attendee->forceFill($fill)->save();
            if ($audit) {
                $this->audit($event, $attendee, $channel, 'autorizo', $user, $ip, $userAgent);
            }

            return ['attendee' => $attendee, 'created' => true, 'conflict' => false];
        });
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
