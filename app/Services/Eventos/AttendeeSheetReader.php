<?php

namespace App\Services\Eventos;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

// Reads the attendee sheet of an .xlsx, .xls or .csv into plain values: numbers stay numbers (so a cédula typed as
// a number can be rebuilt without the ".0" or the exponent) and formulas give their last calculated value.
// Memory: the upload is at most 5 MB, an .xlsx is refused before parsing if it unzips to more than MAX_UNZIPPED_BYTES
// or one entry is compressed more than MAX_RATIO:1 (a zip bomb), and only the cells of the first rows (header search +
// MAX_ROWS) and MAX_COLUMNS columns are kept. PhpSpreadsheet still parses the whole sheet XML, which those two limits
// bound. Any cell found below those rows (even after empty rows) means the file has too many rows.
class AttendeeSheetReader
{
    public const MAX_ROWS = 2000;

    public const MAX_COLUMNS = 30;

    public const MAX_UNZIPPED_BYTES = 50 * 1024 * 1024;

    public const MAX_RATIO = 100;

    // Entries smaller than this are not checked for their ratio (a small, very repetitive XML is normal).
    private const RATIO_MIN_BYTES = 1024 * 1024;

    private const TOO_MANY_ROWS = 'El archivo tiene más de '.self::MAX_ROWS.' filas. Divídalo en varios archivos y cárguelos uno por uno. '
        .'Si no son tantas personas, borre las filas que sobren al final de la hoja.';

    // Rows above the header that may hold a title.
    private const HEADER_SEARCH = 10;

    // ['header_row' => n, 'headers' => [string], 'rows' => [['row_number' => n, 'cells' => [index => value]]]]
    // $sheet: the sheet to read in a workbook with several (one per jornada); null = the default one.
    // Throws RuntimeException with a message for the user.
    public function read(string $path, string $extension, ?string $sheet = null): array
    {
        [$grid, $overflow] = $extension === 'csv' ? $this->readCsv($path) : $this->readSpreadsheet($path, $extension, $sheet);
        if ($overflow) {
            throw new RuntimeException(self::TOO_MANY_ROWS);
        }

        [$headerRow, $headers] = $this->headerRow($grid);
        $rows = [];
        foreach ($grid as $rowNumber => $cells) {
            if ($rowNumber <= $headerRow || $this->isEmpty($cells)) {
                continue;
            }
            $rows[] = ['row_number' => $rowNumber, 'cells' => $cells];
        }
        if ($rows === []) {
            throw new RuntimeException('El archivo no tiene filas con datos debajo de los encabezados.');
        }
        if (count($rows) > self::MAX_ROWS) {
            throw new RuntimeException(self::TOO_MANY_ROWS);
        }

        return ['header_row' => $headerRow, 'headers' => $headers, 'rows' => $rows];
    }

    // [[row number => [column index => value]] (only non-empty rows), overflow].
    // The sheets an attendee list can be in (all but "Instrucciones"), each with the first text of its first rows
    // (the title of the jornada: 'JORNADA "DEL CONGRESO AL BARRIO" VIERNES 23.10.2026…'). [] for a CSV.
    public function sheets(string $path, string $extension): array
    {
        if ($extension === 'csv') {
            return [];
        }
        if ($extension === 'xlsx') {
            $this->assertSafeZip($path);
        }
        $reader = $extension === 'xls' ? new Xls : new Xlsx;
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $reader->setReadFilter(new class(self::HEADER_SEARCH, self::MAX_COLUMNS) implements IReadFilter
        {
            public function __construct(private int $rows, private int $columns) {}

            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                return $row <= $this->rows && Coordinate::columnIndexFromString($columnAddress) <= $this->columns;
            }
        });

