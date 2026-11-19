<?php

namespace App\Services\Configuracion;

use App\Support\Configuracion\AjusteRegistry;
use InvalidArgumentException;

// Ajustes del sistema: Ajuste::get('whatsapp.max_destinatarios'). The saved value when there is a valid one, else the
// registry default.
class Ajuste
{
    public static function get(string $clave): mixed
    {
        if (! AjusteRegistry::existe($clave)) {
            throw new InvalidArgumentException("Ajuste desconocido: {$clave}");
        }
        $def = AjusteRegistry::definicion($clave);
        $guardados = app(AjusteStore::class)->guardados();

        if (array_key_exists($clave, $guardados) && self::enRango($def, $guardados[$clave])) {
            return $guardados[$clave];
        }

        return $def['default'];
    }

    public static function int(string $clave): int
    {
        return (int) self::get($clave);
    }

    public static function bool(string $clave): bool
    {
        return (bool) self::get($clave);
    }

    public static function guardado(string $clave): bool
    {
        return array_key_exists($clave, app(AjusteStore::class)->guardados());
    }

    // Values the frontend needs on every page, guests included (the public QR form): nothing sensitive.
    public static function publicos(): array
    {
        return ['edad_minima_asistentes' => self::int('asistentes.edad_minima')];
    }

    public static function olvidar(): void
    {
        app(AjusteStore::class)->olvidar();
    }

    private static function enRango(array $def, mixed $valor): bool
    {
        if ($def['tipo'] !== 'int') {
            return true;
        }

        return $valor >= $def['min'] && $valor <= $def['max'];
    }
}
