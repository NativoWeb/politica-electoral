<?php

namespace App\Services\Reportes;

use Illuminate\Http\JsonResponse;
use RuntimeException;

// Every refusal of the reports API answers {success: false, message} with its status (403, 422, 429), as the
// frontend contract says (docs/reportes.md). Page visits (the old /exportar/* links) use abort() instead: see
// self::lanzar().
class ReporteException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly array $errors = [],
        public readonly array $headers = [],
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public static function invalido(string $message, array $errors = []): self
    {
        return new self($message, 422, $errors);
    }

    public static function prohibido(string $message = 'No tienes permiso para ver estos datos.'): self
    {
        return new self($message, 403);
    }

    // JSON for the API (and any POST); the Spanish error page for a browser download link.
    public static function lanzar(int $status, string $message, array $headers = [], array $extra = []): never
    {
        $request = request();
        if ($request->expectsJson() || ! $request->isMethod('GET')) {
            throw new self($message, $status, [], $headers, $extra);
        }

        abort($status, $message, $headers);
    }

    // An expected refusal, not an error: never written to the log.
    public function report(): void {}

    public function render(): JsonResponse
    {
        return response()->json(array_filter([
            'success' => false,
            'message' => $this->getMessage(),
            ...$this->extra,
            'errors' => $this->errors ?: null,
        ], fn ($v) => $v !== null), $this->status, $this->headers);
    }
}
