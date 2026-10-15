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
    ];

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
                'metadata' => $request->except([
                    'password', 'password_confirmation', '_token', '_method',
                    'current_password', 'new_password', 'new_password_confirmation',
                ]),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        } catch (\Throwable) {
            // Never interrupt the response
        }

        return $response;
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
