<?php

namespace App\Services\Eventos\Actas;

use App\Services\Eventos\AttendeeMatcher;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;
use ZipArchive;

// Reads an acta written in Word (.docx) with the structure of the campaign's actas: title, date, "Municipio:",
// "Sitio:", "Hora:", then the sections PARTICIPANTES (table), PUNTOS DEL ORDEN DEL DÍA (list), DESARROLLO DE LA
// REUNIÓN (a text per point, "1. Título" and its paragraphs), COMPROMISOS (table) and the closing sentence.
// It only PREFILLS the form: the user reviews everything before saving. Nothing here touches the database.
class ActaDocxReader
{
    public const MAX_XML_BYTES = 15_000_000;

    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const SECTIONS = [
        'PARTICIPANTES' => 'participantes',
        'ASISTENTES' => 'participantes',
        'PUNTOS DEL ORDEN DEL DIA' => 'orden',
        'ORDEN DEL DIA' => 'orden',
        'DESARROLLO DE LA REUNION' => 'desarrollo',
        'DESARROLLO' => 'desarrollo',
        'COMPROMISOS' => 'compromisos',
    ];

    private DOMXPath $xpath;

    // Throws ActaFileException when the file is not a Word document that can be read.
    public function read(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new ActaFileException('El archivo no es un documento de Word (.docx) válido.');
        }
        $stat = $zip->statName('word/document.xml');
        if ($stat === false) {
            $zip->close();
            throw new ActaFileException('El archivo no es un documento de Word (.docx) válido.');
        }
        if ($stat['size'] > self::MAX_XML_BYTES) {
            $zip->close();
            throw new ActaFileException('El documento es demasiado grande para leerlo. Llene el formulario a mano.');
        }
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        // Word never writes a DTD; one with entities can expand a few bytes into gigabytes (billion laughs).
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new ActaFileException('El documento de Word tiene un formato que no se puede leer. Llene el formulario a mano.');
        }

        $doc = new DOMDocument;
        if ($xml === '' || ! @$doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new ActaFileException('No se pudo leer el contenido del documento de Word.');
        }

        return $this->parse($doc);
    }

    public function parse(DOMDocument $doc): array
    {
        $this->xpath = new DOMXPath($doc);
        $this->xpath->registerNamespace('w', self::W);

        $acta = [
            'titulo' => null, 'fecha' => null, 'municipio' => null, 'sitio' => null, 'hora_inicio' => null, 'hora_fin' => null,
            'introduccion' => null, 'orden_dia' => [], 'desarrollo' => [], 'participantes' => [], 'compromisos' => [],
            'cierre' => null, 'avisos' => [],
        ];
        $section = 'encabezado';
        $found = [];
        $lines = ['orden' => [], 'desarrollo' => []];
        $cierre = [];

        foreach ($this->xpath->query('/w:document/w:body/*') as $node) {
            if ($node->localName === 'tbl') {
                if ($section === 'participantes' && $acta['participantes'] === []) {
                    $acta['participantes'] = $this->participantes($this->table($node));
                } elseif ($section === 'compromisos' && $acta['compromisos'] === []) {
                    $acta['compromisos'] = $this->compromisos($this->table($node));
                }

                continue;
            }
            if ($node->localName !== 'p') {
                continue;
            }
            $text = trim(implode(' ', $this->lines($node)));
            if ($text === '') {
                continue;
            }
            $next = self::SECTIONS[self::sectionKey($text)] ?? null;
            if ($next !== null) {
                $section = $next;
                $found[$next] = true;

                continue;
            }
            if (preg_match('/^siendo\s+las\b/iu', $text) || ($section === 'compromisos' && $acta['compromisos'] !== [])) {
                $cierre[] = $text;
                $section = 'cierre';

                continue;
            }

            match ($section) {
                'encabezado' => $this->header($acta, $text),
                'orden', 'desarrollo' => $lines[$section][] = ['text' => $text, 'numbered' => $this->numbered($node)],
                'cierre' => $cierre[] = $text,
                default => null,
            };
        }

        [$acta['introduccion'], $acta['orden_dia']] = $this->orden($lines['orden']);
        $acta['desarrollo'] = $this->desarrollo($lines['desarrollo'], $acta['orden_dia']);
        if ($cierre !== []) {
            $acta['cierre'] = implode("\n", $cierre);
            if ($acta['hora_fin'] === null && preg_match('/siendo\s+las\s+(.{1,20})/iu', $acta['cierre'], $m)) {
                $acta['hora_fin'] = ActaFormat::parseHours($m[1])[0] ?? null;
            }
        }

        foreach (['participantes' => 'PARTICIPANTES', 'orden' => 'PUNTOS DEL ORDEN DEL DÍA', 'desarrollo' => 'DESARROLLO DE LA REUNIÓN', 'compromisos' => 'COMPROMISOS'] as $key => $label) {
            if (! isset($found[$key])) {
                $acta['avisos'][] = "No se encontró la sección «{$label}»: llénela a mano.";
            }
        }
        if (isset($found['participantes']) && $acta['participantes'] === []) {
            $acta['avisos'][] = 'La sección «PARTICIPANTES» no trae una tabla con nombres: agréguelos a mano.';
        }
        if (isset($found['compromisos']) && $acta['compromisos'] === []) {
            $acta['avisos'][] = 'La sección «COMPROMISOS» no trae una tabla: agréguelos a mano.';
        }

        return $acta;
    }

    // "1. PARTICIPANTES", "PARTICIPANTES:" and "Participantes" are the same title.
    public static function sectionKey(string $text): string
    {
        $key = ActaFormat::key($text);
        $key = preg_replace('/^(?:\d{1,2}|[IVX]{1,4})\s*[.)\-]\s*/', '', $key);

        return rtrim($key, ' :.');
    }

    private function header(array &$acta, string $text): void
    {
        if (preg_match('/^\s*(municipio|sitio|lugar|hora|fecha)\s*:\s*(.*)$/iu', $text, $m)) {
            $value = trim($m[2]);
            $field = strtolower(Str::ascii($m[1]));
            if ($field === 'municipio') {
                $acta['municipio'] = $value ?: null;
            } elseif ($field === 'sitio' || $field === 'lugar') {
                $acta['sitio'] = $value ?: null;
            } elseif ($field === 'hora') {
                $hours = ActaFormat::parseHours($value);
                $acta['hora_inicio'] = $hours[0] ?? null;
                $acta['hora_fin'] = $hours[1] ?? null;
            } else {
                $acta['fecha'] = ActaFormat::parseDate($value);
            }

            return;
        }
        if ($acta['titulo'] === null) {
            $acta['titulo'] = mb_substr($text, 0, 255);
        } elseif ($acta['fecha'] === null && ($date = ActaFormat::parseDate($text))) {
            $acta['fecha'] = $date;
        }
    }

    // Non-numbered lines before the first numbered one are the introduction ("Propuestas de gestión …:"). A list
    // without any numbering at all is taken whole as the points.
    private function orden(array $lines): array
    {
        $intro = [];
        $items = [];
        foreach ($lines as $line) {
            $manual = preg_match('/^\s*\d{1,2}\s*[.)\-]\s+(.+)$/u', $line['text'], $m);
            if ($line['numbered'] || $manual) {
                $items[] = $manual ? trim($m[1]) : $line['text'];
            } elseif ($items === []) {
                $intro[] = $line['text'];
            } else {
                $items[] = $line['text'];
            }
        }
        if ($items === []) {
            return [null, $intro];
        }

        return [$intro ? implode("\n", $intro) : null, $items];
    }

    // A point starts at a line "N. Título" (or one equal to a point of the order of the day); the lines after it are
    // its text, list items marked "• ". Lines before the first point are an introduction without title.
    private function desarrollo(array $lines, array $orden): array
    {
        $titles = array_map(fn ($o) => ActaFormat::key($o), $orden);
        $items = [];
        $current = null;
        foreach ($lines as $line) {
            $title = null;
            if (! $line['numbered'] && preg_match('/^\s*\d{1,2}\s*[.)\-]\s+(.+)$/u', $line['text'], $m)) {
                $title = trim($m[1]);
            } elseif ($titles !== [] && in_array(ActaFormat::key($line['text']), $titles, true)) {
                $title = $line['text'];
            }
            if ($title !== null) {
                $items[] = ['titulo' => $title, 'texto' => ''];
                $current = count($items) - 1;

                continue;
            }
            if ($current === null) {
                $items[] = ['titulo' => '', 'texto' => ''];
                $current = 0;
            }
            $text = $line['numbered'] ? '• '.$line['text'] : $line['text'];
            $items[$current]['texto'] = ltrim($items[$current]['texto']."\n".$text);
        }

        return $items;
    }

    private function participantes(array $rows): array
    {
        [$columns, $rows] = $this->columns($rows, [
            'nombre' => ['NOMBRE'], 'perfil' => ['PERFIL', 'CARGO'], 'municipio' => ['MUNICIPIO'],
            'telefono' => ['TELEFONO', 'CELULAR', 'CONTACTO'], 'cedula' => ['CEDULA', 'DOCUMENTO'],
        ], ['nombre', 'perfil', 'municipio', 'telefono']);

        $out = [];
        foreach ($rows as $row) {
            $value = fn (string $field, string $glue = ' ') => isset($columns[$field])
                ? (trim(implode($glue, array_filter(array_map('trim', $row[$columns[$field]] ?? []), 'strlen'))) ?: null) : null;
            $nombre = $value('nombre');
            if ($nombre === null) {
                continue;
            }
            $out[] = [
                'nombre' => mb_substr(preg_replace('/\s+/u', ' ', $nombre), 0, 200),
                'perfil' => self::cut($value('perfil'), 200),
                'municipio' => self::cut($value('municipio'), 120),
                'telefono' => self::cut($value('telefono', ' / '), 60),
                'cedula' => self::cut($value('cedula'), 20),
            ];
        }

        return $out;
    }

    private function compromisos(array $rows): array
    {
        [$columns, $rows] = $this->columns($rows, [
            'descripcion' => ['NOMBRE', 'COMPROMISO', 'DESCRIPCION', 'ACTIVIDAD'], 'responsable' => ['RESPONSABLE'],
            'telefono' => ['TELEFONO', 'CELULAR', 'CONTACTO'], 'fecha' => ['FECHA'],
        ], ['descripcion', 'responsable', 'telefono']);

        $out = [];
        foreach ($rows as $row) {
            $descripcion = trim(implode("\n", array_filter(array_map('trim', $row[$columns['descripcion'] ?? 0] ?? []), 'strlen')));
            if ($descripcion === '') {
                continue;
            }
            $fecha = isset($columns['fecha']) ? ActaFormat::parseDate(implode(' ', $row[$columns['fecha']] ?? [])) : null;
            if (preg_match('/\s*\(?\s*fecha\s+l[ií]mite\s*:?\s*(\d{1,2}\/\d{1,2}\/\d{4})\s*\)?\s*$/iu', $descripcion, $m)) {
                $fecha ??= ActaFormat::parseDate($m[1]);
                $descripcion = trim(mb_substr($descripcion, 0, mb_strlen($descripcion) - mb_strlen($m[0])));
            }
            $out[] = [
                'descripcion' => mb_substr(preg_replace('/[ \t]+/u', ' ', $descripcion), 0, 5000),
                'fecha_limite' => $fecha,
                'responsables' => $this->responsables(
                    isset($columns['responsable']) ? ($row[$columns['responsable']] ?? []) : [],
                    isset($columns['telefono']) ? ($row[$columns['telefono']] ?? []) : [],
                ),
            ];
        }

        return $out;
    }

    // One responsable per line of the cell, with the phone of the same line of the phone cell when both cells have
    // the same lines; otherwise in order. Names split over two lines ("ALEJANDRA" / "NAVAS") are joined when that
    // makes names and phones match.
    public function responsables(array $names, array $phones): array
    {
        $names = array_map(fn ($l) => trim(preg_replace('/\s+/u', ' ', $l)), $names);
        $phones = array_map('trim', $phones);
        $isPhone = fn ($l) => AttendeeMatcher::phones($l) !== [];

        if (count($names) === count($phones) && count($names) > 0) {
            $aligned = true;
            foreach ($phones as $i => $p) {
                if ($p !== '' && $names[$i] === '') {
                    $aligned = false;
                }
            }
            if ($aligned) {
                $out = [];
                foreach ($names as $i => $n) {
                    if ($n !== '') {
                        $out[] = ['nombre' => mb_substr($n, 0, 200), 'telefono' => $phones[$i] !== '' ? mb_substr($phones[$i], 0, 60) : null];
                    }
                }

                return $out;
            }
        }

        $lines = array_values(array_filter($names, 'strlen'));
        $phoneLines = array_values(array_filter($phones, fn ($p) => $p !== '' && $isPhone($p)));
        $groups = [];
        $current = [];
        foreach ([...$names, ''] as $n) {
            if ($n === '') {
                if ($current) {
                    $groups[] = implode(' ', $current);
                }
                $current = [];
            } else {
                $current[] = $n;
            }
        }
        $chosen = ($phoneLines !== [] && count($lines) !== count($phoneLines) && count($groups) === count($phoneLines)) ? $groups : $lines;

        $out = [];
        foreach ($chosen as $i => $n) {
            $out[] = ['nombre' => mb_substr($n, 0, 200), 'telefono' => isset($phoneLines[$i]) ? mb_substr($phoneLines[$i], 0, 60) : null];
        }
        if ($out !== [] && count($phoneLines) > count($out)) {
            $last = count($out) - 1;
            $extra = implode(' / ', array_slice($phoneLines, count($out)));
            $out[$last]['telefono'] = mb_substr(trim(($out[$last]['telefono'] ?? '').' / '.$extra, ' /'), 0, 60);
        }

        return $out;
    }

    // [field => column index] from the header row (the first row with a cell "NOMBRE…"), and the rows after it.
    private function columns(array $rows, array $aliases, array $default): array
    {
        foreach ($rows as $index => $row) {
            $keys = array_map(fn ($cell) => ActaFormat::key(implode(' ', $cell)), $row);
            $columns = [];
            foreach ($aliases as $field => $words) {
                foreach ($keys as $i => $key) {
                    if (! in_array($i, $columns, true) && array_filter($words, fn ($w) => str_contains($key, $w))) {
                        $columns[$field] = $i;
                        break;
                    }
                }
            }
            if (isset($columns[$default[0]]) && count($columns) >= 2) {
                return [$columns, array_slice($rows, $index + 1)];
            }
        }

        return [array_flip($default), $rows];
    }

    // [[cell lines…]…]: each paragraph of a cell is a line (empty ones kept), a line break inside a paragraph too.
    private function table(DOMElement $table): array
    {
        $rows = [];
        foreach ($this->xpath->query('w:tr', $table) as $tr) {
            $cells = [];
            foreach ($this->xpath->query('w:tc', $tr) as $tc) {
                $lines = [];
                foreach ($this->xpath->query('.//w:p', $tc) as $p) {
                    array_push($lines, ...$this->lines($p));
                }
                $cells[] = $lines;
            }
            $rows[] = $cells;
        }

        return $rows;
    }

    private function lines(DOMElement $paragraph): array
    {
        $lines = [''];
        foreach ($this->xpath->query('.//w:t|.//w:br|.//w:tab|.//w:cr', $paragraph) as $run) {
            match ($run->localName) {
                't' => $lines[count($lines) - 1] .= $run->textContent,
                'tab' => $lines[count($lines) - 1] .= ' ',
                default => $lines[] = '',
            };
        }

        return array_map(fn ($l) => trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $l)), $lines);
    }

    private function numbered(DOMElement $paragraph): bool
    {
        return $this->xpath->query('w:pPr/w:numPr', $paragraph)->length > 0;
    }

    private static function cut(?string $value, int $max): ?string
    {
        return $value === null ? null : mb_substr(preg_replace('/\s+/u', ' ', $value), 0, $max);
    }
}
