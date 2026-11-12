<?php

namespace App\Services\Eventos\Actas;

use App\Services\Eventos\AttendeeSheetReader;
use App\Services\Eventos\ImportColumns;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use RuntimeException;

// Reads an acta written in the Excel template (ActaTemplate): sheet "Acta" (Campo | Valor), "Participantes" and
// "Compromisos". It returns exactly what ActaDocxReader returns, so the form is prefilled the same way and the user
// reviews everything before saving. A missing sheet or a bad value is an aviso, never the end of the reading.
class ActaSheetReader
{
    public const MAX_ROWS = 500;

    private const FIELDS = [
        'titulo' => 'titulo', 'titulo del acta' => 'titulo',
        'fecha' => 'fecha', 'fecha de la reunion' => 'fecha',
        'hora' => 'hora', 'hora de inicio' => 'hora_inicio', 'hora inicio' => 'hora_inicio',
        'hora de fin' => 'hora_fin', 'hora fin' => 'hora_fin', 'hora de terminacion' => 'hora_fin',
        'municipio' => 'municipio',
        'lugar' => 'sitio', 'sitio' => 'sitio',
        'objetivo' => 'introduccion', 'tema' => 'introduccion', 'objetivo tema' => 'introduccion', 'introduccion' => 'introduccion',
        'orden del dia' => 'orden_dia', 'puntos del orden del dia' => 'orden_dia',
        'desarrollo' => 'desarrollo', 'desarrollo de la reunion' => 'desarrollo',
        'conclusiones' => 'conclusiones',
        'cierre' => 'cierre',
    ];

    private const PARTICIPANTES = [
        'nombre' => ['nombre', 'nombre completo', 'nombres y apellidos'],
        'cedula' => ['cedula', 'documento', 'numero de cedula'],
        'telefono' => ['telefono', 'celular', 'contacto'],
        'municipio' => ['municipio'],
        'perfil' => ['perfil', 'cargo', 'perfil cargo'],
    ];

    private const COMPROMISOS = [
        'descripcion' => ['descripcion', 'compromiso', 'descripcion del compromiso'],
        'responsable' => ['responsable', 'responsables'],
        'telefono' => ['telefono', 'telefono del responsable', 'celular'],
        'fecha_limite' => ['fecha limite'],
        'recordar_el' => ['recordar el', 'recordatorio'],
    ];

    public function __construct(private AttendeeSheetReader $sheets, private ActaDocxReader $docx) {}

    // $extension: xlsx or xls. Throws ActaFileException when the file cannot be read as the template.
    public function read(string $path, string $extension): array
    {
        try {
            [$book, $overflow] = $this->sheets->workbook($path, $extension, self::MAX_ROWS);
        } catch (RuntimeException $e) {
            throw new ActaFileException($e->getMessage());
        }
        if ($overflow) {
            throw new ActaFileException('Una hoja del Excel tiene más de '.self::MAX_ROWS.' filas. Borre las filas que sobren e intente de nuevo.');
        }
        $byKey = [];
        foreach ($book as $title => $grid) {
            $byKey[ImportColumns::normalize($title)] ??= $grid;
        }
        if (! isset($byKey['acta']) && ! isset($byKey['participantes']) && ! isset($byKey['compromisos'])) {
            throw new ActaFileException('El Excel no tiene las hojas «Acta», «Participantes» ni «Compromisos». Descargue la plantilla Excel y llénela.');
        }

        $acta = [
            'titulo' => null, 'fecha' => null, 'municipio' => null, 'sitio' => null, 'hora_inicio' => null, 'hora_fin' => null,
            'introduccion' => null, 'orden_dia' => [], 'desarrollo' => [], 'participantes' => [], 'compromisos' => [],
            'cierre' => null, 'avisos' => [],
        ];
        foreach (['acta' => 'Acta', 'participantes' => 'Participantes', 'compromisos' => 'Compromisos'] as $key => $label) {
            if (! isset($byKey[$key])) {
                $acta['avisos'][] = "No se encontró la hoja «{$label}»: llene esa parte a mano.";
            }
        }

        $this->header($acta, $byKey['acta'] ?? []);
        $acta['participantes'] = $this->participantes($acta, $byKey['participantes'] ?? []);
        $acta['compromisos'] = $this->compromisos($acta, $byKey['compromisos'] ?? []);
        if (isset($byKey['acta']) && $acta['titulo'] === null) {
            $acta['avisos'][] = 'La hoja «Acta» no trae el título: escríbalo antes de guardar.';
        }

        return $acta;
    }

