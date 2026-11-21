<?php

namespace App\Services\Reportes\Fuentes;

use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\EventCompromiso;
use App\Models\User;
use App\Services\Configuracion\Ajuste;
use App\Services\Empresarios\OrganizacionScope;
use App\Services\Eventos\Actas\CompromisoListing;
use App\Services\Eventos\EventScope;
use App\Services\Reportes\Catalogo\Columna;
use App\Services\Reportes\Catalogo\Filtro;
use App\Services\Reportes\Fuentes\Concerns\AyudasFuente;
use App\Services\Reportes\ReportSpec;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

// The compromisos of confirmed actas the follow-up screen lists (docs/reportes.md, fuente compromisos):
// CompromisoListing's query, so events' only with view.eventos and organizations' only with view.empresarios,
// visible grupos, territory, no deleted event or organization. Vencido / por vencer follow CompromisoAlertas.
class CompromisosFuente implements Fuente
{
    use AyudasFuente, ScopesTerritory;

    public const CLAVE = 'compromisos';

    public const VENCIMIENTOS = ['vencidos' => 'Vencidos', 'por_vencer' => 'Por vencer'];

    public const ORIGENES = ['evento' => 'Evento', 'organizacion' => 'Organización'];

    public function __construct(private CompromisoListing $listing) {}

    public function clave(): string
    {
        return self::CLAVE;
    }

    public function etiqueta(): string
    {
        return 'Compromisos y actas';
    }

    public function descripcion(): string
    {
        return 'Lo que se acordó en las actas de eventos y organizaciones, y cómo va';
    }

    public function unidad(array $filtros): array
    {
        return ['singular' => 'compromiso', 'plural' => 'compromisos', 'todos' => 'todos los compromisos', 'femenino' => false];
    }

    public function disponible(User $user): bool
    {
        return EventScope::canSeeAttendees($user) || OrganizacionScope::canView($user);
    }

    // ---------------------------------------------------------------- catalog

    public function filtros(User $user): array
    {
        $origenes = $this->origenesVisibles($user);
        $dias = Ajuste::int('compromisos.dias_por_vencer');

        return array_values(array_filter([
            $this->grupo('estado', 'Estado', [
                new Filtro('estado', 'Estado', 'chips', self::lista(EventCompromiso::LABELS)),
                new Filtro('vencimiento', 'Vencimiento', 'chips', self::lista(self::VENCIMIENTOS),
                    ayuda: "Abiertos (pendientes o en curso). Por vencer: la fecha límite llega en {$dias} días o menos."),
                $this->filtroFechas('fecha_limite', 'Fecha límite', ['proximos_7', 'ultimos_7', 'ultimos_30']),
            ], abierto: true),
            $this->grupo('quien_donde', '¿Quién y dónde?', array_values(array_filter([
                new Filtro('responsable', 'Responsable', 'buscar', opcionesDe: 'responsables', placeholder: 'Buscar responsable…'),
                count($origenes) > 1 ? new Filtro('origen', 'Origen', 'chips', self::lista($origenes)) : null,
                new Filtro('municipio', 'Municipio', 'buscar', self::lista($this->municipiosVisibles()), placeholder: 'Buscar municipio…'),
            ]))),
            $this->filtroGrupoPolitico(),
        ]));
    }

