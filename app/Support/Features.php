<?php

namespace App\Support;

use App\Models\User;
use App\Services\Configuracion\Ajuste;

// Feature flags of config/features.php. Being superadmin or holding '*' never turns a module on. On/off is the ajuste
// of the Centro de configuración (its default is the env flag); the beta users stay in config.
class Features
{
    private const CONFIG = [
        'campanas' => 'features.campanas_electorales',
    ];

    private const AJUSTES = [
        'campanas' => 'modulos.campanas_electorales',
    ];

    public static function enabled(string $feature): bool
    {
        return isset(self::AJUSTES[$feature]) && Ajuste::bool(self::AJUSTES[$feature]);
    }

    public static function enabledFor(string $feature, ?User $user): bool
    {
        if ($user === null || ! self::enabled($feature)) {
            return false;
        }

        $beta = (array) config(self::CONFIG[$feature].'.beta_users', []);

        return $beta === [] || in_array((string) $user->getKey(), array_map('strval', $beta), true);
    }
}
