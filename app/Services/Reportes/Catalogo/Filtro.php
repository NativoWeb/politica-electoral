<?php

namespace App\Services\Reportes\Catalogo;

// A filter as the screen draws it. tipo:
//  chips    = buttons; multiple (O between them) or a single choice. opciones [{valor, etiqueta, ...}].
//  buscar   = a search box whose options come from GET /reportes/opciones/{opciones_de}; value: list of valores.
//  rango    = numbers desde/hasta ({desde, hasta}); opciones are ready-made ranges with valor {desde, hasta}.
//  casilla  = a single on/off chip; value true.
final class Filtro
{
    public function __construct(
        public readonly string $clave,
        public readonly string $etiqueta,
        public readonly string $tipo,
        public readonly array $opciones = [],
        public readonly bool $multiple = true,
        public readonly ?string $ayuda = null,
        public readonly ?string $opcionesDe = null,
        public readonly ?string $placeholder = null,
        public readonly mixed $porDefecto = null,
    ) {}

    public function paraFrontend(): array
    {
        return array_filter([
            'clave' => $this->clave,
            'etiqueta' => $this->etiqueta,
            'tipo' => $this->tipo,
            'multiple' => $this->multiple,
            'opciones' => $this->opciones,
            'ayuda' => $this->ayuda,
            'opciones_de' => $this->opcionesDe,
            'placeholder' => $this->placeholder,
            'por_defecto' => $this->porDefecto,
        ], fn ($v) => $v !== null);
    }
}
