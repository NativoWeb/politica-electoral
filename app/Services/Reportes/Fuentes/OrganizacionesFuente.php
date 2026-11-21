<?php

namespace App\Services\Reportes\Fuentes;

use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\EventActa;
use App\Models\EventCompromiso;
use App\Models\Organizacion;
use App\Models\User;
use App\Services\Configuracion\Catalogo;
use App\Services\Empresarios\OrganizacionScope;
use App\Services\Reportes\Catalogo\Columna;
use App\Services\Reportes\Catalogo\Filtro;
use App\Services\Reportes\Fuentes\Concerns\AyudasFuente;
use App\Services\Reportes\ReportSpec;
use App\Support\Nit;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

// Empresarios y gremios (docs/reportes.md, fuente empresarios): the organizations of OrganizacionScope (visible grupos,
// territory by municipio, never the deleted ones), seen as one row per organization or one row per member
// (filter "vista", always set). Needs view.empresarios or manage.empresarios.
class OrganizacionesFuente implements Fuente
{
    use AyudasFuente, ScopesTerritory;

    public const CLAVE = 'empresarios';

    public const VISTAS = ['organizaciones' => 'Organizaciones', 'miembros' => 'Miembros'];

    private const ESTADOS = ['activas' => 'Activas', 'inactivas' => 'Inactivas', 'todas' => 'Todas'];

    public function __construct(private OrganizacionScope $scope) {}

    public function clave(): string
    {
        return self::CLAVE;
    }

    public function etiqueta(): string
    {
        return 'Empresarios y gremios';
    }

    public function descripcion(): string
    {
        return 'Empresas y gremios, y las personas que son sus miembros';
    }

    public function unidad(array $filtros): array
    {
        return self::vista($filtros) === 'miembros'
            ? ['singular' => 'miembro', 'plural' => 'miembros', 'todos' => 'todos los miembros de las organizaciones activas', 'femenino' => false]
            : ['singular' => 'organización', 'plural' => 'organizaciones', 'todos' => 'todas las organizaciones activas', 'femenino' => true];
    }

    public function disponible(User $user): bool
    {
        return OrganizacionScope::canView($user);
    }

    // ---------------------------------------------------------------- catalog

    public function filtros(User $user): array
    {
        return array_values(array_filter([
            $this->grupo('vista', '¿Qué quieres ver?', [
                new Filtro('vista', 'Ver', 'chips', self::lista(self::VISTAS), multiple: false, porDefecto: 'organizaciones', obligatorio: true),
            ], abierto: true),
            $this->grupo('que_son', '¿Qué organizaciones?', array_values(array_filter([
                new Filtro('tipo', 'Tipo', 'chips', self::lista(Organizacion::TIPOS)),
                ($se = $this->catalogoUsado('sector_economico_id')) ? new Filtro('sector_economico', 'Sector económico', 'chips', self::lista($se)) : null,
                ($s = $this->catalogoUsado('sector_id')) ? new Filtro('sector', 'Sector', 'chips', self::lista($s)) : null,
                new Filtro('municipio', 'Municipio', 'buscar', self::lista($this->municipiosVisibles()), placeholder: 'Buscar municipio…'),
                new Filtro('compromisos_abiertos', 'Con compromisos abiertos', 'casilla', multiple: false,
                    ayuda: 'Tienen compromisos pendientes o en curso en sus actas.'),
                new Filtro('estado', 'Estado', 'chips', self::lista(self::ESTADOS), multiple: false, porDefecto: 'activas'),
            ]))),
            $this->filtroGrupoPolitico(),
        ]));
    }

