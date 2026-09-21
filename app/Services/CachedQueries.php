<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CachedQueries
{
    public static function allMunicipios(): array
    {
        return Cache::remember('all_municipios', 3600, function () {
            return DB::table('geographic_units as g')
                ->leftJoin('geographic_units as prov', 'g.parent_id', '=', 'prov.id')
                ->where('g.type', 'municipality')
                ->select('g.id', 'g.canonical_name as name', 'prov.canonical_name as provincia')
                ->orderBy('g.canonical_name')
                ->get()
                ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'provincia' => $r->provincia])
                ->toArray();
        });
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
