<?php

namespace App\Http\Controllers;

use App\Models\CargoCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class CargoCategoryController extends Controller
{
    public function index()
    {
        $cargos = CargoCategory::orderBy('display_order')->get();

        $cargoCounts = DB::table('lideres')
            ->pluck('cargo')
            ->flatMap(fn ($c) => array_map('trim', explode(',', $c ?? '')))
            ->filter()
            ->countBy()
            ->toArray();

        return Inertia::render('Admin/Cargos', [
            'cargos' => $cargos,
            'cargoCounts' => $cargoCounts,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100|unique:cargo_categories,name',
        ]);

        $maxOrder = CargoCategory::max('display_order') ?? 0;

        CargoCategory::create([
            'name' => $data['name'],
            'display_order' => $maxOrder + 1,
        ]);

        return redirect()->back()->with('success', "Cargo \"{$data['name']}\" creado.");
    }

    public function update(Request $request, string $id)
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $cargo = CargoCategory::findOrFail($id);

        $data = $request->validate([
            'name' => "string|max:100|unique:cargo_categories,name,{$id}",
            'display_order' => 'integer',
            'is_highlighted' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $cargo->update($data);

        return redirect()->back()->with('success', 'Cargo actualizado.');
    }

    public function reorder(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'uuid|exists:cargo_categories,id',
        ]);

        foreach ($data['ids'] as $index => $id) {
            CargoCategory::where('id', $id)->update(['display_order' => $index + 1]);
        }

        return response()->json(['success' => true]);
    }

    public function graduar(Request $request)
    {
        $data = $request->validate([
            'from_id' => 'required|uuid|exists:cargo_categories,id',
            'to_id' => 'required|uuid|exists:cargo_categories,id|different:from_id',
        ]);

        $from = CargoCategory::findOrFail($data['from_id']);
        $to = CargoCategory::findOrFail($data['to_id']);

        $count = DB::transaction(function () use ($from, $to) {
            $exact = DB::table('lideres')
                ->where('cargo', $from->name)
                ->update(['cargo' => $to->name, 'updated_at' => now()]);

            $csv = DB::table('lideres')
                ->where('cargo', 'LIKE', '%'.$from->name.'%')
                ->where('cargo', '!=', $from->name)
                ->get(['id', 'cargo']);

            $csvCount = 0;
            foreach ($csv as $row) {
                $parts = array_map('trim', explode(',', $row->cargo));
                $parts = array_map(fn ($p) => $p === $from->name ? $to->name : $p, $parts);
                DB::table('lideres')->where('id', $row->id)
                    ->update(['cargo' => implode(', ', $parts), 'updated_at' => now()]);
                $csvCount++;
            }

            $from->update(['is_active' => false]);

            return $exact + $csvCount;
        });

        Cache::forget('cargos_por_tipo');

        return response()->json(['success' => true, 'affected' => $count]);
    }
}