    private function header(array &$acta, array $grid): void
    {
        $desarrollo = [];
        $conclusiones = [];
        foreach ($grid as $row => $cells) {
            $field = self::FIELDS[ImportColumns::normalize((string) ($cells[0] ?? ''))] ?? null;
            $value = $cells[1] ?? null;
            if ($field === null || $value === null || $value === '') {
                continue;
            }
            $text = trim((string) $value);
            match ($field) {
                'fecha' => $acta['fecha'] = $this->date($value) ?? $this->invalidDate($acta, "Acta, fila {$row}: la fecha «{$text}» no es válida (use dd/mm/aaaa); escríbala a mano."),
                'hora', 'hora_inicio', 'hora_fin' => $this->hour($acta, $field, $value, $row),
                'orden_dia' => array_push($acta['orden_dia'], ...$this->lines($text)),
                'desarrollo' => $desarrollo[] = $text,
                'conclusiones' => $conclusiones[] = $text,
                'titulo' => $acta['titulo'] = mb_substr(preg_replace('/\s+/u', ' ', $text), 0, 255),
                'municipio' => $acta['municipio'] = mb_substr($text, 0, 120),
                'sitio' => $acta['sitio'] = mb_substr($text, 0, 255),
                'introduccion' => $acta['introduccion'] = mb_substr($text, 0, 5000),
                'cierre' => $acta['cierre'] = mb_substr($text, 0, 2000),
            };
        }
        $acta['orden_dia'] = array_map(fn ($o) => mb_substr($o, 0, 1000), array_slice($acta['orden_dia'], 0, 60));
        // One "Desarrollo" row per point of the order of the day, in the same order.
        foreach ($desarrollo as $i => $texto) {
            $acta['desarrollo'][] = ['titulo' => $acta['orden_dia'][$i] ?? '', 'texto' => mb_substr($texto, 0, 20000)];
        }
        if ($conclusiones !== []) {
            $acta['desarrollo'][] = ['titulo' => 'Conclusiones', 'texto' => mb_substr(implode("\n", $conclusiones), 0, 20000)];
        }
        $acta['desarrollo'] = array_slice($acta['desarrollo'], 0, 60);
    }

    private function participantes(array &$acta, array $grid): array
    {
        [$columns, $rows] = $this->table($grid, self::PARTICIPANTES);
        if ($rows !== [] && ! isset($columns['nombre'])) {
            $acta['avisos'][] = 'La hoja «Participantes» no tiene la columna «Nombre»: agréguelos a mano.';

            return [];
        }
        $out = [];
        foreach ($rows as $row => $cells) {
            $value = fn (string $field) => isset($columns[$field]) ? $this->text($cells[$columns[$field]] ?? null) : null;
            $nombre = $value('nombre');
            if ($nombre === null) {
                $acta['avisos'][] = "Participantes, fila {$row}: falta el nombre; no se cargó.";

                continue;
            }
            if (count($out) === 300) {
                $acta['avisos'][] = 'Solo se cargaron los primeros 300 participantes.';
                break;
            }
            $out[] = [
                'nombre' => mb_substr(preg_replace('/\s+/u', ' ', $nombre), 0, 200),
                'perfil' => self::cut($value('perfil'), 200),
                'municipio' => self::cut($value('municipio'), 120),
                'telefono' => self::cut($value('telefono'), 60),
                'cedula' => self::cut($value('cedula'), 20),
            ];
        }

        return $out;
    }

