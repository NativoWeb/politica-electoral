<?php

namespace App\Services\CampanasElectorales;

use App\Models\ElectoralCampaign;
use App\Models\ElectoralCampaignGoal;
use App\Services\Import\TextNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// What the screens get (contract in docs/campanas-electorales.md): the card of the list, the detail with its goals,
// suggestions and progress, and the form.
class DetalleCampana
{
    public function __construct(
        private readonly ElectoralCampaignAccess $access,
        private readonly MetaSugerencia $sugerencia,
        private readonly AvanceCampana $avance,
    ) {}

    public function access(): ElectoralCampaignAccess
    {
        return $this->access;
    }

    // The rows of a campaign the user sees (all of them, or those of their territory).
    public function filas(ElectoralCampaign $c): array
    {
        return $this->access->visibleFilas($this->access->territorio()->filas($c->scope_level, $this->access->territoryIds($c)));
    }

    // Cards for the list, with "Metas cargadas: X de Y" from a single query of goals.
    public function tarjetas(Collection $campanas): array
    {
        $goals = $campanas->isEmpty() ? collect() : DB::table('electoral_campaign_goals')
            ->whereIn('electoral_campaign_id', $campanas->pluck('id')->all())
            ->where(fn ($q) => $q->whereNotNull('votos_meta')->orWhereNotNull('lideres_meta')->orWhereNotNull('votantes_meta'))
            ->get(['electoral_campaign_id', 'geographic_unit_id'])
            ->groupBy('electoral_campaign_id');

        return $campanas->map(function (ElectoralCampaign $c) use ($goals) {
            $conMeta = array_flip($goals->get($c->id, collect())->pluck('geographic_unit_id')->all());

            return $this->tarjeta($c, $this->resumenMetas($this->filas($c), $conMeta));
        })->values()->all();
    }

    public function tarjeta(ElectoralCampaign $c, ?array $metas = null): array
    {
        $c->loadMissing(['grupo:id,name', 'candidate:id,full_name', 'politicalOrganization:id,canonical_name']);
        $ids = $this->access->territoryIds($c);
        $t = $this->access->territorio();

        return [
            'id' => $c->id,
            'name' => $c->name,
            'cargo' => $c->cargo,
            'cargo_label' => $c->cargoLabel(),
            'election_year' => $c->election_year,
            'election_date' => $c->election_date?->format('Y-m-d'),
            'status' => $c->status,
            'archived_at' => $c->archived_at?->toIso8601String(),
            'candidato' => ['person_id' => $c->candidate_person_id, 'nombre' => $c->candidate?->full_name ?? $c->candidate_name],
            'partido' => $c->politicalOrganization ? ['id' => $c->politicalOrganization->id, 'nombre' => $c->politicalOrganization->canonical_name] : null,
            'scope_level' => $c->scope_level,
            'territorio' => ['resumen' => $t->resumen($c->scope_level, $ids), 'unidades' => $t->unidades($ids)],
            'metas' => $metas ?? $this->resumenMetas($this->filas($c), $this->conMeta($c)),
            'factores' => ['factor_votos' => $c->factor_votos, 'factor_registro' => $c->factor_registro, 'votos_por_lider' => $c->votos_por_lider],
            'grupo' => ['id' => $c->campaign_id, 'nombre' => $c->grupo?->name, 'propio' => $this->access->campaign()->isOwn($c->campaign_id)],
            'solo_lectura' => $this->access->readOnly($c) !== null,
            'created_at' => $c->created_at?->toIso8601String(),
            'updated_at' => $c->updated_at?->toIso8601String(),
        ];
    }

