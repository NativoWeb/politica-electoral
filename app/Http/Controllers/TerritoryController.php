<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class TerritoryController extends Controller
{
    public function index()
    {
        $countryId = DB::table('geographic_units')->where('type', 'country')->value('id');

        $departamentos = DB::table('geographic_units')
            ->where('type', 'department')
            ->orderBy('canonical_name')
            ->get(['id', 'canonical_name as name', 'official_code as code'])
            ->map(function ($dept) {
                $dept->provincias = DB::table('geographic_units')
                    ->where('parent_id', $dept->id)
                    ->where('type', 'province')
                    ->orderBy('canonical_name')
                    ->get(['id', 'canonical_name as name'])
                    ->map(function ($p) {
                        $p->municipios = DB::table('geographic_units')
                            ->where('parent_id', $p->id)
                            ->where('type', 'municipality')
                            ->orderBy('canonical_name')
                            ->get(['id', 'canonical_name as name', 'official_code as code'])
                            ->toArray();
                        $p->count = count($p->municipios);
                        return $p;
                    })->toArray();
                $dept->totalProvincias = count($dept->provincias);
                $dept->totalMunicipios = collect($dept->provincias)->sum('count');
                return $dept;
            });

        return Inertia::render('Admin/Territory', [
            'departamentos' => $departamentos,
            'countryId' => $countryId,
        ]);
    }

    public function storeDepartamento(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:10',
        ]);

        $countryId = DB::table('geographic_units')->where('type', 'country')->value('id');

        DB::table('geographic_units')->insert([
            'id' => Str::uuid(),
            'parent_id' => $countryId,
            'type' => 'department',
            'canonical_name' => $data['name'],
            'official_code' => $data['code'] ?? null,
            'level' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('success', 'Departamento creado.');
    }

    public function storeProvincia(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'departamento_id' => 'required|exists:geographic_units,id',
        ]);

        DB::table('geographic_units')->insert([
            'id' => Str::uuid(),
            'parent_id' => $data['departamento_id'],
            'type' => 'province',
            'canonical_name' => $data['name'],
            'level' => 2,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('success', 'Provincia creada.');
    }

    public function storeMunicipio(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'provincia_id' => 'required|exists:geographic_units,id',
            'code' => 'nullable|string|max:20',
        ]);

        DB::table('geographic_units')->insert([
            'id' => Str::uuid(),
            'parent_id' => $data['provincia_id'],
            'type' => 'municipality',
            'canonical_name' => $data['name'],
            'official_code' => $data['code'] ?? null,
            'level' => 3,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Cache::forget('all_municipios');

        return back()->with('success', 'Municipio creado.');
    }
}