    public function columnas(User $user): array
    {
        $org = ['organizaciones'];
        $mie = ['miembros'];
        $c = [
            new Columna('nombre', 'Nombre', 'Identificación', siempre: true, ordenable: true),
            new Columna('organizacion', 'Organización', 'Identificación', ordenable: true, vistas: $mie),
            new Columna('tipo', 'Tipo', 'Identificación', vistas: $org),
            new Columna('nit', 'NIT', 'Identificación', vistas: $org),
            new Columna('rol', 'Rol en la organización', 'Identificación', vistas: $mie),
            new Columna('cedula', 'Cédula', 'Contacto', sensible: true, vistas: $mie),
            new Columna('celular', 'Celular', 'Contacto', sensible: true, vistas: $mie),
            new Columna('sector_economico', 'Sector económico', 'Organización', vistas: $org),
            new Columna('sector', 'Sector', 'Organización', vistas: $org),
            new Columna('municipio', 'Municipio', 'Organización', ordenable: true),
            new Columna('empleados', 'Empleados', 'Organización', vistas: $org),
            new Columna('miembros', 'Número de miembros', 'Organización', numero: true, vistas: $org),
            new Columna('representante', 'Representante legal', 'Representante', vistas: $org),
            new Columna('representante_cedula', 'Cédula del representante', 'Representante', sensible: true, vistas: $org),
            new Columna('representante_celular', 'Celular del representante', 'Representante', sensible: true, vistas: $org),
            new Columna('correo', 'Correo', 'Representante', sensible: true, vistas: $org),
        ];
        if ($this->veVariosGrupos()) {
            $c[] = new Columna('grupo', 'Grupo político', 'Sistema');
        }

        return collect($c)->keyBy('clave')->all();
    }

    public function columnasPara(array $filtros, User $user): array
    {
        $vista = self::vista($filtros);

        return array_filter($this->columnas($user), fn (Columna $c) => $c->vistas === null || in_array($vista, $c->vistas, true));
    }

    public function presets(User $user): array
    {
        $conDatos = self::puedeDatos($user);
        $permitidas = fn (string $vista) => array_keys(array_filter($this->columnasPara(['vista' => $vista], $user),
            fn (Columna $c) => ! $c->sensible || $conDatos));

        return [
            ['clave' => 'organizaciones', 'etiqueta' => 'Organizaciones', 'por_defecto' => true, 'vista' => 'organizaciones',
                'columnas' => ['nombre', 'tipo', 'municipio', 'sector_economico', 'representante', ...($conDatos ? ['representante_celular'] : []), 'miembros']],
            ['clave' => 'organizaciones_completo', 'etiqueta' => 'Organizaciones completo', 'por_defecto' => false, 'vista' => 'organizaciones',
                'columnas' => $permitidas('organizaciones')],
            ['clave' => 'miembros', 'etiqueta' => 'Miembros', 'por_defecto' => true, 'vista' => 'miembros',
                'columnas' => ['nombre', 'organizacion', 'rol', ...($conDatos ? ['cedula', 'celular'] : []), 'municipio']],
        ];
    }

    public function columnasPorDefecto(User $user, array $filtros = []): array
    {
        $vista = self::vista($filtros);

        return collect($this->presets($user))->first(fn ($p) => $p['por_defecto'] && $p['vista'] === $vista)['columnas'];
    }

    public function atajos(User $user): array
    {
        $orden = ['campo' => 'nombre', 'dir' => 'asc'];

        return [
            $this->atajo('empresas', 'Empresas', ['vista' => 'organizaciones', 'tipo' => ['empresa']], $user, $orden),
            $this->atajo('gremios', 'Gremios', ['vista' => 'organizaciones', 'tipo' => ['gremio']], $user, $orden),
            $this->atajo('miembros', 'Miembros de organizaciones', ['vista' => 'miembros'], $user, $orden),
            $this->atajo('con_compromisos', 'Con compromisos abiertos', ['vista' => 'organizaciones', 'compromisos_abiertos' => true], $user, $orden),
        ];
    }

