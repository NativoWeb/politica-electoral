<?php

namespace App\Services\Reportes\Catalogo;

// A column a report may show and download. sensible = personal contact data: only with export.datos_personales.
// ordenable = the list can be sorted by it (spec.orden.campo = clave, see PersonasFuente::ordenes()).
final class Columna
{
    public function __construct(
        public readonly string $clave,
        public readonly string $etiqueta,
        public readonly string $grupo,
        public readonly bool $sensible = false,
        public readonly bool $siempre = false,
        public readonly bool $numero = false,
        public readonly bool $ordenable = false,
    ) {}

    public function paraFrontend(): array
    {
        return [
            'clave' => $this->clave,
            'etiqueta' => $this->etiqueta,
            'grupo' => $this->grupo,
            'sensible' => $this->sensible,
            'siempre' => $this->siempre,
            'ordenable' => $this->ordenable,
        ];
    }
}
