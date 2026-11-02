<?php

namespace App\Services\Eventos\Ocr;

use App\Services\Eventos\ImportRowValidator;
use Illuminate\Support\Str;
use RuntimeException;

// Pilot of the AI reading (eventos:ocr-piloto): reads every photo/PDF of a folder with the given model and compares
// what it read with a CSV of the truth typed by a person, to choose the model with real (handwritten) sheets.
// Touches no table: it only calls the OcrClient. Truth columns: archivo, pagina (page of the file, 1 for a photo),
// fila (N.º printed, 1-12), nombres, apellidos, cedula, celular, fijo, fecha_nacimiento, direccion, barrio,
// autorizo (SI/NO), firma (SI/NO).
class OcrPilot
{
    public const FIELDS = ['cedula', 'celular', 'nombres', 'apellidos', 'fecha_nacimiento', 'autorizo', 'firma', 'fijo', 'direccion', 'barrio'];

    private const TRUTH_COLUMNS = ['archivo', 'pagina', 'fila', 'nombres', 'apellidos', 'cedula', 'celular', 'fijo', 'fecha_nacimiento', 'direccion', 'barrio', 'autorizo', 'firma'];

    public function __construct(private OcrClient $client) {}

    // $onPage(string $label) is called before each page. Returns the report (see the command for its use).
    public function run(string $dir, string $truthCsv, string $model, ?callable $onPage = null): array
    {
        $truth = self::readTruth($truthCsv);
        $files = self::files($dir);
        if ($files === []) {
            throw new RuntimeException('La carpeta no tiene fotos (jpg, png, webp) ni PDF.');
        }

        $read = [];
        $pages = [];
        foreach ($files as $path) {
            $content = (string) file_get_contents($path);
            $name = basename($path);
            try {
                $mime = PageImage::mime($path);
            } catch (OcrException $e) {
                $pages[] = ['archivo' => $name, 'pagina' => 1, 'error' => $e->getMessage(), 'segundos' => 0] + self::emptyUsage();

                continue;
            }
            $total = $mime === 'application/pdf' ? min((int) PageImage::pdfPages($content), (int) config('eventos.ocr.max_pages')) : 1;
            for ($page = 1; $page <= $total; $page++) {
                $onPage && $onPage("{$name} p. {$page}");
                $started = microtime(true);
                try {
                    [[$apiContent, $apiMime], $qr] = PageImage::prepare($content, $mime);
                    $result = $this->client->read($apiContent, $apiMime, $page, $model);
                } catch (OcrException $e) {
                    $pages[] = ['archivo' => $name, 'pagina' => $page, 'error' => $e->getMessage(), 'segundos' => round(microtime(true) - $started, 1)] + self::emptyUsage();

                    continue;
                }
                $rows = PlanillaPrompt::rows($result->data);
                foreach ($rows as $row) {
                    $read[self::key($name, $page, $row['numero'])] = $row;
                }
                $pages[] = [
                    'archivo' => $name,
                    'pagina' => $page,
                    'error' => null,
                    'segundos' => round(microtime(true) - $started, 1),
                    'filas' => count($rows),
                    'qr' => $qr !== null ? 'leído' : 'no',
                    'modelo' => $result->model,
                    'input_tokens' => $result->inputTokens,
                    'output_tokens' => $result->outputTokens,
                    'cache_write_tokens' => $result->cacheWriteTokens,
                    'cache_read_tokens' => $result->cacheReadTokens,
                    'costo_usd' => $result->cost(),
                ];
            }
        }

        return ['modelo' => $model, 'umbral' => OcrSettings::threshold(), 'paginas' => $pages] + $this->compare($truth, $read, $pages);
    }