    public function ordenes(): array
    {
        return [
            ['clave' => 'nombre', 'dir' => 'asc', 'etiqueta' => 'Nombre A–Z'],
            ['clave' => 'nombre', 'dir' => 'desc', 'etiqueta' => 'Nombre Z–A'],
            ['clave' => 'municipio', 'dir' => 'asc', 'etiqueta' => 'Municipio'],
            ['clave' => 'organizacion', 'dir' => 'asc', 'etiqueta' => 'Organización'],
        ];
    }

    // ---------------------------------------------------------------- filters

    public function normalizarFiltros(array $filtros, User $user): array
    {
        $out = [
            'vista' => self::vista($filtros),
            'tipo' => self::enLista($filtros['tipo'] ?? null, Organizacion::TIPOS),
            'sector_economico' => self::enLista($filtros['sector_economico'] ?? null, $this->catalogoUsado('sector_economico_id')),
            'sector' => self::enLista($filtros['sector'] ?? null, $this->catalogoUsado('sector_id')),
            'municipio' => self::enLista($filtros['municipio'] ?? null, $this->municipiosVisibles()),
            'compromisos_abiertos' => ($filtros['compromisos_abiertos'] ?? null) === true ? true : null,
            'estado' => in_array($filtros['estado'] ?? null, ['inactivas', 'todas'], true) ? $filtros['estado'] : null,
        ];
        if ($this->veVariosGrupos()) {
            $out['grupo'] = self::enLista($filtros['grupo'] ?? null, $this->gruposVisibles());
        }

        return array_filter($out, fn ($v) => $v !== null && $v !== []);
    }

    private static function vista(array $filtros): string
    {
        return ($filtros['vista'] ?? null) === 'miembros' ? 'miembros' : 'organizaciones';
    }

    // Organizations of the scope with the filters; the miembros view adds one row per member.
    private function consulta(array $f, string $busqueda): Builder
    {
        $q = $this->scope->query('o');
        match ($f['estado'] ?? 'activas') {
            'inactivas' => $q->where('o.is_active', false),
            'todas' => null,
            default => $q->where('o.is_active', true),
        };
        if (isset($f['tipo'])) {
            $q->whereIn('o.tipo', $f['tipo']);
        }
        if (isset($f['sector_economico'])) {
            $q->whereIn('o.sector_economico_id', $f['sector_economico']);
        }
        if (isset($f['sector'])) {
            $q->whereIn('o.sector_id', $f['sector']);
        }
        if (isset($f['municipio'])) {
            $q->whereIn('o.municipio_id', $f['municipio']);
        }
        if (isset($f['compromisos_abiertos'])) {
            $q->whereExists(fn ($c) => $c->select(DB::raw(1))->from('event_compromisos as cc')
                ->join('event_actas as ca', 'ca.id', '=', 'cc.acta_id')
                ->whereColumn('cc.organizacion_id', 'o.id')
                ->where('ca.estado', EventActa::CONFIRMADA)
                ->whereIn('cc.estado', EventCompromiso::ABIERTOS));
        }
        if (isset($f['grupo'])) {
            $q->whereIn('o.campaign_id', $f['grupo']);
        }

        $miembros = self::vista($f) === 'miembros';
        if ($miembros) {
            $q->join('organizacion_miembros as om', 'om.organizacion_id', '=', 'o.id')->join('lideres as l', 'l.id', '=', 'om.lider_id');
        }
        if ($busqueda !== '') {
            $q->where(function ($w) use ($busqueda, $miembros) {
                if ($miembros) {
                    self::contiene($w, 'l.nombre', $busqueda);
                    self::contiene($w, 'o.nombre', $busqueda, 'or');

                    return;
                }
                self::contiene($w, 'o.nombre', $busqueda);
                self::contiene($w, "coalesce(o.representante_nombre, '')", $busqueda, 'or');
                $digitos = preg_replace('/\D/', '', $busqueda);
                if (strlen($digitos) >= 3) {
                    $w->orWhere('o.nit', 'like', '%'.$digitos.'%');
                }
            });
        }

        return $q;
    }