    // The detail page (and the data of every write that changes goals).
    public function detalle(ElectoralCampaign $c): array
    {
        $filas = $this->filas($c);
        $goals = $c->goals()->get()->keyBy('geographic_unit_id');
        $readOnly = $this->access->readOnly($c);
        $disponible = $this->access->campaign()->seesElectoralResults();
        $sug = $disponible ? $this->sugerencia->calcular($c, $filas, $this->access->territorio()) : null;
        $avance = $this->avance->calcular($c, $filas);

        $hijas = [];
        foreach ($filas as $f) {
            if ($f['padre_id'] !== null) {
                $hijas[$f['padre_id']][] = $f['id'];
            }
        }

        $out = [];
        foreach ($filas as $f) {
            $meta = self::meta($goals->get($f['id']));
            $sumaComunas = isset($hijas[$f['id']]) ? self::suma(array_map(fn ($id) => self::meta($goals->get($id)), $hijas[$f['id']])) : null;
            $row = [
                'id' => $f['id'], 'nombre' => $f['nombre'], 'tipo' => $f['tipo'], 'codigo' => $f['codigo'], 'padre_id' => $f['padre_id'],
                'comunas' => count($hijas[$f['id']] ?? []),
                'meta' => $meta,
                'editable' => $readOnly === null && $sumaComunas === null,
                'suma_comunas' => $sumaComunas,
            ];
            if ($disponible) {
                $votosMeta = $meta['votos_meta'] ?? $sumaComunas['votos_meta'] ?? null;
                $row['sugerencia'] = $this->sugerido($c, $sug['filas'][$f['id']] ?? null, $votosMeta);
                $row['sin_sugerencia'] = $row['sugerencia'] === null ? ($sug['filas'][$f['id']]['texto'] ?? null) : null;
            }
            $unidad = $avance['unidades'][$f['id']] ?? ['votantes' => 0, 'lideres' => 0, 'por_origen' => null];
            $row['avance'] = ['votantes' => $unidad['votantes'], 'lideres' => $unidad['lideres']];
            $row['avance_por_origen'] = $unidad['por_origen'];
            $row['sin_comuna'] = $avance['sin_comuna'][$f['id']] ?? (isset($hijas[$f['id']]) ? ['votantes' => 0, 'lideres' => 0] : null);
            $out[] = $row;
        }

        return [
            'campana' => $this->tarjeta($c, $this->resumenMetas($filas, array_flip(array_keys(array_filter(
                $goals->map(fn ($g) => self::meta($g))->all()))))),
            'can' => [
                'edit' => $readOnly === null,
                'archive' => $this->access->canWriteGroup($c) && $c->isActive(),
                'reactivate' => $this->access->canWriteGroup($c) && ! $c->isActive(),
                'plantilla' => $this->access->canWriteGroup($c),
            ],
            'soloLectura' => $readOnly,
            'sugerencias' => [
                'disponible' => $disponible,
                'aviso' => $disponible ? null : MetaSugerencia::SIN_RESULTADOS,
                'comparable' => $sug['comparable'] ?? null,
            ],
            'filas' => $out,
            'totales' => $this->totales($c, $out, $sug),
        ];
    }

    // The form's fields of an existing campaign.
    public function formulario(ElectoralCampaign $c): array
    {
        $c->loadMissing('candidate:id,full_name');

        return [
            'id' => $c->id,
            'name' => $c->name,
            'cargo' => $c->cargo,
            'election_year' => $c->election_year,
            'election_date' => $c->election_date?->format('Y-m-d'),
            'candidate_person_id' => $c->candidate_person_id,
            'candidate_name' => $c->candidate_name,
            'candidato_nombre' => $c->candidate?->full_name ?? $c->candidate_name,
            'political_organization_id' => $c->political_organization_id,
            'scope_level' => $c->scope_level,
            'territorio_ids' => $c->scope_level === ElectoralCampaign::SCOPE_DEPARTMENT ? [] : $this->access->territoryIds($c),
            'factor_votos' => $c->factor_votos,
            'factor_registro' => $c->factor_registro,
            'votos_por_lider' => $c->votos_por_lider,
            'status' => $c->status,
        ];
    }

    // The choices of the form.
    public function opciones(?string $partidoActual = null): array
    {
        $t = $this->access->territorio();
        $dep = $t->departamento();
        $f = config('campanas_electorales.factores');

        return [
            'cargos' => array_map(fn ($value, $def) => ['value' => $value, 'label' => $def['label'], 'scopes' => $def['scopes']],
                array_keys(ElectoralCampaign::CARGOS), ElectoralCampaign::CARGOS),
            'anio' => config('campanas_electorales.anio'),
            'departamento' => $dep ? ['id' => $dep->id, 'nombre' => $dep->nombre] : null,
            'municipios' => array_values(array_map(fn ($m) => ['id' => $m['id'], 'nombre' => $m['nombre'], 'provincia' => $m['provincia']], $t->municipios())),
            'comunas' => array_values(array_map(fn ($c) => ['id' => $c['id'], 'nombre' => $c['nombre'], 'tipo' => $c['tipo'],
                'municipio_id' => $c['municipio_id'], 'municipio' => $c['municipio']], $t->comunas())),
            'partidos' => $this->partidos($partidoActual),
            'factores' => array_map(fn ($v) => ['defecto' => $v['defecto'], 'min' => $v['min'], 'max' => $v['max']], $f),
        ];
    }

    // Parties and movements that endorsed (primary) a candidacy of some contest, by name, one per name written the same
    // way (case, accents and punctuation aside: the one with more endorsements stays). $actual is always offered.
    public function partidos(?string $actual = null): array
    {
        $rows = DB::table('political_organizations as po')
            ->join('candidacy_endorsements as ce', 'ce.organization_id', '=', 'po.id')
            ->join('candidacies as c', 'c.id', '=', 'ce.candidacy_id')
            ->whereNull('po.merged_into_id')
            ->where('ce.is_primary', true)
            ->groupBy('po.id', 'po.canonical_name')
            ->selectRaw('po.id, po.canonical_name, COUNT(*) AS avales')
            ->get();
        if ($actual && ! $rows->contains('id', $actual)) {
            $extra = DB::table('political_organizations')->where('id', $actual)->first(['id', 'canonical_name']);
            if ($extra) {
                $rows->push((object) ['id' => $extra->id, 'canonical_name' => $extra->canonical_name, 'avales' => PHP_INT_MAX]);
            }
        }
        $clave = fn (string $n) => trim(preg_replace('/[^A-Z0-9]+/', ' ', TextNormalizer::normalizeName($n)));

        return $rows->sortBy([fn ($a, $b) => (int) $b->avales <=> (int) $a->avales, fn ($a, $b) => strcmp($a->canonical_name, $b->canonical_name)])
            ->unique(fn ($p) => $clave($p->canonical_name))
            ->sortBy(fn ($p) => $clave($p->canonical_name))
            ->map(fn ($p) => ['id' => $p->id, 'nombre' => $p->canonical_name])
            ->values()->all();
    }

