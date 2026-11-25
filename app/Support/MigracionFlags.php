<?php

namespace App\Support;

use App\Actions\Votantes\UpdateVotante;
use App\Services\Configuracion\Catalogo;
use App\Services\Electo\Normalizer;
use DateTimeInterface;
use Illuminate\Support\Carbon;
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

    // Fields the Electo import can leave in contradicciones (PersonasImporter::COMPARE, its municipio and autorización,
    // and the telefono/email of ContactosImporter) => label. A difference of any other field cannot be resolved.
    public const DIFERENCIAS = [
        'nombre' => 'Nombre',
        'tipo_documento' => 'Tipo de documento',
        'fecha_nacimiento' => 'Fecha de nacimiento',
        'fecha_expedicion_doc' => 'Fecha de expedición',
        'genero' => 'Género',
        'estado_civil' => 'Estado civil',
        'telefono' => 'Teléfono',
        'email' => 'Correo',
        'municipio' => 'Municipio',
        'autoriza_datos' => 'Autoriza datos',
    ];

    // Taking Electo's value of these changes the territory or the data authorization: only by editing the ficha.
    public const SOLO_EDITANDO = ['municipio', 'autoriza_datos'];

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
            $diferencias[] = ['campo' => $campo, 'label' => self::DIFERENCIAS[$campo] ?? self::label($campo), 'electo' => self::texto($campo, $electo),
                'actual' => self::texto($campo, $lider->{$campo} ?? null), 'usar_electo' => self::valorElecto($campo, $electo) !== null];
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

    // Electo's value of a difference as the ficha stores it, or null when it cannot be written as it is: a field that
    // is only changed by editing the ficha, an impossible date, a value outside the list, an empty name.
    public static function valorElecto(string $campo, mixed $electo): ?string
    {
        if (! isset(self::DIFERENCIAS[$campo]) || in_array($campo, self::SOLO_EDITANDO, true) || ! is_scalar($electo) || is_bool($electo)) {
            return null;
        }
        $electo = trim((string) $electo);

        return match ($campo) {
            'nombre' => ($nombre = UpdateVotante::normalizeName($electo)) !== '' && mb_strlen($nombre) <= 255
                && ! NombreFicha::tieneNumeros($nombre) ? $nombre : null,
            'fecha_nacimiento', 'fecha_expedicion_doc' => self::fecha($electo),
            'tipo_documento', 'genero', 'estado_civil' => self::opcion($campo, $electo),
            'telefono' => Normalizer::phone($electo),
            'email' => Normalizer::email($electo),
            default => null,
        };
    }

    // Removes one difference; whether it was there. The caller holds the row lock.
    public static function quitarDiferencia(string $liderId, string $campo): bool
    {
        $flags = self::decode(DB::table('lideres')->where('id', $liderId)->value('migracion_flags'));
        if (! is_array($flags['contradicciones'] ?? null) || ! array_key_exists($campo, $flags['contradicciones'])) {
            return false;
        }
        unset($flags['contradicciones'][$campo]);
        if ($flags['contradicciones'] === []) {
            unset($flags['contradicciones']);
        }
        DB::table('lideres')->where('id', $liderId)->update(['migracion_flags' => $flags === [] ? null : json_encode($flags, JSON_UNESCAPED_UNICODE)]);

        return true;
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

    // A value of the notice as the screens show it: dates Y-m-d (the model casts them to Carbon), booleans Sí/No.
    private static function texto(string $campo, mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_bool($value) || $campo === 'autoriza_datos') {
            return $value === null || $value === '' ? null : (filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Sí' : 'No');
        }
        if (! is_scalar($value)) {
            return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        $value = (string) $value;
        if (str_starts_with($campo, 'fecha_') && preg_match('/^\d{4}-\d{2}-\d{2}[ T]/', $value)) {
            return substr($value, 0, 10);
        }

        return $value === '' ? null : $value;
    }

    private static function fecha(string $value): ?string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = Carbon::createFromFormat('!Y-m-d', $value);
        if (! $date || $date->format('Y-m-d') !== $value || $date->year < 1900 || $date->isAfter(today())) {
            return null;
        }

        return $value;
    }

    // An active option of the list, matched without case or accents on its value or its label ("SOLTERO/A").
    private static function opcion(string $catalogo, string $value): ?string
    {
        $key = Normalizer::key($value);
        if ($key === '') {
            return null;
        }
        foreach (Catalogo::opciones($catalogo) as $opcion) {
            if (Normalizer::key($opcion['valor']) === $key || Normalizer::key($opcion['etiqueta']) === $key) {
                return $opcion['valor'];
            }
        }

        return null;
    }

    private static function label(string $campo): string
    {
        return ucfirst(str_replace('_', ' ', $campo));
    }
}
