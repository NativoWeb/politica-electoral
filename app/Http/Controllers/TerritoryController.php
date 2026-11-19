<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class TerritoryController extends Controller
{
    private const PRINCIPAL_CODE = '68';

    // Admin home: the territory screens and the rest of the admin options as cards.
    public function index(Request $request)
    {
        if ($request->query('tab') === 'barrios') {
            return redirect()->route('admin.territory.barrios');
        }

        $departamentos = DB::table('geographic_units')->where('type', 'department')
            ->get(['id', 'canonical_name as name', 'official_code as code']);
        $provinciaDept = DB::table('geographic_units')->where('type', 'province')->pluck('parent_id', 'id');
        $munPorPadre = DB::table('geographic_units')->where('type', 'municipality')
            ->selectRaw('parent_id, count(*) as n')->groupBy('parent_id')->pluck('n', 'parent_id');

        $munPorDept = [];
        foreach ($munPorPadre as $parentId => $n) {
            $deptId = $provinciaDept[$parentId] ?? $parentId;
            $munPorDept[$deptId] = ($munPorDept[$deptId] ?? 0) + (int) $n;
        }
        $principal = $this->principalOf($departamentos);

        return Inertia::render('Admin/Territory', [
            'resumen' => [
                'departamentos' => $departamentos->count(),
                'municipios' => $departamentos->sum(fn ($d) => $munPorDept[$d->id] ?? 0),
                'principal' => $principal ? [
                    'name' => $principal->name,
                    'provincias' => $provinciaDept->filter(fn ($deptId) => $deptId === $principal->id)->count(),
                    'municipios' => $munPorDept[$principal->id] ?? 0,
                ] : null,
            ],
        ]);
    }

    // Santander (the platform's territory) goes whole; every other departamento only with its counts and the names of
    // its municipios (for the search): their rows come from municipiosDepartamento() when one is opened.
    public function divisiones()
    {
        $tree = $this->tree();

        return Inertia::render('Admin/TerritoryDivisiones', [
            'principal' => $tree['principal'],
            'departamentos' => $tree['otros'],
            'provincias' => $tree['provincias'],
            'totalMunicipios' => $tree['totalMunicipios'],
        ]);
    }

    public function barrios()
    {
        return Inertia::render('Admin/TerritoryBarrios');
    }

    // GET /admin/territorio/departamento/{id}/municipios
    public function municipiosDepartamento(string $id)
    {
        $dept = DB::table('geographic_units')->where('id', $id)->where('type', 'department')->first(['id']);
        if (! $dept) {
            return response()->json(['success' => false, 'message' => 'Departamento no encontrado.'], 404);
        }

        $provincias = DB::table('geographic_units')->where('parent_id', $id)->where('type', 'province')
            ->orderBy('canonical_name')->get(['id', 'canonical_name as name', 'is_active']);
        $municipios = DB::table('geographic_units')->where('type', 'municipality')
            ->whereIn('parent_id', $provincias->pluck('id')->push($id))
            ->orderBy('canonical_name')
            ->get(['id', 'parent_id', 'canonical_name as name', 'official_code as code', 'is_active'])
            ->groupBy('parent_id');

        return response()->json([
            'success' => true,
            'provincias' => $provincias->map(fn ($p) => $this->provinciaRow($p, $municipios->get($p->id, collect())))->values(),
            'municipios' => $this->municipioRows($municipios->get($id, collect())),
        ]);
    }

    private function tree(): array
    {
        $departamentos = DB::table('geographic_units')->where('type', 'department')->orderBy('canonical_name')
            ->get(['id', 'canonical_name as name', 'official_code as code', 'is_active']);
        $provincias = DB::table('geographic_units')->where('type', 'province')->orderBy('canonical_name')
            ->get(['id', 'parent_id', 'canonical_name as name', 'is_active']);
        $municipios = DB::table('geographic_units')->where('type', 'municipality')->orderBy('canonical_name')
            ->get(['id', 'parent_id', 'canonical_name as name', 'official_code as code', 'is_active']);
        $munByParent = $municipios->groupBy('parent_id');
        $provByDept = $provincias->groupBy('parent_id');

        $principal = $this->principalOf($departamentos);

        $deptNames = $departamentos->pluck('name', 'id');
        $total = 0;
        $otros = [];
        $principalRow = null;
        foreach ($departamentos as $dept) {
            $provs = $provByDept->get($dept->id, collect());
            $direct = $munByParent->get($dept->id, collect());
            $count = $direct->count() + $provs->sum(fn ($p) => $munByParent->get($p->id, collect())->count());
            $total += $count;

            $row = [
                'id' => $dept->id,
                'name' => $dept->name,
                'code' => $dept->code,
                'is_active' => (bool) $dept->is_active,
                'totalProvincias' => $provs->count(),
                'totalMunicipios' => $count,
            ];

            if ($principal && $dept->id === $principal->id) {
                $principalRow = $row + [
                    'provincias' => $provs->map(fn ($p) => $this->provinciaRow($p, $munByParent->get($p->id, collect())))->values()->all(),
                    'municipios' => $this->municipioRows($direct),
                ];

                continue;
            }

            $otros[] = $row + [
                'nombresMunicipios' => $direct->pluck('name')
                    ->merge($provs->flatMap(fn ($p) => $munByParent->get($p->id, collect())->pluck('name')))
                    ->values()->all(),
            ];
        }

        return [
            'principal' => $principalRow,
            'otros' => $otros,
            'provincias' => $provincias->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'dept' => $deptNames->get($p->parent_id, ''),
            ])->values()->all(),
            'totalMunicipios' => $total,
        ];
    }

    // Santander: by its DANE code, or by name when it was created by hand without one.
    private function principalOf($departamentos): ?object
    {
        return $departamentos->first(fn ($d) => $d->code === self::PRINCIPAL_CODE)
            ?? $departamentos->first(fn ($d) => Str::lower(Str::ascii(trim($d->name))) === 'santander');
    }

    private function provinciaRow(object $p, $municipios): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'is_active' => (bool) $p->is_active,
            'count' => $municipios->count(),
            'municipios' => $this->municipioRows($municipios),
        ];
    }

    private function municipioRows($municipios): array
    {
        return $municipios->map(fn ($m) => [
            'id' => $m->id,
            'parent_id' => $m->parent_id,
            'name' => $m->name,
            'code' => $m->code,
            'is_active' => (bool) $m->is_active,
        ])->values()->all();
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

    public function update(Request $request, string $id)
    {
        if (! Str::isUuid($id)) {
            abort(400, 'ID inválido.');
        }

        $unit = DB::table('geographic_units')->where('id', $id)->first();
        if (! $unit) {
            abort(404, 'Unidad geográfica no encontrada.');
        }

        $rules = [
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:20',
        ];

        if ($unit->type === 'municipality') {
            $rules['parent_id'] = 'nullable|uuid|exists:geographic_units,id';
        }

        $data = $request->validate($rules);

        $updateData = [
            'canonical_name' => $data['name'],
            'official_code' => $data['code'] ?? null,
            'updated_at' => now(),
        ];

        if ($unit->type === 'municipality' && ! empty($data['parent_id']) && $data['parent_id'] !== $unit->parent_id) {
            $newParent = DB::table('geographic_units')->where('id', $data['parent_id'])->first();
            if (! $newParent || $newParent->type !== 'province') {
                abort(400, 'El destino debe ser una provincia.');
            }
            $updateData['parent_id'] = $data['parent_id'];
        }

        if ($unit->type === 'province' && ! empty($data['parent_id']) && $data['parent_id'] !== $unit->parent_id) {
            $newParent = DB::table('geographic_units')->where('id', $data['parent_id'])->first();
            if (! $newParent || $newParent->type !== 'department') {
                abort(400, 'El destino debe ser un departamento.');
            }
            $updateData['parent_id'] = $data['parent_id'];
        }

        DB::table('geographic_units')->where('id', $id)->update($updateData);

        Cache::forget('all_municipios');

        return back()->with('success', ucfirst($unit->type) . ' actualizado.');
    }

    public function toggleActive(string $id)
    {
        if (! Str::isUuid($id)) {
            abort(400, 'ID inválido.');
        }

        $unit = DB::table('geographic_units')->where('id', $id)->first();
        if (! $unit) {
            abort(404, 'Unidad geográfica no encontrada.');
        }

        $newState = ! $unit->is_active;

        DB::table('geographic_units')->where('id', $id)->update([
            'is_active' => $newState,
            'updated_at' => now(),
        ]);

        if ($unit->type === 'province' && ! $newState) {
            DB::table('geographic_units')
                ->where('parent_id', $id)
                ->where('type', 'municipality')
                ->update(['is_active' => false, 'updated_at' => now()]);
        }

        Cache::forget('all_municipios');

        $label = match ($unit->type) {
            'department' => 'Departamento',
            'province' => 'Provincia',
            'municipality' => 'Municipio',
            default => 'Unidad',
        };

        return back()->with('success', $label . ($newState ? ' activado.' : ' desactivado.'));
    }
}
