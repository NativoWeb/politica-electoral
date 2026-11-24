<?php

namespace App\Services\CampanasElectorales;

use App\Models\ElectoralCampaign;
use DateTimeInterface;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Options as ReaderOptions;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

// The goals template (one row per territory, with the suggestion when the group sees the electoral results and the
// goal columns empty) and its reading: a goal cell left empty changes nothing; one bad cell and nothing is saved.
class PlantillaMetas
{
    public const COLUMNAS = ['CÓDIGO', 'TERRITORIO', 'MUNICIPIO', 'VOTOS SUGERIDOS', 'LÍDERES SUGERIDOS', 'VOTANTES SUGERIDOS',
        'VOTOS META', 'LÍDERES META', 'VOTANTES META'];

    private const CAMPOS = ['VOTOS META' => 'votos_meta', 'LIDERES META' => 'lideres_meta', 'VOTANTES META' => 'votantes_meta'];

    public function descargar(ElectoralCampaign $c, array $detalle): StreamedResponse
    {
        $nombres = array_column($detalle['filas'], 'nombre', 'id');
        $filename = 'metas-'.(Str::slug($c->name) ?: 'campana').'.xlsx';

        return new StreamedResponse(function () use ($detalle, $nombres) {
            $options = new Options;
            foreach ([1 => 14, 2 => 40, 3 => 22, 4 => 16, 5 => 16, 6 => 18, 7 => 14, 8 => 14, 9 => 16] as $col => $width) {
                $options->setColumnWidth($width, $col);
            }
            $writer = new Writer($options);
            $writer->openToFile('php://output');
            $writer->getCurrentSheet()->setName('Metas');
            $header = (new Style)->setFontBold()->setFontColor(Color::WHITE)->setBackgroundColor('003B71');
            $writer->addRow(Row::fromValues(self::COLUMNAS, $header));
            foreach ($detalle['filas'] as $f) {
                $s = $f['sugerencia'] ?? null;
                $writer->addRow(Row::fromValues([
                    (string) $f['codigo'],
                    self::texto($f['nombre']),
                    $f['padre_id'] ? self::texto($nombres[$f['padre_id']] ?? '') : '',
                    $s['votos'] ?? null, $s['lideres'] ?? null, $s['votantes'] ?? null,
                    null, null, null,
                ]));
            }
            $writer->close();
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * The goals of the file as a batch for MetasService, with the Excel row of each item.
     *
     * @return array{items: array<int, array<string, mixed>>, filas: array<int, int>}
     *
     * @throws MetasInvalidas with the row number in each message
     */
    public function leer(string $path, array $filas): array
    {
        $porCodigo = [];
        foreach ($filas as $f) {
            if ($f['codigo'] !== null && $f['codigo'] !== '') {
                $porCodigo[mb_strtoupper(trim((string) $f['codigo']))] = $f['id'];
            }
        }
        $max = (int) config('campanas_electorales.importacion.max_filas');
        $errores = [];
        $items = [];
        $filaDe = [];
        $vistas = [];
        $columnas = null;

        $options = new ReaderOptions;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $reader = new Reader($options);
        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                $numero = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $numero++;
                    $valores = $row->toArray();
                    if ($columnas === null) {
                        $columnas = $this->columnas($valores);
                        if ($columnas === null) {
                            self::fallar('La primera fila debe tener las columnas CÓDIGO y VOTOS META, LÍDERES META o VOTANTES META: usa la plantilla de la campaña.');
                        }

                        continue;
                    }
                    if ($this->vacia($valores)) {
                        continue;
                    }
                    if (count($items) >= $max) {
                        self::fallar('El archivo tiene más de '.TerritorioCampana::miles($max).' filas.');
                    }
                    $codigo = mb_strtoupper(trim(self::celdaTexto($valores[$columnas['codigo']] ?? null)));
                    $unidad = $porCodigo[$codigo] ?? null;
                    if ($codigo === '') {
                        $errores[] = "Fila {$numero}: falta el código del territorio.";

                        continue;
                    }
                    if ($unidad === null) {
                        $errores[] = "Fila {$numero}: el código {$codigo} no es de esta campaña.";

                        continue;
                    }
                    if (isset($vistas[$unidad])) {
                        $errores[] = "Fila {$numero}: el código {$codigo} está repetido (fila {$vistas[$unidad]}).";

                        continue;
                    }
                    $vistas[$unidad] = $numero;
                    $item = ['geographic_unit_id' => $unidad];
                    foreach ($columnas['campos'] as $indice => $campo) {
                        $celda = $valores[$indice] ?? null;
                        [$valor, $error] = self::numero($celda);
                        if ($error !== null) {
                            $errores[] = "Fila {$numero}: {$error}";
                        } elseif ($valor !== null) {
                            $item[$campo] = $valor;
                        }
                    }
                    if (count($item) > 1) {
                        $filaDe[count($items)] = $numero;
                        $items[] = $item;
                    }
                }
                break;
            }
        } catch (MetasInvalidas $e) {
            throw $e;
        } catch (Throwable) {
            self::fallar('No pudimos leer el archivo: sube el .xlsx de la plantilla.');
        } finally {
            $reader->close();
        }

        if ($columnas === null) {
            self::fallar('El archivo está vacío: usa la plantilla de la campaña.');
        }
        if ($errores) {
            throw new MetasInvalidas(array_map(fn ($m) => ['indice' => null, 'campo' => null, 'mensaje' => $m], $errores));
        }

        return ['items' => $items, 'filas' => $filaDe];
    }

