<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SearchController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $q = trim($request->input('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        $normalized = mb_strtoupper($q);
        $normalized = str_replace(
            ['Á','É','Í','Ó','Ú','Ñ'],
            ['A','E','I','O','U','N'],
            $normalized
        );

        try {
            $persons = DB::table('persons')
                ->where(function ($query) use ($normalized) {
                    $query->whereRaw("normalized_name % ?", [$normalized])
                        ->orWhereRaw("normalized_name ILIKE ?", ["%{$normalized}%"]);
                })
                ->orderByRaw("similarity(normalized_name, ?) DESC", [$normalized])
                ->limit(5)
                ->select('id', 'full_name as name', 'identity_status')
                ->get()
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'type' => 'persona',
                    'url' => "/persona/{$r->id}",
                    'detail' => $r->identity_status,
                ]);

            $municipios = DB::table('geographic_aliases as ga')
                ->join('geographic_units as g', 'ga.geographic_unit_id', '=', 'g.id')
                ->where('g.type', 'municipality')
                ->where(function ($query) use ($normalized) {
                    $query->whereRaw("ga.normalized_alias % ?", [$normalized])
                        ->orWhereRaw("ga.normalized_alias ILIKE ?", ["%{$normalized}%"]);
                })
                ->select('g.id', 'g.canonical_name as name')
                ->selectRaw("MAX(similarity(ga.normalized_alias, ?)) as sim", [$normalized])
                ->groupBy('g.id', 'g.canonical_name')
                ->orderByDesc('sim')
                ->limit(5)
                ->get()
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'type' => 'municipio',
                    'url' => "/municipio/{$r->id}",
                    'detail' => 'Municipio',
                ]);

            $orgs = DB::table('political_organizations')
                ->where(function ($query) use ($normalized) {
                    $query->whereRaw("canonical_name % ?", [$normalized])
                        ->orWhereRaw("canonical_name ILIKE ?", ["%{$normalized}%"]);
                })
                ->orderByRaw("similarity(canonical_name, ?) DESC", [$normalized])
                ->limit(5)
                ->select('id', 'canonical_name as name', 'acronym')
                ->get()
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'type' => 'partido',
                    'url' => "/",
                    'detail' => $r->acronym ?? 'Partido',
                ]);

            $results = $persons->concat($municipios)->concat($orgs)->toArray();

            return response()->json(['results' => array_values($results)]);
        } catch (\Exception $e) {
            return response()->json(['results' => []], 500);
        }
    }
}