    public function contar(ReportSpec $spec, User $user): int
    {
        return $this->consulta($spec->filtros, $spec->busqueda)->count();
    }

    public function porMunicipio(ReportSpec $spec, User $user, int $primeros): array
    {
        $grupos = $this->consulta($spec->filtros, $spec->busqueda)
            ->leftJoin('geographic_units as gm', 'gm.id', '=', 'o.municipio_id')
            ->selectRaw('gm.canonical_name as nombre, count(*) as total')
            ->groupBy('gm.canonical_name')
            ->orderByDesc('total')->orderBy('nombre')
            ->limit($primeros)->get();
        $municipios = $grupos->map(fn ($g) => ['nombre' => self::texto($g->nombre) ?? 'Sin municipio', 'total' => (int) $g->total])->all();

        return ['municipios' => $municipios, 'otros' => max(0, $this->contar($spec, $user) - array_sum(array_column($municipios, 'total')))];
    }

    // ---------------------------------------------------------------- rows

    public function pagina(ReportSpec $spec, User $user, int $pagina, int $porPagina): array
    {
        return $this->filasQuery($spec)->offset(($pagina - 1) * $porPagina)->limit($porPagina)->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'editable' => false,
                'accion' => ['tipo' => 'organizacion', 'id' => $row->organizacion_id, 'url' => '/empresarios?abrir='.$row->organizacion_id],
                'celdas' => $this->celdas($row, $spec->columnas),
            ])->all();
    }

    public function filas(ReportSpec $spec, User $user, int $limite): iterable
    {
        $query = $this->filasQuery($spec);
        for ($offset = 0; $offset < $limite; $offset += self::BLOQUE) {
            $bloque = (clone $query)->offset($offset)->limit(min(self::BLOQUE, $limite - $offset))->get();
            foreach ($bloque as $row) {
                yield $this->celdas($row, $spec->columnas);
            }
            if ($bloque->count() < self::BLOQUE) {
                return;
            }
        }
    }

    private function filasQuery(ReportSpec $spec): Builder
    {
        $miembros = self::vista($spec->filtros) === 'miembros';
        $q = $this->consulta($spec->filtros, $spec->busqueda)
            ->leftJoin('geographic_units as gm', 'gm.id', '=', 'o.municipio_id')
            ->select(['o.id as organizacion_id', 'o.nombre as organizacion', 'o.campaign_id', 'gm.canonical_name as municipio']);

        if ($miembros) {
            $q->addSelect(['om.id', 'om.rol', 'l.nombre', 'l.cedula', 'l.telefono as celular', 'l.geographic_unit_id as lider_geo']);
        } else {
            $q->leftJoin('org_catalogos as se', 'se.id', '=', 'o.sector_economico_id')
                ->leftJoin('org_catalogos as s', 's.id', '=', 'o.sector_id')
                ->addSelect(['o.id', 'o.nombre', 'o.tipo', 'o.nit', 'o.nit_dv', 'o.empleados', 'o.empleados_rango', 'o.representante_nombre as representante',
                    'o.representante_cedula', 'o.representante_celular', 'o.correo', 'se.nombre as sector_economico', 's.nombre as sector'])
                ->selectSub(DB::table('organizacion_miembros as cm')->whereColumn('cm.organizacion_id', 'o.id')->selectRaw('count(distinct cm.lider_id)'), 'miembros');
        }
        if (in_array('grupo', $spec->columnas, true)) {
            $q->leftJoin('campaigns as cg', 'cg.id', '=', 'o.campaign_id')->addSelect('cg.name as grupo');
        }

        $dir = $spec->ordenDir === 'desc' ? 'desc' : 'asc';
        $nombre = $miembros ? 'l.nombre' : 'o.nombre';
        match ($spec->ordenCampo) {
            'municipio' => $q->orderByRaw('gm.canonical_name asc'.self::nullsLast())->orderBy($nombre),
            'organizacion' => $q->orderBy('o.nombre', $dir)->orderBy($nombre),
            default => $q->orderBy($nombre, $dir),
        };

        return $q->orderBy($miembros ? 'om.id' : 'o.id');
    }

    private function celdas(object $row, array $columnas): array
    {
        // As in the organization's ficha: of a member outside the user's territory only the name and the role.
        $allowed = $this->allowedGeoIds();
        $dentro = ! property_exists($row, 'lider_geo') || $allowed === null || in_array($row->lider_geo, $allowed, true);

        $out = [];
        foreach ($columnas as $col) {
            $out[$col] = match ($col) {
                'tipo' => Organizacion::TIPOS[$row->tipo] ?? $row->tipo,
                'nit' => Nit::format($row->nit, $row->nit_dv),
                'empleados' => $row->empleados !== null ? (string) $row->empleados : self::texto($row->empleados_rango),
                'miembros' => (int) $row->miembros,
                'rol' => Catalogo::etiqueta('rol_miembro', $row->rol),
                'cedula', 'celular' => $dentro ? self::texto($row->{$col}) : null,
                default => self::texto($row->{$col} ?? null),
            };
        }

        return $out;
    }

    // ---------------------------------------------------------------- words

    public function describir(ReportSpec $spec, User $user): array
    {
        $f = $spec->filtros;
        $partes = [];
        if (self::vista($f) === 'miembros') {
            $partes[] = 'Miembros';
        }
        if (isset($f['tipo'])) {
            $partes[] = self::nombres($f['tipo'], ['empresa' => 'Empresas', 'gremio' => 'Gremios']);
        }
        if (isset($f['sector_economico'])) {
            $partes[] = 'Sector económico '.self::nombres($f['sector_economico'], $this->catalogoUsado('sector_economico_id'));
        }
        if (isset($f['sector'])) {
            $partes[] = 'Sector '.self::nombres($f['sector'], $this->catalogoUsado('sector_id'));
        }
        if (isset($f['municipio'])) {
            $partes[] = self::nombres($f['municipio'], $this->municipiosVisibles());
        }
        if (isset($f['compromisos_abiertos'])) {
            $partes[] = 'Con compromisos abiertos';
        }
        if (isset($f['estado'])) {
            $partes[] = $f['estado'] === 'inactivas' ? 'Inactivas' : 'Activas e inactivas';
        }
        if (isset($f['grupo'])) {
            $partes[] = self::nombres($f['grupo'], $this->gruposVisibles());
        }
        if ($spec->busqueda !== '') {
            $partes[] = self::busquedaEnPalabras($spec->busqueda);
        }

        return $partes;
    }

    // ---------------------------------------------------------------- options

    public function opciones(string $filtro, string $q, array $municipios, User $user): ?array
    {
        $q = mb_substr(trim($q), 0, 60);

        return match ($filtro) {
            'municipios' => collect($this->municipiosVisibles())
                ->filter(fn ($nombre) => self::coincide($nombre, $q))
                ->take(self::OPCIONES_MAX)->map(fn ($nombre, $id) => ['valor' => $id, 'etiqueta' => $nombre])->values()->all(),
            default => null,
        };
    }

    // ---------------------------------------------------------------- helpers

    // [id => nombre] of the municipios of the visible organizations.
    private function municipiosVisibles(): array
    {
        return $this->memo('municipios', fn () => $this->scope->query('o')->join('geographic_units as m', 'm.id', '=', 'o.municipio_id')
            ->distinct()->orderBy('m.canonical_name')->pluck('m.canonical_name', 'm.id')->all());
    }

    // [id => nombre] of the catalog values (sector económico or sector) the visible organizations use.
    private function catalogoUsado(string $columna): array
    {
        return $this->memo('catalogo:'.$columna, fn () => $this->scope->query('o')->join('org_catalogos as k', 'k.id', '=', "o.{$columna}")
            ->distinct()->orderBy('k.nombre')->pluck('k.nombre', 'k.id')->all());
    }
}
