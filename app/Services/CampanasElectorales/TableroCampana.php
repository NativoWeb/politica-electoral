<?php

namespace App\Services\CampanasElectorales;

use App\Models\ElectoralCampaign;
use App\Models\ElectoralCampaignGoal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The campaign's board (docs/campanas-electorales.md, Fase 5): progress of every territory the user sees against the
// goals written (or computed from the votes goal, never the suggestions), an index, ranking, weekly evolution and what
// the map needs. Cached 5 minutes per campaign, goals and territory of the user; what depends on who looks (the card,
// read only, can) is added outside the cache by the controller.
class TableroCampana
{
    public const FORMULA = 'El índice va de 0 a 100: el 60 % sale del avance en votantes registrados y el 40 % del avance en líderes. Pasar la meta no suma más.';

    public const SIN_LIDERES = 'Aún no hay fichas marcadas como líder. Márcalas con la casilla "Es líder" en la ficha. Mientras tanto, el índice llega como máximo a 60.';

    public const ARCHIVADA = 'Campaña archivada: el tablero muestra los datos de hoy.';

    public const RANKING_NOTA = 'Las metas muy pequeñas no entran al ranking';

    public const UN_TERRITORIO = 'Esta campaña es de un solo territorio: mira el avance arriba.';

    public const META_CUMPLIDA = 'La meta de votantes ya se cumplió.';

    public const POCOS_REGISTROS = 'Muy pocos registros para ver la evolución';

    private const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    public function __construct(
        private readonly ElectoralCampaignAccess $access,
        private readonly AvanceCampana $avance,
    ) {}

    public function access(): ElectoralCampaignAccess
    {
        return $this->access;
    }

    // The board of a campaign the user may see ($c comes from ElectoralCampaignAccess::find). $fresh skips the cache.
    public function datos(ElectoralCampaign $c, bool $fresh = false): array
    {
        $key = $this->cacheKey($c);
        if ($fresh) {
            $data = $this->calcular($c);
            Cache::put($key, $data, $this->ttl());

            return $data;
        }

        return Cache::remember($key, $this->ttl(), fn () => $this->calcular($c));
    }

    // One territory of the board with its weeks, or null when the user does not see it in this campaign.
    public function territorio(ElectoralCampaign $c, string $unitId): ?array
    {
        $data = $this->datos($c);
        $t = collect($data['territorios'])->firstWhere('id', $unitId);
        if (! $t) {
            return null;
        }
        $esComuna = $t['padre_id'] !== null || in_array($t['tipo'], ['comuna', 'corregimiento'], true);
        $dias = $this->porDia($c, $esComuna ? [] : [$unitId], $esComuna ? [$unitId] : []);
        $semanas = $this->semanas($dias);
        $puesto = $t['por_origen']['puesto'] ?? null;

        return [
            'territorio' => $t,
            'semanas' => array_map(fn ($s) => ['inicio' => $s['inicio'], 'nuevos' => $this->k($s['nuevos']), 'masiva' => $s['masiva']], $semanas['semanas']),
            'antes' => $this->k($semanas['antes']),
            'pocos_registros' => $t['votantes']['registrados'] + ($t['sin_comuna']['votantes'] ?? 0) + ($t['otras_comunas']['votantes'] ?? 0)
                < (int) $this->cfg('pocos_registros'),
            'pocos_registros_texto' => self::POCOS_REGISTROS,
            'por_puesto_texto' => $puesto ? 'Incluye '.TerritorioCampana::miles($puesto).' por puesto de votación' : null,
            'comunas' => array_values(array_map(fn ($x) => $x['id'], array_filter($data['territorios'], fn ($x) => $x['padre_id'] === $unitId))),
        ];
    }

