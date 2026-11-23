<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// lideres.migracion_flags of the Electo import (docs/migracion-electo.md): what the team must complete (por_completar,
// no municipio, an invalid birth date), where Electo said something else (contradicciones) and why a ficha came in
// inactive. The Votantes filters, the notice of the ficha and the clean-up after saving all read it from here.
class MigracionFlags
{
    // Filter value => label. Catalog fields Electo did not send, plus the municipio and the birth date it had wrong, and
    // what an acta did not bring of a participant whose ficha it created (cédula, teléfono, municipio; docs §14).
    public const POR_COMPLETAR = [
        'barrio' => 'Barrio',
        'nivel_confianza' => 'Nivel de confianza',
        'convenio' => 'Convenio',
        'tipo_hoja_vida' => 'Tipo de hoja de vida',
        'municipio' => 'Municipio',
        'fecha_nacimiento' => 'Fecha de nacimiento',
        'cedula' => 'Cédula',
        'telefono' => 'Teléfono',
    ];

    // Contact kinds that hold a phone: a ficha with one of them has its teléfono (also for the acta's indicators).
    public const PHONE_CONTACTS = ['celular', 'fijo', 'telefono', 'whatsapp'];

    // Imported inactive on purpose (Ley 1581 for minors).
    public const INACTIVOS = [
        'menor' => 'Menor de edad',
        'fallecido' => 'Fallecido',
        'sin_municipio' => 'Sin municipio',
    ];

    private const MARCAS = [
        'menor' => 'Llegó de Electo como menor de edad: quedó inactiva y sin autorización de datos.',
        'fallecido' => 'Electo la marcaba como fallecida: quedó inactiva.',
        'sin_municipio' => 'Electo no tenía municipio: quedó inactiva hasta que se complete.',
        'genero_inferido' => 'El género se dedujo del código de Electo: conviene confirmarlo.',
        'posible_duplicado' => 'Puede ser la misma persona que otra ficha del mismo nombre y municipio.',
        'observacion_recortada' => 'La observación de Electo era muy larga y se recortó.',
    ];

    private static ?bool $hasColumn = null;

