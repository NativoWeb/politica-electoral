<?php

namespace App\Support;

// A ficha's name never carries digits: a cédula or a phone typed into the name belongs in its own field. Shared by the
// validation of every entry (App\Rules\NombreSinNumeros), the «Nombre con números» filter of Votantes and Reportes, and
// data:limpiar-nombres-numeros. Only ASCII digits, the same in PHP and in SQL.
final class NombreFicha
{
    public const MENSAJE = 'El nombre no puede tener números: si es la cédula o el teléfono, escríbelo en su campo.';

    public static function tieneNumeros(?string $nombre): bool
    {
        return $nombre !== null && preg_match('/[0-9]/', $nombre) === 1;
    }

    // LIKE per digit instead of a regex: the same clause in PostgreSQL and in SQLite (tests).
    public static function whereConNumeros($query, string $column = 'nombre')
    {
        return $query->where(function ($q) use ($column) {
            foreach (range(0, 9) as $digit) {
                $q->orWhere($column, 'like', "%{$digit}%");
            }
        });
    }

    // The shape of a name without its content, for reports: letters → A, digits → 9 («JUAN PEREZ2» → «A A9»).
    public static function patron(?string $nombre): string
    {
        $nombre = preg_replace('/\s+/u', ' ', trim((string) $nombre));

        return preg_replace('/[0-9]+/', '9', preg_replace('/\p{L}+/u', 'A', $nombre));
    }
}