    public function cacheKey(ElectoralCampaign $c): string
    {
        $goals = DB::table('electoral_campaign_goals')->where('electoral_campaign_id', $c->id)
            ->orderBy('geographic_unit_id')
            ->get(['geographic_unit_id', 'votos_meta', 'lideres_meta', 'votantes_meta'])
            ->map(fn ($g) => [$g->geographic_unit_id, $g->votos_meta, $g->lideres_meta, $g->votantes_meta])->all();
        $huella = sha1(json_encode([
            $c->updated_at?->toIso8601String(), $c->status, $c->election_date?->format('Y-m-d'), $c->factor_registro,
            $c->votos_por_lider, $c->scope_level, $this->access->territoryIds($c), $goals,
        ]));
        $allowed = $this->access->allowedMunicipios();
        if ($allowed !== null) {
            sort($allowed);
        }

        return "campanas:tablero:v1:{$c->id}:{$huella}:".sha1($allowed === null ? 'todos' : implode('|', $allowed));
    }

    private function ttl(): int
    {
        return 60 * (int) $this->cfg('cache_minutos');
    }

    private function cfg(string $key): mixed
    {
        return config("campanas_electorales.tablero.{$key}");
    }

    // Counts from 1 to k-1 hidden as null.
    public function k(?int $n): ?int
    {
        return self::ocultar($n);
    }

    // The same rule for other screens (the "incluye N por puesto de votación" of the F4 detail).
    public static function ocultar(?int $n): ?int
    {
        return $n !== null && $n > 0 && $n < (int) config('campanas_electorales.tablero.k') ? null : $n;
    }

