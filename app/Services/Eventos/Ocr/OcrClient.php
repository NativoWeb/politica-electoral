<?php

namespace App\Services\Eventos\Ocr;

// Reads one page of a printed sign-in sheet (docs/eventos-api.md §13). AnthropicOcrClient calls the Claude API; tests
// bind a fake (they never call the real API). $content is the file sent (a photo already prepared by PageImage, or a
// PDF), $page the page of a PDF to read (1 for a photo).
interface OcrClient
{
    /** @throws OcrException with a message for the user (no personal data) */
    public function read(string $content, string $mime, int $page, string $model): OcrResult;
}
