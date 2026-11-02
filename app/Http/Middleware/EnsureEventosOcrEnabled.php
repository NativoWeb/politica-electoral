<?php

namespace App\Http\Middleware;

use App\Services\Eventos\Ocr\OcrSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Global switch of the AI reading of printed sheets (EVENTOS_OCR_ENABLED): while it is off its routes do not exist
// (404), before any permission or campaign check.
class EnsureEventosOcrEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(OcrSettings::enabled(), 404);

        return $next($request);
    }
}
