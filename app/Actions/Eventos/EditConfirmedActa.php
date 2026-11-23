<?php

namespace App\Actions\Eventos;

use App\Models\AuditLog;
use App\Models\CampaignEvent;
use App\Models\EventActa;
use App\Models\EventActaParticipante;
use App\Models\EventAttendee;
use App\Models\EventCompromiso;
use App\Models\Organizacion;
use App\Models\OrganizacionMiembro;
use App\Models\User;
use App\Services\Eventos\Actas\ActaService;
use App\Services\Eventos\FichaActivity;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Corrects a CONFIRMED acta (permission edit.actas, docs/eventos-api.md §14 "Corregir un acta confirmada"), in one
// transaction with the acta locked; `version` must be the current one (409 otherwise) and goes up by one. Participants
// and compromisos are matched by id: an existing participant only gets its text corrected (its ficha and attendance stay;
// what the ficha lacks is filled, never overwritten); a new one goes through the same step as confirming; a removed one
// loses only the attendance/membership this acta created and nothing else holds; its ficha is never deleted. An existing
// compromiso keeps its id, estado and avances; one with avances cannot be removed.
class EditConfirmedActa
{
    public const CONFLICTO = 'Otra persona cambió esta acta mientras usted la corregía. Recargue la página para ver los cambios; lo suyo no se guardó.';

    // The acta's fields whose values the audit keeps (before/after); the others are only named.
    public const FECHAS = ['fecha', 'hora_inicio', 'hora_fin', 'municipio'];

    public function __construct(private ActaService $service) {}

    // Returns [acta, resumen]. A correction that changes nothing writes nothing: resumen.sin_cambios = true.
    public function handle(CampaignEvent|Organizacion $owner, EventActa $acta, array $data, User $user): array
    {
        try {
            return $this->apply($owner, $acta, $data, $user);
        } catch (ActaSinCambios) {
            return [$acta->fresh(), self::resumenVacio($owner instanceof CampaignEvent ? 'asistentes' : 'miembros')];
        }
    }

    public static function resumenVacio(string $quien): array
    {
        return [
            'participantes_agregados' => 0, 'participantes_quitados' => 0, 'participantes_editados' => 0, 'vinculados' => 0, 'fichas_creadas' => 0,
            "{$quien}_nuevos" => 0, "{$quien}_quitados" => 0, 'compromisos_agregados' => 0, 'compromisos_editados' => 0, 'compromisos_quitados' => 0,
            'cambios' => [], 'sin_cambios' => true,
        ];
    }

