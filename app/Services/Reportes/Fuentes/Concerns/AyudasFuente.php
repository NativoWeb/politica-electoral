<?php

namespace App\Services\Reportes\Fuentes\Concerns;

use App\Http\Middleware\EnsureCanWrite;
use App\Models\User;
use App\Services\Reportes\Catalogo\Filtro;
use App\Services\Reportes\ReporteService;
use App\Services\Reportes\ReportSpec;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// What the B-3 fuentes share (docs/reportes.md): filter groups, the white-list normalizers, the "fechas" filter and
// the cell formats. The class using it also uses ScopesTerritory (campaign(), scopeToTerritory()).
trait AyudasFuente
{
    // Relative periods of a "fechas" filter, computed on the day of the request (Bogotá).
    public const RELATIVOS = ['ultimos_7' => 'Última semana', 'ultimos_30' => 'Último mes', 'proximos_7' => 'Próximos 7 días'];

    private const MAX_VALORES = 50;

    private const MAX_TEXTO = 120;

    public const OPCIONES_MAX = 50;

    public const BLOQUE = 2000;

    private const NINGUNO = '00000000-0000-0000-0000-000000000000';

    private array $memo = [];

    private function grupo(string $clave, string $etiqueta, array $filtros, bool $abierto = false): array
    {
        return [
            'clave' => $clave,
            'etiqueta' => $etiqueta,
            'abierto' => $abierto,
            'filtros' => array_map(fn (Filtro $f) => $f->paraFrontend(), $filtros),
        ];
    }

    private function filtroGrupoPolitico(): ?array
    {
        $campanas = $this->campaign()->visibleCampaigns();
        if (count($campanas) < 2) {
            return null;
        }

        return $this->grupo('grupo_politico', 'Grupo político', [
            new Filtro('grupo', 'Grupo político', 'chips', array_map(fn ($c) => ['valor' => $c['id'], 'etiqueta' => $c['name']], $campanas)),
        ]);
    }

    private function veVariosGrupos(): bool
    {
        return count($this->campaign()->visibleCampaigns()) > 1;
    }

    private function gruposVisibles(): array
    {
        return array_column($this->campaign()->visibleCampaigns(), 'name', 'id');
    }

    private function filtroFechas(string $clave, string $etiqueta, array $relativos, ?string $ayuda = null): Filtro
    {
        return new Filtro($clave, $etiqueta, 'fechas', array_map(fn ($r) => ['valor' => ['relativo' => $r], 'etiqueta' => self::RELATIVOS[$r]], $relativos),
            multiple: false, ayuda: $ayuda);
    }

