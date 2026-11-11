<?php

namespace App\Services\Eventos\Actas;

use App\Models\EventActa;
use App\Models\EventCompromiso;
use App\Services\Eventos\EventScope;
use Illuminate\Support\Carbon;

// What needs attention for the current user (request()->user(): visible campaigns and territory): open compromisos
// (pendiente, en_curso) of CONFIRMED actas that are overdue, due within DIAS days, or whose reminder date arrived.
// Each compromiso counts once, in that order of priority. Used by the bell and the daily e-mail.
class CompromisoAlertas
{
    public const DIAS = 3;

    public const TIPOS = ['vencido' => 'Vencido', 'por_vencer' => 'Por vencer', 'recordatorio' => 'Recordatorio'];

    public function __construct(private EventScope $events) {}

    public function summary(int $limit = 15): array
    {
        $today = EventCompromiso::today();
        $soon = Carbon::parse($today)->addDays(self::DIAS)->toDateString();
        // "< next day": a date column may come back with a time (SQLite stores 'Y-m-d 00:00:00').
        $afterSoon = Carbon::parse($soon)->addDay()->toDateString();
        $tomorrow = Carbon::parse($today)->addDay()->toDateString();

        $rows = $this->events->events()
            ->join('event_compromisos as c', 'c.event_id', '=', 'ev.id')
            ->join('event_actas as a', 'a.id', '=', 'c.acta_id')
            ->where('a.estado', EventActa::CONFIRMADA)
            ->whereIn('c.estado', EventCompromiso::ABIERTOS)
            ->where(fn ($q) => $q->where('c.fecha_limite', '<', $afterSoon)->orWhere('c.recordar_el', '<', $tomorrow))
            ->get(['c.id', 'c.descripcion', 'c.estado', 'c.fecha_limite', 'c.recordar_el', 'ev.id as event_id', 'ev.nombre as evento']);

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
                'evento' => $c->evento,
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
