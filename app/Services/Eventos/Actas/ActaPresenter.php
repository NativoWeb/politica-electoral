<?php

namespace App\Services\Eventos\Actas;

use App\Models\AuditLog;
use App\Models\EventActa;
use App\Models\EventCompromiso;
use App\Support\MigracionFlags;
use Illuminate\Support\Facades\DB;

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

    // What the acta's page and its JSON responses get besides payload() (docs §14): the version a correction sends back
    // and, per row and in total, what is still missing. Not for the Word, the PDF nor the agent's API.
    public static function forPage(EventActa $acta): array
    {
        $payload = self::payload($acta);
        $fichas = self::fichas(array_values(array_filter(array_column($payload['participantes'], 'lider_id'))));

        $participantes = 0;
        foreach ($payload['participantes'] as $k => $p) {
            $ficha = $p['lider_id'] ? ($fichas[$p['lider_id']] ?? null) : null;
            $faltan = $ficha
                ? array_keys(array_filter(['cedula' => ! $ficha['cedula'], 'telefono' => ! $ficha['telefono'], 'municipio' => ! $ficha['municipio']]))
                : array_keys(array_filter(['cedula' => blank($p['cedula']), 'telefono' => blank($p['telefono']), 'municipio' => blank($p['municipio'])]));
            $payload['participantes'][$k]['por_completar'] = $faltan;
            $participantes += $faltan === [] ? 0 : 1;
        }
        $compromisos = 0;
        foreach ($payload['compromisos'] as $k => $c) {
            $faltan = array_keys(array_filter(['fecha_limite' => $c['fecha_limite'] === null, 'responsable' => $c['responsables'] === []]));
            $payload['compromisos'][$k]['por_completar'] = $faltan;
            $compromisos += $faltan === [] ? 0 : 1;
        }

        return [
            ...$payload,
            'version' => (int) ($acta->version ?? 0),
            'indicadores' => ['participantes_por_completar' => $participantes, 'compromisos_incompletos' => $compromisos],
        ];
    }

    // [lider id => [cedula, telefono, municipio] (whether the ficha has them now)]. A phone in its contacts counts.
    private static function fichas(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $ids = array_values(array_unique($ids));
        $conContacto = DB::table('lider_contactos')->whereIn('lider_id', $ids)->whereIn('tipo', MigracionFlags::PHONE_CONTACTS)
            ->distinct()->pluck('lider_id')->flip();
        $out = [];
        foreach (DB::table('lideres')->whereIn('id', $ids)->get(['id', 'cedula', 'telefono', 'geographic_unit_id']) as $f) {
            $out[$f->id] = [
                'cedula' => ! blank($f->cedula),
                'telefono' => ! blank($f->telefono) || isset($conContacto[$f->id]),
                'municipio' => $f->geographic_unit_id !== null,
            ];
        }

        return $out;
    }

    // The acta's history for its page (docs §14): its confirmation, corrections and the replaced original of a confirmed
    // acta, newest first, at most 50, from audit_logs (which keeps no personal data).
    public static function historial(EventActa $acta): array
    {
        if ($acta->isDraft()) {
            return [];
        }
        $organizacion = $acta->organizacion_id !== null;
        $prefix = $organizacion ? 'ORGANIZACION' : 'EVENTO';
        $acciones = ["{$prefix}_ACTA_CONFIRMADA" => 'confirmada', "{$prefix}_ACTA_CORREGIDA" => 'corregida', "{$prefix}_ACTA_ARCHIVO" => 'archivo'];

        return AuditLog::query()->with('user:id,name')
            ->where('entity_type', $organizacion ? 'organizacion' : 'evento')
            ->where('entity_id', $organizacion ? $acta->organizacion_id : $acta->event_id)
            ->whereIn('action', array_keys($acciones))
            ->where('metadata->acta_id', $acta->id)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(50)->get()
            ->filter(fn (AuditLog $log) => $acciones[$log->action] !== 'archivo' || ! empty($log->metadata['confirmada']))
            // audit_logs.created_at has whole seconds: inside one second the confirmation goes first and corrections by version.
            ->sortByDesc(fn (AuditLog $log) => [$log->created_at?->timestamp ?? 0, match ($acciones[$log->action]) {
                'confirmada' => 0, 'archivo' => 1, default => 2 + (int) ($log->metadata['version'] ?? 0),
            }])
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'accion' => $acciones[$log->action],
                'fecha' => $log->created_at?->copy()->setTimezone(self::TZ)->toIso8601String(),
                'usuario' => $log->user?->name,
                'resumen' => self::resumen($acciones[$log->action], $log),
            ])->values()->all();
    }

    private static function resumen(string $accion, AuditLog $log): string
    {
        $m = $log->metadata ?? [];
        if ($accion === 'confirmada') {
            return 'Confirmó el acta: '.self::plural((int) ($m['participantes'] ?? 0), 'participante').', '.self::plural((int) ($m['compromisos'] ?? 0), 'compromiso').'.';
        }
        if ($accion === 'archivo') {
            return 'Reemplazó el archivo original.';
        }

        $before = $log->before_state ?? [];
        $after = $log->after_state ?? [];
        $campos = [];
        foreach ($m['cambios'] ?? [] as $campo) {
            $label = self::CAMPOS[$campo] ?? $campo;
            $campos[] = array_key_exists($campo, $before)
                ? "{$label} (".self::valor($campo, $before[$campo]).' → '.self::valor($campo, $after[$campo] ?? null).')'
                : $label;
        }
        $partes = $campos === [] ? [] : ['Cambió: '.implode(', ', $campos)];

        $mas = (int) ($m['participantes_agregados'] ?? 0);
        $menos = (int) ($m['participantes_quitados'] ?? 0);
        $editados = (int) ($m['participantes_editados'] ?? 0);
        $personas = array_filter([
            $mas ? '+'.self::plural($mas, 'participante') : null,
            $menos ? ($mas ? "−{$menos}" : '−'.self::plural($menos, 'participante')) : null,
            $editados ? self::plural($editados, 'participante corregido', 'participantes corregidos') : null,
        ]);
        if ($personas !== []) {
            $partes[] = implode(', ', $personas);
        }

        $compromisos = array_filter([
            ($n = (int) ($m['compromisos_agregados'] ?? 0)) ? self::plural($n, 'compromiso nuevo', 'compromisos nuevos') : null,
            ($n = (int) ($m['compromisos_editados'] ?? 0)) ? self::plural($n, 'compromiso editado', 'compromisos editados') : null,
            ($n = (int) ($m['compromisos_quitados'] ?? 0)) ? self::plural($n, 'compromiso quitado', 'compromisos quitados') : null,
        ]);
        if ($compromisos !== []) {
            $partes[] = implode(', ', $compromisos);
        }

        return $partes === [] ? 'Guardó la corrección sin cambios.' : implode('; ', $partes);
    }

    private const CAMPOS = [
        'titulo' => 'título', 'fecha' => 'fecha', 'municipio' => 'municipio', 'sitio' => 'sitio', 'hora_inicio' => 'hora de inicio',
        'hora_fin' => 'hora de fin', 'introduccion' => 'introducción', 'orden_dia' => 'orden del día', 'desarrollo' => 'desarrollo', 'cierre' => 'cierre',
        'orden_participantes' => 'orden de los participantes', 'orden_compromisos' => 'orden de los compromisos',
    ];

    private const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    private static function valor(string $campo, mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'vacío';
        }
        if ($campo === 'fecha' && preg_match('/^\d{4}-(\d{2})-(\d{2})$/', (string) $value, $m)) {
            return ((int) $m[2]).'-'.self::MESES[(int) $m[1] - 1];
        }

        return (string) $value;
    }

    private static function plural(int $n, string $one, ?string $many = null): string
    {
        return $n.' '.($n === 1 ? $one : ($many ?? $one.'s'));
    }
}
