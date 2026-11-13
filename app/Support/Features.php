<?php

namespace App\Support;

use App\Models\User;

// Feature flags of config/features.php. Being superadmin or holding '*' never turns a module on.
class Features
{
    private const CONFIG = [
        'campanas' => 'features.campanas_electorales',
    ];

    public static function enabled(string $feature): bool
    {
        $key = self::CONFIG[$feature] ?? null;

        return $key !== null && (bool) config("{$key}.enabled", false);
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
