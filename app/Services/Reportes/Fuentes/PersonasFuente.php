<?php

namespace App\Services\Reportes\Fuentes;

use App\Actions\Eventos\ApproveAttendees;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Http\Middleware\EnsureCanWrite;
use App\Models\Lider;
use App\Models\User;
use App\Services\CachedQueries;
use App\Services\Configuracion\Catalogo;
use App\Services\Eventos\EventScope;
use App\Services\Reportes\Catalogo\Columna;
use App\Services\Reportes\Catalogo\Filtro;
use App\Services\Reportes\ReporteService;
use App\Services\Reportes\ReportSpec;
use App\Support\MigracionFlags;
use App\Support\Provincias;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Every ficha (lideres): votantes, líderes, directorio, asistentes... The grupo político comes from Lider's global
// scope, the territory from the user's territory_scope; inactive and deceased people are left out by default.
// Filters: "O" inside one filter, "Y" between filters. Same rules as the Votantes screen where both have a filter
// (tests/Feature/ReportesPostgresTest: parity).
class PersonasFuente implements Fuente
{
    use ScopesTerritory;

    public const CLAVE = 'personas';

    public const TIPOS = [
        Lider::TIPO_VOTANTE => 'Votante',
        'directorio' => 'Líder',
        'concejo' => 'Concejo',
        'veeduria' => 'Veeduría',
        'nuevo_lider' => 'Nuevo líder',
        'candidato' => 'Candidato',
        ApproveAttendees::TIPO => 'Asistente a evento',
    ];

    public const MARCAS = [
        'gran_elector' => 'Gran elector',
        'militante' => 'Militante',
        'destacado' => 'Destacado',
        'verificado' => 'Verificado',
        'empresario' => 'Empresario',
        'funcionario' => 'Funcionario',
        'exfuncionario' => 'Exfuncionario',
        'reservista' => 'Reservista',
    ];

    private const ZONAS = ['urbana' => 'Urbana', 'rural' => 'Rural'];

    private const EDADES = [
        ['desde' => 18, 'hasta' => 25, 'etiqueta' => '18 a 25'],
        ['desde' => 26, 'hasta' => 40, 'etiqueta' => '26 a 40'],
        ['desde' => 41, 'hasta' => 60, 'etiqueta' => '41 a 60'],
        ['desde' => 61, 'hasta' => null, 'etiqueta' => 'Más de 60'],
    ];

    private const POR_COMPLETAR_EXTRA = ['cualquiera' => 'Cualquier dato', 'diferencias' => 'Diferencias con Electo'];

    private const ORIGENES = ['electo' => 'Vinieron de Electo', 'aqui' => 'Registradas aquí', 'evento' => 'Llegaron de un evento'];

    private const EVENTOS_MIN = [1 => 'A cualquier evento', 2 => 'A 2 o más', 3 => 'A 3 o más'];

    private const ESTADOS = ['activas' => 'Activas', 'inactivas' => 'Inactivas', 'todas' => 'Todas'];

    // Free-text filters (barrio, partido, profesión...): at most this many values, each this long.
    private const MAX_VALORES = 50;

    private const MAX_TEXTO = 120;

    public const OPCIONES_MAX = 50;

    // Downloads read the rows in blocks of this size (memory stays flat; docs/reportes.md, rendimiento).
    public const BLOQUE = 2000;

    private static array $columnasBd = [];

    // Per user (the instance is scoped to the request, but tests and queue workers reuse the container).
    private array $memo = [];

    public function clave(): string
    {
        return self::CLAVE;
    }

    public function etiqueta(): string
    {
        return 'Personas';
    }

    public function descripcion(): string
    {
        return 'Votantes, líderes, directorio y todos los que tienen ficha';
    }

    // Nobody sees more people here than in the screens they already have.
    public function disponible(User $user): bool
    {
        return $user->hasPermission('view.votantes') || $user->hasPermission('view.mapa');
    }

    // ---------------------------------------------------------------- catalog

    public function filtros(User $user): array
    {
        $eventos = EventScope::canSeeAttendees($user);
        $municipios = array_values($this->municipiosVisibles());
        $grupos = [];

        $grupos[] = $this->grupo('donde', '¿Dónde viven?', [
            new Filtro('provincia', 'Provincia', 'chips', $this->opcionesProvincia()),
            new Filtro('municipio', 'Municipio', 'buscar', array_map(fn ($m) => [
                'valor' => $m['id'], 'etiqueta' => $m['name'], 'provincia' => $m['provincia'],
            ], $municipios), placeholder: 'Buscar municipio…'),
            ...($this->comunasVisibles() ? [new Filtro('comuna', 'Comuna', 'chips', array_map(fn ($c) => [
                'valor' => $c['id'], 'etiqueta' => $c['nombre'], 'municipio' => $c['municipio_id'],
            ], array_values($this->comunasVisibles())), ayuda: 'Solo para los municipios que tienen comunas.')] : []),
            new Filtro('barrio', 'Barrio', 'buscar', opcionesDe: 'barrios', placeholder: 'Buscar barrio…'),
            new Filtro('zona', 'Zona', 'chips', self::lista(self::ZONAS)),
        ], abierto: true);

        $tipos = $eventos ? self::TIPOS : array_diff_key(self::TIPOS, [ApproveAttendees::TIPO => true]);
        $grupos[] = $this->grupo('que_son', '¿Qué son?', [
            new Filtro('tipo', 'Tipo', 'chips', self::lista($tipos)),
            new Filtro('marcas', 'Marcas', 'chips', self::lista(self::MARCAS)),
            new Filtro('nivel_confianza', 'Nivel de confianza', 'chips', $this->opcionesCatalogo('nivel_confianza')),
            new Filtro('partido', 'Partido', 'buscar', opcionesDe: 'partidos', placeholder: 'Buscar partido…'),
        ]);

        $grupos[] = $this->grupo('quien_los_trajo', '¿Quién los trajo?', [
            new Filtro('referente', 'Referente', 'buscar', opcionesDe: 'referentes', placeholder: 'Buscar por nombre…'),
            new Filtro('tiene_referidos', 'Tienen personas referidas', 'casilla', multiple: false),
        ]);

        $grupos[] = $this->grupo('edad_y_datos', 'Edad y datos personales', [
            new Filtro('edad', 'Edad', 'rango', array_map(fn ($e) => [
                'valor' => ['desde' => $e['desde'], 'hasta' => $e['hasta']], 'etiqueta' => $e['etiqueta'],
            ], self::EDADES), multiple: false, ayuda: 'Solo cuenta a quienes tienen fecha de nacimiento.'),
            new Filtro('genero', 'Género', 'chips', $this->opcionesCatalogo('genero')),
            new Filtro('profesion', 'Profesión', 'buscar', opcionesDe: 'profesiones', placeholder: 'Buscar profesión…'),
            new Filtro('escolaridad', 'Escolaridad', 'chips', $this->opcionesCatalogo('escolaridad')),
            new Filtro('estado_civil', 'Estado civil', 'chips', $this->opcionesCatalogo('estado_civil')),
        ]);

        $grupos[] = $this->grupo('autorizacion_estado', 'Autorización y estado', [
            new Filtro('autoriza', 'Autorizó sus datos', 'chips', self::lista(['si' => 'Sí', 'no' => 'No']), multiple: false,
                ayuda: 'Sin elegir, aparecen todos.'),
            new Filtro('estado', 'Estado', 'chips', self::lista(self::ESTADOS), multiple: false, porDefecto: 'activas'),
            new Filtro('incluir_fallecidos', 'Incluir fallecidos', 'casilla', multiple: false),
        ]);

        $completar = [];
        if (self::tieneColumna('migracion_flags')) {
            $completar[] = new Filtro('por_completar', 'Datos por completar', 'chips', self::lista(self::porCompletar()));
        }
        if (self::tieneColumna('origen')) {
            $completar[] = new Filtro('origen', 'Origen', 'chips', self::lista($eventos ? self::ORIGENES : array_diff_key(self::ORIGENES, ['evento' => true])));
        }
        if ($completar) {
            $grupos[] = $this->grupo('por_completar', 'Datos por completar', $completar);
        }

        if ($eventos) {
            $grupos[] = $this->grupo('eventos', 'Eventos', [
                new Filtro('evento', 'Asistieron a', 'buscar', opcionesDe: 'eventos', placeholder: 'Elegir evento…'),
                new Filtro('eventos_min', '¿A cuántos eventos?', 'chips', self::lista(self::EVENTOS_MIN), multiple: false),
            ]);
        }

        $campanas = $this->campaign()->visibleCampaigns();
        if (count($campanas) > 1) {
            $grupos[] = $this->grupo('grupo_politico', 'Grupo político', [
                new Filtro('grupo', 'Grupo político', 'chips', array_map(fn ($c) => ['valor' => $c['id'], 'etiqueta' => $c['name']], $campanas)),
            ]);
        }

        return $grupos;
    }