    private function compromisos(array &$acta, array $grid): array
    {
        [$columns, $rows] = $this->table($grid, self::COMPROMISOS);
        if ($rows !== [] && ! isset($columns['descripcion'])) {
            $acta['avisos'][] = 'La hoja «Compromisos» no tiene la columna «Descripción»: agréguelos a mano.';

            return [];
        }
        $out = [];
        foreach ($rows as $row => $cells) {
            $cell = fn (string $field) => isset($columns[$field]) ? ($cells[$columns[$field]] ?? null) : null;
            $descripcion = $this->text($cell('descripcion'));
            if ($descripcion === null) {
                $acta['avisos'][] = "Compromisos, fila {$row}: falta la descripción; no se cargó.";

                continue;
            }
            if (count($out) === 150) {
                $acta['avisos'][] = 'Solo se cargaron los primeros 150 compromisos.';
                break;
            }
            $dates = [];
            foreach (['fecha_limite' => 'la fecha límite', 'recordar_el' => 'la fecha «Recordar el»'] as $field => $label) {
                $raw = $cell($field);
                $dates[$field] = $raw === null ? null : $this->date($raw);
                if ($raw !== null && $dates[$field] === null) {
                    $acta['avisos'][] = "Compromisos, fila {$row}: {$label} «{$this->text($raw)}» no es válida (use dd/mm/aaaa); quedó vacía.";
                }
            }
            $out[] = [
                'descripcion' => mb_substr($descripcion, 0, 5000),
                'fecha_limite' => $dates['fecha_limite'],
                'recordar_el' => $dates['recordar_el'],
                'responsables' => array_slice($this->docx->responsables(
                    $this->split($this->text($cell('responsable'))),
                    $this->split($this->text($cell('telefono'))),
                ), 0, 15),
            ];
        }

        return $out;
    }

    // [field => column index], [row number => cells] below the header row (the first row with a known header).
    private function table(array $grid, array $synonyms): array
    {
        foreach ($grid as $headerRow => $cells) {
            $columns = [];
            foreach ($cells as $index => $header) {
                $key = ImportColumns::normalize((string) $header);
                foreach ($synonyms as $field => $names) {
                    if (! isset($columns[$field]) && in_array($key, $names, true)) {
                        $columns[$field] = $index;
                    }
                }
            }
            if ($columns !== []) {
                return [$columns, array_filter($grid, fn ($row) => $row > $headerRow, ARRAY_FILTER_USE_KEY)];
            }
        }

        return [[], $grid];
    }

    // An Excel date (a number) or a text: dd/mm/aaaa, aaaa-mm-dd, "8 de noviembre de 2026".
    private function date(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            if ($value < 1 || $value > 2958465) {
                return null;
            }
            $date = Date::excelToDateTimeObject($value);
            $year = (int) $date->format('Y');

            return $year >= 2000 && $year <= 2100 ? $date->format('Y-m-d') : null;
        }

        return ActaFormat::parseDate((string) $value);
    }

    private function invalidDate(array &$acta, string $aviso): ?string
    {
        $acta['avisos'][] = $aviso;

        return null;
    }

    // An Excel time (fraction of a day, also the time of a date-time), a whole hour (10 → 10:00) or a text
    // ("10:45 a. m.", "10:45 - 12:00" for both hours). A date without time or any other number is not an hour.
    private function hour(array &$acta, string $field, mixed $value, int $row): void
    {
        if (is_int($value) || is_float($value)) {
            $hours = [];
            if ($value >= 0 && floor($value) == $value) {
                $hours = $value <= 23 ? [sprintf('%02d:00', (int) $value)] : [];
            } elseif ($value > 0) {
                $minutes = (int) round(fmod((float) $value, 1) * 1440) % 1440;
                $hours = [sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60)];
            }
        } else {
            $hours = ActaFormat::parseHours((string) $value);
        }
        if ($hours === []) {
            $acta['avisos'][] = "Acta, fila {$row}: la hora «{$this->text($value)}» no es válida (use HH:MM); escríbala a mano.";

            return;
        }
        if ($field === 'hora') {
            $acta['hora_inicio'] = $hours[0];
            $acta['hora_fin'] = $hours[1] ?? $acta['hora_fin'];
        } else {
            $acta[$field] = $hours[0];
        }
    }

    // A number typed in a cell (cédula, celular) without ".0" or the exponent.
    private function text(mixed $value): ?string
    {
        if (is_float($value) && floor($value) == $value && abs($value) < 1e15) {
            $value = number_format($value, 0, '', '');
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function lines(string $text): array
    {
        return array_values(array_filter(array_map(fn ($l) => trim(preg_replace('/^\s*(?:\d{1,2}\s*[.)\-]|[•\-])\s*/u', '', $l)), preg_split('/\R/u', $text)), 'strlen'));
    }

    // One responsable (or phone) per line of the cell, or separated by ";".
    private function split(?string $text): array
    {
        return $text === null ? [] : array_values(array_filter(array_map('trim', preg_split('/\R|;/u', $text)), 'strlen'));
    }

    private static function cut(?string $value, int $max): ?string
    {
        return $value === null ? null : mb_substr(preg_replace('/\s+/u', ' ', $value), 0, $max);
    }
}