    private function atajo(string $clave, string $etiqueta, array $filtros, User $user, array $orden): array
    {
        return [
            'clave' => $clave,
            'etiqueta' => $etiqueta,
            'spec' => ['v' => ReportSpec::VERSION, 'fuente' => $this->clave(), 'filtros' => $filtros,
                'columnas' => $this->columnasPorDefecto($user, $filtros), 'orden' => $orden, 'busqueda' => ''],
        ];
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
        foreach (array_slice($valores, 0, 500) as $v) {
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

    private static function uuids(mixed $valores): array
    {
        return is_array($valores)
            ? array_values(array_unique(array_filter(array_slice($valores, 0, self::MAX_VALORES), fn ($v) => is_string($v) && Str::isUuid($v))))
            : [];
    }

    // A "fechas" filter: {desde, hasta} (AAAA-MM-DD, either may be null) or {relativo}. null when nothing valid is left.
    private static function fechas(mixed $valor, array $relativos): ?array
    {
        if (! is_array($valor)) {
            return null;
        }
        if (array_key_exists('relativo', $valor)) {
            return is_string($valor['relativo']) && in_array($valor['relativo'], $relativos, true) ? ['relativo' => $valor['relativo']] : null;
        }
        $desde = self::dia($valor['desde'] ?? null);
        $hasta = self::dia($valor['hasta'] ?? null);
        if ($desde === null && $hasta === null) {
            return null;
        }
        if ($desde !== null && $hasta !== null && $desde > $hasta) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        return ['desde' => $desde, 'hasta' => $hasta];
    }

    private static function dia(mixed $v): ?string
    {
        if (! is_string($v) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return $v;
    }

    // [desde, hasta] as AAAA-MM-DD (Bogotá), either null, for a normalized "fechas" value.
    private static function rango(array $f): array
    {
        if (isset($f['relativo'])) {
            $hoy = CarbonImmutable::now('America/Bogota')->startOfDay();

            return match ($f['relativo']) {
                'ultimos_7' => [$hoy->subDays(7)->toDateString(), $hoy->toDateString()],
                'ultimos_30' => [$hoy->subDays(30)->toDateString(), $hoy->toDateString()],
                'proximos_7' => [$hoy->toDateString(), $hoy->addDays(7)->toDateString()],
            };
        }

        return [$f['desde'], $f['hasta']];
    }

    // A timestamp column stored in UTC, both days included in Bogotá.
    private static function entreFechasHora($query, string $columna, array $f): void
    {
        [$desde, $hasta] = self::rango($f);
        if ($desde !== null) {
            $query->where($columna, '>=', CarbonImmutable::parse($desde, 'America/Bogota')->startOfDay()->utc());
        }
        if ($hasta !== null) {
            $query->where($columna, '<=', CarbonImmutable::parse($hasta, 'America/Bogota')->endOfDay()->utc());
        }
    }

    // A date column; "< next day" because SQLite may store it with a time.
    private static function entreDias($query, string $columna, array $f): void
    {
        [$desde, $hasta] = self::rango($f);
        if ($desde !== null) {
            $query->where($columna, '>=', $desde);
        }
        if ($hasta !== null) {
            $query->where($columna, '<', CarbonImmutable::parse($hasta)->addDay()->toDateString());
        }
    }

    private static function fechasEnPalabras(string $que, array $f): string
    {
        if (isset($f['relativo'])) {
            return $que.': '.mb_strtolower(self::RELATIVOS[$f['relativo']]);
        }
        $d = fn (?string $v) => $v ? CarbonImmutable::parse($v)->format('d/m/Y') : null;

        return match (true) {
            $f['desde'] !== null && $f['hasta'] !== null => "{$que}: del {$d($f['desde'])} al {$d($f['hasta'])}",
            $f['desde'] !== null => "{$que}: desde el {$d($f['desde'])}",
            default => "{$que}: hasta el {$d($f['hasta'])}",
        };
    }

    private static function nombres(array $valores, array $mapa): string
    {
        return implode(' o ', array_map(fn ($v) => $mapa[$v] ?? $v, $valores));
    }

    private static function escapar(string $texto): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $texto);
    }

    // "Contains" without caring about case or accents: unaccent(lower()) on PostgreSQL (as EventoController), lower()
    // elsewhere (SQLite tests).
    private static function contiene($query, string $expresion, string $valor, string $boolean = 'and')
    {
        $like = '%'.self::escapar(mb_strtolower($valor)).'%';

        return self::esPg()
            ? $query->whereRaw("unaccent(lower({$expresion})) like unaccent(?)", [$like], $boolean)
            : $query->whereRaw("lower({$expresion}) like ?", [$like], $boolean);
    }

    // The same, in PHP, for options already in memory (municipios): "giron" finds «Girón».
    private static function coincide(string $texto, string $q): bool
    {
        return $q === '' || str_contains(mb_strtolower(Str::ascii($texto)), mb_strtolower(Str::ascii($q)));
    }

    private static function esPg(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private static function nullsLast(): string
    {
        return self::esPg() ? ' nulls last' : '';
    }

    private static function texto(mixed $v): ?string
    {
        return $v === null || $v === '' ? null : (string) $v;
    }

    private static function fechaCorta(mixed $v): ?string
    {
        if (! $v) {
            return null;
        }
        try {
            return CarbonImmutable::parse(substr((string) $v, 0, 10))->format('d/m/Y');
        } catch (\Throwable) {
            return null;
        }
    }

    // A UTC timestamp shown in Bogotá: "21/11/2026 3:40 p. m."
    private static function fechaHora(mixed $v): ?string
    {
        if (! $v) {
            return null;
        }
        $f = CarbonImmutable::parse((string) $v, 'UTC')->setTimezone('America/Bogota');

        return $f->format('d/m/Y g:i').' '.($f->format('A') === 'AM' ? 'a. m.' : 'p. m.');
    }

    private static function busquedaEnPalabras(string $busqueda): string
    {
        $digitos = preg_replace('/\D/', '', $busqueda);
        if (strlen($digitos) >= 5) {
            return 'Búsqueda por documento terminado en '.substr($digitos, -4);
        }

        return "Búsqueda «{$busqueda}»";
    }

    // Whether a ficha opened from a row may be edited: the same rule as the Personas list.
    private function fichaEditable(User $user, ?string $campaignId): bool
    {
        $own = $this->campaign()->ownId();

        return in_array($user->role?->code, EnsureCanWrite::WRITE_ROLES, true) && $user->hasPermission('write.data')
            && ($own === null || $campaignId === $own);
    }

    private static function puedeAbrirFichas(User $user): bool
    {
        return $user->hasPermission('view.votantes') || $user->hasPermission('view.mapa');
    }

    private static function puedeDatos(User $user): bool
    {
        return ReporteService::puedeDatosPersonales($user);
    }

    private function memo(string $clave, \Closure $calcular): mixed
    {
        $key = ($this->campaign()->user()?->id ?? '-').'|'.$clave;

        return $this->memo[$key] ??= $calcular();
    }
}