    private function conMeta(ElectoralCampaign $c): array
    {
        return array_flip(DB::table('electoral_campaign_goals')->where('electoral_campaign_id', $c->id)
            ->where(fn ($q) => $q->whereNotNull('votos_meta')->orWhereNotNull('lideres_meta')->orWhereNotNull('votantes_meta'))
            ->pluck('geographic_unit_id')->all());
    }

    // "Metas cargadas: X de Y": top level rows (municipios, or the comuna alone) with a goal of their own or in a comuna.
    private function resumenMetas(array $filas, array $conMeta): array
    {
        $top = [];
        foreach ($filas as $f) {
            if ($f['padre_id'] === null) {
                $top[$f['id']] = $top[$f['id']] ?? isset($conMeta[$f['id']]);
            } elseif (isset($conMeta[$f['id']])) {
                $top[$f['padre_id']] = true;
            }
        }

        return ['cargadas' => count(array_filter($top)), 'total' => count($top)];
    }

    private static function meta(?ElectoralCampaignGoal $g): ?array
    {
        if (! $g || ($g->votos_meta === null && $g->lideres_meta === null && $g->votantes_meta === null)) {
            return null;
        }

        return ['votos_meta' => $g->votos_meta, 'lideres_meta' => $g->lideres_meta, 'votantes_meta' => $g->votantes_meta];
    }

    // Field by field sum of the goals given; null when none has a value.
    private static function suma(array $metas): ?array
    {
        $metas = array_filter($metas);
        if (! $metas) {
            return null;
        }
        $out = [];
        foreach (ElectoralCampaignGoal::CAMPOS as $campo) {
            $valores = array_filter(array_column($metas, $campo), fn ($v) => $v !== null);
            $out[$campo] = $valores ? array_sum($valores) : null;
        }

        return $out;
    }

    private function sugerido(ElectoralCampaign $c, ?array $s, ?int $votosMeta): ?array
    {
        if (! $s || $s['base'] === null) {
            return null;
        }
        $votos = MetaSugerencia::aplicar($s['base'], $c->factor_votos);
        $ref = $votosMeta ?? $votos;

        return [
            'votos' => $votos,
            'lideres' => intdiv($ref + $c->votos_por_lider - 1, $c->votos_por_lider),
            'votantes' => MetaSugerencia::aplicar($ref, $c->factor_registro),
            'base' => $s['base'],
            'texto' => MetaSugerencia::texto($votos, $s['base'], $s['etiqueta'], $c->factor_votos, $s['sufijo'] ?? null),
        ];
    }

    private function totales(ElectoralCampaign $c, array $filas, ?array $sug): array
    {
        $top = array_values(array_filter($filas, fn ($f) => $f['padre_id'] === null));
        $metas = self::suma(array_map(fn ($f) => $f['meta'] ?? $f['suma_comunas'], $top));
        $out = [
            'votos_meta' => $metas['votos_meta'] ?? null,
            'lideres_meta' => $metas['lideres_meta'] ?? null,
            'votantes_meta' => $metas['votantes_meta'] ?? null,
            'avance' => [
                'votantes' => array_sum(array_map(fn ($f) => $f['avance']['votantes'], $top)),
                'lideres' => array_sum(array_map(fn ($f) => $f['avance']['lideres'], $top)),
            ],
        ];
        if ($sug === null) {
            return $out;
        }

        // Gobernación's total comes from the departamento's own result, unless the user only sees part of it.
        $total = $c->cargo === 'gobernacion' && $this->access->allowedMunicipios() !== null ? null : $sug['total'];
        $sufijo = $total === null && $this->access->allowedMunicipios() === null ? ($sug['total_sufijo'] ?? null) : null;
        if ($total === null) {
            $conBase = array_values(array_filter(array_map(fn ($f) => $f['sugerencia'] ?? null, $top)));
            $etiquetas = array_values(array_unique(array_filter(array_map(fn ($f) => $sug['filas'][$f['id']]['etiqueta'] ?? null, $top))));
            $total = $conBase ? ['base' => array_sum(array_column($conBase, 'base')), 'etiqueta' => implode(' o ', $etiquetas)] : null;
        }
        $out['sugerencia'] = $total ? $this->sugerido($c, $total + ['sufijo' => $sufijo], $out['votos_meta']) : null;

        return $out;
    }
}
