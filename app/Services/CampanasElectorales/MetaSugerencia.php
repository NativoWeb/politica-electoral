<?php

namespace App\Services\CampanasElectorales;

use App\Models\ElectoralCampaign;
use Illuminate\Support\Facades\DB;

// Suggested goals, computed on the fly from the latest comparable election (docs/campanas-electorales.md), never
// stored. Base per municipio: the candidate's votes when their ficha ran in that contest there, else the party's.
// The party's votes per corporation, as the screens add them up:
//  - Asamblea: the votes for the list alone ('votes' with organization_id) + its candidates' (/asamblea).
//  - Senado and Cámara: the party TOTAL loaded from the Excel (it already holds its candidates); without it, the sum
//    of its candidates.
//  - Alcaldía, Concejo, Gobernación: the sum of its candidates (primary endorsement), as /concejo/{id}.
// Only called for groups that see the electoral results: the caller leaves every suggestion out otherwise.
class MetaSugerencia
{
    public const SIN_RESULTADOS = 'Tu grupo no tiene acceso a resultados electorales: escribe las metas a mano';

    public const SOLO_MUNICIPIO = 'Sin sugerencia: los resultados solo existen por municipio';

    public const SIN_FUENTE = 'Sin sugerencia: elige el partido o el candidato de la campaña';

    public const SIN_GOBERNACION_MUNICIPIO = '(no hay Gobernación por municipio)';

    // cargo => [table of offices|corporations, name, label in the text].
    private const FUENTES = [
        'alcaldia' => ['offices', 'Alcaldía', 'Alcaldía'],
        'gobernacion' => ['offices', 'Gobernación', 'Gobernación'],
        'concejo' => ['corporations', 'Concejo', 'Concejo'],
        'asamblea' => ['corporations', 'Asamblea', 'Asamblea'],
        'senado' => ['corporations', 'Senado', 'Senado'],
        'camara' => ['corporations', 'Cámara de Representantes', 'Cámara'],
    ];

    // The party's 'votes' row is its total (list + candidates), not the list alone.
    private const TOTAL_PARTIDO = ['senado', 'camara'];

    /**
     * @param  array  $filas  rows of TerritorioCampana::filas()
     * @return array{comparable: ?string, filas: array<string, array{base: ?int, texto: ?string, etiqueta: ?string, sufijo: ?string}>, total: ?array{base: int, etiqueta: string}, total_sufijo: ?string}
     */
    public function calcular(ElectoralCampaign $c, array $filas, TerritorioCampana $territorio): array
    {
        $out = ['comparable' => null, 'filas' => [], 'total' => null, 'total_sufijo' => null];
        $municipios = array_values(array_filter($filas, fn ($f) => $f['tipo'] === 'municipality'));
        foreach ($filas as $f) {
            if ($f['tipo'] !== 'municipality') {
                $out['filas'][$f['id']] = ['base' => null, 'texto' => self::SOLO_MUNICIPIO, 'etiqueta' => null, 'sufijo' => null];
            }
        }
        if ($c->cargo === 'jal' || ! isset(self::FUENTES[$c->cargo])) {
            return $out;
        }
        $munIds = array_column($municipios, 'id');
        $c->loadMissing(['candidate:id,full_name', 'politicalOrganization:id,canonical_name']);

        if ($c->cargo === 'gobernacion') {
            $this->cargarComparables(['gobernacion', 'asamblea']);
            $gob = $this->comparable('gobernacion');
            $out['comparable'] = $gob ? 'Gobernación '.$gob['anio'] : null;
            if ($gob && ($dep = $territorio->departamento())) {
                $total = $this->bases($c, 'gobernacion', $gob, [$dep->id], $territorio, true);
                if (isset($total[$dep->id])) {
                    $out['total'] = $total[$dep->id];
                }
            }
            // No Gobernación result for the campaign's candidate or party: the total is the sum of the municipios.
            $out['total_sufijo'] = $out['total'] === null && $gob ? "(sin votos en Gobernación {$gob['anio']}: suma de Asamblea por municipio)" : null;
            $asamblea = $this->comparable('asamblea');
            $bases = $asamblea && $munIds ? $this->bases($c, 'asamblea', $asamblea, $munIds, $territorio, false) : [];
            foreach ($municipios as $m) {
                $out['filas'][$m['id']] = $this->fila($bases[$m['id']] ?? null, $c, $asamblea, 'asamblea', self::SIN_GOBERNACION_MUNICIPIO);
            }

            return $out;
        }

        $this->cargarComparables([$c->cargo]);
        $comparable = $this->comparable($c->cargo);
        $out['comparable'] = $comparable ? self::FUENTES[$c->cargo][2].' '.$comparable['anio'] : null;
        $bases = $comparable && $munIds ? $this->bases($c, $c->cargo, $comparable, $munIds, $territorio, true) : [];
        foreach ($municipios as $m) {
            $out['filas'][$m['id']] = $this->fila($bases[$m['id']] ?? null, $c, $comparable, $c->cargo, null);
        }
        $conBase = array_filter($bases, fn ($b) => $b['base'] !== null);
        if ($conBase) {
            $etiquetas = array_values(array_unique(array_column($conBase, 'etiqueta')));
            $out['total'] = ['base' => array_sum(array_column($conBase, 'base')), 'etiqueta' => implode(' o ', $etiquetas)];
        }

        return $out;
    }

