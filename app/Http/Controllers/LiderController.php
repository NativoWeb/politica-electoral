<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class LiderController extends Controller
{
    public function index()
    {
        $first = DB::table('geographic_units')->where('type', 'municipality')->orderBy('canonical_name')->value('id');
        if ($first) return redirect("/lideres/{$first}");
        return Inertia::render('Lideres', ['municipio' => null, 'lideres' => [], 'allMunicipios' => []]);
    }

    public function show(string $munId)
    {
        $municipio = DB::table('geographic_units as g')
            ->leftJoin('geographic_units as prov', 'g.parent_id', '=', 'prov.id')
            ->where('g.id', $munId)
            ->select('g.id', 'g.canonical_name as name', 'prov.canonical_name as provincia')
            ->first();

        abort_if(!$municipio, 404);

        $lideres = DB::table('lideres')
            ->where('geographic_unit_id', $munId)
            ->orderByRaw("CASE WHEN cargo = 'CONCEJAL CD' THEN 1 WHEN cargo = 'COORDINADOR MUNICIPAL' THEN 2 WHEN cargo = 'MIEMBRO DIRECTORIO' THEN 3 WHEN cargo = 'REPRESENTANTE JOVENES' THEN 4 WHEN cargo = 'REPRESENTANTE RESERVA' THEN 5 WHEN cargo = 'VEEDOR' THEN 6 ELSE 7 END")
            ->orderBy('nombre')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'nombre' => $r->nombre,
                'cargo' => $r->cargo,
                'tipo' => $r->tipo,
                'telefono' => $r->telefono,
                'email' => $r->email,
            ])
            ->toArray();

        // Stats por cargo
        $statsByCargo = DB::table('lideres')
            ->where('geographic_unit_id', $munId)
            ->select('cargo', DB::raw('COUNT(*) as total'))
            ->groupBy('cargo')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['cargo' => $r->cargo, 'total' => $r->total])
            ->toArray();

        // Stats globales
        $totalLideres = DB::table('lideres')->count();
        $totalMunicipios = DB::table('lideres')->distinct('geographic_unit_id')->count('geographic_unit_id');

        $allMunicipios = DB::table('geographic_units as g')
            ->leftJoin('geographic_units as prov', 'g.parent_id', '=', 'prov.id')
            ->where('g.type', 'municipality')
            ->select('g.id', 'g.canonical_name as name', 'prov.canonical_name as provincia')
            ->orderBy('g.canonical_name')->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'provincia' => $r->provincia])->toArray();

        return Inertia::render('Lideres', [
            'municipio' => $municipio,
            'lideres' => $lideres,
            'statsByCargo' => $statsByCargo,
            'totalLideres' => $totalLideres,
            'totalMunicipios' => $totalMunicipios,
            'allMunicipios' => $allMunicipios,
        ]);
    }
}
