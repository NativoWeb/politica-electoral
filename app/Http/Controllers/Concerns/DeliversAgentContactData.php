<?php

namespace App\Http\Controllers\Concerns;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

// Daily contact quota and audit of the WhatsApp agent's read endpoints. The controller provides $this->quota.
trait DeliversAgentContactData
{
    abstract protected function auditEntityType(): string;

    // 429 (and an audit row of the refusal) when this delivery would pass the user's daily contact quota.
    private function overQuota(Request $request, string $action, array $filters, array $counts): ?JsonResponse
    {
        if ($this->quota->consume($request->user()->id, $counts['con_datos'])) {
            return null;
        }

        $this->audit($request, $action, $filters, $counts + ['bloqueado' => 'limite_diario']);

        return response()->json([
            'success' => false,
            'message' => 'Alcanzaste el límite diario de datos de contacto.',
            'restantes' => $this->quota->remaining($request->user()->id),
        ], 429);
    }

    // The quota was already reserved: if the audit row cannot be written, nothing is delivered and the quota is given back.
    private function deliver(Request $request, string $action, array $filters, array $counts): void
    {
        try {
            $this->audit($request, $action, $filters, $counts);
        } catch (Throwable $e) {
            $this->quota->release($request->user()->id, $counts['con_datos']);

            throw $e;
        }
    }

    // Counts and the (masked, see config/audit.php) filters only: never the personal data delivered.
    private function audit(Request $request, string $action, array $filters, array $counts): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => $this->auditEntityType(),
            'entity_id' => null,
            'metadata' => ['filtros' => $filters, ...$counts, 'ruta' => $request->route()?->getName()],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'correlation_id' => $this->correlationId($request),
        ]);
    }

    // Same rule as AuditActivity: only a well-formed UUID is stored.
    private function correlationId(Request $request): ?string
    {
        $value = $request->header('X-Correlation-Id');

        return is_string($value) && Str::isUuid($value) ? strtolower($value) : null;
    }
}
