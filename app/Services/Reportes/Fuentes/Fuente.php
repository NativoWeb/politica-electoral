<?php

namespace App\Services\Reportes\Fuentes;

use App\Models\User;
use App\Services\Reportes\Catalogo\Columna;
use App\Services\Reportes\ReportSpec;

// One kind of thing a report lists (B-1: personas). The fuente owns its catalog of filters and columns and applies
// the grupo político and territory of the user itself: nothing in the spec can widen them.
interface Fuente
{
    public function clave(): string;

    public function etiqueta(): string;

    public function descripcion(): string;

    // Whether the user may use it at all (besides view.reportes).
    public function disponible(User $user): bool;

    // [{clave, etiqueta, abierto, filtros: [Filtro::paraFrontend()]}] in screen order.
    public function filtros(User $user): array;

    /** @return Columna[] keyed by clave, in screen order */
    public function columnas(User $user): array;

    // [{clave, etiqueta, descripcion, columnas}]
    public function presets(User $user): array;

    // [{clave, etiqueta, descripcion, spec}]
    public function atajos(User $user): array;

    // Filters reduced to what the user may apply: unknown keys and values outside the lists are dropped.
    public function normalizarFiltros(array $filtros, User $user): array;

    public function contar(ReportSpec $spec, User $user): int;

    // The $primeros municipios with the most people, over the whole list: {municipios: [{nombre, total}], otros}.
    public function porMunicipio(ReportSpec $spec, User $user, int $primeros): array;

    // [{id, editable, celdas}] of one page, in the spec's order.
    public function pagina(ReportSpec $spec, User $user, int $pagina, int $porPagina): array;

    // Rows to download (celdas only), read in blocks; at most $limite.
    public function filas(ReportSpec $spec, User $user, int $limite): iterable;

    // The filters in words, for the file's heading and its name: ["Gran elector", "Bucaramanga"].
    public function describir(ReportSpec $spec, User $user): array;

    // Options of a "buscar" filter: [{valor, etiqueta}], at most 50.
    public function opciones(string $filtro, string $q, array $municipios, User $user): ?array;
}
