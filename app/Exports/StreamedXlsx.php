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

    // $rows: any iterable of arrays (a lazy query keeps memory flat). $notice: a first line above the headings.
    public static function download(iterable $rows, array $columns, string $title, string $filename, array $labels = [], ?string $notice = null): StreamedResponse
    {
        $labels = array_merge(self::LABELS, $labels);

        return new StreamedResponse(function () use ($rows, $columns, $title, $labels, $notice) {
            $options = new Options;
            $obsIndex = array_search('observacion', $columns, true);
            if ($obsIndex !== false) {
                $options->setColumnWidth(60, $obsIndex + 1);
            }
            $writer = new Writer($options);
            $writer->openToFile('php://output');
            $writer->getCurrentSheet()->setName(mb_substr(str_replace(['\\', '/', '?', '*', ':', '[', ']'], ' ', $title), 0, 31));

            if ($notice !== null) {
                $writer->addRow(Row::fromValues([$notice], (new Style)->setFontBold()->setFontColor(Color::RED)));
            }
            $header = (new Style)->setFontBold()->setFontSize(12)->setFontColor(Color::WHITE)->setBackgroundColor('003B71');
            $writer->addRow(Row::fromValues(array_map(fn ($c) => $labels[$c] ?? mb_strtoupper($c), $columns), $header));

            $columnStyles = $obsIndex !== false ? [$obsIndex => (new Style)->setShouldWrapText()] : [];
            foreach ($rows as $row) {
                $writer->addRow(Row::fromValuesWithStyles(self::cells((array) $row, $columns), null, $columnStyles));
            }
            $writer->close();
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $filename).'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    // Documents and phones stay text (leading zeros); a value starting with = + - @ is not run as a formula.
    public static function cells(array $row, array $columns): array
    {
        $out = [];
        foreach ($columns as $col) {
            $val = $row[$col] ?? '—';
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
            $out[] = $val == 0 && $col === 'votos' ? '—' : $val;
        }

        return $out;
    }
}
