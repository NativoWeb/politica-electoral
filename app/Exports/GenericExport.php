<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class GenericExport extends DefaultValueBinder implements FromArray, WithHeadings, WithTitle, WithStyles, WithCustomValueBinder, WithColumnFormatting
{
    // Documents and phones are text: Excel would drop leading zeros or show long numbers as 1,09877E+09.
    public const TEXT_COLUMNS = ['cedula', 'documento', 'referente_documento', 'celular', 'fijo', 'telefono', 'nit', 'representante_cedula', 'representante_celular'];

    private array $data;
    private array $columns;
    private string $title;

    private array $columnLabels = [
        'nombre' => 'NOMBRE',
        'municipio' => 'MUNICIPIO',
        'tipo' => 'TIPO',
        'cargo' => 'CARGO',
        'partido' => 'PARTIDO',
        'telefono' => 'TELÉFONO',
        'votos' => 'VOTOS',
        'email' => 'EMAIL',
        'provincia' => 'PROVINCIA',
        'observacion' => 'OBSERVACIONES',
    ];

    // $labels: column => heading, for columns outside the default list.
    public function __construct(array $data, array $columns, string $title, array $labels = [])
    {
        $this->data = $data;
        $this->columns = $columns;
        $this->title = $title;
        $this->columnLabels = array_merge($this->columnLabels, $labels);
    }

    public function array(): array
    {
        return array_map(function ($row) {
            $out = [];
            foreach ($this->columns as $col) {
                $val = $row[$col] ?? '—';
                // A cell starting with = + - @ would run as a spreadsheet formula.
                if (is_string($val) && $val !== '' && in_array($val[0], ['=', '+', '-', '@'], true)) {
                    $val = "'".$val;
                }
                $out[] = $val == 0 && $col === 'votos' ? '—' : $val;
            }
            return $out;
        }, $this->data);
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if ($cell->getRow() > 1 && $value !== null && in_array($cell->getColumn(), $this->textColumnLetters(), true)) {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function columnFormats(): array
    {
        return array_fill_keys($this->textColumnLetters(), NumberFormat::FORMAT_TEXT);
    }

    private function textColumnLetters(): array
    {
        $letters = [];
        foreach ($this->columns as $i => $col) {
            if (in_array($col, self::TEXT_COLUMNS, true)) {
                $letters[] = Coordinate::stringFromColumnIndex($i + 1);
            }
        }

        return $letters;
    }

    public function headings(): array
    {
        return array_map(fn ($c) => $this->columnLabels[$c] ?? strtoupper($c), $this->columns);
    }

    public function title(): string
    {
        return substr($this->title, 0, 31);
    }

    public function styles(Worksheet $sheet): array
    {
        $lastCol = chr(64 + count($this->columns));
        $lastRow = count($this->data) + 1;

        // Auto-size columns
        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $obsIndex = array_search('observacion', $this->columns, true);
        if ($obsIndex !== false) {
            $obsCol = chr(65 + $obsIndex);
            $sheet->getColumnDimension($obsCol)->setAutoSize(false)->setWidth(60);
            $sheet->getStyle("{$obsCol}2:{$obsCol}{$lastRow}")->getAlignment()->setWrapText(true)->setVertical('top');
        }

        return [
            1 => [
                'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '003B71']],
            ],
            "A1:{$lastCol}{$lastRow}" => [
                'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'CCCCCC']]],
            ],
        ];
    }
}
