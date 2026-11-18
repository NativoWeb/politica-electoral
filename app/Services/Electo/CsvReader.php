<?php

namespace App\Services\Electo;

// Streams an Electo export (UTF-8, comma separated, double quotes, CRLF, line breaks INSIDE quoted fields) as
// [column => value] rows. fgetcsv with no length limit reads a quoted field across physical lines; the backslash
// escape is disabled so a value ending in "\" does not swallow the closing quote.
class CsvReader
{
    public static function rows(string $path): \Generator
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \InvalidArgumentException('No se pudo abrir '.basename($path).'.');
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if ($header === false || $header === [null]) {
                return;
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            $header = array_map('trim', $header);
            $width = count($header);
            $line = 1;

            while (($fields = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $line++;
                if ($fields === [null]) {
                    continue;
                }
                if (count($fields) !== $width) {
                    throw new \UnexpectedValueException(basename($path).": el registro {$line} tiene ".count($fields)." columnas y el encabezado {$width}.");
                }
                yield array_combine($header, $fields);
            }
        } finally {
            fclose($handle);
        }
    }

    // The single export of a table in the folder: "<tabla>_<yyyymmddhhmm>.csv".
    public static function find(string $dir, string $table): ?string
    {
        $matches = [];
        foreach (scandir($dir) ?: [] as $file) {
            if (preg_match('/^'.preg_quote($table, '/').'_\d{8,14}\.csv$/i', $file)) {
                $matches[] = $dir.DIRECTORY_SEPARATOR.$file;
            }
        }
        if (count($matches) > 1) {
            throw new \InvalidArgumentException("Hay más de un archivo de {$table} en la carpeta.");
        }

        return $matches[0] ?? null;
    }
}
