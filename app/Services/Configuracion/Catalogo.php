<?php

namespace App\Services\Configuracion;

// Options of the lists of the Centro de configuración: Catalogo::opciones('genero').
class Catalogo
{
    // Options in their order; only the active ones unless $todas.
    public static function opciones(string $catalogo, bool $todas = false): array
    {
        $opciones = app(CatalogoStore::class)->todas()[$catalogo] ?? [];

        return $todas ? $opciones : array_values(array_filter($opciones, fn ($o) => $o['activo']));
    }

    public static function valores(string $catalogo, bool $todas = false): array
    {
        return array_column(self::opciones($catalogo, $todas), 'valor');
    }

    // The etiqueta of a stored valor; the valor itself when it is not (or no longer) an option.
    public static function etiqueta(string $catalogo, ?string $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return $valor;
        }
        foreach (self::opciones($catalogo, true) as $o) {
            if ($o['valor'] === $valor) {
                return $o['etiqueta'];
            }
        }

        return $valor;
    }

    // An active option, or one of the values the record already has (a switched off option does not break old fichas).
    public static function permite(string $catalogo, mixed $valor, array $actuales = []): bool
    {
        if ($valor === null || $valor === '') {
            return true;
        }
        if (! is_string($valor)) {
            return false;
        }

        return in_array($valor, self::valores($catalogo), true) || in_array($valor, $actuales, true);
    }

    // [valor => etiqueta] of the active options (selects built by the backend).
    public static function mapa(string $catalogo): array
    {
        return array_column(self::opciones($catalogo), 'etiqueta', 'valor');
    }

    // Every list for the forms (shared Inertia prop): inactive options travel too, to name the values old fichas have.
    public static function paraFrontend(): array
    {
        $out = [];
        foreach (app(CatalogoStore::class)->todas() as $catalogo => $opciones) {
            $out[$catalogo] = array_map(fn ($o) => array_filter([
                'valor' => $o['valor'], 'etiqueta' => $o['etiqueta'], 'activo' => $o['activo'], 'color' => $o['color'],
            ], fn ($v) => $v !== null), $opciones);
        }

        return $out;
    }

    public static function olvidar(): void
    {
        app(CatalogoStore::class)->olvidar();
    }
}