    private function grupo(string $clave, string $etiqueta, array $filtros, bool $abierto = false): array
    {
        return [
            'clave' => $clave,
            'etiqueta' => $etiqueta,
            'abierto' => $abierto,
            'filtros' => array_map(fn (Filtro $f) => $f->paraFrontend(), $filtros),
        ];
    }

    public function columnas(User $user): array
    {
        $c = [
            new Columna('nombre', 'Nombre', 'Identificación', siempre: true, ordenable: true),
            new Columna('tipo_documento', 'Tipo de documento', 'Identificación'),
            new Columna('cedula', 'Cédula', 'Identificación', sensible: true),
            new Columna('fecha_expedicion', 'Fecha de expedición', 'Identificación', sensible: true),
            new Columna('telefono', 'Teléfono', 'Contacto', sensible: true),
            new Columna('email', 'Email', 'Contacto', sensible: true),
            new Columna('direccion', 'Dirección', 'Contacto', sensible: true),
            new Columna('contactos', 'Todos los teléfonos y correos', 'Contacto', sensible: true),
            new Columna('redes', 'Redes sociales', 'Contacto', sensible: true),
            new Columna('municipio', 'Municipio', 'Ubicación', ordenable: true),
            new Columna('provincia', 'Provincia', 'Ubicación'),
            ...(self::tieneColumna('comuna_id') ? [new Columna('comuna', 'Comuna', 'Ubicación')] : []),
            new Columna('barrio', 'Barrio', 'Ubicación', ordenable: true),
            new Columna('zona', 'Zona', 'Ubicación'),
            new Columna('puesto_votacion', 'Puesto de votación', 'Votación'),
            new Columna('mesa_votacion', 'Mesa', 'Votación'),
            new Columna('municipio_votacion', 'Municipio de votación', 'Votación'),
            new Columna('genero', 'Género', 'Perfil'),
            new Columna('edad', 'Edad', 'Perfil', numero: true),
            new Columna('fecha_nacimiento', 'Fecha de nacimiento', 'Perfil', sensible: true),
            new Columna('profesion', 'Profesión', 'Perfil'),
            new Columna('escolaridad', 'Escolaridad', 'Perfil'),
            new Columna('estado_civil', 'Estado civil', 'Perfil'),
            new Columna('tipo', 'Tipo', 'En la campaña', ordenable: true),
            new Columna('cargo', 'Cargo', 'En la campaña'),
            new Columna('partido', 'Partido', 'En la campaña'),
            new Columna('nivel_confianza', 'Nivel de confianza', 'En la campaña'),
            new Columna('marcas', 'Marcas', 'En la campaña'),
            new Columna('votos', 'Votos', 'En la campaña', numero: true),
            new Columna('referente', 'Quién lo trajo', 'Referente'),
            new Columna('referente_documento', 'Cédula del referente', 'Referente', sensible: true),
            new Columna('referidos', 'Personas que ha traído', 'Referente', numero: true, ordenable: true),
        ];
        if (EventScope::canSeeAttendees($user)) {
            $c[] = new Columna('eventos', 'Eventos a los que asistió', 'Eventos');
            $c[] = new Columna('ultimo_evento', 'Último evento', 'Eventos');
        }
        $c[] = new Columna('autoriza_datos', 'Autorizó datos', 'Sistema');
        if (self::tieneColumna('origen')) {
            $c[] = new Columna('origen', 'Origen', 'Sistema');
        }
        if (self::tieneColumna('migracion_flags')) {
            $c[] = new Columna('por_completar', 'Datos por completar', 'Sistema');
        }
        $c[] = new Columna('grupo', 'Grupo político', 'Sistema');
        $c[] = new Columna('observacion', 'Observación', 'Sistema', sensible: true);

        return collect($c)->keyBy('clave')->all();
    }

    public function presets(User $user): array
    {
        $permitidas = array_keys(array_filter($this->columnas($user), fn (Columna $c) => ! $c->sensible || ReporteService::puedeDatosPersonales($user)));
        $solo = fn (array $cols) => array_values(array_intersect($cols, $permitidas));
        $conDatos = ReporteService::puedeDatosPersonales($user);

        return [
            ['clave' => 'contacto_basico', 'etiqueta' => 'Contacto básico', 'por_defecto' => $conDatos,
                'columnas' => $solo(['nombre', 'cedula', 'telefono', 'municipio', 'barrio', 'referente'])],
            ['clave' => 'nombre_lugar', 'etiqueta' => 'Solo nombre y lugar', 'por_defecto' => ! $conDatos,
                'columnas' => $solo(['nombre', 'municipio', 'barrio', 'tipo', 'nivel_confianza'])],
            ['clave' => 'completo', 'etiqueta' => 'Completo', 'por_defecto' => false, 'columnas' => $permitidas],
        ];
    }

