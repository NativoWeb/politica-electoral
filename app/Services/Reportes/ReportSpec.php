<?php

namespace App\Services\Reportes;

// What a report asks for, version 1 (docs/reportes.md). It travels in the URL and the requests, and serializes to
// JSON as it is, so a later phase can save it ("reportes guardados"). The shape is checked here; what each filter
// and column may hold is checked by the fuente, against the user's rights.
final class ReportSpec
{
    public const VERSION = 1;

    public const MAX_BUSQUEDA = 100;

    public const MAX_COLUMNAS = 60;

    public function __construct(
        public readonly string $fuente,
        public readonly array $filtros,
        public readonly array $columnas,
        public readonly string $ordenCampo,
        public readonly string $ordenDir,
        public readonly string $busqueda,
    ) {}

    // $input is the request's "spec"; $fuente the one of the URL. 422 on a wrong shape.
    public static function desde(mixed $input, string $fuente): self
    {
        if (! is_array($input)) {
            throw ReporteException::invalido('Falta la descripción del reporte.', ['spec' => ['Falta la descripción del reporte.']]);
        }
        if (($input['v'] ?? null) !== self::VERSION) {
            throw ReporteException::invalido('Esta versión del reporte no se reconoce. Recarga la página.', ['spec.v' => ['Versión no soportada.']]);
        }
        if (isset($input['fuente']) && $input['fuente'] !== $fuente) {
            throw ReporteException::invalido('El reporte no corresponde a esta fuente.', ['spec.fuente' => ['No coincide con la dirección.']]);
        }

        $filtros = $input['filtros'] ?? [];
        if (! is_array($filtros) || ($filtros !== [] && array_is_list($filtros))) {
            throw ReporteException::invalido('Los filtros del reporte no son válidos.', ['spec.filtros' => ['Deben ser un objeto.']]);
        }

        $columnas = $input['columnas'] ?? [];
        if (! is_array($columnas) || ! array_is_list($columnas) || count($columnas) > self::MAX_COLUMNAS
            || collect($columnas)->contains(fn ($c) => ! is_string($c))) {
            throw ReporteException::invalido('Las columnas del reporte no son válidas.', ['spec.columnas' => ['Deben ser una lista de nombres.']]);
        }

        $orden = is_array($input['orden'] ?? null) ? $input['orden'] : [];
        $busqueda = is_string($input['busqueda'] ?? null) ? trim($input['busqueda']) : '';

        return new self(
            $fuente,
            $filtros,
            array_values(array_unique($columnas)),
            is_string($orden['campo'] ?? null) ? $orden['campo'] : '',
            ($orden['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
            mb_substr($busqueda, 0, self::MAX_BUSQUEDA),
        );
    }

    public function conFiltros(array $filtros): self
    {
        return new self($this->fuente, $filtros, $this->columnas, $this->ordenCampo, $this->ordenDir, $this->busqueda);
    }

    public function conColumnas(array $columnas): self
    {
        return new self($this->fuente, $this->filtros, $columnas, $this->ordenCampo, $this->ordenDir, $this->busqueda);
    }

    public function conOrden(string $campo, string $dir): self
    {
        return new self($this->fuente, $this->filtros, $this->columnas, $campo, $dir, $this->busqueda);
    }

    public function toArray(): array
    {
        return [
            'v' => self::VERSION,
            'fuente' => $this->fuente,
            'filtros' => $this->filtros,
            'columnas' => $this->columnas,
            'orden' => ['campo' => $this->ordenCampo, 'dir' => $this->ordenDir],
            'busqueda' => $this->busqueda,
        ];
    }
}
