<?php

namespace App\Services\Eventos;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Which fichas an "undo" (Excel/OCR load, confirmed acta) may delete: only those with no life of their own besides
// what the undone operation created. Shared by UndoEventImport and DeleteConfirmedActa.
class FichaActivity
{
    // Tables whose rows give a ficha a life of its own: [table, column]. lider_contactos and the actas are checked apart.
    private const ACTIVITY = [
        ['event_organizers', 'lider_id'], ['lider_info_politica', 'lider_id'], ['lider_seguimientos', 'lider_id'],
        ['lider_municipios_influencia', 'lider_id'], ['nexos_familiares', 'person_id'], ['whatsapp_send_recipients', 'person_id'],
        ['taggables', 'taggable_id'], ['favorites', 'favoritable_id'], ['data_consents', 'lider_id'],
    ];

    // Ficha ids of $liderIds with no activity besides the attendees in $ignoredAttendees and the acta $ignoredActa.
    // $copiedContacts null: the operation copied at most one fijo (approval of an attendee); otherwise
    // [lider_id => [valor, ...]] the celulares the operation itself wrote, and any other contact is activity.
    public function free(array $liderIds, array $ignoredAttendees = [], ?string $ignoredActa = null, ?array $copiedContacts = null): array
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

        self::chunked($liderIds, function ($ids) use ($mark, $ignoredAttendees, $ignoredActa, $copiedContacts) {
            $lideres = DB::table('lideres')->whereIn('id', $ids)->get(['id', 'campaign_id', 'cedula', 'created_at', 'updated_at']);
            // Edited after the operation created it.
            $mark($lideres->filter(fn ($l) => $l->updated_at !== null && (string) $l->updated_at !== (string) $l->created_at)->pluck('id'));

            $mark(DB::table('event_attendees')->whereIn('lider_id', $ids)
                ->when($ignoredAttendees !== [], fn ($q) => $q->whereNotIn('id', $ignoredAttendees))
                ->distinct()->pluck('lider_id'));

            if ($copiedContacts === null) {
                // The approval copies at most one fijo: any other contact was added by someone.
                $mark(DB::table('lider_contactos')->whereIn('lider_id', $ids)->groupBy('lider_id')
                    ->havingRaw("COUNT(*) > 1 OR SUM(CASE WHEN tipo = 'fijo' THEN 0 ELSE 1 END) > 0")->pluck('lider_id'));
            } else {
                $seen = [];
                foreach (DB::table('lider_contactos')->whereIn('lider_id', $ids)->get(['lider_id', 'tipo', 'valor']) as $c) {
                    $allowed = $copiedContacts[$c->lider_id] ?? [];
                    $seen[$c->lider_id] = ($seen[$c->lider_id] ?? 0) + 1;
                    if ($c->tipo !== 'celular' || ! in_array($c->valor, $allowed, true) || $seen[$c->lider_id] > count($allowed)) {
                        $mark([$c->lider_id]);
                    }
                }
            }

            foreach (self::ACTIVITY as [$table, $column]) {
                if (Schema::hasTable($table)) {
                    $mark(DB::table($table)->whereIn($column, $ids)->distinct()->pluck($column));
                }
            }

            // Participant or responsable of another acta.
            if (Schema::hasTable('event_acta_participantes')) {
                $mark(DB::table('event_acta_participantes')->whereIn('lider_id', $ids)
                    ->when($ignoredActa, fn ($q) => $q->where('acta_id', '!=', $ignoredActa))->distinct()->pluck('lider_id'));
                $mark(DB::table('event_compromiso_responsables as r')->join('event_compromisos as c', 'c.id', '=', 'r.compromiso_id')
                    ->whereIn('r.lider_id', $ids)->when($ignoredActa, fn ($q) => $q->where('c.acta_id', '!=', $ignoredActa))
                    ->distinct()->pluck('r.lider_id'));
            }

            // Referred someone of the same campaign.
            foreach ($lideres->filter(fn ($l) => $l->cedula) as $l) {
                if (DB::table('lideres')->where('campaign_id', $l->campaign_id)->where('referente_documento', $l->cedula)->where('id', '!=', $l->id)->exists()) {
                    $mark([$l->id]);
                }
            }

            return [];
        });

        return array_values(array_filter($liderIds, fn ($id) => ! isset($busy[$id])));
    }

    // Deletes fichas already proven free, with what hangs from them without a cascade.
    public static function deleteFichas(array $liderIds): void
    {
        if (Schema::hasTable('recent_views')) {
            self::chunked($liderIds, fn ($ids) => [DB::table('recent_views')->whereIn('viewable_id', $ids)->delete()]);
        }
        self::chunked($liderIds, fn ($ids) => [DB::table('lider_contactos')->whereIn('lider_id', $ids)->delete()]);
        self::chunked($liderIds, fn ($ids) => [DB::table('lideres')->whereIn('id', $ids)->delete()]);
    }

    public static function chunked(array $ids, callable $fn): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            array_push($out, ...$fn($chunk));
        }

        return $out;
    }
}