    // "Sugerido: 1.100 (1.000 votos de Centro Democrático a Asamblea 2023 × 1,10)".
    public static function texto(int $votos, int $base, string $etiqueta, float $factor, ?string $sufijo = null): string
    {
        $texto = 'Sugerido: '.TerritorioCampana::miles($votos).' ('.TerritorioCampana::miles($base).' votos de '.$etiqueta
            .' × '.number_format($factor, 2, ',', '.').')';

        return $sufijo ? $texto.' '.$sufijo : $texto;
    }

    // round(base × factor) with the factor's two decimals, half up, without float error.
    public static function aplicar(int $votos, float $factor): int
    {
        return intdiv($votos * (int) round($factor * 100) + 50, 100);
    }

    private function fila(?array $base, ElectoralCampaign $c, ?array $comparable, string $cargo, ?string $sufijo): array
    {
        $label = self::FUENTES[$cargo][2];
        if (! $comparable) {
            return ['base' => null, 'texto' => "Sin sugerencia: no hay resultados de {$label} cargados", 'etiqueta' => null, 'sufijo' => null];
        }
        if (! $c->political_organization_id && ! $c->candidate_person_id) {
            return ['base' => null, 'texto' => self::SIN_FUENTE, 'etiqueta' => null, 'sufijo' => null];
        }
        if ($base === null) {
            $partido = $this->nombrePartido($c);
            $texto = $partido
                ? "Sin sugerencia: {$partido} no tuvo votos en {$label} {$comparable['anio']} en este municipio"
                : "Sin sugerencia: el candidato no tiene votos en {$label} {$comparable['anio']}; elige el partido de la campaña";

            return ['base' => null, 'texto' => $texto, 'etiqueta' => null, 'sufijo' => null];
        }

        return $base + ['texto' => null, 'sufijo' => $sufijo];
    }

    private array $comparables = [];

    // The latest election with a contest of each cargo, in one query: its contests and year (consultas never count).
    private function cargarComparables(array $cargos): void
    {
        $offices = [];
        $corporations = [];
        foreach ($cargos as $cargo) {
            [$table, $name] = self::FUENTES[$cargo];
            $table === 'offices' ? $offices[$name] = $cargo : $corporations[$name] = $cargo;
            $this->comparables[$cargo] = null;
        }
        $rows = DB::table('contests as con')
            ->join('electoral_events as ev', 'ev.id', '=', 'con.electoral_event_id')
            ->leftJoin('offices as o', 'o.id', '=', 'con.office_id')
            ->leftJoin('corporations as co', 'co.id', '=', 'con.corporation_id')
            ->where(fn ($q) => $q->whereIn('o.name', array_keys($offices) ?: [''])->orWhereIn('co.name', array_keys($corporations) ?: ['']))
            ->where(fn ($q) => $q->whereNull('ev.event_type')->orWhere('ev.event_type', '!=', 'consultation'))
            ->orderByDesc('ev.election_date')->orderBy('con.id')
            ->get(['con.id', 'con.geographic_unit_id', 'ev.id as event_id', 'ev.election_date', 'o.name as office', 'co.name as corporation']);

        foreach ($rows->groupBy(fn ($r) => $r->office !== null ? ($offices[$r->office] ?? null) : ($corporations[$r->corporation] ?? null)) as $cargo => $contests) {
            if ($cargo === '') {
                continue;
            }
            $event = $contests->first()->event_id;
            $latest = $contests->where('event_id', $event);
            $this->comparables[$cargo] = [
                'contest_ids' => $latest->pluck('id')->all(),
                'contest_geo' => $latest->pluck('geographic_unit_id', 'id')->all(),
                'anio' => (int) substr((string) $contests->first()->election_date, 0, 4),
            ];
        }
    }

    private function comparable(string $cargo): ?array
    {
        return $this->comparables[$cargo] ?? null;
    }

