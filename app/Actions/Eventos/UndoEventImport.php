<?php

namespace App\Actions\Eventos;

use App\Models\AuditLog;
use App\Models\EventAttendee;
use App\Models\EventImport;
use App\Models\User;
use App\Services\Eventos\EventImportService;
use App\Services\Eventos\FichaActivity;
use Illuminate\Support\Facades\DB;

// "Deshacer carga": deletes the attendees a confirmed Excel/OCR load created and the load itself (its staging rows,
// pages and file), so the same file can be uploaded again. A ficha that APPROVING one of those attendees created
// (tipo asistente_evento) is deleted too, but only while nothing else points to it: no other attendance, organizer
// role, contacts besides the fijo the approval copied, political info, follow-ups, nexos, referidos, tags,
// favorites, WhatsApp sends, consent, actas or edit after the approval (FichaActivity). Fichas that already existed and were only linked
// (vinculado) are never touched. An attendee with a data_consents row stays (append-only table with a foreign key).
class UndoEventImport
{
    public function __construct(private FichaActivity $activity) {}

    // ['asistentes' => n to delete, 'con_autorizacion' => n kept, 'fichas_borrar' => n, 'fichas_conservar' => n,
    //  'vinculadas' => n existing fichas only linked, plus the ids].
    public function plan(EventImport $import): array
    {
        $attendees = EventAttendee::where('import_id', $import->id)->get(['id', 'lider_id', 'link_status']);
        $consented = DB::table('data_consents')->whereIn('attendee_id', $attendees->pluck('id'))->distinct()->pluck('attendee_id')->all();
        $deletable = $attendees->reject(fn ($a) => in_array($a->id, $consented, true));
        $deletableIds = $deletable->pluck('id')->all();

        $created = $deletable->where('link_status', 'aprobado')->pluck('lider_id')->filter()->unique()->values()->all();
        $created = $this->chunked($created, fn ($ids) => DB::table('lideres')->whereIn('id', $ids)
            ->where('campaign_id', $import->campaign_id)->where('tipo', ApproveAttendees::TIPO)->pluck('id')->all());
        $free = $this->activity->free($created, $deletableIds);

        return [
            'attendee_ids' => $deletableIds,
            'lider_ids' => $free,
            'asistentes' => count($deletableIds),
            'con_autorizacion' => count($consented),
            'fichas_borrar' => count($free),
            'fichas_conservar' => count($created) - count($free),
            'vinculadas' => $deletable->where('link_status', 'vinculado')->count(),
        ];
    }

    public function handle(EventImport $import, User $user): array
    {
        $plan = DB::transaction(function () use ($import) {
            EventImport::whereKey($import->id)->lockForUpdate()->firstOrFail(['id']);
            // An approval arriving meanwhile waits: no ficha is left behind without its attendee.
            EventAttendee::where('import_id', $import->id)->lockForUpdate()->pluck('id');
            $initial = $this->plan($import);
            $this->chunked($initial['lider_ids'], fn ($ids) => DB::table('lideres')->whereIn('id', $ids)->lockForUpdate()->pluck('id')->all());
            // Recomputed under the locks: an edit that arrived meanwhile keeps its ficha.
            $plan = $this->plan($import);

            $this->chunked($plan['attendee_ids'], fn ($ids) => [EventAttendee::whereIn('id', $ids)->delete()]);
            FichaActivity::deleteFichas($plan['lider_ids']);
            $import->delete();

            return $plan;
        });
        EventImportService::deleteFiles($import);

        $counts = collect($plan)->except(['attendee_ids', 'lider_ids'])->all();
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'EVENTO_CARGA_DESHECHA',
            'entity_type' => 'evento',
            'entity_id' => $import->event_id,
            'metadata' => ['import_id' => $import->id, 'kind' => $import->kind, 'lider_ids_borrados' => $plan['lider_ids'], ...$counts],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return $counts;
    }

    private function chunked(array $ids, callable $fn): array
    {
        return FichaActivity::chunked($ids, $fn);
    }
}
