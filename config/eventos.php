<?php

// Eventos especiales. Reading of printed sign-in sheets with AI (phase 5, docs/eventos-api.md §13): built but OFF.
// It works only with EVENTOS_OCR_ENABLED=true, an ANTHROPIC_API_KEY and campaigns.ocr_enabled = true for the
// event's campaign (php artisan eventos:ocr-campana). Each photo is sent to Anthropic (international transfer of
// personal data): do not turn it on without the DPA/ZDR and the provider mentioned in the authorization text.
return [

    'ocr' => [

        'enabled' => (bool) env('EVENTOS_OCR_ENABLED', false),

        'api_key' => env('ANTHROPIC_API_KEY'),

        // Most capable current model for reading handwriting. Cheaper alternative: claude-sonnet-5-5 (half the
        // price per token); decide with the pilot (php artisan eventos:ocr-piloto). Claude Fable is not an option:
        // it requires 30-day data retention, which is incompatible with zero data retention (ZDR).
        'model' => env('EVENTOS_OCR_MODEL', 'claude-opus-5-5'),

        // low | medium | high | xhigh | max. medium is the default of claude-opus-5-5; low is faster and cheaper.
        'effort' => env('EVENTOS_OCR_EFFORT', 'medium'),

        // Cells whose confidence (0-1) is below this are highlighted for the human review. A ticked "Autorizo" or a
        // signature below it is NOT taken as an authorization (the row is not importable).
        'threshold' => (float) env('EVENTOS_OCR_UMBRAL', 0.85),

        // Seconds the request to Anthropic may take for one page (the screen reads one page per request).
        'timeout' => (int) env('EVENTOS_OCR_TIMEOUT', 150),

        'max_tokens' => 16000,

        'max_file_kb' => 10240,

        'max_pages' => 20,

        // Cost guard: pages read per campaign and day (America/Bogota).
        'max_pages_per_day' => (int) env('EVENTOS_OCR_MAX_PAGINAS_DIA', 300),

        // Long edge sent to the API (the high-resolution limit of current models).
        'max_image_px' => 2576,

        // USD per million tokens: input, output, cache write (5 min), cache read. Used for the cost estimate of the
        // audit and the pilot; check https://platform.claude.com/docs/en/about-claude/pricing before deciding.
        'prices' => [
            'claude-opus-5-5' => [4.00, 20.00, 5.00, 0.20],
            'claude-sonnet-5-5' => [2.00, 10.00, 2.50, 0.20],
            'claude-opus-5' => [5.00, 25.00, 6.25, 0.50],
            // Default server-side fallback of claude-opus-5-5 when it declines a page.
            'claude-opus-4-8' => [5.00, 25.00, 6.25, 0.50],
            'claude-sonnet-5' => [2.00, 10.00, 2.50, 0.20],
            'claude-haiku-4-5' => [1.00, 5.00, 1.25, 0.10],
        ],
    ],

];