    private function calcular(ElectoralCampaign $c): array
    {
        $filas = $this->access->visibleFilas($this->access->territorio()->filas($c->scope_level, $this->access->territoryIds($c)));
        $goals = $c->goals()->get()->keyBy('geographic_unit_id');
        $avance = $this->avance->calcular($c, $filas);

        $hijas = [];
        foreach ($filas as $f) {
            if ($f['padre_id'] !== null) {
                $hijas[$f['padre_id']][] = $f['id'];
            }
        }

        // Effective goals: the comunas first, so a municipio with goals in its comunas adds them up.
        $metas = [];
        foreach ($filas as $f) {
            if (! isset($hijas[$f['id']])) {
                $metas[$f['id']] = $this->metaEfectiva($c, $goals->get($f['id']));
            }
        }
        $desdeComunas = [];
        foreach ($hijas as $padre => $ids) {
            $propia = $this->metaEfectiva($c, $goals->get($padre));
            if ($propia['votantes'][0] !== null || $propia['lideres'][0] !== null) {
                $metas[$padre] = $propia;
            } else {
                $metas[$padre] = self::sumaMetas(array_map(fn ($id) => $metas[$id], $ids));
                $desdeComunas[$padre] = true;
            }
        }

        $cobertura = $this->cobertura($c, $filas, $hijas);
        $min = (float) $this->cfg('cobertura_comuna_min');
        $nombres = array_column($filas, 'nombre', 'id');

        $territorios = [];
        foreach ($filas as $f) {
            $u = $avance['unidades'][$f['id']] ?? ['votantes' => 0, 'lideres' => 0, 'por_origen' => []];
            $m = $metas[$f['id']];
            $v = self::medida($u['votantes'], $m['votantes'][0], $m['votantes'][1]);
            $l = self::medida($u['lideres'], $m['lideres'][0], $m['lideres'][1]);
            $aparte = null;
            if (isset($desdeComunas[$f['id']])) {
                // Goals in its comunas: only the fichas of the comunas with that goal count against it; the rest apart.
                $aparte = ['sin_comuna' => [], 'otras_comunas' => []];
                foreach (['votantes' => &$v, 'lideres' => &$l] as $campo => &$medida) {
                    if ($medida['meta'] === null) {
                        $aparte['sin_comuna'][$campo] = null;
                        $aparte['otras_comunas'][$campo] = null;

                        continue;
                    }
                    $conMeta = 0;
                    $otras = 0;
                    $falta = 0;
                    foreach ($hijas[$f['id']] as $hija) {
                        $n = $avance['unidades'][$hija][$campo] ?? 0;
                        $metaHija = $metas[$hija][$campo][0];
                        if ($metaHija === null) {
                            $otras += $n;
                        } else {
                            $conMeta += $n;
                            $falta += max($metaHija - $n, 0);
                        }
                    }
                    $total = $medida['registrados'];
                    $medida = self::medida($conMeta, $medida['meta'], $medida['meta_calculada']);
                    $medida['falta'] = $falta;
                    $aparte['sin_comuna'][$campo] = max($total - $conMeta - $otras, 0);
                    $aparte['otras_comunas'][$campo] = $otras;
                }
                unset($medida);
            }
            $indice = $this->indice($v, $l);
            $esComuna = in_array($f['tipo'], ['comuna', 'corregimiento'], true);

            $estado = 'ok';
            $motivo = null;
            if ($v['meta'] === null && $l['meta'] === null) {
                $estado = 'sin_meta';
            } elseif ($esComuna && ($cob = $cobertura[$f['municipio_id']] ?? null) && $cob['total'] > 0 && $min > $cob['con'] / $cob['total']) {
                $estado = 'insuficiente';
                $motivo = self::textoCobertura($cob, $nombres[$f['municipio_id']] ?? $this->access->territorio()->municipios()[$f['municipio_id']]['nombre'] ?? '');
            }

            $row = [
                'id' => $f['id'], 'nombre' => $f['nombre'], 'tipo' => $f['tipo'], 'padre_id' => $f['padre_id'], 'codigo' => $f['codigo'],
                'votantes' => $v, 'lideres' => $l, 'indice' => $indice,
                'tramo' => ['indice' => self::tramo($indice), 'votantes' => self::tramo(self::pctEntero($v['pct'])), 'lideres' => self::tramo(self::pctEntero($l['pct']))],
                'estado' => $estado, 'motivo' => $motivo,
            ];
            if (isset($hijas[$f['id']])) {
                $row['cobertura_comuna'] = $cobertura[$f['id']] ?? ['con' => 0, 'total' => 0];
            }
            if ($aparte !== null) {
                $row += $aparte;
            }
            if ($esComuna) {
                $row['por_origen'] = array_map(fn ($n) => $this->k((int) $n), $u['por_origen']);
            }
            $territorios[] = $row;
        }

        $top = array_values(array_filter($territorios, fn ($t) => $t['padre_id'] === null));
        $totales = $this->totales($top, $territorios);
        $municipiosVisibles = array_values(array_filter($filas, fn ($f) => $f['tipo'] === 'municipality'));
        $capa = $this->capa($c, $municipiosVisibles, $hijas);
        $municipioIds = array_column($municipiosVisibles, 'id');
        $dias = $c->scope_level === ElectoralCampaign::SCOPE_COMUNA
            ? $this->porDia($c, [], array_column($filas, 'id'))
            : $this->porDia($c, $municipioIds, []);
        $evolucion = $this->evolucion($c, $dias, $totales['votantes']['falta']);

        return [
            'generado_at' => now('America/Bogota')->toIso8601String(),
            'formula' => ['texto' => self::FORMULA, 'peso_votantes' => (float) $this->cfg('peso_votantes'), 'peso_lideres' => (float) $this->cfg('peso_lideres')],
            'umbrales' => [
                'k' => (int) $this->cfg('k'), 'cobertura_comuna_min' => $min,
                'ranking_min_votantes' => (int) $this->cfg('ranking.min_votantes_meta'), 'ranking_min_lideres' => (int) $this->cfg('ranking.min_lideres_meta'),
                'pocos_registros' => (int) $this->cfg('pocos_registros'),
            ],
            'totales' => $totales,
            'territorios' => $territorios,
            'ranking' => $this->ranking($capa === 'comunas' ? array_values(array_filter($territorios, fn ($t) => $t['padre_id'] !== null)) : $top),
            'evolucion' => $evolucion,
            'avisos' => $this->avisos($c, $totales, $territorios, $cobertura, $hijas, $municipiosVisibles),
            'mapa' => [
                'capa' => $capa,
                'con_comunas' => array_values(array_map(fn ($m) => ['id' => $m['id'], 'codigo' => $m['codigo'], 'nombre' => $m['nombre']],
                    array_filter($municipiosVisibles, fn ($m) => isset($hijas[$m['id']])))),
                'sin_poligono' => $capa === null ? [] : $this->sinPoligono($capa === 'comunas'
                    ? array_values(array_filter($filas, fn ($f) => $f['padre_id'] !== null)) : $municipiosVisibles),
                'texto' => $capa === null ? self::UN_TERRITORIO : null,
            ],
        ];
    }