    public function columnasPorDefecto(User $user): array
    {
        return collect($this->presets($user))->firstWhere('por_defecto', true)['columnas'];
    }

    public function atajos(User $user): array
    {
        $atajo = fn (string $clave, string $etiqueta, array $filtros) => [
            'clave' => $clave, 'etiqueta' => $etiqueta,
            'spec' => ['v' => ReportSpec::VERSION, 'fuente' => self::CLAVE, 'filtros' => $filtros,
                'columnas' => $this->columnasPorDefecto($user), 'orden' => ['campo' => 'nombre', 'dir' => 'asc'], 'busqueda' => ''],
        ];

        return array_values(array_filter([
            $atajo('gran_elector', 'Grandes electores', ['marcas' => ['gran_elector']]),
            $atajo('militante', 'Militantes', ['marcas' => ['militante']]),
            $atajo('destacado', 'Destacados', ['marcas' => ['destacado']]),
            self::tieneColumna('migracion_flags') ? $atajo('por_completar', 'Datos por completar', ['por_completar' => ['cualquiera']]) : null,
            EventScope::canSeeAttendees($user) ? $atajo('asistentes', 'Asistentes a eventos', ['eventos_min' => 1]) : null,
        ]));
    }

    // [{clave, dir, etiqueta}] the list can be sorted by.
    public static function ordenes(): array
    {
        return [
            ['clave' => 'nombre', 'dir' => 'asc', 'etiqueta' => 'Nombre A–Z'],
            ['clave' => 'nombre', 'dir' => 'desc', 'etiqueta' => 'Nombre Z–A'],
            ['clave' => 'municipio', 'dir' => 'asc', 'etiqueta' => 'Municipio'],
            ['clave' => 'barrio', 'dir' => 'asc', 'etiqueta' => 'Barrio'],
            ['clave' => 'tipo', 'dir' => 'asc', 'etiqueta' => 'Tipo'],
            ['clave' => 'recientes', 'dir' => 'desc', 'etiqueta' => 'Más recientes'],
            ['clave' => 'referidos', 'dir' => 'desc', 'etiqueta' => 'Más personas referidas'],
        ];
    }

    // ---------------------------------------------------------------- filters

    public function normalizarFiltros(array $filtros, User $user): array
    {
        $eventos = EventScope::canSeeAttendees($user);
        $out = [];

        $municipios = $this->municipiosVisibles();
        $provincias = array_flip(array_column($this->opcionesProvincia(), 'valor'));
        $comunas = $this->comunasVisibles();
        $tipos = $eventos ? self::TIPOS : array_diff_key(self::TIPOS, [ApproveAttendees::TIPO => true]);

        $out['provincia'] = self::enLista($filtros['provincia'] ?? null, $provincias);
        $out['municipio'] = self::enLista($filtros['municipio'] ?? null, $municipios);
        $out['comuna'] = self::enLista($filtros['comuna'] ?? null, $comunas);
        $out['barrio'] = self::textos($filtros['barrio'] ?? null);
        $out['zona'] = self::enLista($filtros['zona'] ?? null, self::ZONAS);
        $out['tipo'] = self::enLista($filtros['tipo'] ?? null, $tipos);
        $out['marcas'] = self::enLista($filtros['marcas'] ?? null, self::MARCAS);
        $out['nivel_confianza'] = self::enLista($filtros['nivel_confianza'] ?? null, array_flip(Catalogo::valores('nivel_confianza', true)));
        $out['partido'] = self::textos($filtros['partido'] ?? null);
        $referentes = $this->referentesVisibles(self::textos($filtros['referente'] ?? null));
        $out['referente'] = $referentes ? array_column($referentes, 'id') : null;
        $out['tiene_referidos'] = ($filtros['tiene_referidos'] ?? null) === true ? true : null;
        $out['edad'] = self::edad($filtros['edad'] ?? null);
        $out['genero'] = self::enLista($filtros['genero'] ?? null, array_flip(Catalogo::valores('genero', true)));
        $out['profesion'] = self::textos($filtros['profesion'] ?? null);
        $out['escolaridad'] = self::enLista($filtros['escolaridad'] ?? null, array_flip(Catalogo::valores('escolaridad', true)));
        $out['estado_civil'] = self::enLista($filtros['estado_civil'] ?? null, array_flip(Catalogo::valores('estado_civil', true)));
        $out['autoriza'] = in_array($filtros['autoriza'] ?? null, ['si', 'no'], true) ? $filtros['autoriza'] : null;
        $out['estado'] = in_array($filtros['estado'] ?? null, ['inactivas', 'todas'], true) ? $filtros['estado'] : null;
        $out['incluir_fallecidos'] = ($filtros['incluir_fallecidos'] ?? null) === true ? true : null;
        if (self::tieneColumna('migracion_flags')) {
            $out['por_completar'] = self::enLista($filtros['por_completar'] ?? null, self::porCompletar());
        }
        if (self::tieneColumna('origen')) {
            $out['origen'] = self::enLista($filtros['origen'] ?? null, $eventos ? self::ORIGENES : array_diff_key(self::ORIGENES, ['evento' => true]));
        }
        if ($eventos) {
            $out['evento'] = $this->eventosVisibles(self::textos($filtros['evento'] ?? null));
            $min = $filtros['eventos_min'] ?? null;
            $out['eventos_min'] = is_int($min) && isset(self::EVENTOS_MIN[$min]) ? $min : null;
        }
        $visibles = $this->campaign()->visibleCampaigns();
        if (count($visibles) > 1) {
            $out['grupo'] = self::enLista($filtros['grupo'] ?? null, array_flip(array_column($visibles, 'id')));
        }

        return array_filter($out, fn ($v) => $v !== null && $v !== []);
    }

