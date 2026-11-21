<?php

namespace App\Services\Reportes\Fuentes;

use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Http\Controllers\EventoIntegracionController;
use App\Models\Lider;
use App\Models\User;
use App\Services\Eventos\EventScope;
use App\Services\Reportes\Catalogo\Columna;
use App\Services\Reportes\Catalogo\Filtro;
use App\Services\Reportes\Fuentes\Concerns\AyudasFuente;
use App\Services\Reportes\ReportSpec;
use App\Support\Cedula;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;

// One row per attendee and event (docs/reportes.md, fuente asistencias): every attendee of the events the user may
// open (EventScope::events(): visible grupos, territory by the event's municipio, not deleted), in any link status,
// as the event's screen lists them. Needs view.eventos or manage.eventos.
class AsistenciasFuente implements Fuente
{
    use AyudasFuente, ScopesTerritory;

    public const CLAVE = 'asistencias';

    public const ORIGENES = EventoIntegracionController::SOURCES + ['electo' => 'Electo'];

    private static ?bool $conComuna = null;

    public function __construct(private EventScope $eventos) {}

    public function clave(): string
    {
        return self::CLAVE;
    }

    public function etiqueta(): string
    {
        return 'Eventos y asistencia';
    }

    public function descripcion(): string
    {
        return 'Quiénes asistieron a cada evento, con la fecha y el lugar';
    }

    public function unidad(array $filtros): array
    {
        return ['singular' => 'asistente', 'plural' => 'asistentes', 'todos' => 'todos los asistentes de los eventos', 'femenino' => false];
    }

    public function disponible(User $user): bool
    {
        return EventScope::canSeeAttendees($user);
    }

    // ---------------------------------------------------------------- catalog

    public function filtros(User $user): array
    {
        return array_values(array_filter([
            $this->grupo('evento', '¿Qué evento?', [
                new Filtro('evento', 'Evento', 'buscar', opcionesDe: 'eventos', placeholder: 'Buscar evento…'),
                new Filtro('tipo_evento', 'Tipo de evento', 'chips', self::lista($this->tiposVisibles())),
                $this->filtroFechas('fecha', 'Fecha del evento', ['ultimos_7', 'ultimos_30']),
            ], abierto: true),
            $this->grupo('donde', '¿Dónde?', [
                new Filtro('municipio', 'Municipio del evento', 'buscar', self::lista($this->municipiosVisibles()), placeholder: 'Buscar municipio…'),
                new Filtro('barrio', 'Barrio del asistente', 'buscar', opcionesDe: 'barrios', placeholder: 'Buscar barrio…'),
            ]),
            $this->grupo('ficha', 'Ficha', [
                new Filtro('sin_ficha', 'Sin ficha en Votantes', 'casilla', multiple: false, ayuda: 'Asistentes que todavía no están vinculados a una ficha.'),
            ]),
            $this->filtroGrupoPolitico(),
        ]));
    }

    public function columnas(User $user): array
    {
        $c = [
            new Columna('nombre', 'Persona', 'Asistente', siempre: true, ordenable: true),
            new Columna('cedula', 'Cédula', 'Asistente', sensible: true),
            new Columna('celular', 'Celular', 'Asistente', sensible: true),
            new Columna('barrio', 'Barrio', 'Asistente'),
            ...(self::conComuna() ? [new Columna('comuna', 'Comuna', 'Asistente')] : []),
            new Columna('evento', 'Evento', 'Evento', ordenable: true),
            new Columna('tipo_evento', 'Tipo de evento', 'Evento'),
            new Columna('fecha', 'Fecha del evento', 'Evento', ordenable: true),
            new Columna('municipio', 'Municipio del evento', 'Evento', ordenable: true),
            new Columna('origen', 'Origen del registro', 'Registro'),
            new Columna('vinculado', 'Vinculado a ficha', 'Registro'),
        ];
        if ($this->veVariosGrupos()) {
            $c[] = new Columna('grupo', 'Grupo político', 'Registro');
        }

        return collect($c)->keyBy('clave')->all();
    }

    public function columnasPara(array $filtros, User $user): array
    {
        return $this->columnas($user);
    }

