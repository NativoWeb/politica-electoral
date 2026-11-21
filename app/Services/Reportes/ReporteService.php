<?php

namespace App\Services\Reportes;

use App\Models\User;
use App\Services\Configuracion\Ajuste;
use App\Services\FichaCatalogos;
use App\Services\Reportes\Catalogo\Columna;
use App\Services\Reportes\Fuentes\Fuente;
use App\Services\Reportes\Fuentes\PersonasFuente;

// The reports module (docs/reportes.md): which fuentes a user has and the one check every request goes through
// before a fuente reads anything (spec shape, catalog columns, personal data permission, filters reduced to the
// user's rights).
class ReporteService
{
    public const PERMISO = 'view.reportes';

    public const PERMISO_DATOS = 'export.datos_personales';

    public const POR_PAGINA = 50;

    public const MAX_PAGINA = 2000;

    private const FUENTES = [PersonasFuente::class];

    public static function puedeDatosPersonales(User $user): bool
    {
        return $user->hasPermission(self::PERMISO_DATOS);
    }

    public static function topes(): array
    {
        return ['excel' => Ajuste::int('reportes.excel_max_filas'), 'pdf' => Ajuste::int('reportes.pdf_max_filas')];
    }

    /** @return Fuente[] the fuentes the user may use, keyed by clave */
    public function disponibles(User $user): array
    {
        $out = [];
        foreach (self::FUENTES as $class) {
            $fuente = app($class);
            if ($fuente->disponible($user)) {
                $out[$fuente->clave()] = $fuente;
            }
        }

        return $out;
    }

    // 422 for a fuente that does not exist, 403 for one the user may not use.
    public function fuente(string $clave, User $user): Fuente
    {
        foreach (self::FUENTES as $class) {
            $fuente = app($class);
            if ($fuente->clave() === $clave) {
                if (! $fuente->disponible($user)) {
                    throw ReporteException::prohibido('No tienes permiso para ver esta información.');
                }

                return $fuente;
            }
        }

        throw ReporteException::invalido('Ese tipo de reporte no existe.', ['fuente' => ['No existe.']]);
    }

    // [fuente, spec with normalized filters and checked columns]. $conColumnas: the request shows or downloads rows,
    // so empty columns take the default preset.
    public function preparar(string $clave, mixed $spec, User $user, bool $conColumnas): array
    {
        $fuente = $this->fuente($clave, $user);
        $spec = ReportSpec::desde($spec, $clave);

        $catalogo = $fuente->columnas($user);
        $desconocidas = array_values(array_diff($spec->columnas, array_keys($catalogo)));
        if ($desconocidas) {
            throw ReporteException::invalido('Una de las columnas pedidas no existe.', ['spec.columnas' => ['Columna no reconocida.']]);
        }
        $sensibles = array_filter($spec->columnas, fn ($c) => $catalogo[$c]->sensible);
        if ($sensibles && ! self::puedeDatosPersonales($user)) {
            throw ReporteException::prohibido('Necesitas permiso para ver o descargar datos de contacto. Pídeselo a tu administrador.');
        }

        $columnas = $spec->columnas;
        if ($conColumnas) {
            if ($columnas === [] && $fuente instanceof PersonasFuente) {
                $columnas = $fuente->columnasPorDefecto($user);
            }
            // The always-present columns (the name) go first.
            $siempre = array_keys(array_filter($catalogo, fn (Columna $c) => $c->siempre));
            $columnas = array_values(array_unique([...$siempre, ...$columnas]));
        }

        $ordenes = PersonasFuente::ordenes();
        $orden = collect($ordenes)->first(fn ($o) => $o['clave'] === $spec->ordenCampo);
        $spec = $spec->conFiltros($fuente->normalizarFiltros($spec->filtros, $user))
            ->conColumnas($columnas)
            ->conOrden($orden ? $spec->ordenCampo : 'nombre', $orden ? $spec->ordenDir : 'asc');

        return [$fuente, $spec];
    }

    public function conDatosPersonales(Fuente $fuente, ReportSpec $spec, User $user): bool
    {
        $catalogo = $fuente->columnas($user);

        return collect($spec->columnas)->contains(fn ($c) => isset($catalogo[$c]) && $catalogo[$c]->sensible);
    }

    // The props of GET /reportes.
    public function props(User $user): array
    {
        $fuentes = [];
        foreach ($this->disponibles($user) as $fuente) {
            $base = ReportSpec::desde(['v' => ReportSpec::VERSION, 'filtros' => []], $fuente->clave());
            $fuentes[] = [
                'clave' => $fuente->clave(),
                'etiqueta' => $fuente->etiqueta(),
                'descripcion' => $fuente->descripcion(),
                'total' => $fuente->contar($base->conFiltros($fuente->normalizarFiltros([], $user)), $user),
                'filtros' => $fuente->filtros($user),
                'columnas' => array_values(array_map(fn (Columna $c) => $c->paraFrontend(), $fuente->columnas($user))),
                'presets' => $fuente->presets($user),
                'atajos' => $fuente->atajos($user),
                'ordenes' => PersonasFuente::ordenes(),
            ];
        }

        // The ficha opened from the list: PersonaPanel (view.mapa) needs the Mapa's cargos; both fichas the municipios.
        $fichas = app(FichaCatalogos::class);
        $conMapa = $fuentes && $user->hasPermission('view.mapa');

        return [
            'fuentes' => $fuentes,
            'puedeDatosPersonales' => self::puedeDatosPersonales($user),
            'topes' => self::topes(),
            'porPagina' => self::POR_PAGINA,
            'cargosPorTipo' => $conMapa ? $fichas->cargosPorTipo() : (object) [],
            'cargoCategorias' => $conMapa ? $fichas->cargoCategorias() : [],
            'municipios' => $fuentes ? $fichas->municipios() : [],
        ];
    }
}
