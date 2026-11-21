<?php

namespace App\Services\Reportes;

use App\Models\AuditLog;
use App\Models\User;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

// Every file with people in it (Reportes, the old /exportar/* and the Eventos and Empresarios exports) goes through here: one download at a time per
// user, 5 per minute and 30 per hour (limiter reportes-descarga, AppServiceProvider), and an audit row written
// BEFORE the first byte leaves. Without export.datos_personales no personal column reaches the file.
class DescargaGuard
{
    public const EXCEL_MAX_FILAS = 50000;

    public const PDF_MAX_FILAS = 500;

    // The most the ajuste may be set to: DomPDF keeps every cell in memory.
    public const PDF_MAX_FILAS_TOPE = 700;

    public const LIMITADOR = 'reportes-descarga';

    public const POR_MINUTO = 5;

    public const POR_HORA = 30;

    // Longest a download may hold the user's lock (a worker that dies mid-stream frees it by itself).
    public const LOCK_SEGUNDOS = 180;

    public const EN_CURSO = 'Ya estamos preparando una descarga. Espera a que termine.';

    public const SEGUIDAS = 'Pediste varias descargas seguidas. Espera un minuto y vuelve a intentarlo.';

    // Generic on purpose: the same line heads every file that lost columns (the exact ones go in X-Columnas-Quitadas).
    public const AVISO_SIN_DATOS = 'Este archivo no incluye datos personales (cédula, teléfonos, correo, dirección u observaciones) porque no tienes el permiso Descargar datos personales.';

    public const POR_HORA_MENSAJE = 'Llegaste al máximo de descargas por hora. Intenta de nuevo más tarde.';

    // Columns of the old exports (/exportar/*, Eventos, Empresarios) that are personal data: the same rule as the Reportes catalog
    // (sensible), by the names those exports use.
    public const COLUMNAS_SENSIBLES = [
        'cedula', 'documento', 'telefono', 'celular', 'fijo', 'whatsapp', 'email', 'direccion', 'contactos',
        'facebook', 'twitter', 'instagram', 'redes', 'fecha_nacimiento', 'fecha_expedicion', 'fecha_expedicion_doc',
        'referente_documento', 'observacion', 'observaciones', 'representante_cedula', 'representante_celular', 'correo',
    ];

    public static function limites(Request $request): array
    {
        $id = $request->user()?->id ?? $request->ip();

        return [
            Limit::perMinute(self::POR_MINUTO)->by('min|'.$id),
            Limit::perHour(self::POR_HORA)->by('hora|'.$id),
        ];
    }

    // The columns of an old export the user may download, and the ones taken out.
    public static function columnasPermitidas(array $columnas, ?object $user): array
    {
        if ($user instanceof User && ReporteService::puedeDatosPersonales($user)) {
            return [$columnas, []];
        }
        $quitadas = array_values(array_intersect($columnas, self::COLUMNAS_SENSIBLES));

        return [array_values(array_diff($columnas, self::COLUMNAS_SENSIBLES)), $quitadas];
    }

    // $preparar() runs once the lock and the quota are granted and returns ['metadata' => [...], 'generar' => Closure
    // that builds the response]. The lock is released when the file has been sent (or on any failure).
    public function descargar(Request $request, string $accion, Closure $preparar): Response
    {
        $user = $request->user();
        $lock = Cache::lock('reporte-descarga:'.$user->id, self::LOCK_SEGUNDOS);
        if (! $lock->get()) {
            ReporteException::lanzar(429, self::EN_CURSO, [], ['motivo' => 'en_curso']);
        }
        // A fatal error (out of memory while building a PDF) skips the catch below: free the lock when the script ends.
        // Releasing twice is harmless, the lock checks its owner.
        register_shutdown_function(fn () => $lock->release());

        try {
            $this->consumirCupo($request);
            $preparado = $preparar();
            $this->auditar($request, $accion, $preparado['metadata'] ?? []);
            $response = ($preparado['generar'])();
        } catch (Throwable $e) {
            $lock->release();

            throw $e;
        }

        if ($response instanceof StreamedResponse && ($callback = $response->getCallback())) {
            $response->setCallback(function () use ($callback, $lock) {
                // A download cancelled by the browser ends the script without running finally: free the lock then too.
                register_shutdown_function(fn () => $lock->release());
                try {
                    $callback();
                } finally {
                    $lock->release();
                }
            });
        } else {
            $lock->release();
        }

        return $response;
    }

    // 429 without spending anything when any limit is used up; otherwise one hit on each.
    private function consumirCupo(Request $request): void
    {
        $limites = Arr::wrap(RateLimiter::limiter(self::LIMITADOR)($request));
        foreach ($limites as $limite) {
            $key = self::LIMITADOR.'|'.$limite->key;
            if (RateLimiter::tooManyAttempts($key, $limite->maxAttempts)) {
                $porHora = $limite->decaySeconds >= 3600;
                ReporteException::lanzar(429, $porHora ? self::POR_HORA_MENSAJE : self::SEGUIDAS,
                    ['Retry-After' => RateLimiter::availableIn($key)], ['motivo' => $porHora ? 'por_hora' : 'seguidas']);
            }
        }
        foreach ($limites as $limite) {
            RateLimiter::hit(self::LIMITADOR.'|'.$limite->key, $limite->decaySeconds);
        }
    }

    // Counts, columns and the (masked, config/audit.php) spec: never the rows.
    private function auditar(Request $request, string $accion, array $metadata): void
    {
        $correlation = $request->header('X-Correlation-Id');

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => Str::limit($accion, 80, ''),
            'entity_type' => 'reporte',
            'entity_id' => null,
            'metadata' => [...$metadata, 'ruta' => $request->route()?->getName()],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'correlation_id' => is_string($correlation) && Str::isUuid($correlation) ? strtolower($correlation) : null,
        ]);
    }
}