    public function presets(User $user): array
    {
        $conDatos = self::puedeDatos($user);
        $basico = ['nombre', 'evento', 'fecha', 'municipio', 'barrio', 'vinculado'];

        // A contact preset only with export.datos_personales.
        return array_values(array_filter([
            $conDatos ? ['clave' => 'contacto', 'etiqueta' => 'Con contacto', 'por_defecto' => true,
                'columnas' => ['nombre', 'cedula', 'celular', 'evento', 'fecha', 'municipio', 'barrio']] : null,
            ['clave' => 'asistencia', 'etiqueta' => 'Solo asistencia', 'por_defecto' => ! $conDatos, 'columnas' => $basico],
            ['clave' => 'completo', 'etiqueta' => 'Completo', 'por_defecto' => false,
                'columnas' => array_keys(array_filter($this->columnas($user), fn (Columna $c) => ! $c->sensible || $conDatos))],
        ]));
    }

    public function columnasPorDefecto(User $user, array $filtros = []): array
    {
        return collect($this->presets($user))->firstWhere('por_defecto', true)['columnas'];
    }

    public function atajos(User $user): array
    {
        $orden = ['campo' => 'fecha', 'dir' => 'desc'];

        return [
            $this->atajo('ultimo_mes', 'Asistentes del último mes', ['fecha' => ['relativo' => 'ultimos_30']], $user, $orden),
            $this->atajo('sin_ficha', 'Asistentes sin ficha', ['sin_ficha' => true], $user, $orden),
        ];
    }

    public function ordenes(): array
    {
        return [
            ['clave' => 'fecha', 'dir' => 'desc', 'etiqueta' => 'Eventos más recientes'],
            ['clave' => 'fecha', 'dir' => 'asc', 'etiqueta' => 'Eventos más antiguos'],
            ['clave' => 'nombre', 'dir' => 'asc', 'etiqueta' => 'Nombre A–Z'],
            ['clave' => 'evento', 'dir' => 'asc', 'etiqueta' => 'Evento'],
            ['clave' => 'municipio', 'dir' => 'asc', 'etiqueta' => 'Municipio'],
        ];
    }

    // ---------------------------------------------------------------- filters

    public function normalizarFiltros(array $filtros, User $user): array
    {
        $ids = self::uuids($filtros['evento'] ?? null);
        $out = [
            'evento' => $ids ? ($this->eventos->events()->whereIn('ev.id', $ids)->pluck('ev.id')->all() ?: null) : null,
            'tipo_evento' => self::enLista($filtros['tipo_evento'] ?? null, $this->tiposVisibles()),
            'fecha' => self::fechas($filtros['fecha'] ?? null, array_keys(self::RELATIVOS)),
            'municipio' => self::enLista($filtros['municipio'] ?? null, $this->municipiosVisibles()),
            'barrio' => self::textos($filtros['barrio'] ?? null),
            'sin_ficha' => ($filtros['sin_ficha'] ?? null) === true ? true : null,
        ];
        if ($this->veVariosGrupos()) {
            $out['grupo'] = self::enLista($filtros['grupo'] ?? null, $this->gruposVisibles());
        }

        return array_filter($out, fn ($v) => $v !== null && $v !== []);
    }

    private function consulta(array $f, string $busqueda, User $user): Builder
    {
        $q = $this->eventos->events('ev')->join('event_attendees as ea', 'ea.event_id', '=', 'ev.id');

        if (isset($f['evento'])) {
            $q->whereIn('ev.id', $f['evento']);
        }
        if (isset($f['tipo_evento'])) {
            $q->whereIn('ev.event_type_id', $f['tipo_evento']);
        }
        if (isset($f['fecha'])) {
            self::entreFechasHora($q, 'ev.starts_at', $f['fecha']);
        }
        if (isset($f['municipio'])) {
            $q->whereIn('ev.municipio_id', $f['municipio']);
        }
        if (isset($f['barrio'])) {
            $q->whereIn('ea.barrio', $f['barrio']);
        }
        if (isset($f['sin_ficha'])) {
            $q->whereNull('ea.lider_id');
        }
        if (isset($f['grupo'])) {
            $q->whereIn('ev.campaign_id', $f['grupo']);
        }
        if ($busqueda !== '') {
            $cedula = self::puedeDatos($user) ? Cedula::normalize($busqueda) : null;
            $q->where(fn ($w) => self::contiene($w, "coalesce(ea.nombres, '') || ' ' || coalesce(ea.apellidos, '')", $busqueda)
                ->when($cedula !== null && $cedula !== '', fn ($x) => $x->orWhere('ea.cedula', 'like', '%'.self::escapar($cedula).'%')));
        }

        return $q;
    }

