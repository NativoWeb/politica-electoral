<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CachedQueries
{
    // The Provincia option that groups the municipios of other departamentos (only those with fichas).
    public const FUERA_DE_SANTANDER = 'Fuera de Santander';

    // Every municipio of the country (DIVIPOLA). Santander's hang from a provincia; the rest from their departamento,
    // which is their 'provincia' here. 'santander' tells them apart.
    public static function allMunicipios(): array
    {
        return Cache::remember('all_municipios', 3600, function () {
            return DB::table('geographic_units as g')
                ->leftJoin('geographic_units as prov', 'g.parent_id', '=', 'prov.id')
                ->leftJoin('geographic_units as dep', 'prov.parent_id', '=', 'dep.id')
                ->where('g.type', 'municipality')
                ->select('g.id', 'g.canonical_name as name', 'prov.canonical_name as provincia', 'prov.type as parent_type',
                    'dep.canonical_name as departamento', 'dep.type as dep_type')
                ->orderBy('g.canonical_name')
                ->get()
                ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'provincia' => $r->provincia,
                    'santander' => ! self::otherDepartamento($r)])
                ->toArray();
        });
    }

    // Under a departamento other than Santander, directly (DIVIPOLA) or through a provincia.
    private static function otherDepartamento(object $r): bool
    {
        $departamento = match (true) {
            $r->parent_type === 'department' => $r->provincia,
            $r->dep_type === 'department' => $r->departamento,
            default => null,
        };

        return $departamento !== null && $departamento !== 'Santander';
    }

    public static function isSantander(array $municipio): bool
    {
        // An entry cached before the flag existed was Santander's (the platform only had Santander).
        return $municipio['santander'] ?? true;
    }

    public static function santanderMunicipios(): array
    {
        return array_values(array_filter(self::allMunicipios(), fn ($m) => self::isSantander($m)));
    }

    // Filters and selectors: Santander's municipios plus, under the provincia "Fuera de Santander", the municipios of
    // other departamentos where these campaigns (null: all) have fichas. A national catalog of ~1.100 municipios would
    // bury the 87 that matter.
    public static function filterMunicipios(?array $campaignIds): array
    {
        $all = self::allMunicipios();
        if (! collect($all)->contains(fn ($m) => ! self::isSantander($m))) {
            return $all;
        }

        $withFichas = array_flip(DB::table('lideres')
            ->when($campaignIds !== null, fn ($q) => $q->whereIn('campaign_id', $campaignIds))
            ->whereNotNull('geographic_unit_id')
            ->distinct()
            ->pluck('geographic_unit_id')
            ->all());

        $santander = [];
        $fuera = [];
        foreach ($all as $m) {
            if (self::isSantander($m)) {
                $santander[] = $m;
            } elseif (isset($withFichas[$m['id']])) {
                $fuera[] = ['departamento' => $m['provincia']] + array_merge($m, ['provincia' => self::FUERA_DE_SANTANDER]);
            }
        }

        return array_merge($santander, $fuera);
    }

    public static function partyTotals(string $corpName): array
    {
        return Cache::remember("party_totals_{$corpName}", 1800, function () use ($corpName) {
            $corpId = DB::table('corporations')->where('name', $corpName)->value('id');
            if (!$corpId) return [];

            // Try direct org_id first
            $direct = DB::table('electoral_results as er')
                ->join('contests as con', 'er.contest_id', '=', 'con.id')
                ->where('con.corporation_id', $corpId)
                ->where('er.metric_type', 'votes')
                ->whereNotNull('er.organization_id')
                ->count();

            if ($direct > 0) {
                return DB::table('electoral_results as er')
                    ->join('contests as con', 'er.contest_id', '=', 'con.id')
                    ->where('con.corporation_id', $corpId)
                    ->where('er.metric_type', 'votes')
                    ->whereNotNull('er.organization_id')
                    ->join('political_organizations as po', 'er.organization_id', '=', 'po.id')
                    ->select('po.id', 'po.canonical_name as name', 'po.acronym', 'po.color_hex', DB::raw('SUM(er.value) as total'))
                    ->groupBy('po.id', 'po.canonical_name', 'po.acronym', 'po.color_hex')
                    ->orderByDesc('total')
                    ->get()
                    ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'acronym' => $r->acronym, 'color' => $r->color_hex, 'total' => (int) $r->total])
                    ->toArray();
            }

            // Fallback: endorsements
            return DB::table('electoral_results as er')
                ->join('candidacies as c', 'er.candidacy_id', '=', 'c.id')
                ->join('contests as con', 'er.contest_id', '=', 'con.id')
                ->join('candidacy_endorsements as ce', fn ($j) => $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true))
                ->join('political_organizations as po', 'ce.organization_id', '=', 'po.id')
                ->where('con.corporation_id', $corpId)
                ->whereIn('er.metric_type', ['votes', 'nominal_votes'])
                ->select('po.id', 'po.canonical_name as name', 'po.acronym', 'po.color_hex', DB::raw('SUM(er.value) as total'))
                ->groupBy('po.id', 'po.canonical_name', 'po.acronym', 'po.color_hex')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'acronym' => $r->acronym, 'color' => $r->color_hex, 'total' => (int) $r->total])
                ->toArray();
        });
    }

    public static function results(string $corpName): array
    {
        return Cache::remember("results_{$corpName}", 1800, function () use ($corpName) {
            $corpId = DB::table('corporations')->where('name', $corpName)->value('id');
            if (!$corpId) return [];

            return DB::table('candidacies as c')
                ->join('persons as p', 'c.person_id', '=', 'p.id')
                ->join('contests as con', 'c.contest_id', '=', 'con.id')
                ->where('con.corporation_id', $corpId)
                ->leftJoin('candidacy_endorsements as ce', fn ($j) => $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true))
                ->leftJoin('political_organizations as po', 'ce.organization_id', '=', 'po.id')
                ->leftJoinSub(
                    DB::table('electoral_results')->whereIn('metric_type', ['votes', 'nominal_votes'])
                        ->select('candidacy_id', DB::raw('SUM(value) as votos'))->groupBy('candidacy_id'),
                    'res', 'res.candidacy_id', '=', 'c.id'
                )
                ->select('p.id', 'p.full_name as name', 'po.canonical_name as partido', 'po.acronym', 'po.color_hex',
                    DB::raw('COALESCE(res.votos, 0) as votos'))
                ->orderByDesc('res.votos')
                ->limit(30)
                ->get()
                ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'partido' => $r->partido, 'acronym' => $r->acronym, 'color' => $r->color_hex, 'votos' => (int) $r->votos])
                ->toArray();
        });
    }

    public static function flush(): void
    {
        Cache::forget('all_municipios');
        foreach (['Senado', 'Cámara de Representantes', 'Asamblea', 'Concejo'] as $corp) {
            Cache::forget("party_totals_{$corp}");
            Cache::forget("results_{$corp}");
        }
    }
}
