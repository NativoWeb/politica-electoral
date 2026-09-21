<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PoliticalOrganization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CatalogCrudController extends Controller
{
    // --- Partidos ---

    public function storePartido(Request $request)
    {
        $data = $request->validate([
            'canonical_name' => 'required|string|max:255',
            'acronym' => 'nullable|string|max:20',
            'type' => 'nullable|in:party,movement,coalition,other',
            'color_hex' => 'nullable|string|max:7',
            'status' => 'nullable|in:active,inactive,dissolved,suspended',
        ]);

        PoliticalOrganization::create([...$data, 'status' => $data['status'] ?? 'active']);

        return back()->with('success', "Partido «{$data['canonical_name']}» creado.");
    }

    public function updatePartido(Request $request, string $id)
    {
        $org = PoliticalOrganization::findOrFail($id);

        $data = $request->validate([
            'canonical_name' => 'required|string|max:255',
            'acronym' => 'nullable|string|max:20',
            'type' => 'nullable|in:party,movement,coalition,other',
            'color_hex' => 'nullable|string|max:7',
            'status' => 'nullable|in:active,inactive,dissolved,suspended',
        ]);

        $org->update($data);

        return back()->with('success', "Partido «{$org->canonical_name}» actualizado.");
    }

    public function destroyPartido(string $id)
    {
        $org = PoliticalOrganization::findOrFail($id);
        $org->delete();
        return back()->with('success', "Partido «{$org->canonical_name}» eliminado.");
    }

    // --- Oficios ---

    public function storeOficio(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'nullable|in:executive,legislative,other',
        ]);

        DB::table('offices')->insert([
            'id' => \Illuminate\Support\Str::uuid(),
            'name' => $data['name'],
            'type' => $data['type'] ?? 'other',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('success', "Oficio «{$data['name']}» creado.");
    }

    public function updateOficio(Request $request, string $id)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'nullable|in:executive,legislative,other',
        ]);

        DB::table('offices')->where('id', $id)->update([
            'name' => $data['name'],
            'type' => $data['type'],
            'updated_at' => now(),
        ]);

        return back()->with('success', "Oficio actualizado.");
    }

    public function destroyOficio(string $id)
    {
        DB::table('offices')->where('id', $id)->delete();
        return back()->with('success', 'Oficio eliminado.');
    }

    // --- Corporaciones ---

    public function storeCorporacion(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'scope' => 'nullable|in:national,departmental,municipal',
        ]);

        DB::table('corporations')->insert([
            'id' => \Illuminate\Support\Str::uuid(),
            'name' => $data['name'],
            'scope' => $data['scope'] ?? 'municipal',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('success', "Corporacion «{$data['name']}» creada.");
    }

    public function updateCorporacion(Request $request, string $id)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'scope' => 'nullable|in:national,departmental,municipal',
        ]);

        DB::table('corporations')->where('id', $id)->update([
            'name' => $data['name'],
            'scope' => $data['scope'],
            'updated_at' => now(),
        ]);

        return back()->with('success', 'Corporacion actualizada.');
    }

    public function destroyCorporacion(string $id)
    {
        DB::table('corporations')->where('id', $id)->delete();
        return back()->with('success', 'Corporacion eliminada.');
    }
}
