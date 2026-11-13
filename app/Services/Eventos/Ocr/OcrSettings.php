<?php

namespace App\Services\Eventos\Ocr;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// The two switches of the AI reading (§13): EVENTOS_OCR_ENABLED (global, off → the routes answer 404) and
// campaigns.ocr_enabled (per campaign, off → 403), plus the key, the model and the price table.
class OcrSettings
{
    public const DISABLED_FOR_CAMPAIGN = 'La lectura de planillas con IA no está activada para este grupo político.';

    public const MISSING_KEY = 'La lectura con IA no está lista: falta configurar la clave del proveedor (ANTHROPIC_API_KEY). Avise al administrador de la plataforma.';

    public static function enabled(): bool
    {
        return (bool) config('eventos.ocr.enabled');
    }

    public static function enabledFor(?string $campaignId): bool
    {
        return self::enabled() && $campaignId !== null
            && (bool) DB::table('campaigns')->where('id', $campaignId)->value('ocr_enabled');
    }

    public static function hasKey(): bool
    {
        return trim((string) config('eventos.ocr.api_key')) !== '';
    }

    public static function model(): string
    {
        return (string) config('eventos.ocr.model');
    }

    public static function threshold(): float
    {
        return (float) config('eventos.ocr.threshold');
    }

    // USD estimate from the price table of config/eventos.php; 0 for a model without prices (the audit says so).
    public static function cost(string $model, int $input, int $output, int $cacheWrite = 0, int $cacheRead = 0): float
    {
        $prices = self::prices($model);
        if ($prices === null) {
            Log::warning('Eventos OCR: el modelo no tiene precio en config/eventos.php; su costo se registra en 0', ['model' => $model]);

            return 0.0;
        }
        [$in, $out, $write, $read] = $prices;

        return round(($input * $in + $output * $out + $cacheWrite * $write + $cacheRead * $read) / 1_000_000, 5);
    }

    // Exact id first, then the longest known id the served model starts with (a dated id of the same model).
    public static function prices(string $model): ?array
    {
        $table = (array) config('eventos.ocr.prices');
        if (isset($table[$model])) {
            return $table[$model];
        }
        $best = null;
        foreach ($table as $id => $prices) {
            if (str_starts_with($model, $id.'-') && ($best === null || strlen($id) > strlen($best))) {
                $best = $id;
            }
        }

        return $best ? $table[$best] : null;
    }
}