    // Lider query with the user's scope and the (normalized) filters. Global scope = visible grupos políticos.
    private function consulta(array $f, string $busqueda): Builder
    {
        $q = Lider::query();
        $this->scopeToTerritory($q, 'lideres.geographic_unit_id');

        match ($f['estado'] ?? 'activas') {
            'inactivas' => $q->where('lideres.is_active', false),
            'todas' => null,
            default => $q->where('lideres.is_active', true),
        };
        if (empty($f['incluir_fallecidos'])) {
            $q->where('lideres.fallecido', false);
        }

        if (isset($f['provincia'])) {
            $ids = array_keys(array_filter($this->municipiosVisibles(), fn ($m) => in_array($m['provincia_valor'], $f['provincia'], true)));
            $q->whereIn('lideres.geographic_unit_id', $ids ?: ['00000000-0000-0000-0000-000000000000']);
        }
        if (isset($f['municipio'])) {
            $q->whereIn('lideres.geographic_unit_id', $f['municipio']);
        }
        if (isset($f['comuna'])) {
            $q->whereIn('lideres.comuna_id', $f['comuna']);
        }
        if (isset($f['barrio'])) {
            $q->whereIn('lideres.barrio', $f['barrio']);
        }
        if (isset($f['zona'])) {
            $q->whereIn('lideres.zona', $f['zona']);
        }
        if (isset($f['tipo'])) {
            $tipos = $f['tipo'];
            // Without view.eventos an attendee's ficha is shown as a Líder (EventScope::visibleTipo): it is filtered as one.
            if (in_array('directorio', $tipos, true) && ! EventScope::canSeeAttendees($this->user())) {
                $tipos[] = ApproveAttendees::TIPO;
            }
            $q->whereIn('lideres.tipo', $tipos);
        }
        if (isset($f['marcas'])) {
            $q->where(function ($w) use ($f) {
                foreach ($f['marcas'] as $marca) {
                    $w->orWhere("lideres.{$marca}", true);
                }
            });
        }
        if (isset($f['nivel_confianza'])) {
            $q->whereIn('lideres.nivel_confianza', $f['nivel_confianza']);
        }
        if (isset($f['partido'])) {
            $q->whereIn('lideres.partido', $f['partido']);
        }
        if (isset($f['referente'])) {
            $referentes = $this->referentesVisibles($f['referente']) ?? [['cedula' => '', 'campaign_id' => '00000000-0000-0000-0000-000000000000']];
            $q->where(function ($w) use ($referentes) {
                foreach ($referentes as $ref) {
                    $w->orWhere(fn ($x) => $x->where('lideres.referente_documento', $ref['cedula'])->where('lideres.campaign_id', $ref['campaign_id']));
                }
            });
        }
        if (isset($f['tiene_referidos'])) {
            $q->whereExists(fn ($r) => $this->referidosDe($r->from('lideres as r')->selectRaw('1')));
        }
        if (isset($f['edad'])) {
            $hoy = CarbonImmutable::now('America/Bogota')->startOfDay();
            $q->whereNotNull('lideres.fecha_nacimiento');
            if ($f['edad']['desde'] !== null) {
                $q->where('lideres.fecha_nacimiento', '<=', $hoy->subYears($f['edad']['desde'])->toDateString());
            }
            if ($f['edad']['hasta'] !== null) {
                $q->where('lideres.fecha_nacimiento', '>', $hoy->subYears($f['edad']['hasta'] + 1)->toDateString());
            }
        }
        foreach (['genero', 'profesion', 'escolaridad', 'estado_civil'] as $campo) {
            if (isset($f[$campo])) {
                $q->whereIn("lideres.{$campo}", $f[$campo]);
            }
        }
        if (isset($f['autoriza'])) {
            $q->where('lideres.autoriza_datos', $f['autoriza'] === 'si');
        }
        if (isset($f['por_completar'])) {
            // Each value with the very same clause as the Votantes filter, joined with O.
            $q->where(function ($w) use ($f) {
                foreach ($f['por_completar'] as $valor) {
                    $w->orWhere(fn ($x) => MigracionFlags::applyFilters($x, $valor === 'diferencias' ? ['diferencias' => true] : ['por_completar' => $valor]));
                }
            });
        }
        if (isset($f['origen'])) {
            $q->where(function ($w) use ($f) {
                foreach ($f['origen'] as $origen) {
                    match ($origen) {
                        'electo' => $w->orWhere('lideres.origen', 'electo'),
                        'evento' => $w->orWhere('lideres.tipo', ApproveAttendees::TIPO),
                        default => $w->orWhere(fn ($x) => $x->where(fn ($o) => $o->whereNull('lideres.origen')->orWhere('lideres.origen', '!=', 'electo'))
                            ->when(EventScope::canSeeAttendees($this->user()), fn ($t) => $t->where('lideres.tipo', '!=', ApproveAttendees::TIPO))),
                    };
                }
            });
        }
        if (isset($f['evento'])) {
            $q->whereExists(fn ($e) => app(EventScope::class)->attendanceOf($e, 'lideres.id')->whereIn('ea.event_id', $f['evento']));
        }
        if (isset($f['eventos_min'])) {
            if ($f['eventos_min'] === 1) {
                $q->whereExists(fn ($e) => app(EventScope::class)->attendanceOf($e, 'lideres.id'));
            } else {
                $q->whereIn('lideres.id', app(EventScope::class)->scopeEvents(DB::table('event_attendees as ea')
                    ->join('events as ev', 'ev.id', '=', 'ea.event_id')
                    ->whereNotNull('ea.lider_id')
                    ->groupBy('ea.lider_id')
                    ->havingRaw('count(distinct ea.event_id) >= ?', [$f['eventos_min']])
                    ->select('ea.lider_id')));
            }
        }
        if (isset($f['grupo'])) {
            $q->whereIn('lideres.campaign_id', $f['grupo']);
        }

        if ($busqueda !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $busqueda);
            // The cédula is personal data: only searched with export.datos_personales.
            $user = $this->user();
            $porCedula = $user !== null && ReporteService::puedeDatosPersonales($user);
            $q->where(fn ($w) => $w->where('lideres.nombre', self::like(), "%{$escaped}%")
                ->when($porCedula, fn ($x) => $x->orWhere('lideres.cedula', 'like', "%{$escaped}%")));
        }

