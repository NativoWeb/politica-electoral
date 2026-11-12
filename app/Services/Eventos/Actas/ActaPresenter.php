<?php

namespace App\Services\Eventos\Actas;

use App\Models\EventActa;
use App\Models\EventCompromiso;

// The acta as the page, the Word and the PDF read it (events and organizations alike).
class ActaPresenter
{
    public const TZ = 'America/Bogota';

    public static function payload(EventActa $acta): array
    {
        $acta->loadMissing(['participantes', 'compromisos.responsables', 'creator:id,name', 'confirmer:id,name']);
        $acta->compromisos->loadCount('avances');

        return [
            'id' => $acta->id,
            'event_id' => $acta->event_id,
            'organizacion_id' => $acta->organizacion_id,
            'titulo' => $acta->titulo,
            'fecha' => $acta->fecha?->toDateString(),
            'municipio' => $acta->municipio,
            'sitio' => $acta->sitio,
            'hora_inicio' => $acta->hora_inicio,
            'hora_fin' => $acta->hora_fin,
            'introduccion' => $acta->introduccion,
            'orden_dia' => $acta->orden_dia ?? [],
            'desarrollo' => $acta->desarrollo ?? [],
            'cierre' => $acta->cierre,
            'estado' => $acta->estado,
            'confirmed_at' => $acta->confirmed_at?->copy()->setTimezone(self::TZ)->toIso8601String(),
            'confirmed_by' => $acta->confirmer?->name,
            'archivo' => $acta->archivo_path ? ['nombre' => ActaFiles::downloadName($acta->archivo_nombre, $acta->archivo_mime), 'tipo' => ActaFiles::EXTENSIONS[$acta->archivo_mime] ?? null] : null,
            'participantes' => $acta->participantes->map(fn ($p) => $p->only(['id', 'nombre', 'perfil', 'municipio', 'telefono', 'cedula', 'lider_id', 'attendee_id', 'sugerencia_rechazada']))->values()->all(),
            'compromisos' => $acta->compromisos->map(fn (EventCompromiso $c) => [
                'id' => $c->id,
                'descripcion' => $c->descripcion,
                'fecha_limite' => $c->fecha_limite?->toDateString(),
                'recordar_el' => $c->recordar_el?->toDateString(),
                'estado' => $c->estado,
                'vencido' => $c->vencido(),
                'avances' => (int) $c->avances_count,
                'responsables' => $c->responsables->map(fn ($r) => $r->only(['id', 'nombre', 'telefono', 'lider_id', 'sugerencia_rechazada']))->values()->all(),
            ])->values()->all(),
            'created_by' => $acta->creator?->name,
            'created_at' => $acta->created_at?->copy()->setTimezone(self::TZ)->toIso8601String(),
        ];
    }
}
