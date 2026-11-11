<?php

namespace App\Services\Eventos\Actas;

use RuntimeException;
use ZipArchive;

// Writes an acta as a Word document (.docx) with the format of the campaign's actas: centered title and date, the
// header lines, the numbered sections and the tables with borders. Plain WordprocessingML with ZipArchive (no extra
// package); ActaDocxReader reads it back.
class ActaDocxWriter
{
    private const FONT = 'Arial';

    // $acta: the shape of EventoActaController::payload(). Returns the path of a temporary file the caller deletes.
    public function build(array $acta): string
    {
        $body = [];
        $body[] = $this->p($acta['titulo'] ?? '', bold: true, center: true, size: 26);
        if (! empty($acta['fecha'])) {
            $body[] = $this->p(ActaFormat::longDate($acta['fecha']), bold: true, center: true);
        }
        $body[] = $this->p('');
        foreach (['Municipio' => $acta['municipio'] ?? null, 'Sitio' => $acta['sitio'] ?? null,
            'Hora' => ActaFormat::hourRange($acta['hora_inicio'] ?? null, $acta['hora_fin'] ?? null)] as $label => $value) {
            if ($value) {
                $body[] = $this->labeled($label, $value);
            }
        }

        $n = 0;
        $body[] = $this->heading(++$n, 'PARTICIPANTES');
        $rows = [['NOMBRE', 'PERFIL', 'MUNICIPIO', 'TELEFONO']];
        foreach ($acta['participantes'] ?? [] as $p) {
            $rows[] = [$p['nombre'] ?? '', $p['perfil'] ?? '', $p['municipio'] ?? '', $p['telefono'] ?? ''];
        }
        $body[] = $this->table($rows, [3400, 3000, 1900, 1700]);

        $body[] = $this->heading(++$n, 'PUNTOS DEL ORDEN DEL DÍA');
        foreach ($this->split($acta['introduccion'] ?? null) as $line) {
            $body[] = $this->p($line);
        }
        foreach (array_values($acta['orden_dia'] ?? []) as $i => $punto) {
            $body[] = $this->p(($i + 1).'. '.$punto, indent: 360);
        }

        $body[] = $this->heading(++$n, 'DESARROLLO DE LA REUNIÓN');
        $numero = 0;
        foreach ($acta['desarrollo'] ?? [] as $item) {
            if (trim((string) ($item['titulo'] ?? '')) !== '') {
                $body[] = $this->p((++$numero).'. '.$item['titulo'], bold: true);
            }
            foreach ($this->split($item['texto'] ?? null) as $line) {
                $body[] = $this->p($line, indent: str_starts_with($line, '•') ? 360 : 0, justify: true);
            }
        }

        $body[] = $this->heading(++$n, 'COMPROMISOS');
        $rows = [['NOMBRE', 'RESPONSABLE', 'TELEFONO']];
        foreach ($acta['compromisos'] ?? [] as $c) {
            $descripcion = $c['descripcion'] ?? '';
            if (! empty($c['fecha_limite'])) {
                $descripcion .= ' (Fecha límite: '.ActaFormat::shortDate($c['fecha_limite']).')';
            }
            $responsables = $c['responsables'] ?? [];
            $rows[] = [
                $descripcion,
                array_map(fn ($r) => $r['nombre'] ?? '', $responsables),
                array_map(fn ($r) => $r['telefono'] ?? '', $responsables),
            ];
        }
        $body[] = $this->table($rows, [5200, 2600, 2200]);

        $body[] = $this->p('');
        foreach ($this->split($acta['cierre'] ?? null) as $line) {
            $body[] = $this->p($line);
        }

        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            .implode('', $body)
            .'<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr>'
            .'</w:body></w:document>';

        $path = tempnam(sys_get_temp_dir(), 'acta');
        $zip = new ZipArchive;
        if ($path === false || $zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el documento de Word.');
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'</Relationships>');
        $zip->addFromString('word/document.xml', $document);
        $zip->close();

        return $path;
    }

    private function heading(int $n, string $text): string
    {
        return $this->p('').$this->p("{$n}. {$text}", bold: true);
    }

    private function labeled(string $label, string $value): string
    {
        return '<w:p>'.$this->run("{$label}: ", true).$this->run($value).'</w:p>';
    }

    private function p(string $text, bool $bold = false, bool $center = false, int $size = 22, int $indent = 0, bool $justify = false): string
    {
        $props = '';
        if ($center || $justify) {
            $props .= '<w:jc w:val="'.($center ? 'center' : 'both').'"/>';
        }
        if ($indent > 0) {
            $props .= '<w:ind w:left="'.$indent.'"/>';
        }

        return '<w:p>'.($props ? "<w:pPr>{$props}</w:pPr>" : '').($text !== '' ? $this->run($text, $bold, $size) : '').'</w:p>';
    }

    private function run(string $text, bool $bold = false, int $size = 22): string
    {
        $props = '<w:rFonts w:ascii="'.self::FONT.'" w:hAnsi="'.self::FONT.'" w:cs="'.self::FONT.'"/>'
            .($bold ? '<w:b/>' : '').'<w:sz w:val="'.$size.'"/>';

        return '<w:r><w:rPr>'.$props.'</w:rPr><w:t xml:space="preserve">'.self::escape($text).'</w:t></w:r>';
    }

    // $rows[0] is the header (bold, shaded). A cell is a string or a list of lines (one paragraph each, so the
    // responsables and their phones stay on the same lines).
    private function table(array $rows, array $widths): string
    {
        $border = fn ($side) => "<w:{$side} w:val=\"single\" w:sz=\"6\" w:space=\"0\" w:color=\"000000\"/>";
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="'.array_sum($widths).'" w:type="dxa"/><w:tblBorders>'
            .implode('', array_map($border, ['top', 'left', 'bottom', 'right', 'insideH', 'insideV']))
            .'</w:tblBorders><w:tblLayout w:type="fixed"/></w:tblPr><w:tblGrid>'
            .implode('', array_map(fn ($w) => '<w:gridCol w:w="'.$w.'"/>', $widths)).'</w:tblGrid>';
        foreach ($rows as $r => $row) {
            $xml .= '<w:tr>';
            foreach ($row as $c => $cell) {
                $shade = $r === 0 ? '<w:shd w:val="clear" w:color="auto" w:fill="D9E2F3"/>' : '';
                $xml .= '<w:tc><w:tcPr><w:tcW w:w="'.$widths[$c].'" w:type="dxa"/>'.$shade.'</w:tcPr>';
                $lines = is_array($cell) ? ($cell === [] ? [''] : $cell) : $this->split($cell, keepEmpty: true);
                foreach ($lines as $line) {
                    $xml .= '<w:p>'.((string) $line !== '' ? $this->run((string) $line, $r === 0, 20) : '').'</w:p>';
                }
                $xml .= '</w:tc>';
            }
            $xml .= '</w:tr>';
        }

        return $xml.'</w:tbl>';
    }

    private function split(?string $text, bool $keepEmpty = false): array
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) $text);
        $lines = $keepEmpty ? $lines : array_values(array_filter(array_map('trim', $lines), 'strlen'));

        return $lines === [] ? [] : $lines;
    }

    private static function escape(string $text): string
    {
        // Control characters are not allowed in XML 1.0 and would make Word refuse the file.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text);

        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