        return $q;
    }

    // Who a ficha brought: active, same grupo político, inside the user's territory (as Votantes counts them).
    private function referidosDe($query)
    {
        $this->scopeToTerritory($query, 'r.geographic_unit_id');

        return $query->whereColumn('r.referente_documento', 'lideres.cedula')
            ->whereColumn('r.campaign_id', 'lideres.campaign_id')
            ->whereNotNull('r.referente_documento')
            ->where('r.referente_documento', '!=', '')
            ->where('r.is_active', true);
    }

    public function contar(ReportSpec $spec, User $user): int
    {
        return $this->consulta($spec->filtros, $spec->busqueda)->toBase()->count();
    }

    public function porMunicipio(ReportSpec $spec, User $user, int $primeros): array
    {
        $nombre = 'coalesce(gm.canonical_name, lideres.municipio)';
        $grupos = $this->consulta($spec->filtros, $spec->busqueda)->toBase()
            ->leftJoin('geographic_units as gm', 'gm.id', '=', 'lideres.geographic_unit_id')
            ->selectRaw("{$nombre} as nombre, count(*) as total")
            ->groupBy(DB::raw($nombre))
            ->orderByDesc('total')->orderBy('nombre')
            ->limit($primeros)
            ->get();

        $municipios = $grupos->map(fn ($g) => ['nombre' => self::texto($g->nombre) ?? 'Sin municipio', 'total' => (int) $g->total])->all();

        return ['municipios' => $municipios, 'otros' => max(0, $this->contar($spec, $user) - array_sum(array_column($municipios, 'total')))];
    }

    // ---------------------------------------------------------------- rows

    public function pagina(ReportSpec $spec, User $user, int $pagina, int $porPagina): array
    {
        $own = $this->campaign()->ownId();
        $puedeEscribir = in_array($user->role?->code, EnsureCanWrite::WRITE_ROLES, true) && $user->hasPermission('write.data');

        return $this->filasQuery($spec, $user)
            ->offset(($pagina - 1) * $porPagina)
            ->limit($porPagina)
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                // A subgrupo's ficha is read-only (ScopesTerritory::authorizeCampaign), and so is any ficha without write rights.
                'editable' => $puedeEscribir && ($own === null || $row->campaign_id === $own),
                'celdas' => $this->celdas($row, $spec->columnas, $user),
            ])->all();
    }

    public function filas(ReportSpec $spec, User $user, int $limite): iterable
    {
        $query = $this->filasQuery($spec, $user);
        for ($offset = 0; $offset < $limite; $offset += self::BLOQUE) {
            $bloque = (clone $query)->offset($offset)->limit(min(self::BLOQUE, $limite - $offset))->get();
            foreach ($bloque as $row) {
                yield $this->celdas($row, $spec->columnas, $user);
            }
            if ($bloque->count() < self::BLOQUE) {
                return;
            }
        }
    }

    private function filasQuery(ReportSpec $spec, User $user)
    {
        $q = $this->consulta($spec->filtros, $spec->busqueda)->toBase();
        $cols = array_flip($spec->columnas);
        $select = ['lideres.id', 'lideres.campaign_id', 'lideres.nombre'];

        $directas = [
            'tipo_documento' => ['tipo_documento'], 'cedula' => ['cedula'], 'fecha_expedicion' => ['fecha_expedicion_doc'],
            'telefono' => ['telefono'], 'email' => ['email'], 'direccion' => ['direccion'], 'redes' => ['facebook', 'twitter', 'instagram'],
            'barrio' => ['barrio'], 'zona' => ['zona'],
            'puesto_votacion' => ['puesto_votacion'], 'mesa_votacion' => ['mesa_votacion'], 'municipio_votacion' => ['municipio_votacion'],
            'genero' => ['genero'], 'edad' => ['fecha_nacimiento'], 'fecha_nacimiento' => ['fecha_nacimiento'], 'profesion' => ['profesion'],
            'escolaridad' => ['escolaridad'], 'estado_civil' => ['estado_civil'], 'tipo' => ['tipo'], 'cargo' => ['cargo'],
            'partido' => ['partido'], 'nivel_confianza' => ['nivel_confianza'], 'marcas' => array_keys(self::MARCAS), 'votos' => ['votos'],
            'referente' => ['referente_nombre', 'referente_apellido', 'referente_documento'], 'referente_documento' => ['referente_documento'],
            'autoriza_datos' => ['autoriza_datos'], 'origen' => ['origen', 'tipo'], 'por_completar' => ['migracion_flags'],
            'observacion' => ['observacion'],
        ];
        foreach ($directas as $clave => $campos) {
            if (isset($cols[$clave])) {
                foreach ($campos as $campo) {
                    $select[] = "lideres.{$campo}";
                }
            }
        }
        $q->select(array_values(array_unique($select)));

        // Municipio and provincia from the ficha's geographic unit («Giron» and «Girón» typed by hand are one municipio);
        // the stored text only when the ficha has no unit.
        if (isset($cols['municipio']) || isset($cols['provincia']) || $spec->ordenCampo === 'municipio') {
            $q->leftJoin('geographic_units as gm', 'gm.id', '=', 'lideres.geographic_unit_id')
                ->addSelect(DB::raw('coalesce(gm.canonical_name, lideres.municipio) as municipio_nombre'));
        }
        if (isset($cols['provincia'])) {
            $q->leftJoin('geographic_units as gp', fn ($j) => $j->on('gp.id', '=', 'gm.parent_id')->where('gp.type', '=', 'province'))
                ->addSelect(DB::raw('coalesce(gp.canonical_name, lideres.provincia) as provincia_nombre'));
        }
        if (isset($cols['comuna'])) {
            $q->leftJoin('geographic_units as gc', 'gc.id', '=', 'lideres.comuna_id')->addSelect('gc.canonical_name as comuna_nombre');
        }
        if (isset($cols['grupo'])) {
            $q->leftJoin('campaigns as cg', 'cg.id', '=', 'lideres.campaign_id')->addSelect('cg.name as grupo_nombre');
        }
        if (isset($cols['referente'])) {
            $q->selectSub(DB::table('lideres as rn')->select('rn.nombre')
                ->whereColumn('rn.campaign_id', 'lideres.campaign_id')->whereColumn('rn.cedula', 'lideres.referente_documento')
                ->limit(1), 'referente_ficha');
        }
        if (isset($cols['referidos']) || $spec->ordenCampo === 'referidos') {
            $grupo = $this->scopeToTerritory(DB::table('lideres as r'), 'r.geographic_unit_id')
                ->where('r.is_active', true)->whereNotNull('r.referente_documento')->where('r.referente_documento', '!=', '')
                ->groupBy('r.campaign_id', 'r.referente_documento')
                ->select('r.campaign_id', 'r.referente_documento', DB::raw('count(*) as n'));
            $q->leftJoinSub($grupo, 'rc', fn ($j) => $j->on('rc.campaign_id', '=', 'lideres.campaign_id')->on('rc.referente_documento', '=', 'lideres.cedula'))
                ->addSelect(DB::raw('coalesce(rc.n, 0) as referidos'));
        }
        if (isset($cols['contactos'])) {
            $q->selectSub(DB::table('lider_contactos as lc')->whereColumn('lc.lider_id', 'lideres.id')
                ->selectRaw(self::agregar("lc.tipo || ':' || lc.valor")), 'contactos_raw');
        }
        if (isset($cols['eventos'])) {
            $q->selectSub(app(EventScope::class)->scopeEvents(DB::table('event_attendees as ea')->join('events as ev', 'ev.id', '=', 'ea.event_id')
                ->whereColumn('ea.lider_id', 'lideres.id')->selectRaw(self::agregar('ev.nombre'))), 'eventos_raw');
        }
        if (isset($cols['ultimo_evento'])) {
            $ultimo = fn (string $campo) => app(EventScope::class)->scopeEvents(DB::table('event_attendees as ea')->join('events as ev', 'ev.id', '=', 'ea.event_id')
                ->whereColumn('ea.lider_id', 'lideres.id')->orderByDesc('ev.starts_at')->limit(1)->select($campo));
            $q->selectSub($ultimo('ev.nombre'), 'ultimo_evento_nombre')->selectSub($ultimo('ev.starts_at'), 'ultimo_evento_fecha');
        }

        $dir = $spec->ordenDir;
        match ($spec->ordenCampo) {
            'municipio' => $q->orderBy('municipio_nombre', $dir)->orderBy('lideres.nombre'),
            'barrio', 'tipo' => $q->orderByRaw("lideres.{$spec->ordenCampo} ".($dir === 'desc' ? 'desc' : 'asc').(self::esPg() ? ' nulls last' : ''))->orderBy('lideres.nombre'),
            'recientes' => $q->orderByRaw('lideres.created_at '.($dir === 'asc' ? 'asc' : 'desc').(self::esPg() ? ' nulls last' : '')),
            'referidos' => $q->orderBy('referidos', $dir === 'asc' ? 'asc' : 'desc')->orderBy('lideres.nombre'),
            default => $q->orderBy('lideres.nombre', $dir),
        };

        return $q->orderBy('lideres.id');
    }

    // The cells of one row, ready to show (labels, dd/mm/aaaa dates, "Sí"/"No"); null = no data.
    public function celdas(object $row, array $columnas, User $user): array
    {
        $out = [];
        foreach ($columnas as $col) {
            $out[$col] = match ($col) {
                'nombre' => $row->nombre,
                'fecha_expedicion' => self::fecha($row->fecha_expedicion_doc ?? null),
                'redes' => self::redes($row),
                'contactos' => $this->contactos($row->contactos_raw ?? null),
                'municipio' => $row->municipio_nombre ?? null,
                'provincia' => Provincias::display($row->provincia_nombre ?? null),
                'comuna' => $row->comuna_nombre ?? null,
                'zona' => self::ZONAS[$row->zona ?? ''] ?? ($row->zona ?? null),
                'genero' => Catalogo::etiqueta('genero', $row->genero ?? null),
                'edad' => self::edadDe($row->fecha_nacimiento ?? null),
                'fecha_nacimiento' => self::fecha($row->fecha_nacimiento ?? null),
                'escolaridad' => Catalogo::etiqueta('escolaridad', $row->escolaridad ?? null),
                'estado_civil' => Catalogo::etiqueta('estado_civil', $row->estado_civil ?? null),
                'tipo' => self::tipoEtiqueta(EventScope::visibleTipo($row->tipo ?? null, $user)),
                'nivel_confianza' => Catalogo::etiqueta('nivel_confianza', $row->nivel_confianza ?? null),
                'marcas' => self::marcas($row),
                'votos' => isset($row->votos) ? (int) $row->votos : null,
                'referente' => self::referente($row),
                'referidos' => (int) ($row->referidos ?? 0),
                'eventos' => self::nombres($row->eventos_raw ?? null),
                'ultimo_evento' => ($row->ultimo_evento_nombre ?? null)
                    ? $row->ultimo_evento_nombre.' ('.self::fecha(EventScope::iso($row->ultimo_evento_fecha)).')' : null,
                'autoriza_datos' => self::si($row->autoriza_datos ?? null) ? 'Sí' : 'No',
                'origen' => $this->origenEtiqueta($row, $user),
                'por_completar' => self::porCompletarDe($row->migracion_flags ?? null),
                'grupo' => $row->grupo_nombre ?? null,
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
        $nombres = fn (array $valores, array $mapa) => implode(' o ', array_map(fn ($v) => $mapa[$v] ?? $v, $valores));

        if (isset($f['tipo'])) {
            $partes[] = $nombres($f['tipo'], self::TIPOS);
        }
        if (isset($f['marcas'])) {
            $partes[] = $nombres($f['marcas'], self::MARCAS);
        }
        if (isset($f['provincia'])) {
            $partes[] = $nombres($f['provincia'], array_column($this->opcionesProvincia(), 'etiqueta', 'valor'));
        }
        if (isset($f['municipio'])) {
            $partes[] = $nombres($f['municipio'], array_column($this->municipiosVisibles(), 'name', 'id'));
        }
        if (isset($f['comuna'])) {
            $partes[] = $nombres($f['comuna'], array_column($this->comunasVisibles(), 'nombre', 'id'));
        }
        if (isset($f['barrio'])) {
            $partes[] = 'Barrio '.implode(' o ', $f['barrio']);
        }
        if (isset($f['zona'])) {
            $partes[] = 'Zona '.mb_strtolower($nombres($f['zona'], self::ZONAS));
        }
        if (isset($f['nivel_confianza'])) {
            $partes[] = 'Confianza: '.$nombres($f['nivel_confianza'], Catalogo::mapa('nivel_confianza'));
        }
        if (isset($f['partido'])) {
            $partes[] = 'Partido '.implode(' o ', $f['partido']);
        }
        if (isset($f['referente'])) {
            $partes[] = 'Traídos por '.implode(' o ', array_column($this->referentesVisibles($f['referente']) ?? [], 'nombre'));
        }
        if (isset($f['tiene_referidos'])) {
            $partes[] = 'Tienen personas referidas';
        }
        if (isset($f['edad'])) {
            $e = $f['edad'];
            $partes[] = match (true) {
                $e['hasta'] === null => "Desde {$e['desde']} años",
                $e['desde'] === null => "Hasta {$e['hasta']} años",
                default => "De {$e['desde']} a {$e['hasta']} años",
            };
        }
        if (isset($f['genero'])) {
            $partes[] = $nombres($f['genero'], Catalogo::mapa('genero'));
        }
        if (isset($f['profesion'])) {
            $partes[] = 'Profesión '.implode(' o ', $f['profesion']);
        }
        if (isset($f['escolaridad'])) {
            $partes[] = $nombres($f['escolaridad'], Catalogo::mapa('escolaridad'));
        }
        if (isset($f['estado_civil'])) {
            $partes[] = $nombres($f['estado_civil'], Catalogo::mapa('estado_civil'));
        }
        if (isset($f['autoriza'])) {
            $partes[] = $f['autoriza'] === 'si' ? 'Autorizaron sus datos' : 'No autorizaron sus datos';
        }
        if (isset($f['estado'])) {
            $partes[] = $f['estado'] === 'inactivas' ? 'Inactivas' : 'Activas e inactivas';
        }
        if (isset($f['incluir_fallecidos'])) {
            $partes[] = 'Con fallecidos';
        }
        if (isset($f['por_completar'])) {
            $partes[] = 'Por completar: '.mb_strtolower($nombres($f['por_completar'], self::porCompletar()));
        }
        if (isset($f['origen'])) {
            $partes[] = $nombres($f['origen'], self::ORIGENES);
        }
        if (isset($f['evento'])) {
            $partes[] = 'Asistieron a '.implode(' o ', app(EventScope::class)->events()->whereIn('ev.id', $f['evento'])->pluck('ev.nombre')->all());
        }
        if (isset($f['eventos_min'])) {
            $partes[] = $f['eventos_min'] === 1 ? 'Asistieron a eventos' : "Asistieron a {$f['eventos_min']} o más eventos";
        }
        if (isset($f['grupo'])) {
            $partes[] = $nombres($f['grupo'], array_column($this->campaign()->visibleCampaigns(), 'name', 'id'));
        }
        if ($spec->busqueda !== '') {
            $partes[] = self::busquedaEnPalabras($spec->busqueda);
        }

        return $partes;
    }

    // A search that looks like a document (5 or more digits) is never printed whole: file, cover and title.
    public static function busquedaEnPalabras(string $busqueda): string
    {
        $digitos = preg_replace('/\D/', '', $busqueda);
        if (strlen($digitos) >= 5) {
            return 'Búsqueda por documento terminado en '.substr($digitos, -4);
        }

        return "Búsqueda «{$busqueda}»";
    }

    // ---------------------------------------------------------------- options

    public function opciones(string $filtro, string $q, array $municipios, User $user): ?array
    {
        $q = mb_substr(trim($q), 0, 60);
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
        $visibles = $this->municipiosVisibles();
        $municipios = array_values(array_filter($municipios, fn ($m) => is_string($m) && isset($visibles[$m])));

        $distintos = function (string $campo) use ($escaped, $municipios) {
            $query = $this->scopeToTerritory(Lider::query()->toBase(), 'lideres.geographic_unit_id')
                ->whereNotNull("lideres.{$campo}")->where("lideres.{$campo}", '!=', '')
                ->when($municipios, fn ($x) => $x->whereIn('lideres.geographic_unit_id', $municipios))
                ->when($escaped !== '', fn ($x) => $x->where("lideres.{$campo}", self::like(), "%{$escaped}%"))
                ->distinct()->orderBy("lideres.{$campo}")->limit(self::OPCIONES_MAX);

            return $query->pluck("lideres.{$campo}")->map(fn ($v) => ['valor' => $v, 'etiqueta' => $v])->all();
        };

        return match ($filtro) {
            'barrios' => $distintos('barrio'),
            'partidos' => $distintos('partido'),
            'profesiones' => $distintos('profesion'),
            'referentes' => mb_strlen($q) < 2 ? [] : $this->scopeToTerritory(Lider::query()->toBase(), 'lideres.geographic_unit_id')
                ->whereNotNull('lideres.cedula')->where('lideres.cedula', '!=', '')->where('lideres.is_active', true)
                ->where('lideres.nombre', self::like(), "%{$escaped}%")
                ->when($municipios, fn ($x) => $x->whereIn('lideres.geographic_unit_id', $municipios))
                ->orderBy('lideres.nombre')->orderBy('lideres.id')->limit(self::OPCIONES_MAX)
                ->get(['lideres.id', 'lideres.nombre', 'lideres.municipio'])
                ->map(fn ($r) => ['valor' => $r->id, 'etiqueta' => $r->nombre.($r->municipio ? ' · '.$r->municipio : '')])->all(),
            'eventos' => ! EventScope::canSeeAttendees($user) ? null : app(EventScope::class)->events()
                ->leftJoin('geographic_units as m', 'm.id', '=', 'ev.municipio_id')
                ->when($escaped !== '', fn ($x) => $x->where('ev.nombre', self::like(), "%{$escaped}%"))
                ->orderByDesc('ev.starts_at')->limit(self::OPCIONES_MAX)
                ->get(['ev.id', 'ev.nombre', 'ev.starts_at', 'm.canonical_name as municipio'])
                ->map(fn ($e) => ['valor' => $e->id, 'etiqueta' => $e->nombre.' · '.self::fecha(EventScope::iso($e->starts_at)).($e->municipio ? ' · '.$e->municipio : '')])->all(),
            default => null,
        };
    }

    // ---------------------------------------------------------------- helpers

    // [id => {id, name, provincia (shown), provincia_valor}] the user may filter by: visible grupos and territory.
    private function municipiosVisibles(): array
    {
        $key = $this->memoKey('municipios');
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        $allowed = $this->allowedGeoIds();
        $out = [];
        foreach (CachedQueries::filterMunicipios($this->campaign()->visibleIds()) as $m) {
            if ($allowed !== null && ! in_array($m['id'], $allowed, true)) {
                continue;
            }
            $prov = $m['provincia'] ?? 'Sin provincia';
            $out[$m['id']] = ['id' => $m['id'], 'name' => $m['name'], 'provincia' => Provincias::display($prov), 'provincia_valor' => $prov];
        }

        return $this->memo[$key] = $out;
    }

    private function opcionesProvincia(): array
    {
        $out = [];
        foreach ($this->municipiosVisibles() as $m) {
            $out[$m['provincia_valor']] = ['valor' => $m['provincia_valor'], 'etiqueta' => $m['provincia']];
        }
        uasort($out, fn ($a, $b) => strcmp($a['etiqueta'], $b['etiqueta']));

        return array_values($out);
    }

    // [id => {id, nombre, municipio_id}] comunas and corregimientos of the visible municipios.
    private function comunasVisibles(): array
    {
        $key = $this->memoKey('comunas');
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        if (! self::tieneColumna('comuna_id')) {
            return $this->memo[$key] = [];
        }

        // Comunas before corregimientos, in natural order: «Comuna 2» before «Comuna 13».
        return $this->memo[$key] = DB::table('geographic_units')
            ->whereIn('type', ['comuna', 'corregimiento'])
            ->whereIn('parent_id', array_keys($this->municipiosVisibles()) ?: ['00000000-0000-0000-0000-000000000000'])
            ->get(['id', 'canonical_name', 'parent_id', 'type'])
            ->sort(fn ($a, $b) => ($a->type === 'comuna' ? 0 : 1) <=> ($b->type === 'comuna' ? 0 : 1)
                ?: strnatcasecmp($a->canonical_name, $b->canonical_name))
            ->mapWithKeys(fn ($c) => [$c->id => ['id' => $c->id, 'nombre' => $c->canonical_name, 'municipio_id' => $c->parent_id]])
            ->all();
    }

    // Referente filter: ids of fichas the user can see (grupo and territory); the server resolves their cédula.
    private function referentesVisibles(?array $ids): ?array
    {
        $ids = array_values(array_filter($ids ?? [], fn ($id) => Str::isUuid($id)));
        if (! $ids) {
            return null;
        }
        $key = $this->memoKey('referentes:'.implode(',', $ids));

        return $this->memo[$key] ??= $this->scopeToTerritory(Lider::query()->toBase(), 'lideres.geographic_unit_id')
            ->whereIn('lideres.id', $ids)->whereNotNull('lideres.cedula')->where('lideres.cedula', '!=', '')
            ->get(['lideres.id', 'lideres.cedula', 'lideres.campaign_id', 'lideres.nombre'])
            ->map(fn ($r) => ['id' => $r->id, 'cedula' => $r->cedula, 'campaign_id' => $r->campaign_id, 'nombre' => $r->nombre])
            ->values()->all() ?: null;
    }

    private function eventosVisibles(?array $ids): ?array
    {
        $ids = array_values(array_filter($ids ?? [], fn ($id) => Str::isUuid($id)));
        if (! $ids) {
            return null;
        }

        return app(EventScope::class)->events()->whereIn('ev.id', $ids)->pluck('ev.id')->all() ?: null;
    }

    private function opcionesCatalogo(string $catalogo): array
    {
        return array_map(fn ($o) => ['valor' => $o['valor'], 'etiqueta' => $o['etiqueta']], Catalogo::opciones($catalogo));
    }

    private static function porCompletar(): array
    {
        return ['cualquiera' => self::POR_COMPLETAR_EXTRA['cualquiera']] + MigracionFlags::POR_COMPLETAR + ['diferencias' => self::POR_COMPLETAR_EXTRA['diferencias']];
    }

    private static function lista(array $mapa): array
    {
        return array_map(fn ($valor, $etiqueta) => ['valor' => $valor, 'etiqueta' => $etiqueta], array_keys($mapa), $mapa);
    }

    // Values of a list filter that are keys of $permitidos; anything else is dropped. null when nothing is left.
    private static function enLista(mixed $valores, array $permitidos): ?array
    {
        if (! is_array($valores)) {
            return null;
        }
        $out = [];
        foreach ($valores as $v) {
            if ((is_string($v) || is_int($v)) && array_key_exists($v, $permitidos)) {
                $out[] = (string) $v;
            }
        }

        return $out ? array_values(array_unique($out)) : null;
    }

    private static function textos(mixed $valores): ?array
    {
        if (! is_array($valores)) {
            return null;
        }
        $out = [];
        foreach (array_slice($valores, 0, self::MAX_VALORES) as $v) {
            if (is_string($v) && trim($v) !== '') {
                $out[] = mb_substr(trim($v), 0, self::MAX_TEXTO);
            }
        }

        return $out ? array_values(array_unique($out)) : null;
    }

    private static function edad(mixed $valor): ?array
    {
        if (! is_array($valor)) {
            return null;
        }
        $num = fn ($v) => is_int($v) && $v >= 0 && $v <= 120 ? $v : null;
        $desde = $num($valor['desde'] ?? null);
        $hasta = $num($valor['hasta'] ?? null);
        if ($desde === null && $hasta === null) {
            return null;
        }
        if ($desde !== null && $hasta !== null && $desde > $hasta) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        return ['desde' => $desde, 'hasta' => $hasta];
    }

    private static function tieneColumna(string $columna): bool
    {
        return self::$columnasBd[$columna] ??= Schema::hasColumn('lideres', $columna);
    }

    // Forget the cached schema checks (tests build their own tables).
    public static function olvidarEsquema(): void
    {
        self::$columnasBd = [];
    }

    private static function esPg(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private static function like(): string
    {
        return self::esPg() ? 'ilike' : 'like';
    }

    // Values of a subquery joined by the unit separator (parsed back in PHP).
    private static function agregar(string $expresion): string
    {
        return self::esPg() ? "string_agg({$expresion}, chr(31))" : "group_concat({$expresion}, char(31))";
    }

    private static function si(mixed $v): bool
    {
        return in_array($v, [true, 1, '1', 't', 'true'], true);
    }

    private static function texto(mixed $v): ?string
    {
        return $v === null || $v === '' ? null : (string) $v;
    }

    private static function fecha(mixed $v): ?string
    {
        if (! $v) {
            return null;
        }
        try {
            return CarbonImmutable::parse((string) $v)->format('d/m/Y');
        } catch (\Throwable) {
            return null;
        }
    }

    private static function edadDe(mixed $v): ?int
    {
        if (! $v) {
            return null;
        }
        try {
            $nacimiento = CarbonImmutable::parse(substr((string) $v, 0, 10), 'America/Bogota')->startOfDay();
        } catch (\Throwable) {
            return null;
        }
        $hoy = CarbonImmutable::now('America/Bogota')->startOfDay();

        return $nacimiento > $hoy ? null : $nacimiento->diff($hoy)->y;
    }

    public static function tipoEtiqueta(?string $tipo): ?string
    {
        return $tipo === null ? null : (self::TIPOS[$tipo] ?? Str::ucfirst(str_replace('_', ' ', $tipo)));
    }

    private static function marcas(object $row): ?string
    {
        $out = [];
        foreach (self::MARCAS as $campo => $etiqueta) {
            if (self::si($row->{$campo} ?? null)) {
                $out[] = $etiqueta;
            }
        }

        return $out ? implode(', ', $out) : null;
    }

    private static function redes(object $row): ?string
    {
        $out = [];
        foreach (['facebook' => 'Facebook', 'twitter' => 'X', 'instagram' => 'Instagram'] as $campo => $etiqueta) {
            if (($row->{$campo} ?? '') !== '' && $row->{$campo} !== null) {
                $out[] = "{$etiqueta}: {$row->{$campo}}";
            }
        }

        return $out ? implode(' · ', $out) : null;
    }

    private function contactos(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $out = [];
        foreach (explode(chr(31), $raw) as $par) {
            [$tipo, $valor] = array_pad(explode(':', $par, 2), 2, '');
            if ($valor !== '') {
                $out[] = Catalogo::etiqueta('tipo_contacto', $tipo).': '.$valor;
            }
        }

        return $out ? implode(', ', array_unique($out)) : null;
    }

    private static function nombres(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $nombres = array_unique(explode(chr(31), $raw));
        sort($nombres);

        return implode(', ', $nombres);
    }

    private static function referente(object $row): ?string
    {
        if (! empty($row->referente_ficha)) {
            return $row->referente_ficha;
        }
        $nombre = trim(($row->referente_nombre ?? '').' '.($row->referente_apellido ?? ''));

        return $nombre !== '' ? $nombre : null;
    }

    private function origenEtiqueta(object $row, User $user): string
    {
        return match (true) {
            ($row->origen ?? null) === 'electo' => 'Electo',
            ($row->tipo ?? null) === ApproveAttendees::TIPO && EventScope::canSeeAttendees($user) => 'Evento',
            default => 'Registrada aquí',
        };
    }

    private static function porCompletarDe(mixed $raw): ?string
    {
        $flags = MigracionFlags::decode($raw);
        $out = [];
        foreach ($flags['por_completar'] ?? [] as $campo) {
            $out[] = MigracionFlags::POR_COMPLETAR[$campo] ?? $campo;
        }
        if (! empty($flags['sin_municipio'])) {
            $out[] = 'Municipio';
        }
        if (in_array('fecha_nacimiento', (array) ($flags['fecha_invalida'] ?? []), true)) {
            $out[] = 'Fecha de nacimiento';
        }
        if (! empty($flags['contradicciones'])) {
            $out[] = 'Diferencias con Electo';
        }

        return $out ? implode(', ', array_unique($out)) : null;
    }

    private function memoKey(string $what): string
    {
        return ($this->user()?->id ?? '-').'|'.$what;
    }

    private function user(): ?User
    {
        return $this->campaign()->user();
    }
}
