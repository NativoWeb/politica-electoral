<?php

namespace App\Services\Reportes\Fuentes;

use App\Models\User;
use App\Services\Reportes\Catalogo\Columna;
use App\Services\Reportes\ReportSpec;

// One kind of thing a report lists (personas, asistencias, compromisos, empresarios). The fuente owns its catalog of
// filters and columns and applies the grupo político and territory of the user itself: nothing in the spec can widen
// them.
interface Fuente
{
    public function clave(): string;

    public function etiqueta(): string;

    public function descripcion(): string;

    // What one row is, for the file and the cover: {singular, plural, todos, femenino} ("todos" = the list
    // without filters; femenino = "las primeras" or "los primeros").
    public function unidad(array $filtros): array;

    // Whether the user may use it at all (besides view.reportes).
    public function disponible(User $user): bool;

    // [{clave, etiqueta, abierto, filtros: [Filtro::paraFrontend()]}] in screen order.
    public function filtros(User $user): array;

    /** @return Columna[] keyed by clave, in screen order: the whole catalog */
    public function columnas(User $user): array;

    /** @return Columna[] the columns valid with these (normalized) filters (a fuente with views narrows them) */
    public function columnasPara(array $filtros, User $user): array;

    // [{clave, etiqueta, por_defecto, columnas}]
    public function presets(User $user): array;

    // The columns of a list asked without columns.
    public function columnasPorDefecto(User $user, array $filtros = []): array;

    // [{clave, etiqueta, spec}]
    public function atajos(User $user): array;

    // [{clave, dir, etiqueta}] the list can be sorted by; the first one is the default.
    public function ordenes(): array;

    // Filters reduced to what the user may apply: unknown keys and values outside the lists are dropped.
    public function normalizarFiltros(array $filtros, User $user): array;

    public function contar(ReportSpec $spec, User $user): int;

    // The $primeros municipios with the most rows, over the whole list: {municipios: [{nombre, total}], otros}.
    public function porMunicipio(ReportSpec $spec, User $user, int $primeros): array;

    // [{id, editable, accion, celdas}] of one page, in the spec's order. accion: what tapping the row opens, or null.
    public function pagina(ReportSpec $spec, User $user, int $pagina, int $porPagina): array;

    // Rows to download (celdas only), read in blocks; at most $limite.
    public function filas(ReportSpec $spec, User $user, int $limite): iterable;

    // The filters in words, for the file's heading and its name: ["Gran elector", "Bucaramanga"].
    public function describir(ReportSpec $spec, User $user): array;

    // Options of a "buscar" filter: [{valor, etiqueta}], at most 50. null = this fuente has no such filter.
    public function opciones(string $filtro, string $q, array $municipios, User $user): ?array;
}