    public function columnas(User $user): array
    {
        $c = [
            new Columna('descripcion', 'Compromiso', 'Compromiso', siempre: true),
            new Columna('estado', 'Estado', 'Compromiso', ordenable: true),
            new Columna('vencimiento', 'Vencido o por vencer', 'Compromiso'),
            new Columna('fecha_limite', 'Fecha límite', 'Compromiso', ordenable: true),
            new Columna('responsables', 'Responsables', 'Responsables'),
            new Columna('telefono_responsable', 'Teléfono del responsable', 'Responsables', sensible: true),
            new Columna('origen', 'Origen', 'Origen'),
            new Columna('origen_nombre', 'Evento u organización', 'Origen'),
            new Columna('acta', 'Acta', 'Origen'),
            new Columna('municipio', 'Municipio', 'Origen', ordenable: true),
        ];
        if ($this->veVariosGrupos()) {
            $c[] = new Columna('grupo', 'Grupo político', 'Origen');
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
        $seguimiento = ['descripcion', 'estado', 'vencimiento', 'fecha_limite', 'responsables', 'origen_nombre', 'municipio'];

        // The phone preset only with export.datos_personales.
        return array_values(array_filter([
            ['clave' => 'seguimiento', 'etiqueta' => 'Seguimiento', 'por_defecto' => ! $conDatos, 'columnas' => $seguimiento],
            $conDatos ? ['clave' => 'con_telefono', 'etiqueta' => 'Con teléfono del responsable', 'por_defecto' => true,
                'columnas' => [...array_slice($seguimiento, 0, 5), 'telefono_responsable', 'origen_nombre', 'municipio']] : null,
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
        $orden = ['campo' => 'estado', 'dir' => 'asc'];

        return [
            $this->atajo('vencidos', 'Compromisos vencidos', ['vencimiento' => ['vencidos']], $user, $orden),
            $this->atajo('esta_semana', 'Vencen esta semana', ['estado' => EventCompromiso::ABIERTOS, 'fecha_limite' => ['relativo' => 'proximos_7']], $user, $orden),
        ];
    }

    public function ordenes(): array
    {
        return [
            // As the follow-up screen: open first, then by deadline (none last).
            ['clave' => 'estado', 'dir' => 'asc', 'etiqueta' => 'Abiertos primero'],
            ['clave' => 'fecha_limite', 'dir' => 'asc', 'etiqueta' => 'Fecha límite: primero la más antigua'],
            ['clave' => 'fecha_limite', 'dir' => 'desc', 'etiqueta' => 'Fecha límite: primero la más lejana'],
            ['clave' => 'municipio', 'dir' => 'asc', 'etiqueta' => 'Municipio'],
        ];
    }

    // ---------------------------------------------------------------- filters

    public function normalizarFiltros(array $filtros, User $user): array
    {
        $out = [
            'estado' => self::enLista($filtros['estado'] ?? null, EventCompromiso::LABELS),
            'vencimiento' => self::enLista($filtros['vencimiento'] ?? null, self::VENCIMIENTOS),
            'fecha_limite' => self::fechas($filtros['fecha_limite'] ?? null, array_keys(self::RELATIVOS)),
            'responsable' => self::textos($filtros['responsable'] ?? null),
            'origen' => self::enLista($filtros['origen'] ?? null, $this->origenesVisibles($user)),
            'municipio' => self::enLista($filtros['municipio'] ?? null, $this->municipiosVisibles()),
        ];
        if ($this->veVariosGrupos()) {
            $out['grupo'] = self::enLista($filtros['grupo'] ?? null, $this->gruposVisibles());
        }

        return array_filter($out, fn ($v) => $v !== null && $v !== []);
    }

    // CompromisoListing's query (permission per origin, grupo, territory, confirmed actas) plus the report's filters.
    private function consulta(array $f, string $busqueda): Builder
    {
        // Both origins chosen = no origin filter (each one still needs its permission inside the listing).
        $origen = isset($f['origen']) && count($f['origen']) === 1 ? $f['origen'][0] : null;
        $q = $this->listing->query(['origen' => $origen] + array_fill_keys(CompromisoListing::FILTER_KEYS, null));

        if (isset($f['estado'])) {
            $q->whereIn('c.estado', $f['estado']);
        }
        if (isset($f['vencimiento'])) {
            [$hoy, $despues] = self::porVencerHasta();
            $q->where(function ($w) use ($f, $hoy, $despues) {
                foreach ($f['vencimiento'] as $v) {
                    $w->orWhere(fn ($x) => $x->whereIn('c.estado', EventCompromiso::ABIERTOS)->whereNotNull('c.fecha_limite')
                        ->when($v === 'vencidos', fn ($y) => $y->where('c.fecha_limite', '<', $hoy))
                        ->when($v === 'por_vencer', fn ($y) => $y->where('c.fecha_limite', '>=', $hoy)->where('c.fecha_limite', '<', $despues)));
                }
            });
        }
        if (isset($f['fecha_limite'])) {
            self::entreDias($q, 'c.fecha_limite', $f['fecha_limite']);
        }
        if (isset($f['responsable'])) {
            $q->whereExists(fn ($r) => $r->select(DB::raw(1))->from('event_compromiso_responsables as r')
                ->whereColumn('r.compromiso_id', 'c.id')->whereIn('r.nombre', $f['responsable']));
        }
        if (isset($f['municipio'])) {
            $q->whereIn('m.id', $f['municipio']);
        }
        if (isset($f['grupo'])) {
            $q->whereIn('c.campaign_id', $f['grupo']);
        }
        if ($busqueda !== '') {
            self::contiene($q, 'c.descripcion', $busqueda);
        }

        return $q;
    }

    // [today, the day after the last "por vencer" day]: the same window as CompromisoAlertas (ajuste compromisos.dias_por_vencer).
    private static function porVencerHasta(): array
    {
        $hoy = EventCompromiso::today();

        return [$hoy, CarbonImmutable::parse($hoy)->addDays(Ajuste::int('compromisos.dias_por_vencer') + 1)->toDateString()];
    }

    public function contar(ReportSpec $spec, User $user): int
    {
        return $this->consulta($spec->filtros, $spec->busqueda)->reorder()->count();
    }

    public function porMunicipio(ReportSpec $spec, User $user, int $primeros): array
    {
        $grupos = DB::query()->fromSub($this->consulta($spec->filtros, $spec->busqueda)->reorder(), 'x')
            ->selectRaw('x.municipio as nombre, count(*) as total')
            ->groupBy('x.municipio')
            ->orderByDesc('total')->orderBy('nombre')
            ->limit($primeros)->get();
        $municipios = $grupos->map(fn ($g) => ['nombre' => self::texto($g->nombre) ?? 'Sin municipio', 'total' => (int) $g->total])->all();

        return ['municipios' => $municipios, 'otros' => max(0, $this->contar($spec, $user) - array_sum(array_column($municipios, 'total')))];
    }

    // ---------------------------------------------------------------- rows

    public function pagina(ReportSpec $spec, User $user, int $pagina, int $porPagina): array
    {
        $rows = $this->filasQuery($spec)->offset(($pagina - 1) * $porPagina)->limit($porPagina)->get();
        $responsables = $this->listing->responsables($rows->pluck('id')->all());

        return $rows->map(fn ($row) => [
            'id' => $row->id,
            'editable' => false,
            'accion' => ['tipo' => 'compromiso', 'id' => $row->id, 'url' => '/eventos/compromisos?abrir='.$row->id],
            'celdas' => $this->celdas($row, $responsables[$row->id] ?? [], $spec->columnas),
        ])->all();
    }

    public function filas(ReportSpec $spec, User $user, int $limite): iterable
    {
        $query = $this->filasQuery($spec);
        for ($offset = 0; $offset < $limite; $offset += self::BLOQUE) {
            $bloque = (clone $query)->offset($offset)->limit(min(self::BLOQUE, $limite - $offset))->get();
            $responsables = $this->listing->responsables($bloque->pluck('id')->all());
            foreach ($bloque as $row) {
                yield $this->celdas($row, $responsables[$row->id] ?? [], $spec->columnas);
            }
            if ($bloque->count() < self::BLOQUE) {
                return;
            }
        }
    }

    private function filasQuery(ReportSpec $spec): Builder
    {
        $q = $this->consulta($spec->filtros, $spec->busqueda)->reorder();
        if (in_array('grupo', $spec->columnas, true)) {
            $q->leftJoin('campaigns as cg', 'cg.id', '=', 'c.campaign_id')->addSelect('cg.name as grupo');
        }
        $abiertos = "CASE WHEN c.estado IN ('pendiente', 'en_curso') THEN 0 ELSE 1 END";
        $sinFecha = 'CASE WHEN c.fecha_limite IS NULL THEN 1 ELSE 0 END';

        match ($spec->ordenCampo) {
            'estado' => $q->orderByRaw($abiertos)->orderByRaw($sinFecha)->orderBy('c.fecha_limite'),
            'municipio' => $q->orderByRaw('m.canonical_name asc'.self::nullsLast())->orderByRaw($sinFecha)->orderBy('c.fecha_limite'),
            default => $q->orderByRaw($sinFecha)->orderBy('c.fecha_limite', $spec->ordenDir === 'desc' ? 'desc' : 'asc'),
        };

        return $q->orderByDesc('a.fecha')->orderBy('c.id');
    }

    private function celdas(object $row, array $responsables, array $columnas): array
    {
        $out = [];
        foreach ($columnas as $col) {
            $out[$col] = match ($col) {
                'estado' => EventCompromiso::LABELS[$row->estado] ?? $row->estado,
                'vencimiento' => self::vencimiento($row),
                'fecha_limite' => self::fechaCorta($row->fecha_limite),
                'responsables' => self::texto(implode(' / ', array_filter(array_column($responsables, 'nombre')))),
                'telefono_responsable' => CompromisoListing::telefonosConNombre($responsables),
                'origen' => $row->event_id ? self::ORIGENES['evento'] : self::ORIGENES['organizacion'],
                'origen_nombre' => $row->event_id ? $row->evento : $row->organizacion,
                'acta' => self::texto(trim(($row->acta_titulo ?? '').($row->acta_fecha ? ' ('.self::fechaCorta($row->acta_fecha).')' : ''))),
                default => self::texto($row->{$col} ?? null),
            };
        }

        return $out;
    }

    private static function vencimiento(object $row): ?string
    {
        if (CompromisoListing::isVencido($row)) {
            return 'Vencido';
        }
        if ($row->fecha_limite === null || ! in_array($row->estado, EventCompromiso::ABIERTOS, true)) {
            return null;
        }
        [$hoy, $despues] = self::porVencerHasta();
        $limite = substr((string) $row->fecha_limite, 0, 10);

        return $limite >= $hoy && $limite < $despues ? 'Por vencer' : null;
    }

    // ---------------------------------------------------------------- words

    public function describir(ReportSpec $spec, User $user): array
    {
        $f = $spec->filtros;
        $partes = [];
        if (isset($f['estado'])) {
            $partes[] = self::nombres($f['estado'], EventCompromiso::LABELS);
        }
        if (isset($f['vencimiento'])) {
            $partes[] = self::nombres($f['vencimiento'], self::VENCIMIENTOS);
        }
        if (isset($f['fecha_limite'])) {
            $partes[] = self::fechasEnPalabras('Fecha límite', $f['fecha_limite']);
        }
        if (isset($f['responsable'])) {
            $partes[] = 'Responsable '.implode(' o ', $f['responsable']);
        }
        if (isset($f['origen'])) {
            $partes[] = 'De '.mb_strtolower(self::nombres($f['origen'], ['evento' => 'Eventos', 'organizacion' => 'Organizaciones']));
        }
        if (isset($f['municipio'])) {
            $partes[] = self::nombres($f['municipio'], $this->municipiosVisibles());
        }
        if (isset($f['grupo'])) {
            $partes[] = self::nombres($f['grupo'], $this->gruposVisibles());
        }
        if ($spec->busqueda !== '') {
            $partes[] = "Búsqueda «{$spec->busqueda}»";
        }

        return $partes;
    }

    // ---------------------------------------------------------------- options

    public function opciones(string $filtro, string $q, array $municipios, User $user): ?array
    {
        $q = mb_substr(trim($q), 0, 60);

        return match ($filtro) {
            'responsables' => self::contiene(
                DB::table('event_compromiso_responsables as r')
                    ->whereIn('r.compromiso_id', $this->base()->select('c.id'))
                    ->whereNotNull('r.nombre')->where('r.nombre', '!=', ''),
                'r.nombre', $q)
                ->distinct()->orderBy('r.nombre')->limit(self::OPCIONES_MAX)
                ->pluck('r.nombre')->map(fn ($n) => ['valor' => $n, 'etiqueta' => $n])->all(),
            'municipios' => collect($this->municipiosVisibles())
                ->filter(fn ($nombre) => self::coincide($nombre, $q))
                ->take(self::OPCIONES_MAX)->map(fn ($nombre, $id) => ['valor' => $id, 'etiqueta' => $nombre])->values()->all(),
            default => null,
        };
    }

    // ---------------------------------------------------------------- helpers

    private function base(): Builder
    {
        return $this->listing->query(['origen' => null] + array_fill_keys(CompromisoListing::FILTER_KEYS, null))->reorder();
    }

    private function origenesVisibles(User $user): array
    {
        return array_filter([
            'evento' => EventScope::canSeeAttendees($user) ? self::ORIGENES['evento'] : null,
            'organizacion' => OrganizacionScope::canView($user) ? self::ORIGENES['organizacion'] : null,
        ]);
    }

    // [id => nombre] of the municipios of the visible compromisos.
    private function municipiosVisibles(): array
    {
        return $this->memo('municipios', fn () => DB::query()->fromSub($this->base(), 'x')->whereNotNull('x.municipio_id')
            ->distinct()->orderBy('x.municipio')->pluck('x.municipio', 'x.municipio_id')->all());
    }
}
