<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PartidoCrudController extends Controller
{
    public function index()
    {
        $partidos = DB::table('political_organizations')
            ->orderBy('canonical_name')
            ->get(['id', 'canonical_name as name', 'acronym', 'color_hex as color', 'status'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'acronym' => $p->acronym,
                'color' => $p->color,
                'active' => $p->status === 'active',
            ]);

        return Inertia::render('Admin/Partidos', [
            'partidos' => $partidos,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'acronym' => 'nullable|string|max:20',
            'color' => 'nullable|string|max:7',
        ]);

        DB::table('political_organizations')->insert([
            'id' => Str::uuid(),
            'canonical_name' => $data['name'],
            'acronym' => $data['acronym'] ?? null,
            'color_hex' => $data['color'] ?? null,
            'type' => 'party',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Cache::forget('all_partidos');
        return back()->with('success', 'Partido creado.');
    }

    public function update(Request $request, string $id)
    {
        if (!Str::isUuid($id)) abort(404);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'acronym' => 'nullable|string|max:20',
            'color' => 'nullable|string|max:7',
        ]);

        DB::table('political_organizations')->where('id', $id)->update([
            'canonical_name' => $data['name'],
            'acronym' => $data['acronym'] ?? null,
            'color_hex' => $data['color'] ?? null,
            'updated_at' => now(),
        ]);

        Cache::forget('all_partidos');
        return back()->with('success', 'Partido actualizado.');
    }

    public function destroy(string $id)
    {
        if (!Str::isUuid($id)) abort(404);

        DB::table('political_organizations')->where('id', $id)->update([
            'status' => 'inactive',
            'updated_at' => now(),
        ]);

        Cache::forget('all_partidos');
        return back()->with('success', 'Partido desactivado.');
    }

    public function restore(string $id)
    {
        if (!Str::isUuid($id)) abort(404);

        DB::table('political_organizations')->where('id', $id)->update([
            'status' => 'active',
            'updated_at' => now(),
        ]);

        Cache::forget('all_partidos');
        return back()->with('success', 'Partido reactivado.');
    }

    public function forceDelete(string $id)
    {
        if (!Str::isUuid($id)) abort(404);

        // Move endorsements to null before deleting
        DB::table('candidacy_endorsements')->where('organization_id', $id)->delete();
        DB::table('lideres')->where('partido', DB::table('political_organizations')->where('id', $id)->value('canonical_name'))->update(['partido' => null]);
        DB::table('political_organizations')->where('id', $id)->delete();

        Cache::forget('all_partidos');
        return back()->with('success', 'Partido eliminado permanentemente.');
    }
}
