<?php

namespace App\Exports;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

// An .xlsx written row by row to the response (openspout): memory stays flat with tens of thousands of rows, where
// PhpSpreadsheet keeps every cell in memory. Same headings, text columns and formula guard as GenericExport.
class StreamedXlsx
{
    private const LABELS = [
        'nombre' => 'NOMBRE', 'municipio' => 'MUNICIPIO', 'tipo' => 'TIPO', 'cargo' => 'CARGO', 'partido' => 'PARTIDO',
        'telefono' => 'TELÉFONO', 'votos' => 'VOTOS', 'email' => 'EMAIL', 'provincia' => 'PROVINCIA',
        'observacion' => 'OBSERVACIONES', 'cedula' => 'CÉDULA',
    ];

    // $rows: any iterable of arrays (a lazy query keeps memory flat). $notice: a red line above the headings.
    // $heading: lines above everything else, the first one as the title (Reportes: title, filters, who and when).
    // $widths: column => width in characters. $empty: what a missing value shows ('—' in the old exports, null = blank).
    public static function download(iterable $rows, array $columns, string $title, string $filename, array $labels = [], ?string $notice = null,
        array $heading = [], array $headers = [], array $widths = [], ?string $empty = '—'): StreamedResponse
    {
        $labels = array_merge(self::LABELS, $labels);

        return new StreamedResponse(function () use ($rows, $columns, $title, $labels, $notice, $heading, $widths, $empty) {
            $options = new Options;
            foreach ($columns as $i => $col) {
                if (isset($widths[$col])) {
                    $options->setColumnWidth((float) $widths[$col], $i + 1);
                }
            }
            $obsIndex = array_search('observacion', $columns, true);
            if ($obsIndex !== false) {
                $options->setColumnWidth(60, $obsIndex + 1);
            }
            $writer = new Writer($options);
            $writer->openToFile('php://output');
            $writer->getCurrentSheet()->setName(mb_substr(str_replace(['\\', '/', '?', '*', ':', '[', ']'], ' ', $title), 0, 31));

            foreach (array_values($heading) as $i => $line) {
                $style = $i === 0 ? (new Style)->setFontBold()->setFontSize(14)->setFontColor('003B71') : (new Style)->setFontColor('4A5568');
                $writer->addRow(Row::fromValues(self::cells(['linea' => $line], ['linea']), $style));
            }
            if ($notice !== null) {
                $writer->addRow(Row::fromValues([$notice], (new Style)->setFontBold()->setFontColor(Color::RED)));
            }
            $header = (new Style)->setFontBold()->setFontSize(12)->setFontColor(Color::WHITE)->setBackgroundColor('003B71');
            $writer->addRow(Row::fromValues(array_map(fn ($c) => $labels[$c] ?? mb_strtoupper($c), $columns), $header));

            $columnStyles = $obsIndex !== false ? [$obsIndex => (new Style)->setShouldWrapText()] : [];
            foreach ($rows as $row) {
                $writer->addRow(Row::fromValuesWithStyles(self::cells((array) $row, $columns, $empty), null, $columnStyles));
            }
            $writer->close();
        }, 200, array_merge($headers, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $filename).'"',
            'Cache-Control' => 'no-store',
        ]));
    }

    // Documents and phones stay text (leading zeros); a value starting with = + - @ is not run as a formula.
    public static function cells(array $row, array $columns, ?string $empty = '—'): array
    {
        $out = [];
        foreach ($columns as $col) {
            $val = $row[$col] ?? $empty;
            if (is_bool($val)) {
                $val = $val ? 'Sí' : 'No';
            } elseif ($val !== null && ! is_int($val) && ! is_float($val)) {
                $val = (string) $val;
            }
            if (in_array($col, GenericExport::TEXT_COLUMNS, true) && $val !== null) {
                $val = (string) $val;
            }
            if (is_string($val) && $val !== '' && in_array($val[0], ['=', '+', '-', '@'], true)) {
                $val = "'".$val;
            }
            $out[] = $empty === '—' && $val == 0 && $col === 'votos' ? '—' : $val;
        }

        return $out;
    }
}