    public function contar(ReportSpec $spec, User $user): int
    {
        return $this->consulta($spec->filtros, $spec->busqueda, $user)->count();
    }

    public function porMunicipio(ReportSpec $spec, User $user, int $primeros): array
    {
        $grupos = $this->consulta($spec->filtros, $spec->busqueda, $user)
            ->leftJoin('geographic_units as gm', 'gm.id', '=', 'ev.municipio_id')
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
        $rows = $this->filasQuery($spec, $user)->offset(($pagina - 1) * $porPagina)->limit($porPagina)->get();
        $fichas = $this->fichasVisibles($rows->pluck('lider_id')->filter()->unique()->values()->all(), $user);

        return $rows->map(fn ($row) => [
            'id' => $row->id,
            'editable' => false,
            'accion' => isset($fichas[$row->lider_id ?? ''])
                ? ['tipo' => 'ficha', 'id' => $row->lider_id, 'editable' => $this->fichaEditable($user, $fichas[$row->lider_id])]
                : null,
            'celdas' => $this->celdas($row, $spec->columnas),
        ])->all();
    }

    public function filas(ReportSpec $spec, User $user, int $limite): iterable
    {
        $query = $this->filasQuery($spec, $user);
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

    private function filasQuery(ReportSpec $spec, User $user): Builder
    {
        $cols = array_flip($spec->columnas);
        $q = $this->consulta($spec->filtros, $spec->busqueda, $user)
            ->leftJoin('event_types as t', 't.id', '=', 'ev.event_type_id')
            ->leftJoin('geographic_units as gm', 'gm.id', '=', 'ev.municipio_id')
            ->select(['ea.id', 'ea.lider_id', 'ea.nombres', 'ea.apellidos', 'ea.cedula', 'ea.celular', 'ea.barrio', 'ea.source',
                'ev.nombre as evento', 'ev.starts_at', 'ev.campaign_id', 't.nombre as tipo_evento', 'gm.canonical_name as municipio']);
        if (isset($cols['comuna'])) {
            $q->leftJoin('geographic_units as gc', 'gc.id', '=', 'ea.comuna_id')->addSelect('gc.canonical_name as comuna');
        }
        if (isset($cols['grupo'])) {
            $q->leftJoin('campaigns as cg', 'cg.id', '=', 'ev.campaign_id')->addSelect('cg.name as grupo');
        }

        $dir = $spec->ordenDir === 'desc' ? 'desc' : 'asc';
        match ($spec->ordenCampo) {
            'nombre' => $q->orderBy('ea.nombres', $dir)->orderBy('ea.apellidos', $dir),
            'evento' => $q->orderBy('ev.nombre', $dir)->orderByDesc('ev.starts_at'),
            'municipio' => $q->orderByRaw("gm.canonical_name {$dir}".self::nullsLast())->orderByDesc('ev.starts_at'),
            default => $q->orderBy('ev.starts_at', $dir === 'asc' ? 'asc' : 'desc'),
        };

        return $q->orderBy('ea.nombres')->orderBy('ea.apellidos')->orderBy('ea.id');
    }

    private function celdas(object $row, array $columnas): array
    {
        $out = [];
        foreach ($columnas as $col) {
            $out[$col] = match ($col) {
                'nombre' => trim($row->nombres.' '.$row->apellidos),
                'fecha' => self::fechaHora($row->starts_at),
                'origen' => self::ORIGENES[$row->source] ?? $row->source,
                'vinculado' => $row->lider_id ? 'Sí' : 'No',
                default => self::texto($row->{$col} ?? null),
            };
        }

        return $out;
    }

    // [lider id => campaign id] of the linked fichas the user may open (view.votantes or view.mapa, grupo and territory).
    private function fichasVisibles(array $ids, User $user): array
    {
        if ($ids === [] || ! self::puedeAbrirFichas($user)) {
            return [];
        }

        return $this->scopeToTerritory(Lider::query()->toBase(), 'lideres.geographic_unit_id')
            ->whereIn('lideres.id', $ids)->pluck('lideres.campaign_id', 'lideres.id')->all();
    }

    // ---------------------------------------------------------------- words

    public function describir(ReportSpec $spec, User $user): array
    {
        $f = $spec->filtros;
        $partes = [];
        if (isset($f['evento'])) {
            $partes[] = 'Evento '.implode(' o ', $this->eventos->events()->whereIn('ev.id', $f['evento'])->orderBy('ev.nombre')->pluck('ev.nombre')->all());
        }
        if (isset($f['tipo_evento'])) {
            $partes[] = self::nombres($f['tipo_evento'], $this->tiposVisibles());
        }
        if (isset($f['fecha'])) {
            $partes[] = self::fechasEnPalabras('Fecha del evento', $f['fecha']);
        }
        if (isset($f['municipio'])) {
            $partes[] = self::nombres($f['municipio'], $this->municipiosVisibles());
        }
        if (isset($f['barrio'])) {
            $partes[] = 'Barrio '.implode(' o ', $f['barrio']);
        }
        if (isset($f['sin_ficha'])) {
            $partes[] = 'Sin ficha';
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
            'eventos' => $this->eventos->events()
                ->leftJoin('geographic_units as m', 'm.id', '=', 'ev.municipio_id')
                ->when($q !== '', fn ($x) => self::contiene($x, 'ev.nombre', $q))
                ->orderByDesc('ev.starts_at')->orderBy('ev.id')->limit(self::OPCIONES_MAX)
                ->get(['ev.id', 'ev.nombre', 'ev.starts_at', 'm.canonical_name as municipio'])
                ->map(fn ($e) => ['valor' => $e->id, 'etiqueta' => $e->nombre.' · '.self::fechaCorta(EventScope::iso($e->starts_at)).($e->municipio ? ' · '.$e->municipio : '')])
                ->all(),
            'barrios' => $this->eventos->events()->join('event_attendees as ea', 'ea.event_id', '=', 'ev.id')
                ->whereNotNull('ea.barrio')->where('ea.barrio', '!=', '')
                ->when(($ids = array_values(array_intersect($municipios, array_keys($this->municipiosVisibles())))) !== [], fn ($x) => $x->whereIn('ev.municipio_id', $ids))
                ->when($q !== '', fn ($x) => self::contiene($x, 'ea.barrio', $q))
                ->distinct()->orderBy('ea.barrio')->limit(self::OPCIONES_MAX)
                ->pluck('ea.barrio')->map(fn ($b) => ['valor' => $b, 'etiqueta' => $b])->all(),
            'municipios' => collect($this->municipiosVisibles())
                ->filter(fn ($nombre) => self::coincide($nombre, $q))
                ->take(self::OPCIONES_MAX)->map(fn ($nombre, $id) => ['valor' => $id, 'etiqueta' => $nombre])->values()->all(),
            default => null,
        };
    }

    // ---------------------------------------------------------------- helpers

    // [id => nombre] of the types of the visible events.
    private function tiposVisibles(): array
    {
        return $this->memo('tipos', fn () => $this->eventos->events()->join('event_types as t', 't.id', '=', 'ev.event_type_id')
            ->distinct()->orderBy('t.nombre')->pluck('t.nombre', 't.id')->all());
    }

    // [id => nombre] of the municipios of the visible events.
    private function municipiosVisibles(): array
    {
        return $this->memo('municipios', fn () => $this->eventos->events()->join('geographic_units as m', 'm.id', '=', 'ev.municipio_id')
            ->distinct()->orderBy('m.canonical_name')->pluck('m.canonical_name', 'm.id')->all());
    }

    private static function conComuna(): bool
    {
        return self::$conComuna ??= Schema::hasColumn('event_attendees', 'comuna_id');
    }

    // Forget the cached schema check (tests build their own tables).
    public static function olvidarEsquema(): void
    {
        self::$conComuna = null;
    }
}
