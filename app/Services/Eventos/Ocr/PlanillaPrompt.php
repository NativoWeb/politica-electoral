<?php

namespace App\Services\Eventos\Ocr;

// Prompt and JSON schema (structured outputs) to read one page of the printed sign-in sheet of resources/views/
// eventos/planilla.blade.php: 12 numbered rows, one person per row, ending with "Firma" (proof of attendance; since
// 2026-11-07 there is no "Autorizo" box and a missing signature does not stop a row).
// Every value comes with the model's confidence (0 to 1) so the review screen can highlight doubtful cells.
class PlanillaPrompt
{
    public const ROWS = 12;

    public const TEXT_FIELDS = ['nombres', 'apellidos', 'cedula', 'celular', 'fijo', 'fecha_nacimiento', 'direccion', 'barrio'];

    public const BOOL_FIELDS = ['firma_presente'];

    public const SYSTEM = <<<'TXT'
Eres un transcriptor de planillas de asistencia en papel de eventos de una campaña política en Colombia. Cada página tiene un encabezado (nombre del evento, "Página X de Y", un código QR y el texto "Evento" seguido de un código de 8 caracteres) y una tabla de 12 filas numeradas del 1 al 12, una persona por fila, escritas a mano. Columnas, en orden: N.º, NOMBRES, APELLIDOS, CÉDULA (casillas de un dígito), CELULAR (10 casillas), TEL. FIJO (7 casillas), FECHA DE NACIMIENTO (DD / MM / AAAA), DIRECCIÓN, BARRIO y FIRMA. Las planillas impresas antes pueden traer además una casilla AUTORIZO antes de FIRMA: ignórala. Al pie hay una línea pequeña que dice "Evento <identificador completo> · Página X de Y · Generada ...".

Reglas:
- Transcribe exactamente lo escrito, sin corregir ortografía ni completar datos. No inventes: si una celda está vacía o es ilegible, su valor es null.
- Cédula, celular y teléfono fijo: solo los dígitos, en el orden de las casillas.
- Fecha de nacimiento: con el formato DD/MM/AAAA tal como está escrita.
- firma_presente: true solo si en la columna FIRMA de esa fila hay una firma o rúbrica a mano. Un nombre escrito en otra columna no es una firma.
- confianza: tu seguridad de 0 a 1 de que el valor transcrito es exactamente el escrito (1 = seguro; menos de 0.85 = dudoso). Para una celda vacía, tu seguridad de que está vacía.
- Incluye solo las filas en las que hay algo escrito o marcado; omite las filas vacías. numero es el N.º impreso de la fila (1 a 12).
- es_planilla: false si la imagen no es esta planilla de asistencia (otra hoja, una foto sin tabla, una página en blanco).
- evento_id: el identificador completo que aparece después de "Evento" en la línea pequeña del pie (formato xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx), o null si no se lee completo. codigo_evento: el código de 8 caracteres junto a "Página X de Y", o null.
- pagina y total_paginas: los números de "Página X de Y", o null.
TXT;

    public static function instruction(int $page, bool $pdf): string
    {
        return $pdf
            ? "Transcribe solo la página {$page} de este PDF siguiendo las reglas. En paginas_documento indica cuántas páginas tiene el PDF."
            : 'Transcribe esta foto de la planilla siguiendo las reglas. paginas_documento es 1.';
    }

    public static function schema(): array
    {
        $text = [
            'type' => 'object',
            'properties' => [
                'valor' => ['type' => ['string', 'null']],
                'confianza' => ['type' => 'number'],
            ],
            'required' => ['valor', 'confianza'],
            'additionalProperties' => false,
        ];
        $bool = [
            'type' => 'object',
            'properties' => [
                'valor' => ['type' => 'boolean'],
                'confianza' => ['type' => 'number'],
            ],
            'required' => ['valor', 'confianza'],
            'additionalProperties' => false,
        ];

        $row = ['numero' => ['type' => 'integer']];
        foreach (self::TEXT_FIELDS as $field) {
            $row[$field] = $text;
        }
        foreach (self::BOOL_FIELDS as $field) {
            $row[$field] = $bool;
        }

        return [
            'type' => 'object',
            'properties' => [
                'es_planilla' => ['type' => 'boolean'],
                'evento_id' => ['type' => ['string', 'null']],
                'codigo_evento' => ['type' => ['string', 'null']],
                'pagina' => ['type' => ['integer', 'null']],
                'total_paginas' => ['type' => ['integer', 'null']],
                'paginas_documento' => ['type' => ['integer', 'null']],
                'filas' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => $row,
                        'required' => array_keys($row),
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['es_planilla', 'evento_id', 'codigo_evento', 'pagina', 'total_paginas', 'paginas_documento', 'filas'],
            'additionalProperties' => false,
        ];
    }

    // The rows of an answer, cleaned: at most ROWS, numbers in 1..ROWS (a repeated or missing number takes the next
    // free one), confidences clamped to 0..1 and rows with nothing written nor ticked dropped.
    public static function rows(array $data): array
    {
        $rows = [];
        $used = [];
        foreach (array_slice(is_array($data['filas'] ?? null) ? $data['filas'] : [], 0, self::ROWS) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $values = [];
            $confidence = [];
            foreach (self::TEXT_FIELDS as $field) {
                $value = $item[$field]['valor'] ?? null;
                $values[$field] = is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
                $confidence[$field] = self::confidence($item[$field]['confianza'] ?? null);
            }
            foreach (self::BOOL_FIELDS as $field) {
                $values[$field] = ($item[$field]['valor'] ?? false) === true;
                $confidence[$field] = self::confidence($item[$field]['confianza'] ?? null);
            }
            if (array_filter($values, fn ($v) => $v !== null && $v !== false) === []) {
                continue;
            }

            $number = is_int($item['numero'] ?? null) ? $item['numero'] : 0;
            if ($number < 1 || $number > self::ROWS || isset($used[$number])) {
                $number = 1;
                while (isset($used[$number]) && $number <= self::ROWS) {
                    $number++;
                }
            }
            $used[$number] = true;
            $rows[] = ['numero' => $number, 'valores' => $values, 'confianza' => $confidence];
        }

        usort($rows, fn ($a, $b) => $a['numero'] <=> $b['numero']);

        return $rows;
    }

    private static function confidence(mixed $value): float
    {
        return is_int($value) || is_float($value) ? round(max(0.0, min(1.0, (float) $value)), 2) : 0.0;
    }
}
