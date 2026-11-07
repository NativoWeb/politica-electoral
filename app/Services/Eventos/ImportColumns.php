<?php

namespace App\Services\Eventos;

use Illuminate\Support\Str;

// Attendee fields an import can fill, the template headers and the synonyms used to guess which column is which.
class ImportColumns
{
    // key => [label shown on screen, header of the template]
    public const FIELDS = [
        'nombres' => ['Nombres', 'Nombres'],
        'apellidos' => ['Apellidos', 'Apellidos'],
        'nombre_completo' => ['Nombre completo (se divide en nombres y apellidos)', null],
        'cedula' => ['Cédula', 'Cédula'],
        'celular' => ['Celular', 'Celular'],
        'fijo' => ['Teléfono fijo', 'Teléfono fijo'],
        'fecha_nacimiento' => ['Fecha de nacimiento', 'Fecha de nacimiento (DD/MM/AAAA)'],
        'direccion' => ['Dirección', 'Dirección'],
        'barrio' => ['Barrio', 'Barrio'],
        'municipio' => ['Municipio', 'Municipio'],
        'liderazgo' => ['Liderazgo o actividad (queda como nota del asistente)', 'Liderazgo o actividad'],
    ];

    // Compared after normalize(); for each field the first synonym found among the headers wins, so a sheet with
    // "Celular" and "Teléfono" puts the first in celular and leaves "Teléfono" for the fijo.
    private const SYNONYMS = [
        'cedula' => ['cedula', 'cc', 'c c', 'cedula de ciudadania', 'no cedula', 'n cedula', 'nro cedula', 'num cedula',
            'numero cedula', 'numero de cedula', 'no de cedula', 'documento', 'documento de identidad', 'no documento',
            'numero documento', 'numero de documento', 'identificacion', 'nit cedula', 'c c no', 'doc', 'no documento identidad',
            'documento identidad', 'no documento de identidad', 'numero documento identidad', 'numero de documento de identidad'],
        'nombre_completo' => ['nombre completo', 'nombres completos', 'nombres y apellidos', 'nombre y apellido',
            'nombre y apellidos', 'apellidos y nombres', 'nombre del asistente', 'asistente'],
        'nombres' => ['nombres', 'nombre', 'primer nombre'],
        'apellidos' => ['apellidos', 'apellido', 'primer apellido'],
        'celular' => ['celular', 'cel', 'movil', 'telefono celular', 'telefono movil', 'numero celular', 'numero de celular',
            'no celular', 'whatsapp', 'tel', 'telefono', 'telefonos', 'contacto', 'celular fijo', 'celular o fijo', 'telefono celular fijo'],
        'fijo' => ['telefono fijo', 'fijo', 'tel fijo', 'telefono', 'tel', 'telefono 2', 'otro telefono'],
        'fecha_nacimiento' => ['fecha de nacimiento', 'fecha nacimiento', 'fecha nac', 'f nacimiento', 'nacimiento',
            'fecha de cumpleanos', 'fecha cumpleanos', 'cumpleanos'],
        'direccion' => ['direccion', 'direccion de residencia', 'dir', 'domicilio', 'residencia'],
        'barrio' => ['barrio', 'barrio vereda', 'barrio o vereda', 'sector', 'vereda', 'comuna'],
        'municipio' => ['municipio', 'mpio', 'municipio de residencia', 'ciudad'],
        'liderazgo' => ['liderazgo', 'tipo de liderazgo', 'lider', 'rol', 'actividad', 'nota', 'observacion', 'observaciones'],
    ];

    // Order in which fields pick their column.
    private const ORDER = ['cedula', 'nombre_completo', 'nombres', 'apellidos', 'celular', 'fijo', 'fecha_nacimiento', 'direccion', 'barrio', 'municipio', 'liderazgo'];

    public static function templateHeaders(): array
    {
        return array_values(array_filter(array_column(self::FIELDS, 1)));
    }

    public static function fieldOptions(): array
    {
        return array_map(fn ($key) => ['key' => $key, 'label' => self::FIELDS[$key][0]], array_keys(self::FIELDS));
    }

    // "No. Cédula (sin puntos)" → "no cedula".
    public static function normalize(?string $header): string
    {
        $text = Str::lower(Str::ascii((string) $header));
        $text = preg_replace('/\(.*?\)/', ' ', $text);
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    // [field => column index|null]. $saved: [normalized header => field] remembered for the campaign; it wins
    // over the synonyms for the headers it knows.
    public static function detect(array $headers, array $saved = []): array
    {
        $normalized = array_map(fn ($h) => self::normalize(is_string($h) ? $h : (string) $h), $headers);
        $mapping = array_fill_keys(array_keys(self::FIELDS), null);
        $used = [];

        foreach ($normalized as $index => $header) {
            $field = $saved[$header] ?? null;
            if ($header !== '' && $field !== null && array_key_exists($field, $mapping) && $mapping[$field] === null) {
                $mapping[$field] = $index;
                $used[$index] = true;
            }
        }

        foreach (self::ORDER as $field) {
            if ($mapping[$field] !== null) {
                continue;
            }
            foreach (self::SYNONYMS[$field] as $synonym) {
                foreach ($normalized as $index => $header) {
                    if (! isset($used[$index]) && $header === $synonym) {
                        $mapping[$field] = $index;
                        $used[$index] = true;
                        break 2;
                    }
                }
            }
        }

        return self::resolveFullName($mapping, $normalized);
    }

    // A lone "Nombre" column with no apellidos column holds the full name.
    private static function resolveFullName(array $mapping, array $normalized): array
    {
        if ($mapping['nombres'] !== null && $mapping['apellidos'] === null && $mapping['nombre_completo'] === null
            && $normalized[$mapping['nombres']] === 'nombre') {
            $mapping['nombre_completo'] = $mapping['nombres'];
            $mapping['nombres'] = null;
        }

        return $mapping;
    }

    // How many recognised headers a row has: used to find the header row under a title.
    public static function score(array $cells): int
    {
        $all = array_merge(...array_values(self::SYNONYMS));
        $score = 0;
        foreach ($cells as $cell) {
            if (is_string($cell) && in_array(self::normalize($cell), $all, true)) {
                $score++;
            }
        }

        return $score;
    }

    // Returns the error message of an incomplete mapping, or null: the name and the cédula or the phone, to find who
    // is already in the platform.
    public static function missing(array $mapping): ?string
    {
        $set = fn (string $field) => ($mapping[$field] ?? null) !== null;
        $hasName = $set('nombre_completo') || ($set('nombres') && $set('apellidos'));
        if (! $hasName) {
            return 'Elija la columna de nombres y la de apellidos, o una sola columna con el nombre completo.';
        }
        if (! $set('cedula') && ! $set('celular')) {
            return 'Elija la columna de la cédula o la del teléfono: sin ninguna no se puede saber si la persona ya está en la plataforma.';
        }
        $indexes = array_filter($mapping, fn ($v) => $v !== null);
        if (count($indexes) !== count(array_unique($indexes))) {
            return 'Una misma columna está elegida para dos datos distintos. Revise las columnas.';
        }

        return null;
    }
}