    // [valor|null, error|null] of a goal cell: empty is null (no change).
    public static function numero(mixed $celda): array
    {
        $max = (int) config('campanas_electorales.meta_max');
        if ($celda === null || (is_string($celda) && trim($celda) === '')) {
            return [null, null];
        }
        $mostrado = is_float($celda) ? rtrim(rtrim(number_format($celda, 6, ',', ''), '0'), ',') : self::celdaTexto($celda);
        if (is_int($celda) || (is_float($celda) && is_finite($celda) && floor($celda) === $celda)) {
            $n = (int) $celda;
        } elseif (is_string($celda)) {
            $limpio = str_replace([' ', "\u{00A0}"], '', trim($celda));
            if (preg_match('/^\d{1,3}(\.\d{3})+$/', $limpio)) {
                $limpio = str_replace('.', '', $limpio);
            }
            if (! preg_match('/^-?\d+$/', $limpio)) {
                return [null, preg_match('/^-?\d+([.,]\d+)?$/', $limpio)
                    ? "\"{$mostrado}\" no es un número entero."
                    : "\"{$mostrado}\" no es un número."];
            }
            $n = (int) $limpio;
        } elseif (is_float($celda)) {
            return [null, "\"{$mostrado}\" no es un número entero."];
        } else {
            return [null, "\"{$mostrado}\" no es un número."];
        }
        if ($n < 0) {
            return [null, "\"{$mostrado}\" es menor que 0."];
        }
        if ($n > $max) {
            return [null, "\"{$mostrado}\" es más de ".TerritorioCampana::miles($max).'.'];
        }

        return [$n, null];
    }

    // Column indexes of the code and of each goal, found by heading (any order, accents and case ignored).
    private function columnas(array $valores): ?array
    {
        $codigo = null;
        $campos = [];
        foreach ($valores as $i => $v) {
            $h = self::normalizar(self::celdaTexto($v));
            if ($h === 'CODIGO' || $h === 'CODIGO DANE') {
                $codigo = $i;
            } elseif (isset(self::CAMPOS[$h])) {
                $campos[$i] = self::CAMPOS[$h];
            }
        }

        return $codigo === null || $campos === [] ? null : ['codigo' => $codigo, 'campos' => $campos];
    }

    private function vacia(array $valores): bool
    {
        foreach ($valores as $v) {
            if ($v !== null && trim(self::celdaTexto($v)) !== '') {
                return false;
            }
        }

        return true;
    }

    private static function celdaTexto(mixed $v): string
    {
        return match (true) {
            $v === null => '',
            is_float($v) && floor($v) === $v => (string) (int) $v,
            $v instanceof DateTimeInterface => $v->format('Y-m-d'),
            is_bool($v) => $v ? 'VERDADERO' : 'FALSO',
            default => (string) $v,
        };
    }

    private static function normalizar(string $h): string
    {
        $h = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $h)));

        return strtr($h, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
    }

    // Formula guard: a name starting with = + - @ is shown as text.
    private static function texto(string $v): string
    {
        return $v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) ? "'".$v : $v;
    }

    private static function fallar(string $mensaje): never
    {
        throw new MetasInvalidas([['indice' => null, 'campo' => null, 'mensaje' => $mensaje]]);
    }
}
