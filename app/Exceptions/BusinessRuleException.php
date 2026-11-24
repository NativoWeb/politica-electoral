<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

// A business rule the user can fix (duplicate cedula, missing record...). Its message is safe to show.
// $extra goes into the JSON next to the message (e.g. the record a duplicate cédula belongs to).
class BusinessRuleException extends RuntimeException
{
    public function __construct(string $message, private readonly int $status = 422, private readonly array $extra = [])
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function render(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $this->getMessage()] + $this->extra, $this->status);
    }
}
