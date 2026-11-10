<?php

namespace App\Actions\Eventos;

use App\Models\AuditLog;
use App\Models\EventAttendee;
use App\Models\EventImport;
use App\Models\User;
use App\Services\Eventos\EventImportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// "Deshacer carga": deletes the attendees a confirmed Excel/OCR load created and the load itself (its staging rows,
// pages and file), so the same file can be uploaded again. A ficha that APPROVING one of those attendees created
// (tipo asistente_evento) is deleted too, but only while nothing else points to it: no other attendance, organizer
// role, contacts besides the fijo the approval copied, political info, follow-ups, nexos, referidos, tags,
// favorites, WhatsApp sends, consent or edit after the approval. Fichas that already existed and were only linked
// (vinculado) are never touched. An attendee with a data_consents row stays (append-only table with a foreign key).
class UndoEventImport
{
    // Tables whose rows give a ficha a life of its own: [table, column]. lider_contactos is checked apart.
    private const ACTIVITY = [
        ['event_organizers', 'lider_id'], ['lider_info_politica', 'lider_id'], ['lider_seguimientos', 'lider_id'],
        ['lider_municipios_influencia', 'lider_id'], ['nexos_familiares', 'person_id'], ['whatsapp_send_recipients', 'person_id'],
        ['taggables', 'taggable_id'], ['favorites', 'favoritable_id'], ['data_consents', 'lider_id'],
    ];

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
        $free = $this->withoutActivity($created, $deletableIds);

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
            if (Schema::hasTable('recent_views')) {
                $this->chunked($plan['lider_ids'], fn ($ids) => [DB::table('recent_views')->whereIn('viewable_id', $ids)->delete()]);
            }
            $this->chunked($plan['lider_ids'], fn ($ids) => [DB::table('lider_contactos')->whereIn('lider_id', $ids)->delete()]);
            $this->chunked($plan['lider_ids'], fn ($ids) => [DB::table('lideres')->whereIn('id', $ids)->delete()]);
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

    // Ficha ids of $liderIds with no activity besides the attendees in $ignoredAttendees.
    private function withoutActivity(array $liderIds, array $ignoredAttendees): array
    {
        if ($liderIds === []) {
            return [];
        }
        $busy = [];
        $mark = function (iterable $ids) use (&$busy) {
            foreach ($ids as $id) {
                $busy[$id] = true;
            }
        };

        $this->chunked($liderIds, function ($ids) use ($mark, $ignoredAttendees) {
            $lideres = DB::table('lideres')->whereIn('id', $ids)->get(['id', 'campaign_id', 'cedula', 'created_at', 'updated_at']);
            // Edited after the approval created it.
            $mark($lideres->filter(fn ($l) => $l->updated_at !== null && (string) $l->updated_at !== (string) $l->created_at)->pluck('id'));

            $mark(DB::table('event_attendees')->whereIn('lider_id', $ids)
                ->when($ignoredAttendees !== [], fn ($q) => $q->whereNotIn('id', $ignoredAttendees))
                ->distinct()->pluck('lider_id'));

            // The approval copies at most one fijo: any other contact was added by someone.
            $mark(DB::table('lider_contactos')->whereIn('lider_id', $ids)->groupBy('lider_id')
                ->havingRaw("COUNT(*) > 1 OR SUM(CASE WHEN tipo = 'fijo' THEN 0 ELSE 1 END) > 0")->pluck('lider_id'));

            foreach (self::ACTIVITY as [$table, $column]) {
                if (Schema::hasTable($table)) {
                    $mark(DB::table($table)->whereIn($column, $ids)->distinct()->pluck($column));
                }
            }

            // Referred someone of the same campaign.
            foreach ($lideres->filter(fn ($l) => $l->cedula) as $l) {
                if (DB::table('lideres')->where('campaign_id', $l->campaign_id)->where('referente_documento', $l->cedula)->where('id', '!=', $l->id)->exists()) {
                    $busy[$l->id] = true;
                }
            }

            return [];
        });

        return array_values(array_filter($liderIds, fn ($id) => ! isset($busy[$id])));
    }

    private function chunked(array $ids, callable $fn): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            array_push($out, ...$fn($chunk));
        }

        return $out;
    }
}
