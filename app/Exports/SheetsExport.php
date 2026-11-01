<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

// One workbook with several GenericExport sheets (each keeps its formula neutralization and styles).
class SheetsExport implements WithMultipleSheets
{
    public function __construct(private array $sheets) {}

    public function sheets(): array
    {
        return $this->sheets;
    }
}
