<?php

namespace App\Services\Eventos\Actas;

use App\Models\EventActa;
use App\Models\EventCompromiso;
use App\Services\Empresarios\OrganizacionScope;
use App\Services\Eventos\EventScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// What needs attention for the current user (request()->user(): visible campaigns and territory): open compromisos
// (pendiente, en_curso) of CONFIRMED actas that are overdue, due within DIAS days, or whose reminder date arrived.
// Each compromiso counts once, in that order of priority. Used by the bell and the daily e-mail. Events' compromisos
// with view.eventos/manage.eventos, organizations' (docs/empresarios.md) with view.empresarios/manage.empresarios.
class CompromisoAlertas
{
    public const DIAS = 3;

    public const TIPOS = ['vencido' => 'Vencido', 'por_vencer' => 'Por vencer', 'recordatorio' => 'Recordatorio'];

    public function __construct(private EventScope $events, private OrganizacionScope $orgs) {}

    public function summary(int $limit = 15): array
    {
        $today = EventCompromiso::today();
        $soon = Carbon::parse($today)->addDays(self::DIAS)->toDateString();
        // "< next day": a date column may come back with a time (SQLite stores 'Y-m-d 00:00:00').
        $afterSoon = Carbon::parse($soon)->addDay()->toDateString();
        $tomorrow = Carbon::parse($today)->addDay()->toDateString();

        $user = request()->user();
        $seesEvents = EventScope::canSeeAttendees($user);
        $seesOrgs = OrganizacionScope::canView($user);
        $rows = DB::table('event_compromisos as c')
            ->join('event_actas as a', 'a.id', '=', 'c.acta_id')
            ->leftJoin('events as ev', 'ev.id', '=', 'c.event_id')
            ->leftJoin('organizaciones as o', 'o.id', '=', 'c.organizacion_id')
            ->where(function ($w) use ($seesEvents, $seesOrgs) {
                if ($seesEvents) {
                    $w->orWhere(fn ($e) => $this->events->scopeEvents($e->whereNotNull('c.event_id')));
                }
                if ($seesOrgs) {
                    $w->orWhere(fn ($o) => $this->orgs->scope($o->whereNotNull('c.organizacion_id'), 'o'));
                }
                if (! $seesEvents && ! $seesOrgs) {
                    $w->whereRaw('1 = 0');
                }
            })
            ->where('a.estado', EventActa::CONFIRMADA)
            ->whereIn('c.estado', EventCompromiso::ABIERTOS)
            ->where(fn ($q) => $q->where('c.fecha_limite', '<', $afterSoon)->orWhere('c.recordar_el', '<', $tomorrow))
            ->get(['c.id', 'c.descripcion', 'c.estado', 'c.fecha_limite', 'c.recordar_el', 'ev.id as event_id', 'ev.nombre as evento', 'o.nombre as organizacion']);

        $items = $rows->map(function ($c) use ($today, $soon) {
            $limite = $c->fecha_limite ? substr((string) $c->fecha_limite, 0, 10) : null;
            $recordar = $c->recordar_el ? substr((string) $c->recordar_el, 0, 10) : null;
            $tipo = match (true) {
                $limite !== null && $limite < $today => 'vencido',
                $limite !== null && $limite <= $soon => 'por_vencer',
                default => 'recordatorio',
            };

            return [
                'id' => $c->id,
                'tipo' => $tipo,
                'descripcion' => $c->descripcion,
                'estado' => $c->estado,
                'fecha_limite' => $limite,
                'recordar_el' => $recordar,
                'evento' => $c->evento ?? $c->organizacion,
                'url' => '/eventos/compromisos?abrir='.$c->id,
                'orden' => [array_search($tipo, array_keys(self::TIPOS), true), $limite ?? $recordar ?? '9999', $c->id],
            ];
        })->sortBy('orden')->values();

        $count = fn (string $tipo) => $items->where('tipo', $tipo)->count();

        return [
            'total' => $items->count(),
            'vencidos' => $count('vencido'),
            'por_vencer' => $count('por_vencer'),
            'recordatorios' => $count('recordatorio'),
            'items' => $items->take($limit)->map(fn ($i) => collect($i)->except('orden')->all())->all(),
        ];
    }
}
