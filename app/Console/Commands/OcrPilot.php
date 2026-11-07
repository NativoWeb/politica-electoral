<?php

namespace App\Console\Commands;

use App\Services\Eventos\Ocr\OcrException;
use App\Services\Eventos\Ocr\OcrPilot as Pilot;
use App\Services\Eventos\Ocr\OcrSettings;
use Illuminate\Console\Command;
use RuntimeException;

// Pilot to choose the model of the AI reading (docs/eventos-api.md §13): reads a folder of sheet photos and compares
// with the truth typed by a person. Does not need EVENTOS_OCR_ENABLED nor touches the events tables, but it DOES send
// the images to Anthropic: with real sheets, only after the DPA/ZDR is signed.
class OcrPilot extends Command
{
    protected $signature = 'eventos:ocr-piloto
        {carpeta : Carpeta con las fotos (jpg, png, webp) o PDF de las planillas}
        {verdad : CSV con lo que dice cada fila (archivo, pagina, fila, nombres, apellidos, cedula, celular, fijo, fecha_nacimiento, direccion, barrio, firma)}
        {--model= : Modelo a probar (por defecto EVENTOS_OCR_MODEL)}
        {--json= : Guarda el informe completo en este archivo JSON}
        {--force : No pregunta antes de enviar las imágenes al proveedor}';

    protected $description = 'Pilot of the AI reading of printed sign-in sheets: accuracy per field, rows, tokens and cost per page';

    public function handle(Pilot $pilot): int
    {
        $model = (string) ($this->option('model') ?: OcrSettings::model());
        if (! OcrSettings::hasKey()) {
            $this->error(OcrSettings::MISSING_KEY);

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm("Las imágenes de la carpeta se envían a Anthropic (EE. UU.) para leerlas con {$model}. ¿Continuar?")) {
            $this->line('Cancelado.');

            return self::FAILURE;
        }

        try {
            $report = $pilot->run($this->argument('carpeta'), $this->argument('verdad'), $model, fn (string $page) => $this->line("Leyendo {$page}…"));
        } catch (RuntimeException|OcrException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Modelo {$report['modelo']} · umbral de confianza {$report['umbral']}");
        $this->table(['Página', 'Filas', 'QR', 'Modelo', 'Entrada', 'Salida', 'Caché (esc/lec)', 'USD', 'Seg.', 'Error'], array_map(fn ($p) => [
            "{$p['archivo']} p.{$p['pagina']}", $p['filas'], $p['qr'], $p['modelo'] ?? '—', $p['input_tokens'], $p['output_tokens'],
            "{$p['cache_write_tokens']}/{$p['cache_read_tokens']}", number_format($p['costo_usd'], 4), $p['segundos'], $p['error'] ?? '',
        ], $report['paginas']));

        $f = $report['filas'];
        $this->line("Filas: {$f['en_verdad']} en la verdad · {$f['detectadas']} detectadas · {$f['omitidas']} omitidas · {$f['sobrantes']} sobrantes (leídas sin estar en la verdad).");
        $this->table(['Campo', 'Precisión', 'Aciertos', 'Comparadas', 'Errores con confianza alta (no se resaltarían)'], array_map(
            fn ($field, $c) => [$field, $c['precision'] === null ? '—' : number_format($c['precision'] * 100, 1).' %', $c['aciertos'], $c['total'], $c['errores_sin_marcar']],
            array_keys($report['campos']), $report['campos'],
        ));
        $t = $report['totales'];
        $this->line("Total: {$t['paginas']} páginas ({$t['paginas_con_error']} con error) · {$t['input_tokens']} tokens de entrada · {$t['output_tokens']} de salida · "
            ."USD {$t['costo_usd']} · USD ".($t['costo_por_pagina_usd'] ?? '—').' por página · '.($t['segundos_por_pagina'] ?? '—').' s por página.');
        $this->line('Costo estimado con la tabla de precios de config/eventos.php (no es la factura).');

        if ($path = $this->option('json')) {
            file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->info("Informe guardado en {$path}.");
        }

        return self::SUCCESS;
    }
}