    private function apply(CampaignEvent|Organizacion $owner, EventActa $acta, array $data, User $user): array
    {
        return DB::transaction(function () use ($owner, $acta, $data, $user) {
            $locked = EventActa::whereKey($acta->id)->lockForUpdate()->firstOrFail();
            if ($locked->isDraft()) {
                throw ValidationException::withMessages(['acta' => 'El acta no está confirmada: corríjala como borrador.']);
            }
            if ((int) $locked->version !== (int) $data['version']) {
                throw new HttpResponseException(response()->json(['success' => false, 'code' => 'version', 'message' => self::CONFLICTO], 409));
            }
            $isEvent = $owner instanceof CampaignEvent;

            $fields = ActaService::actaFields($data);
            $current = [...$locked->only(array_keys($fields)), 'fecha' => $locked->fecha?->toDateString()];
            // jsonb gives the keys of desarrollo back in its own order: compared without key order.
            $cambios = array_values(array_filter(array_keys($fields), fn ($f) => self::canonical($current[$f] ?? null) !== self::canonical($fields[$f])));
            $before = array_intersect_key($current, array_flip(array_intersect($cambios, self::FECHAS)));
            $after = array_intersect_key($fields, $before);

            $participantes = $this->participantes($owner, $locked, array_values($data['participantes'] ?? []), $user);
            [$compromisos, $fechasAntes, $fechasDespues] = $this->compromisos($owner, $locked, array_values($data['compromisos'] ?? []));
            if ($fechasAntes !== [] || $fechasDespues !== []) {
                $before['compromisos'] = $fechasAntes;
                $after['compromisos'] = $fechasDespues;
            }
            array_push($cambios, ...array_keys(array_filter(['orden_participantes' => $participantes['reordenado'], 'orden_compromisos' => $compromisos['reordenado']])));
            $nada = $cambios === [] && $participantes['agregados'] === [] && $participantes['quitados'] === [] && $participantes['editados'] === []
                && $compromisos['agregados'] === [] && $compromisos['editados'] === [] && $compromisos['quitados'] === [];
            if ($nada) {
                throw new ActaSinCambios;
            }

            $locked->forceFill([...$fields, 'updated_by' => $user->id, 'version' => (int) $locked->version + 1])->save();

            $quien = $isEvent ? 'asistentes' : 'miembros';
            $resumen = [
                'participantes_agregados' => count($participantes['agregados']),
                'participantes_quitados' => count($participantes['quitados']),
                'participantes_editados' => count($participantes['editados']),
                'vinculados' => $participantes['counts']['vinculados'],
                'fichas_creadas' => $participantes['counts']['fichas_creadas'],
                "{$quien}_nuevos" => $participantes['counts']["{$quien}_nuevos"],
                "{$quien}_quitados" => count($participantes['quitar']),
                'compromisos_agregados' => count($compromisos['agregados']),
                'compromisos_editados' => count($compromisos['editados']),
                'compromisos_quitados' => count($compromisos['quitados']),
                'cambios' => $cambios,
                'sin_cambios' => false,
            ];

            // Ids, counts, field names and dates only: never names, cédulas, phones or what the acta says.
            AuditLog::create([
                'user_id' => $user->id,
                'action' => $isEvent ? 'EVENTO_ACTA_CORREGIDA' : 'ORGANIZACION_ACTA_CORREGIDA',
                'entity_type' => $isEvent ? 'evento' : 'organizacion',
                'entity_id' => $owner->id,
                'metadata' => [
                    'acta_id' => $locked->id,
                    'version' => (int) $locked->version,
                    ...$resumen,
                    'participantes_agregados_ids' => $participantes['agregados'],
                    'participantes_quitados_ids' => $participantes['quitados'],
                    'participantes_editados_ids' => $participantes['editados'],
                    'fichas_completadas' => $participantes['completadas'],
                    "{$quien}_quitados_ids" => $participantes['quitar'],
                    'compromisos_agregados_ids' => $compromisos['agregados'],
                    'compromisos_editados_ids' => $compromisos['editados'],
                    'compromisos_quitados_ids' => $compromisos['quitados'],
                ],
                'before_state' => $before,
                'after_state' => $after,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            return [$locked, $resumen];
        });
    }

    private function participantes(CampaignEvent|Organizacion $owner, EventActa $acta, array $items, User $user): array
    {
        $existing = EventActaParticipante::where('acta_id', $acta->id)->get()->keyBy('id');
        $kept = self::matchIds($items, $existing->keys()->all(), 'participantes');

        $editados = [];
        $completadas = 0;
        $reordenado = false;
        foreach ($kept as $id => $i) {
            $row = $existing[$id];
            $reordenado = $reordenado || (int) $row->orden !== $i;
            $text = ActaService::participanteText($items[$i]);
            $changed = array_filter($text, fn ($value, $field) => $row->{$field} !== $value, ARRAY_FILTER_USE_BOTH) !== [];
            // The ficha and the attendance it was linked to stay: lider_id/attendee_id of the request are ignored.
            $row->forceFill([...$text, 'orden' => $i])->save();
            if ($changed) {
                $editados[] = $id;
                $completadas += $this->service->completeFicha($row, $i, (bool) $row->created_lider) ? 1 : 0;
            }
        }

        $counts = ['participantes' => 0, 'vinculados' => 0, 'fichas_creadas' => 0, ($owner instanceof CampaignEvent ? 'asistentes_nuevos' : 'miembros_nuevos') => 0];
        $nuevos = array_diff_key($items, array_flip($kept));
        $agregados = [];
        if ($nuevos !== []) {
            $rows = $this->service->writeParticipantes($owner, $acta, $nuevos);
            $this->service->linkParticipantes($owner, $rows, $user, $counts);
            $agregados = $rows->pluck('id')->values()->all();
        }

        // Removed after the new ones are linked: a person removed and added again keeps the attendance.
        $removed = $existing->reject(fn ($row) => isset($kept[$row->id]))->keyBy('id');
        $quitar = [];
        if ($removed->isNotEmpty()) {
            $plan = DeleteConfirmedActa::removables($acta, $removed);
            FichaActivity::chunked($plan['attendee_ids'], fn ($ids) => EventAttendee::whereIn('id', $ids)->lockForUpdate()->pluck('id')->all());
            // Recomputed under the locks, as deleting a confirmed acta does.
            $plan = DeleteConfirmedActa::removables($acta, $removed);
            self::inheritCreated($acta, $removed);
            EventActaParticipante::whereIn('id', $removed->keys()->all())->delete();
            FichaActivity::chunked($plan['attendee_ids'], fn ($ids) => [EventAttendee::whereIn('id', $ids)->delete()]);
            FichaActivity::chunked($plan['miembro_ids'], fn ($ids) => [OrganizacionMiembro::whereIn('id', $ids)->delete()]);
            $quitar = [...$plan['attendee_ids'], ...$plan['miembro_ids']];
        }

        return [
            'agregados' => $agregados, 'quitados' => $removed->keys()->values()->all(), 'editados' => $editados,
            'completadas' => $completadas, 'counts' => $counts, 'quitar' => $quitar, 'reordenado' => $reordenado,
        ];
    }

    // Returns [ids by group, fecha_limite/recordar_el before, after] (dates by compromiso id, only those that changed).
    private function compromisos(CampaignEvent|Organizacion $owner, EventActa $acta, array $items): array
    {
        $existing = EventCompromiso::where('acta_id', $acta->id)->with('responsables')->get()->keyBy('id');
        $kept = self::matchIds($items, $existing->keys()->all(), 'compromisos');

        $removed = $existing->reject(fn ($row) => isset($kept[$row->id]))->keyBy('id');
        $avances = $removed->isEmpty() ? collect() : DB::table('event_compromiso_avances')->whereIn('compromiso_id', $removed->keys()->all())
            ->groupBy('compromiso_id')->selectRaw('compromiso_id, COUNT(*) as n')->pluck('n', 'compromiso_id');
        foreach ($removed as $id => $c) {
            if (($n = (int) ($avances[$id] ?? 0)) > 0) {
                throw ValidationException::withMessages(["compromisos_quitados.{$id}" => "Este compromiso tiene {$n} ".($n === 1 ? 'avance' : 'avances').': no se puede quitar. Márquelo como No cumplido en Seguimiento.']);
            }
        }

        $chosen = $this->service->chosenResponsables($owner, $items);
        $ids = ['agregados' => [], 'editados' => [], 'quitados' => $removed->keys()->values()->all(), 'reordenado' => false];
        $antes = [];
        $despues = [];
        foreach ($items as $i => $item) {
            $compromiso = isset($item['id']) ? $existing[$item['id']] : null;
            $old = $compromiso ? self::compromisoState($compromiso) : null;
            $ids['reordenado'] = $ids['reordenado'] || ($compromiso && (int) $compromiso->orden !== $i);
            $compromiso = $this->service->writeCompromiso($owner, $acta, $compromiso, $i, $item, $chosen);
            $new = self::compromisoState($compromiso->load('responsables'));
            if ($old === null) {
                $ids['agregados'][] = $compromiso->id;
            } elseif ($old !== $new) {
                $ids['editados'][] = $compromiso->id;
            }
            $dates = fn (?array $s) => $s === null ? null : ['fecha_limite' => $s['fecha_limite'], 'recordar_el' => $s['recordar_el']];
            if ($dates($old) !== $dates($new)) {
                if ($old !== null) {
                    $antes[$compromiso->id] = $dates($old);
                }
                $despues[$compromiso->id] = $dates($new);
            }
        }
        if ($removed->isNotEmpty()) {
            // Responsables go by cascade.
            EventCompromiso::whereIn('id', $removed->keys()->all())->delete();
        }

        return [$ids, $antes, $despues];
    }

    // A removed row whose attendance, ficha or membership another row of this acta keeps (the same person added again)
    // hands that row its created_* mark, so deleting the acta later still undoes what the acta created.
    private static function inheritCreated(EventActa $acta, Collection $removed): void
    {
        $others = EventActaParticipante::where('acta_id', $acta->id)->whereNotIn('id', $removed->keys()->all())->get();
        $marks = ['created_attendee' => 'attendee_id', 'created_lider' => 'lider_id', ...($acta->organizacion_id ? ['created_miembro' => 'lider_id'] : [])];
        foreach ($removed as $row) {
            foreach ($marks as $mark => $key) {
                if (! $row->{$mark} || ! $row->{$key} || $others->contains(fn ($o) => $o->{$key} === $row->{$key} && $o->{$mark})) {
                    continue;
                }
                $heir = $others->first(fn ($o) => $o->{$key} === $row->{$key});
                if ($heir) {
                    $heir->forceFill([$mark => true])->save();
                }
            }
        }
    }

    // A value with the keys of every object sorted, to compare what jsonb returns with what was sent.
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }

    private static function compromisoState(EventCompromiso $c): array
    {
        return [
            'descripcion' => $c->descripcion,
            'fecha_limite' => $c->fecha_limite?->toDateString(),
            'recordar_el' => $c->recordar_el?->toDateString(),
            'responsables' => $c->responsables->map(fn ($r) => [$r->nombre, $r->telefono, $r->lider_id, $r->sugerencia_rechazada])->values()->all(),
        ];
    }

    // [id => index] of the items that name an existing row; an id of another acta, or repeated, is a 422.
    private static function matchIds(array $items, array $ids, string $field): array
    {
        $kept = [];
        foreach ($items as $i => $item) {
            $id = $item['id'] ?? null;
            if ($id === null) {
                continue;
            }
            if (! in_array($id, $ids, true) || isset($kept[$id])) {
                throw ValidationException::withMessages(["{$field}.{$i}.id" => 'Esta fila no es de esta acta o está repetida. Recargue la página e intente de nuevo.']);
            }
            $kept[$id] = $i;
        }

        return $kept;
    }
}