    private function compare(array $truth, array $read, array $pages): array
    {
        $threshold = OcrSettings::threshold();
        $read = array_filter($read, fn ($row, $key) => in_array(explode('|', $key)[0], array_column($pages, 'archivo'), true), ARRAY_FILTER_USE_BOTH);
        $fields = array_fill_keys(self::FIELDS, ['aciertos' => 0, 'total' => 0, 'errores_sin_marcar' => 0]);

        $matched = 0;
        foreach ($truth as $key => $expected) {
            $got = $read[$key] ?? null;
            if ($got === null) {
                continue;
            }
            $matched++;
            foreach (self::FIELDS as $field) {
                $source = match ($field) {
                    'autorizo' => 'autorizo', 'firma' => 'firma_presente', default => $field,
                };
                $ok = self::normalize($field, $expected[$field] ?? null) === self::normalize($field, $got['valores'][$source] ?? null);
                $fields[$field]['total']++;
                if ($ok) {
                    $fields[$field]['aciertos']++;
                } elseif (($got['confianza'][$source] ?? 0) >= $threshold) {
                    // Wrong but with high confidence: the review screen would not highlight it.
                    $fields[$field]['errores_sin_marcar']++;
                }
            }
        }
        foreach ($fields as $field => $f) {
            $fields[$field]['precision'] = $f['total'] ? round($f['aciertos'] / $f['total'], 4) : null;
        }

        $ok = array_filter($pages, fn ($p) => $p['error'] === null);
        $cost = array_sum(array_column($ok, 'costo_usd'));

        return [
            'filas' => [
                'en_verdad' => count($truth),
                'detectadas' => $matched,
                'omitidas' => count(array_diff_key($truth, $read)),
                'sobrantes' => count(array_diff_key($read, $truth)),
            ],
            'campos' => $fields,
            'totales' => [
                'paginas' => count($pages),
                'paginas_con_error' => count($pages) - count($ok),
                'input_tokens' => array_sum(array_column($ok, 'input_tokens')),
                'output_tokens' => array_sum(array_column($ok, 'output_tokens')),
                'costo_usd' => round($cost, 4),
                'costo_por_pagina_usd' => $ok ? round($cost / count($ok), 4) : null,
                'segundos_por_pagina' => $ok ? round(array_sum(array_column($ok, 'segundos')) / count($ok), 1) : null,
            ],
        ];
    }

    // [archivo|pagina|fila => row] of the truth CSV (`,` or `;`, UTF-8 with or without BOM).
    public static function readTruth(string $path): array
    {
        $handle = is_file($path) ? fopen($path, 'r') : false;
        if ($handle === false) {
            throw new RuntimeException('No se encontró el CSV de verdad.');
        }
        $first = (string) fgets($handle);
        $separator = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $header = array_map(fn ($h) => Str::of($h)->replace("\u{FEFF}", '')->trim()->lower()->ascii()->toString(), str_getcsv(trim($first), $separator, '"', ''));
        $missing = array_diff(self::TRUTH_COLUMNS, $header);
        if ($missing !== []) {
            fclose($handle);
            throw new RuntimeException('Al CSV de verdad le faltan columnas: '.implode(', ', $missing).'.');
        }

        $truth = [];
        while (($line = fgetcsv($handle, null, $separator, '"', '')) !== false) {
            if ($line === [null] || count($line) < count($header)) {
                continue;
            }
            $row = array_combine($header, array_slice($line, 0, count($header)));
            $truth[self::key(trim($row['archivo']), (int) $row['pagina'], (int) $row['fila'])] = $row;
        }
        fclose($handle);

        return $truth;
    }

    public static function files(string $dir): array
    {
        $files = [];
        foreach (is_dir($dir) ? scandir($dir) : [] as $name) {
            if (preg_match('/\.(jpe?g|png|webp|pdf)$/i', $name) && is_file($dir.DIRECTORY_SEPARATOR.$name)) {
                $files[] = $dir.DIRECTORY_SEPARATOR.$name;
            }
        }
        sort($files, SORT_NATURAL);

        return $files;
    }

    // Comparable form: digits for numbers, AAAA-MM-DD for the date, SÍ/NO as a boolean, the rest in capitals without
    // accents and with single spaces (handwriting rarely carries accents).
    public static function normalize(string $field, mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        $text = trim((string) $value);

        return match ($field) {
            'cedula', 'celular', 'fijo' => preg_replace('/\D/', '', $text),
            'fecha_nacimiento' => ImportRowValidator::isoDate($text) ?? $text,
            'autorizo', 'firma' => ImportRowValidator::yesNo($text) === true ? '1' : '0',
            default => Str::upper(Str::ascii(preg_replace('/\s+/u', ' ', $text))),
        };
    }

    private static function key(string $file, int $page, int $row): string
    {
        return "{$file}|{$page}|{$row}";
    }

    private static function emptyUsage(): array
    {
        return ['filas' => 0, 'qr' => '—', 'modelo' => null, 'input_tokens' => 0, 'output_tokens' => 0, 'cache_write_tokens' => 0, 'cache_read_tokens' => 0, 'costo_usd' => 0.0];
    }
}