    // [meta, calculada] for votantes and lideres of one goal row.
    private function metaEfectiva(ElectoralCampaign $c, ?ElectoralCampaignGoal $g): array
    {
        $out = ['votantes' => [null, false], 'lideres' => [null, false]];
        if (! $g) {
            return $out;
        }
        if ($g->votantes_meta !== null) {
            $out['votantes'] = [(int) $g->votantes_meta, false];
        } elseif ($g->votos_meta !== null) {
            $out['votantes'] = [MetaSugerencia::aplicar((int) $g->votos_meta, (float) $c->factor_registro), true];
        }
        if ($g->lideres_meta !== null) {
            $out['lideres'] = [(int) $g->lideres_meta, false];
        } elseif ($g->votos_meta !== null) {
            $por = max(1, (int) $c->votos_por_lider);
            $out['lideres'] = [intdiv((int) $g->votos_meta + $por - 1, $por), true];
        }

        return $out;
    }

    private static function sumaMetas(array $metas): array
    {
        $out = [];
        foreach (['votantes', 'lideres'] as $campo) {
            $valores = array_values(array_filter($metas, fn ($m) => $m[$campo][0] !== null));
            $out[$campo] = $valores
                ? [array_sum(array_map(fn ($m) => $m[$campo][0], $valores)), in_array(true, array_map(fn ($m) => $m[$campo][1], $valores), true)]
                : [null, false];
        }

        return $out;
    }

    private static function medida(int $registrados, ?int $meta, bool $calculada): array
    {
        return [
            'registrados' => $registrados,
            'meta' => $meta,
            'meta_calculada' => $meta !== null && $calculada,
            'pct' => $meta === null ? null : ($meta === 0 ? null : round($registrados / $meta, 4)),
            'falta' => $meta === null ? null : max($meta - $registrados, 0),
        ];
    }

    private function indice(array $v, array $l): ?int
    {
        $pv = self::progreso($v);
        $pl = self::progreso($l);
        if ($pv === null && $pl === null) {
            return null;
        }
        if ($pv !== null && $pl !== null) {
            return (int) round(100 * ((float) $this->cfg('peso_votantes') * min($pv, 1) + (float) $this->cfg('peso_lideres') * min($pl, 1)));
        }

        return (int) round(100 * min($pv ?? $pl, 1));
    }

    // Exact progress of a measure: a goal of 0 is met.
    private static function progreso(array $m): ?float
    {
        if ($m['meta'] === null) {
            return null;
        }
        $registrados = $m['registrados_con_meta'] ?? $m['registrados'];

        return $m['meta'] === 0 ? 1.0 : $registrados / $m['meta'];
    }

    private static function pctEntero(?float $pct): ?int
    {
        return $pct === null ? null : (int) floor($pct * 100 + 1e-9);
    }

    private static function tramo(?int $valor): ?int
    {
        return match (true) {
            $valor === null => null,
            $valor >= 100 => 4,
            $valor >= 75 => 3,
            $valor >= 50 => 2,
            $valor >= 25 => 1,
            default => 0,
        };
    }

    // Fichas with a comuna and in all of each municipio that has comunas: municipio id => {con, total}.
    private function cobertura(ElectoralCampaign $c, array $filas, array $hijas): array
    {
        $municipios = array_values(array_unique(array_merge(
            array_keys($hijas),
            array_column(array_filter($filas, fn ($f) => $f['padre_id'] === null && $f['tipo'] !== 'municipality'), 'municipio_id'),
        )));
        if (! $municipios) {
            return [];
        }
        $out = array_fill_keys($municipios, ['con' => 0, 'total' => 0]);
        $rows = $this->universo($c)
            ->whereIn('geographic_unit_id', $municipios)
            ->groupBy('geographic_unit_id')
            ->selectRaw('geographic_unit_id, COUNT(*) AS total, COUNT(comuna_id) AS con')
            ->get();
        foreach ($rows as $r) {
            $out[$r->geographic_unit_id] = ['con' => (int) $r->con, 'total' => (int) $r->total];
        }

        return $out;
    }

