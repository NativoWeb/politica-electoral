<?php

namespace App\Services\Eventos\Ocr;

// What the AI read on one page ($data follows PlanillaPrompt::schema()) and what it cost. $model is the model that
// answered (a server-side fallback may answer instead of the requested one).
class OcrResult
{
    public function __construct(
        public readonly array $data,
        public readonly string $model,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $cacheWriteTokens = 0,
        public readonly int $cacheReadTokens = 0,
    ) {}

    public function cost(): float
    {
        return OcrSettings::cost($this->model, $this->inputTokens, $this->outputTokens, $this->cacheWriteTokens, $this->cacheReadTokens);
    }
}
