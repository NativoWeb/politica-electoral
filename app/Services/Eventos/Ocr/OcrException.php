<?php

namespace App\Services\Eventos\Ocr;

use RuntimeException;

// A page could not be read. The message is shown to the operator as it is: never includes personal data nor what the
// API answered. $retryable = trying the same page again later may work (timeout, rate limit, provider down).
// $billed = the tokens of a call that was answered (and charged) but could not be used (cut, refused, bad JSON).
class OcrException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        ?\Throwable $previous = null,
        public readonly ?OcrResult $billed = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