    private function universo(ElectoralCampaign $c)
    {
        return DB::table('lideres')
            ->where('campaign_id', $c->campaign_id)
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('fallecido', false)->orWhereNull('fallecido'));
    }

    private static function textoCobertura(array $cob, string $municipio): string
    {
        $pct = $cob['total'] > 0 ? (int) floor(100 * $cob['con'] / $cob['total']) : 0;

        return 'Solo '.TerritorioCampana::miles($cob['con']).' de '.TerritorioCampana::miles($cob['total'])." fichas de {$municipio} ({$pct} %) tienen comuna";
    }

    private function totales(array $top, array $todos): array
    {
        $out = [];
        foreach (['votantes', 'lideres'] as $campo) {
            $conMeta = array_values(array_filter($top, fn ($t) => $t[$campo]['meta'] !== null));
            $meta = $conMeta ? array_sum(array_map(fn ($t) => $t[$campo]['meta'], $conMeta)) : null;
            $regMeta = array_sum(array_map(fn ($t) => $t[$campo]['registrados'], $conMeta));
            $out[$campo] = [
                // Every ficha, also those a municipio with goals in its comunas keeps apart.
                'registrados' => array_sum(array_map(fn ($t) => $t[$campo]['registrados'] + ($t['sin_comuna'][$campo] ?? 0) + ($t['otras_comunas'][$campo] ?? 0), $top)),
                'registrados_con_meta' => $regMeta,
                'meta' => $meta,
                'meta_calculada' => in_array(true, array_map(fn ($t) => $t[$campo]['meta_calculada'], $conMeta), true),
                'pct' => $meta === null || $meta === 0 ? null : round($regMeta / $meta, 4),
                // What each territory lacks, added up: a territory over its goal does not cover another one.
                'falta' => $meta === null ? null : array_sum(array_map(fn ($t) => $t[$campo]['falta'], $conMeta)),
            ];
        }
        $out['indice'] = $this->indice($out['votantes'], $out['lideres']);
        $conMeta = count(array_filter($top, fn ($t) => $t['estado'] !== 'sin_meta'));
        $out['territorios'] = [
            'total' => count($top),
            'con_meta' => $conMeta,
            'sin_meta' => count($top) - $conMeta,
            'insuficientes' => count(array_filter($todos, fn ($t) => $t['estado'] === 'insuficiente')),
        ];

        return $out;
    }

    private function ranking(array $candidatos): array
    {
        $minV = (int) $this->cfg('ranking.min_votantes_meta');
        $minL = (int) $this->cfg('ranking.min_lideres_meta');
        $top = (int) $this->cfg('ranking.top');
        $entran = array_values(array_filter($candidatos, fn ($t) => $t['estado'] === 'ok' && $t['indice'] !== null
            && (($t['votantes']['meta'] ?? 0) >= $minV || ($t['lideres']['meta'] ?? 0) >= $minL)));

        $desempate = fn ($a, $b) => (($b['votantes']['falta'] ?? 0) <=> ($a['votantes']['falta'] ?? 0)) ?: strcmp($a['nombre'], $b['nombre']);
        $mejores = $entran;
        usort($mejores, fn ($a, $b) => ($b['indice'] <=> $a['indice']) ?: $desempate($a, $b));
        $peores = $entran;
        usort($peores, fn ($a, $b) => ($a['indice'] <=> $b['indice']) ?: $desempate($a, $b));

        $unaLista = count($entran) <= (int) $this->cfg('ranking.una_lista_max');

        return [
            'mejores' => array_column($unaLista ? $mejores : array_slice($mejores, 0, $top), 'id'),
            'por_apoyar' => $unaLista ? [] : array_column(array_slice($peores, 0, $top), 'id'),
            'una_lista' => $unaLista,
            'nota' => self::RANKING_NOTA,
        ];
    }

    // New fichas per Bogotá day: 'Y-m-d' => n, for the municipios given or the comunas given.
    private function porDia(ElectoralCampaign $c, array $municipios, array $comunas): array
    {
        if (! $municipios && ! $comunas) {
            return [];
        }
        $dia = DB::connection()->getDriverName() === 'pgsql'
            ? "((created_at AT TIME ZONE 'UTC') AT TIME ZONE '".$this->zona()."')::date"
            : "date(created_at, '-5 hours')";
        $q = $this->universo($c)->whereNotNull('created_at');
        $municipios ? $q->whereIn('geographic_unit_id', $municipios) : $q->whereIn('comuna_id', $comunas);

        return $q->groupByRaw($dia)->selectRaw("{$dia} AS dia, COUNT(*) AS n")->get()
            ->mapWithKeys(fn ($r) => [substr((string) $r->dia, 0, 10) => (int) $r->n])->all();
    }

    private function zona(): string
    {
        $zona = (string) $this->cfg('evolucion.zona');

        return preg_match('#^[A-Za-z_]+/[A-Za-z_]+$#', $zona) ? $zona : 'America/Bogota';
    }

    // Exact weekly counts (Monday to Sunday) from 'desde' to the current week, and what came before.
    private function semanas(array $dias): array
    {
        $zona = $this->zona();
        $desde = CarbonImmutable::parse((string) $this->cfg('evolucion.desde'), $zona)->startOfWeek();
        $hoy = CarbonImmutable::now($zona)->startOfDay();
        $masivo = (int) $this->cfg('evolucion.dia_masivo');
        $semanas = [];
        for ($s = $desde; $s->lte($hoy); $s = $s->addWeek()) {
            $semanas[$s->format('Y-m-d')] = ['inicio' => $s->format('Y-m-d'), 'nuevos' => 0, 'masiva' => false];
        }
        $antes = 0;
        foreach ($dias as $dia => $n) {
            $d = CarbonImmutable::parse($dia, $zona);
            if ($d->lt($desde)) {
                $antes += $n;

                continue;
            }
            $inicio = $d->startOfWeek()->format('Y-m-d');
            if (! isset($semanas[$inicio])) {
                continue;
            }
            $semanas[$inicio]['nuevos'] += $n;
            $semanas[$inicio]['masiva'] = $semanas[$inicio]['masiva'] || $n > $masivo;
        }

        return ['semanas' => array_values($semanas), 'antes' => $antes];
    }

    private function evolucion(ElectoralCampaign $c, array $dias, ?int $falta): array
    {
        $s = $this->semanas($dias);
        $completas = array_slice($s['semanas'], 0, -1);
        $ultimas = array_slice($completas, -4);
        $promedio = $ultimas ? (int) round(array_sum(array_column($ultimas, 'nuevos')) / count($ultimas)) : null;
        $antes = $this->k($s['antes']);
        $fecha = CarbonImmutable::parse((string) $this->cfg('evolucion.desde'));

        return [
            'desde' => (string) $this->cfg('evolucion.desde'),
            'semanas_defecto' => (int) $this->cfg('evolucion.semanas_defecto'),
            'antes' => $antes,
            'antes_texto' => $s['antes'] > 0
                ? ($antes === null ? 'Menos de 5' : TerritorioCampana::miles($antes)).' ya estaban registrados antes del '.self::fecha($fecha).' (carga inicial del sistema anterior)'
                : null,
            'masiva_texto' => 'Incluye un día con más de '.TerritorioCampana::miles((int) $this->cfg('evolucion.dia_masivo')).' registros (posible carga de archivo)',
            'semanas' => array_map(fn ($w) => ['inicio' => $w['inicio'], 'nuevos' => $this->k($w['nuevos']), 'masiva' => $w['masiva']], $s['semanas']),
            'promedio_4' => $promedio,
            'ritmo_necesario' => $this->ritmo($c, $falta, $promedio),
        ];
    }

    private function ritmo(ElectoralCampaign $c, ?int $falta, ?int $promedio): ?array
    {
        if (! $c->election_date || $falta === null) {
            return null;
        }
        $hoy = CarbonImmutable::now($this->zona())->startOfDay();
        $eleccion = CarbonImmutable::parse($c->election_date->format('Y-m-d'), $this->zona());
        if ($eleccion->lte($hoy)) {
            return null;
        }
        $semanas = (int) ceil($hoy->diffInDays($eleccion) / 7);
        $porSemana = (int) ceil($falta / $semanas);
        if ($falta === 0) {
            return ['semanas' => $semanas, 'por_semana' => 0, 'texto' => self::META_CUMPLIDA];
        }
        $texto = 'Faltan '.TerritorioCampana::miles($semanas).($semanas === 1 ? ' semana' : ' semanas').' para el '.self::fecha($eleccion)
            .': hay que registrar '.TerritorioCampana::miles($porSemana).' por semana.';
        if ($promedio !== null) {
            $texto .= ' Las últimas 4 semanas: '.TerritorioCampana::miles($promedio).' por semana.';
        }

        return ['semanas' => $semanas, 'por_semana' => $porSemana, 'texto' => $texto];
    }

    private static function fecha(CarbonImmutable $d): string
    {
        return $d->day.' '.self::MESES[$d->month - 1].' '.$d->year;
    }

    private function capa(ElectoralCampaign $c, array $municipios, array $hijas): ?string
    {
        if ($c->scope_level === ElectoralCampaign::SCOPE_COMUNA) {
            return null;
        }
        if (count($municipios) >= 2) {
            return 'municipios';
        }

        return count($municipios) === 1 && isset($hijas[$municipios[0]['id']]) ? 'comunas' : null;
    }

    // Names of the units of the map layer without a polygon (none known when the column does not exist).
    private function sinPoligono(array $unidades): array
    {
        if (! $unidades || ! Schema::hasColumn('geographic_units', 'geom')) {
            return [];
        }
        $sin = array_flip(DB::table('geographic_units')->whereIn('id', array_column($unidades, 'id'))->whereNull('geom')->pluck('id')->all());

        return array_values(array_map(fn ($u) => $u['nombre'], array_filter($unidades, fn ($u) => isset($sin[$u['id']]))));
    }

    private function avisos(ElectoralCampaign $c, array $totales, array $territorios, array $cobertura, array $hijas, array $municipios): array
    {
        $avisos = [];
        if (! $c->isActive()) {
            $avisos[] = ['tipo' => 'archivada', 'texto' => self::ARCHIVADA];
        }
        if ($this->access->allowedMunicipios() !== null) {
            $nombres = array_column($municipios, 'nombre');
            if (! $nombres) {
                $nombres = array_values(array_unique(array_filter(array_map(
                    fn ($t) => $this->access->territorio()->comunas()[$t['id']]['municipio'] ?? null, $territorios))));
            }
            $avisos[] = ['tipo' => 'territorio_usuario', 'texto' => 'Ves solo tus municipios: '.implode(', ', $nombres).'.'];
        }
        $t = $totales['territorios'];
        if ($t['con_meta'] > 0 && $t['sin_meta'] > 0) {
            $avisos[] = ['tipo' => 'sin_metas', 'texto' => TerritorioCampana::miles($t['sin_meta']).' de '.TerritorioCampana::miles($t['total'])
                .($t['sin_meta'] === 1 ? ' territorios no tiene meta: aparece en gris.' : ' territorios no tienen meta: aparecen en gris.')];
        }
        if ($totales['lideres']['registrados'] === 0) {
            $avisos[] = ['tipo' => 'sin_lideres', 'texto' => self::SIN_LIDERES];
        }
        $min = (float) $this->cfg('cobertura_comuna_min');
        $nombres = array_column($territorios, 'nombre', 'id');
        foreach ($cobertura as $municipio => $cob) {
            $conComunasVisibles = isset($hijas[$municipio]) || $c->scope_level === ElectoralCampaign::SCOPE_COMUNA;
            if ($conComunasVisibles && $cob['total'] > 0 && $min > $cob['con'] / $cob['total']) {
                $nombre = $nombres[$municipio] ?? $this->access->territorio()->municipios()[$municipio]['nombre'] ?? '';
                $avisos[] = ['tipo' => 'cobertura_comuna', 'texto' => self::textoCobertura($cob, $nombre).': las comunas se ven rayadas hasta que haya más datos.'];
            }
        }

        return $avisos;
    }
}