        try {
            $spreadsheet = $reader->load($path);
        } catch (\Throwable) {
            throw new RuntimeException('No se pudo leer el archivo. Ábralo en Excel, guárdelo como .xlsx e intente de nuevo.');
        }
        $sheets = [];
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            if (ImportColumns::normalize($sheet->getTitle()) === 'instrucciones') {
                continue;
            }
            $title = null;
            foreach ($this->grid($sheet) as $cells) {
                foreach ($cells as $value) {
                    if (is_string($value) && mb_strlen($value) > 12 && ImportColumns::score([$value]) === 0) {
                        $title = trim(preg_replace('/\s+/u', ' ', $value));
                        break 2;
                    }
                }
            }
            $sheets[] = ['nombre' => $sheet->getTitle(), 'titulo' => $title !== null ? mb_substr($title, 0, 300) : null];
        }
        $spreadsheet->disconnectWorksheets();

        return $sheets;
    }

    private function readSpreadsheet(string $path, string $extension, ?string $sheet = null): array
    {
        if ($extension === 'xlsx') {
            $this->assertSafeZip($path);
        }
        $reader = $extension === 'xls' ? new Xls : new Xlsx;
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $filter = new class(self::HEADER_SEARCH + self::MAX_ROWS + 1, self::MAX_COLUMNS) implements IReadFilter
        {
            public bool $beyond = false;

            public function __construct(private int $rows, private int $columns) {}

            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                if ($row > $this->rows) {
                    $this->beyond = true;

                    return false;
                }

                return Coordinate::columnIndexFromString($columnAddress) <= $this->columns;
            }
        };
        $reader->setReadFilter($filter);

        try {
            $sheetName = $sheet !== null && in_array($sheet, $reader->listWorksheetNames($path), true) ? $sheet : $this->sheetName($reader, $path);
            if ($sheetName !== null) {
                $reader->setLoadSheetsOnly([$sheetName]);
            }
            $spreadsheet = $reader->load($path);
        } catch (\Throwable) {
            throw new RuntimeException('No se pudo leer el archivo. Ábralo en Excel, guárdelo como .xlsx e intente de nuevo.');
        }

        $sheet = $spreadsheet->getSheet(0);
        $grid = $this->grid($sheet);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return [$grid, $filter->beyond];
    }

    // An .xlsx is a zip: refuse it before parsing if it unzips to too much or an entry is compressed suspiciously well.
    private function assertSafeZip(string $path): void
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('No se pudo leer el archivo. Ábralo en Excel, guárdelo como .xlsx e intente de nuevo.');
        }
        $total = 0;
        $suspicious = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $size = (int) ($stat['size'] ?? 0);
            $compressed = max(1, (int) ($stat['comp_size'] ?? 0));
            $total += $size;
            if ($size >= self::RATIO_MIN_BYTES && $size / $compressed > self::MAX_RATIO) {
                $suspicious = true;
            }
        }
        $zip->close();

        if ($suspicious || $total > self::MAX_UNZIPPED_BYTES) {
            throw new RuntimeException('El archivo es demasiado grande por dentro para leerlo. Copie solo las filas de los asistentes '
                .'en un libro nuevo de Excel, guárdelo como .xlsx e intente de nuevo.');
        }
    }

    // The "Asistentes" sheet of the template if it is there, otherwise the first sheet that is not "Instrucciones".
    private function sheetName(Xlsx|Xls $reader, string $path): ?string
    {
        $names = $reader->listWorksheetNames($path);
        foreach ($names as $name) {
            if (ImportColumns::normalize($name) === 'asistentes') {
                return $name;
            }
        }
        foreach ($names as $name) {
            if (ImportColumns::normalize($name) !== 'instrucciones') {
                return $name;
            }
        }

        return $names[0] ?? null;
    }

    private function grid(Worksheet $sheet): array
    {
        $grid = [];
        foreach ($sheet->getCoordinates() as $coordinate) {
            [$column, $row] = Coordinate::coordinateFromString($coordinate);
            $value = $this->cellValue($sheet->getCell($coordinate));
            if ($value === null || $value === '') {
                continue;
            }
            $grid[(int) $row][Coordinate::columnIndexFromString($column) - 1] = $value;
        }
        ksort($grid);

        return $grid;
    }

    private function cellValue(Cell $cell): mixed
    {
        $value = $cell->getValue();
        if ($cell->getDataType() === DataType::TYPE_FORMULA) {
            $value = $cell->getOldCalculatedValue();
        }
        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }
        if (is_bool($value)) {
            return $value ? 'SI' : 'NO';
        }
        if (is_string($value)) {
            $value = trim($value);
        }

        return is_scalar($value) ? $value : null;
    }

    private function readCsv(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('No se pudo leer el archivo.');
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (! mb_check_encoding($content, 'UTF-8')) {
            // Excel in Spanish saves CSV as Windows-1252.
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $firstLine = strtok($content, "\r\n") ?: '';
        $delimiter = collect([';', ',', "\t"])->sortByDesc(fn ($d) => substr_count($firstLine, $d))->first();

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        $grid = [];
        $rowNumber = 0;
        $overflow = false;
        $limitRow = self::HEADER_SEARCH + self::MAX_ROWS + 1;
        while (($line = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $rowNumber++;
            if ($rowNumber > $limitRow) {
                // Keep reading (the file is at most 5 MB) only to see whether there is data after empty lines.
                if (array_filter($line, fn ($v) => $v !== null && trim((string) $v) !== '') !== []) {
                    $overflow = true;
                    break;
                }

                continue;
            }
            $cells = [];
            foreach (array_slice($line, 0, self::MAX_COLUMNS) as $index => $value) {
                $value = is_string($value) ? trim($value) : $value;
                if ($value !== null && $value !== '') {
                    $cells[$index] = $value;
                }
            }
            if ($cells !== []) {
                $grid[$rowNumber] = $cells;
            }
        }
        fclose($handle);

        return [$grid, $overflow];
    }

    // The row (within the first rows) with the most recognised headers; without any, the first non-empty row.
    private function headerRow(array $grid): array
    {
        $best = null;
        $bestScore = 0;
        foreach (array_slice($grid, 0, self::HEADER_SEARCH, true) as $rowNumber => $cells) {
            $score = ImportColumns::score($cells);
            if ($score > $bestScore) {
                [$best, $bestScore] = [$rowNumber, $score];
            }
        }
        $best ??= array_key_first($grid);
        if ($best === null) {
            throw new RuntimeException('El archivo está vacío.');
        }

        $cells = $grid[$best];
        $width = max(array_keys($cells)) + 1;
        foreach ($grid as $rowNumber => $row) {
            if ($rowNumber > $best && $row !== []) {
                $width = max($width, max(array_keys($row)) + 1);
            }
        }
        $headers = [];
        for ($i = 0; $i < min($width, self::MAX_COLUMNS); $i++) {
            $headers[] = isset($cells[$i]) ? (string) $cells[$i] : 'Columna '.Coordinate::stringFromColumnIndex($i + 1);
        }

        return [$best, $headers];
    }

    private function isEmpty(array $cells): bool
    {
        foreach ($cells as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }
}
