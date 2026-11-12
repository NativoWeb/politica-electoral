<?php

namespace App\Actions\Eventos;

use App\Models\AuditLog;
use App\Models\EventActa;
use App\Models\EventActaParticipante;
use App\Models\EventAttendee;
use App\Models\OrganizacionMiembro;
use App\Models\User;
use App\Services\Eventos\Actas\ActaFiles;
use App\Services\Eventos\AttendeeMatcher;
use App\Services\Eventos\FichaActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Deletes a CONFIRMED acta (permission delete.actas, docs/eventos-api.md §14): the acta with its participants,
// compromisos, responsables, avances and files, the attendees its confirmation CREATED (created_attendee) and the
// fichas it CREATED (created_lider) while they have no other activity (FichaActivity). Fichas and attendees that
// already existed and were only linked are never touched, nor is data_consents. Actas confirmed before the flags
// existed count as "nothing created": only the acta itself goes. An organization's acta (docs/empresarios.md) has no
// attendees: it undoes the memberships (rol 'otro') its confirmation created (created_miembro).
class DeleteConfirmedActa
{
    public function __construct(private FichaActivity $activity) {}

    // Counts for the confirmation dialog, plus the ids to delete.
    public function plan(EventActa $acta): array
    {
        $rows = EventActaParticipante::where('acta_id', $acta->id)->get(['id', 'lider_id', 'attendee_id', 'telefono', 'created_lider', 'created_attendee', ...($acta->organizacion_id ? ['created_miembro'] : [])]);
        // An organization's acta: the memberships (rol 'otro') its confirmation created.
        $miembroIds = $acta->organizacion_id === null ? [] : OrganizacionMiembro::where('organizacion_id', $acta->organizacion_id)->where('rol', 'otro')
            ->whereIn('lider_id', $rows->where('created_miembro', true)->pluck('lider_id')->filter()->unique()->values()->all())->pluck('id')->all();

        $createdAttendees = $rows->where('created_attendee', true)->pluck('attendee_id')->filter()->unique()->values()->all();
        $createdAttendees = FichaActivity::chunked($createdAttendees, fn ($ids) => EventAttendee::whereIn('id', $ids)
            ->where('event_id', $acta->event_id)->where('source', 'acta')->pluck('id')->all());
        $kept = [];
        FichaActivity::chunked($createdAttendees, function ($ids) use ($acta, &$kept) {
            foreach (DB::table('data_consents')->whereIn('attendee_id', $ids)->distinct()->pluck('attendee_id') as $id) {
                $kept[$id] = true;
            }
            // Picked from the list in another acta: it is that acta's attendance too.
            foreach (DB::table('event_acta_participantes')->whereIn('attendee_id', $ids)->where('acta_id', '!=', $acta->id)->distinct()->pluck('attendee_id') as $id) {
                $kept[$id] = true;
            }

            return [];
        });
        $attendeeIds = array_values(array_filter($createdAttendees, fn ($id) => ! isset($kept[$id])));

        $created = $rows->where('created_lider', true)->pluck('lider_id')->filter()->unique()->values()->all();
        $created = FichaActivity::chunked($created, fn ($ids) => DB::table('lideres')->whereIn('id', $ids)
            ->where('campaign_id', $acta->campaign_id)->pluck('id')->all());
        $copied = [];
        foreach ($rows->where('created_lider', true) as $row) {
            if ($row->lider_id) {
                $copied[$row->lider_id] = array_slice(AttendeeMatcher::phones($row->telefono), 1);
            }
        }
        $free = $this->activity->free($created, $attendeeIds, $acta->id, $copied, $miembroIds);

        $compromisos = DB::table('event_compromisos')->where('acta_id', $acta->id)->pluck('id')->all();

        return [
            'attendee_ids' => $attendeeIds,
            'lider_ids' => $free,
            'miembro_ids' => $miembroIds,
            'participantes' => $rows->count(),
            ...($acta->organizacion_id ? ['miembros' => count($miembroIds)] : []),
            'asistentes' => count($attendeeIds),
            'asistentes_conservados' => $rows->pluck('attendee_id')->filter()->unique()->count() - count($attendeeIds),
            'fichas_borrar' => count($free),
            'fichas_conservar' => count($created) - count($free),
            'vinculadas' => $rows->where('created_lider', false)->pluck('lider_id')->filter()->unique()->count(),
            'compromisos' => count($compromisos),
            'responsables' => $compromisos === [] ? 0 : DB::table('event_compromiso_responsables')->whereIn('compromiso_id', $compromisos)->count(),
            'avances' => $compromisos === [] ? 0 : DB::table('event_compromiso_avances')->whereIn('compromiso_id', $compromisos)->count(),
        ];
    }

    public function handle(EventActa $acta, User $user): array
    {
        [$plan, $paths] = DB::transaction(function () use ($acta) {
            $locked = EventActa::whereKey($acta->id)->lockForUpdate()->firstOrFail();
            if ($locked->isDraft()) {
                throw ValidationException::withMessages(['acta' => 'El acta no está confirmada.']);
            }
            $initial = $this->plan($locked);
            FichaActivity::chunked($initial['attendee_ids'], fn ($ids) => EventAttendee::whereIn('id', $ids)->lockForUpdate()->pluck('id')->all());
            FichaActivity::chunked($initial['lider_ids'], fn ($ids) => DB::table('lideres')->whereIn('id', $ids)->lockForUpdate()->pluck('id')->all());
            // Recomputed under the locks: an edit that arrived meanwhile keeps its ficha.
            $plan = $this->plan($locked);

            $compromisos = DB::table('event_compromisos')->where('acta_id', $locked->id)->pluck('id')->all();
            $paths = array_merge(
                [$locked->archivo_path],
                $compromisos === [] ? [] : DB::table('event_compromiso_avances')->whereIn('compromiso_id', $compromisos)->whereNotNull('archivo_path')->pluck('archivo_path')->all(),
            );

            // Participants, compromisos, responsables and avances go by cascade.
            $locked->delete();
            FichaActivity::chunked($plan['attendee_ids'], fn ($ids) => [EventAttendee::whereIn('id', $ids)->delete()]);
            FichaActivity::chunked($plan['miembro_ids'], fn ($ids) => [OrganizacionMiembro::whereIn('id', $ids)->delete()]);
            FichaActivity::deleteFichas($plan['lider_ids']);

            return [$plan, $paths];
        });
        foreach (array_filter($paths) as $path) {
            ActaFiles::delete($path);
        }

        $counts = collect($plan)->except(['attendee_ids', 'lider_ids', 'miembro_ids'])->all();
        $organizacion = $acta->organizacion_id !== null;
        AuditLog::create([
            'user_id' => $user->id,
            'action' => $organizacion ? 'ORGANIZACION_ACTA_CONFIRMADA_BORRADA' : 'EVENTO_ACTA_CONFIRMADA_BORRADA',
            'entity_type' => $organizacion ? 'organizacion' : 'evento',
            'entity_id' => $organizacion ? $acta->organizacion_id : $acta->event_id,
            'metadata' => ['acta_id' => $acta->id, 'attendee_ids_borrados' => $plan['attendee_ids'], 'lider_ids_borrados' => $plan['lider_ids'], ...$counts],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return $counts;
    }
}
