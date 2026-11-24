<?php

namespace App\Support;

use App\Models\User;

// The "Es líder (cuenta para las metas)" mark of a ficha (lideres.es_lider), part of Campañas electorales: it is only
// shown, filtered and saved while the module is on for the user. It changes only when the key comes with true/false:
// a form, the offline queue or the agent that leaves it out (or sends null) never unmarks anyone.
class EsLider
{
    public const CAMPO = 'es_lider';

    public const ETIQUETA = 'Marcados como líder';

    // The same mark on one person (a report's Marcas column).
    public const ETIQUETA_FICHA = 'Es líder';

    public static function activo(?User $user = null): bool
    {
        $user ??= request()->user();

        return $user instanceof User && Features::enabledFor('campanas', $user);
    }

    // The validated data without es_lider when the module is off or it came empty.
    public static function filtrar(array $data, ?User $user = null): array
    {
        if (! array_key_exists(self::CAMPO, $data)) {
            return $data;
        }
        if ($data[self::CAMPO] === null || ! self::activo($user)) {
            unset($data[self::CAMPO]);
        } else {
            $data[self::CAMPO] = (bool) $data[self::CAMPO];
        }

        return $data;
    }
}
