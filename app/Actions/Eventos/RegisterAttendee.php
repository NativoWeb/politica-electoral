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

// The only way an attendee is registered, by the public QR or by an operator. Idempotent by client_uuid and by
// (event, cédula). Everything lands in the EVENT's campaign: the lider lookup filters it explicitly because the
// Lider global scope does not restrict anything without an authenticated user. An existing lider is only linked,
// never modified, and a new person stays "pendiente" until someone approves it (ApproveAttendees).
class RegisterAttendee
{
    // $data was validated with AttendeeInput (normalized cédula and phones). Returns
    // ['attendee' => EventAttendee|null, 'created' => bool, 'conflict' => bool]; conflict = client_uuid of another event.
    public function handle(CampaignEvent $event, ConsentText $consent, array $data, string $channel, ?User $user, ?string $ip, ?string $userAgent): array
    {
        $previous = $this->previous($event, $data);
        if ($previous !== null) {
            return $this->repeated($event, $previous, $channel, $user, $ip, $userAgent);
        }

        $liderId = DB::table('lideres')->where('campaign_id', $event->campaign_id)
            ->where('cedula', $data['cedula'])->value('id');

        try {
            $attendee = DB::transaction(function () use ($event, $consent, $data, $channel, $user, $ip, $userAgent, $liderId) {
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
                    'barrio' => $data['barrio'],
                    'barrio_normalizado' => AttendeeInput::barrioKey($data['barrio']),
                    'autoriza_datos' => true,
                    'source' => $channel,
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
                    'channel' => $channel,
                    'ip' => $ip,
                    'user_agent' => $userAgent,
                    'accepted_at' => now(),
                ]);
                $proof->save();

                $attendee->forceFill(['consent_id' => $proof->id])->save();
                $this->audit($event, $attendee, $channel, 'nuevo', $user, $ip, $userAgent);

                return $attendee;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Same submission or same cédula saved concurrently between the lookup above and the insert.
            $previous = $this->previous($event, $data);
            if ($previous === null) {
                throw $e;
            }

            return $this->repeated($event, $previous, $channel, $user, $ip, $userAgent);
        }

        return ['attendee' => $attendee, 'created' => true, 'conflict' => false];
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
