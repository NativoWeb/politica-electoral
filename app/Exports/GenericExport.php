<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class GenericExport implements FromArray, WithHeadings, WithTitle, WithStyles
{
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

    public function __construct(array $data, array $columns, string $title)
    {
        $this->data = $data;
        $this->columns = $columns;
        $this->title = $title;
    }

    public function array(): array
    {
        return array_map(function ($row) {
            $out = [];
            foreach ($this->columns as $col) {
                $val = $row[$col] ?? '—';
                $out[] = $val == 0 && $col === 'votos' ? '—' : $val;
            }
            return $out;
        }, $this->data);
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