    // unit id => {base, etiqueta} for the units with results. The candidate's votes where their candidacy's contest is
    // held (the unit, one above it or national), the party's everywhere else when $partido allows it.
    private function bases(ElectoralCampaign $c, string $cargo, array $comparable, array $unitIds, TerritorioCampana $territorio, bool $conCandidato): array
    {
        $label = self::FUENTES[$cargo][2].' '.$comparable['anio'];
        $out = [];
        $restantes = $unitIds;

        if ($conCandidato && $c->candidate_person_id) {
            $candidaturas = DB::table('candidacies')->where('person_id', $c->candidate_person_id)
                ->whereIn('contest_id', $comparable['contest_ids'])->get(['id', 'contest_id']);
            if ($candidaturas->isNotEmpty()) {
                $cubiertas = array_values(array_filter($unitIds, fn ($u) => $candidaturas->contains(
                    fn ($cand) => $this->cubre($comparable['contest_geo'][$cand->contest_id] ?? null, $u, $territorio))));
                if ($cubiertas) {
                    $nombre = $c->candidate?->full_name ?? 'el candidato';
                    $votos = DB::table('electoral_results')
                        ->whereIn('candidacy_id', $candidaturas->pluck('id')->all())
                        ->whereIn('metric_type', ['votes', 'nominal_votes'])
                        ->whereIn('geographic_unit_id', $cubiertas)
                        ->groupBy('geographic_unit_id')
                        ->selectRaw('geographic_unit_id, SUM(value) AS votos')
                        ->pluck('votos', 'geographic_unit_id');
                    foreach ($cubiertas as $u) {
                        $out[$u] = ['base' => (int) ($votos[$u] ?? 0), 'etiqueta' => "{$nombre} a {$label}"];
                    }
                    $restantes = array_values(array_diff($unitIds, $cubiertas));
                }
            }
        }

        if ($restantes && $c->political_organization_id && ($partido = $this->nombrePartido($c))) {
            foreach ($this->votosPartido($c->political_organization_id, $cargo, $comparable['contest_ids'], $restantes) as $u => $votos) {
                // Asamblea adds the list and the candidates: said so, /asamblea shows them apart.
                $out[$u] = ['base' => $votos, 'etiqueta' => "{$partido} a {$label}".($cargo === 'asamblea' ? ' (lista y candidatos)' : '')];
            }
        }

        return $out;
    }

    // unit id => the party's votes, only for the units where it has results.
    private function votosPartido(string $partyId, string $cargo, array $contestIds, array $unitIds): array
    {
        $avalados = DB::table('candidacy_endorsements as ce')
            ->join('candidacies as c', 'c.id', '=', 'ce.candidacy_id')
            ->where('ce.is_primary', true)
            ->where('ce.organization_id', $partyId)
            ->whereIn('c.contest_id', $contestIds)
            ->select('ce.candidacy_id');

        $rows = DB::table('electoral_results as er')
            ->whereIn('er.contest_id', $contestIds)
            ->whereIn('er.metric_type', ['votes', 'nominal_votes'])
            ->whereIn('er.geographic_unit_id', $unitIds)
            ->where(fn ($q) => $q->where('er.organization_id', $partyId)
                ->orWhere(fn ($n) => $n->whereNull('er.organization_id')->whereIn('er.candidacy_id', $avalados)))
            ->groupBy('er.geographic_unit_id')
            ->selectRaw('er.geographic_unit_id AS unidad,
                SUM(CASE WHEN er.organization_id IS NOT NULL THEN COALESCE(er.value, 0) ELSE 0 END) AS lista,
                SUM(CASE WHEN er.organization_id IS NOT NULL THEN 1 ELSE 0 END) AS filas_lista,
                SUM(CASE WHEN er.organization_id IS NULL THEN COALESCE(er.value, 0) ELSE 0 END) AS nominal')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->unidad] = in_array($cargo, self::TOTAL_PARTIDO, true) && (int) $r->filas_lista > 0
                ? (int) $r->lista
                : (int) $r->lista + (int) $r->nominal;
        }

        return $out;
    }

    private function cubre(?string $contestGeo, string $unitId, TerritorioCampana $territorio): bool
    {
        if ($contestGeo === null || $contestGeo === $unitId) {
            return true;
        }
        $m = $territorio->municipios()[$unitId] ?? null;

        return $m !== null && ($contestGeo === $m['parent_id'] || $contestGeo === $territorio->departamento()?->id);
    }

    private function nombrePartido(ElectoralCampaign $c): ?string
    {
        return $c->political_organization_id ? $c->politicalOrganization?->canonical_name : null;
    }
}