    public static function decode(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    // The Votantes filters "Datos por completar", "Diferencias con Electo" and "Inactivos importados" (PostgreSQL
    // jsonb; @> uses the GIN index). Returns whether an inactive-import filter is on: it replaces the estado filter.
    public static function applyFilters($query, array $filters): bool
    {
        $col = 'lideres.migracion_flags';
        $porCompletar = $filters['por_completar'] ?? null;
        if ($porCompletar === 'municipio') {
            $query->where(fn ($q) => $q->whereRaw("{$col} @> ?::jsonb", ['{"sin_municipio": true}'])
                ->orWhereRaw("{$col} @> ?::jsonb", ['{"por_completar": ["municipio"]}']));
        } elseif ($porCompletar === 'fecha_nacimiento') {
            $query->whereRaw("{$col} @> ?::jsonb", ['{"fecha_invalida": ["fecha_nacimiento"]}']);
        } elseif ($porCompletar === 'cualquiera') {
            $query->whereRaw("(jsonb_array_length(coalesce({$col} -> 'por_completar', '[]'::jsonb)) > 0 OR {$col} @> ?::jsonb OR {$col} @> ?::jsonb)",
                ['{"sin_municipio": true}', '{"fecha_invalida": ["fecha_nacimiento"]}']);
        } elseif (is_string($porCompletar) && isset(self::POR_COMPLETAR[$porCompletar])) {
            $query->whereRaw("{$col} @> ?::jsonb", [json_encode(['por_completar' => [$porCompletar]])]);
        }

        if (filter_var($filters['diferencias'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->whereRaw("jsonb_exists({$col}, 'contradicciones')");
        }

        $inactivos = $filters['importados_inactivos'] ?? null;
        if (! is_string($inactivos) || ($inactivos !== 'todos' && ! isset(self::INACTIVOS[$inactivos]))) {
            return false;
        }
        $query->where('lideres.is_active', false);
        if ($inactivos === 'todos') {
            $query->where(function ($q) use ($col) {
                foreach (array_keys(self::INACTIVOS) as $flag) {
                    $q->orWhereRaw("{$col} @> ?::jsonb", [json_encode([$flag => true])]);
                }
            });
        } else {
            $query->whereRaw("{$col} @> ?::jsonb", [json_encode([$inactivos => true])]);
        }

        return true;
    }

    // What the ficha's notice shows, or null for a ficha without marks: pending fields (with Electo's code when it
    // had one), Electo's value against ours where they differ, and the other marks explained.
    public static function forFicha(object $lider): ?array
    {
        $flags = self::decode($lider->migracion_flags ?? null);
        if ($flags === []) {
            return null;
        }
        $codes = $flags['electo_codigos'] ?? [];

        $pendientes = [];
        foreach ($flags['por_completar'] ?? [] as $campo) {
            $pendientes[] = ['campo' => $campo, 'label' => self::POR_COMPLETAR[$campo] ?? self::label($campo), 'codigo_electo' => $codes[$campo] ?? null];
        }
        if (! empty($flags['sin_municipio'])) {
            $pendientes[] = ['campo' => 'municipio', 'label' => 'Municipio', 'codigo_electo' => null];
        }
        if (in_array('fecha_nacimiento', (array) ($flags['fecha_invalida'] ?? []), true)) {
            $pendientes[] = ['campo' => 'fecha_nacimiento', 'label' => 'Fecha de nacimiento', 'codigo_electo' => null];
        }

        $diferencias = [];
        foreach ($flags['contradicciones'] ?? [] as $campo => $electo) {
            $diferencias[] = ['campo' => $campo, 'label' => self::label($campo), 'electo' => is_scalar($electo) ? (string) $electo : json_encode($electo),
                'actual' => isset($lider->{$campo}) && is_scalar($lider->{$campo}) ? (string) $lider->{$campo} : null];
        }

        $marcas = [];
        foreach (self::MARCAS as $flag => $text) {
            if (! empty($flags[$flag])) {
                $marcas[] = $text;
            }
        }

        if ($pendientes === [] && $diferencias === [] && $marcas === []) {
            return null;
        }

        return ['por_completar' => $pendientes, 'diferencias' => $diferencias, 'marcas' => $marcas];
    }

    // After saving a ficha: a pending field that now has a value stops being pending (also the municipio and the
    // birth date). Everything else in the flags stays.
    public static function clearCompleted(string $liderId): void
    {
        if (! self::hasColumn()) {
            return;
        }
        $row = DB::table('lideres')->where('id', $liderId)->first();
        $flags = self::decode($row->migracion_flags ?? null);
        if (! $row || $flags === []) {
            return;
        }

        $before = $flags;
        if (isset($flags['por_completar'])) {
            $flags['por_completar'] = array_values(array_filter($flags['por_completar'], fn ($campo) => ! self::filled($row, $campo)));
            if ($flags['por_completar'] === []) {
                unset($flags['por_completar']);
            }
        }
        if (! empty($flags['sin_municipio']) && $row->geographic_unit_id) {
            unset($flags['sin_municipio']);
        }
        if (isset($flags['fecha_invalida']) && $row->fecha_nacimiento) {
            $flags['fecha_invalida'] = array_values(array_diff((array) $flags['fecha_invalida'], ['fecha_nacimiento']));
            if ($flags['fecha_invalida'] === []) {
                unset($flags['fecha_invalida']);
            }
        }

        if ($flags !== $before) {
            DB::table('lideres')->where('id', $liderId)->update(['migracion_flags' => $flags === [] ? null : json_encode($flags, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public static function hasColumn(): bool
    {
        return self::$hasColumn ??= Schema::hasColumn('lideres', 'migracion_flags');
    }

    private static function filled(object $row, string $campo): bool
    {
        $value = $row->{$campo} ?? null;
        if ($campo === 'telefono' && ($value === null || trim((string) $value) === '')) {
            return DB::table('lider_contactos')->where('lider_id', $row->id)->whereIn('tipo', self::PHONE_CONTACTS)
                ->whereRaw("trim(coalesce(valor, '')) <> ''")->exists();
        }
        if ($campo === 'nivel_confianza') {
            return is_string($value) && $value !== '' && $value !== 'sin_llamar';
        }

        return $value !== null && trim((string) $value) !== '';
    }

    private static function label(string $campo): string
    {
        return ucfirst(str_replace('_', ' ', $campo));
    }
}
