<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuditActivity
{
    private const ENTITY_MAP = [
        'lider' => 'lider',
        'persona' => 'persona',
        'votantes' => 'votante',
        'users' => 'usuario',
        'usuarios' => 'usuario',
        'partidos' => 'partido',
        'territorio' => 'territorio',
        'catalogos' => 'catalogo',
        'elecciones' => 'eleccion',
        'nexo' => 'nexo',
        'referido' => 'referido',
        'contactos' => 'contacto',
        'whatsapp' => 'whatsapp',
        'cargos' => 'cargo',
        'seguimientos' => 'seguimiento',
    ];

    private const MASKED_PHONE_KEYS = ['phone', 'whatsapp_phone'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
                return $response;
            }

            $user = $request->user();
            if (! $user) {
                return $response;
            }

            if (! $response->isSuccessful() && ! $response->isRedirection()) {
                return $response;
            }

            $routeName = $request->route()?->getName();
            $action = $request->method().' '.($routeName ?: $request->path());

            $entityType = $this->resolveEntityType($routeName, $request->path());
            $entityId = $this->resolveEntityId($request);

            AuditLog::create([
                'user_id' => $user->id,
                'action' => Str::limit($action, 80, ''),
                'entity_type' => Str::limit($entityType, 80, ''),
                'entity_id' => $entityId,
                'metadata' => $this->metadata($request),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'correlation_id' => $this->correlationId($request),
            ]);
        } catch (\Throwable) {
            // Never interrupt the response
        }

        return $response;
    }

    private function metadata(Request $request): array
    {
        $data = $request->except([
            'password', 'password_confirmation', '_token', '_method',
            'current_password', 'new_password', 'new_password_confirmation',
        ]);

        // WhatsApp linking: the one-time code is a credential and is never stored.
        if ($request->is('perfil/whatsapp*', 'api/agent/*/link')) {
            unset($data['code']);
        }

        foreach (self::MASKED_PHONE_KEYS as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $digits = is_scalar($data[$key]) ? preg_replace('/\D/', '', (string) $data[$key]) : '';
            unset($data[$key]);
            $data[$key.'_last4'] = $digits === '' ? null : substr($digits, -4);
        }

        return $data;
    }

    // Only a well-formed UUID is stored, so callers cannot write arbitrary text into the audit trail.
    private function correlationId(Request $request): ?string
    {
        $value = $request->header('X-Correlation-Id');

        return is_string($value) && Str::isUuid($value) ? strtolower($value) : null;
    }

    private function resolveEntityType(?string $routeName, string $path): string
    {
        if ($routeName) {
            $segments = explode('.', $routeName);
            foreach (array_reverse($segments) as $segment) {
                if (isset(self::ENTITY_MAP[$segment])) {
                    return self::ENTITY_MAP[$segment];
                }
            }
            // Second pass: check if any segment contains a map key
            foreach ($segments as $segment) {
                foreach (self::ENTITY_MAP as $key => $value) {
                    if (str_contains($segment, $key)) {
                        return $value;
                    }
                }
            }
        }

        // Fallback: extract from URI path segments
        $parts = array_filter(explode('/', $path));
        foreach ($parts as $part) {
            if (isset(self::ENTITY_MAP[$part])) {
                return self::ENTITY_MAP[$part];
            }
        }

        return 'system';
    }

    private function resolveEntityId(Request $request): ?string
    {
        $params = $request->route()?->parameters() ?? [];

        foreach ($params as $value) {
            if (is_string($value) && Str::isUuid($value)) {
                return $value;
            }
        }

        return null;
    }
}
