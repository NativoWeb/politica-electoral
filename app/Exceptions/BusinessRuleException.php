<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

// A business rule the user can fix (duplicate cedula, missing record...). Its message is safe to show.
class BusinessRuleException extends RuntimeException
{
    public function __construct(string $message, private readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function render(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $this->getMessage()], $this->status);
    }
}
