<?php

namespace App\Actions\Eventos;

use App\Models\AuditLog;
use App\Models\CampaignEvent;
use App\Models\EventAttendee;
use App\Models\Lider;
use App\Models\LiderContacto;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

// Turns "pendiente" attendees into Votantes of the EVENT's campaign (never the user's own campaign by default):
// a cédula that already exists there is only linked, without touching that lider; otherwise a lider of
// tipo asistente_evento is created, with autoriza_datos only when the attendee accepted an approved text (registrations
// since 2026-11-07 carry no authorization, so it stays false). Anything that is not pendiente is skipped, so approving
// twice never duplicates.
class ApproveAttendees
{
    public const TIPO = 'asistente_evento';

    // ['aprobados' => n, 'vinculados' => n, 'omitidos' => n]
    public function handle(CampaignEvent $event, array $ids, User $user): array
    {
        $counts = ['aprobados' => 0, 'vinculados' => 0, 'omitidos' => 0];
        // Stored like CreateVotante stores it (the province's canonical name, "Area Metropolitana" in production), so the
        // new lider does not add another spelling to lideres.provincia; screens show it through Provincias::display().
        $place = DB::table('geographic_units as g')
            ->leftJoin('geographic_units as prov', 'g.parent_id', '=', 'prov.id')
            ->where('g.id', $event->municipio_id)
            ->first(['g.canonical_name as municipio', 'prov.canonical_name as provincia']);

        foreach ($ids as $id) {
            $result = DB::transaction(function () use ($event, $id, $place) {
                $attendee = EventAttendee::where('event_id', $event->id)->where('id', $id)->lockForUpdate()->first();
                if (! $attendee || $attendee->link_status !== 'pendiente') {
                    return 'omitidos';
                }

                $existing = $this->existingLider($event, $attendee);
                if ($existing) {
                    $attendee->forceFill(['lider_id' => $existing, 'link_status' => 'vinculado'])->save();

                    return 'vinculados';
                }

                try {
                    $liderId = DB::transaction(fn () => $this->createLider($event, $attendee, $place));
                } catch (UniqueConstraintViolationException $e) {
                    // The same cédula was saved in the campaign meanwhile: link to it.
                    $liderId = $this->existingLider($event, $attendee) ?? throw $e;
                    $attendee->forceFill(['lider_id' => $liderId, 'link_status' => 'vinculado'])->save();

                    return 'vinculados';
                }
                $attendee->forceFill(['lider_id' => $liderId, 'link_status' => 'aprobado'])->save();

                return 'aprobados';
            });
            $counts[$result]++;
        }

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'EVENTO_ASISTENTES_APROBADOS',
            'entity_type' => 'evento',
            'entity_id' => $event->id,
            'metadata' => ['attendee_ids' => array_values($ids), ...$counts],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return $counts;
    }

    // Only a consent given over an APPROVED text authorizes the data in Votantes; the provisional text does not.
    public static function consentApproved(?string $consentId): bool
    {
        if (! $consentId) {
            return false;
        }

        return (bool) DB::table('data_consents as d')->join('consent_texts as t', 'd.consent_text_id', '=', 't.id')
            ->where('d.id', $consentId)->value('t.approved');
    }

    private function existingLider(CampaignEvent $event, EventAttendee $attendee): ?string
    {
        if (! $attendee->cedula) {
            return null;
        }

        return DB::table('lideres')->where('campaign_id', $event->campaign_id)->where('cedula', $attendee->cedula)->value('id');
    }

    private function createLider(CampaignEvent $event, EventAttendee $attendee, ?object $place): string
    {
        $lider = new Lider([
            'nombre' => trim($attendee->nombres.' '.$attendee->apellidos),
            'municipio' => $place->municipio ?? '',
            'provincia' => $place->provincia ?? '',
            'geographic_unit_id' => $event->municipio_id,
            'cargo' => null,
            'tipo' => self::TIPO,
            'telefono' => $attendee->celular,
            'cedula' => $attendee->cedula,
            'fecha_nacimiento' => $attendee->fecha_nacimiento,
            'direccion' => $attendee->direccion,
            'barrio' => $attendee->barrio,
            'autoriza_datos' => (bool) $attendee->autoriza_datos && self::consentApproved($attendee->consent_id),
            // No event name here: the ficha is read by users who may not see events; the link lives in event_attendees.
            'observacion' => null,
            'is_active' => true,
            'destacado' => false,
            'votos' => 0,
            'nivel_confianza' => 'sin_llamar',
        ]);
        $lider->forceFill(['campaign_id' => $event->campaign_id])->save();

        if ($attendee->fijo) {
            LiderContacto::create(['lider_id' => $lider->id, 'tipo' => 'fijo', 'valor' => $attendee->fijo, 'principal' => false, 'estado' => 'activo']);
        }

        return $lider->id;
    }
}
