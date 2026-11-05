<?php

namespace App\Http\Controllers;

use App\Models\PoliticalOrganization;
use App\Support\PartyLogos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PartidoCrudController extends Controller
{
    public function index()
    {
        $partidos = DB::table('political_organizations')
            ->orderBy('canonical_name')
            ->get(['id', 'canonical_name as name', 'acronym', 'color_hex as color', 'status', 'logo_path'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'acronym' => $p->acronym,
                'color' => $p->color,
                'active' => $p->status === 'active',
                'logo' => PartyLogos::url($p->logo_path),
            ]);

        return Inertia::render('Admin/Partidos', [
            'partidos' => $partidos,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validatePartido($request);

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

        $data = $this->validatePartido($request, $id);

        DB::table('political_organizations')->where('id', $id)->update([
            'canonical_name' => $data['name'],
            'acronym' => $data['acronym'] ?? null,
            'color_hex' => $data['color'] ?? null,
            'updated_at' => now(),
        ]);

        Cache::forget('all_partidos');
        PartyLogos::forget();
        return back()->with('success', 'Partido actualizado.');
    }

    public function uploadLogo(Request $request, string $id)
    {
        if (! Str::isUuid($id)) abort(404);
        $org = DB::table('political_organizations')->where('id', $id)->first(['logo_path']);
        abort_unless($org, 404);

        // mimetypes checks the real content (finfo), not the extension; SVG is refused (it can carry scripts).
        $request->validate([
            'logo' => 'required|file|max:1024|mimes:png,jpg,jpeg,webp|mimetypes:image/png,image/jpeg,image/webp',
        ], [
            'logo.max' => 'El logo no puede pesar más de 1 MB.',
            'logo.mimes' => 'El logo debe ser una imagen PNG, JPG o WEBP.',
            'logo.mimetypes' => 'El logo debe ser una imagen PNG, JPG o WEBP.',
        ]);

        $path = $request->file('logo')->store(PartyLogos::DIRECTORY, 'public');
        if ($path === false) {
            return back()->withErrors(['logo' => 'No se pudo guardar el logo.']);
        }

        DB::table('political_organizations')->where('id', $id)->update(['logo_path' => $path, 'updated_at' => now()]);
        $this->deleteLogoFile($org->logo_path);

        PartyLogos::forget();
        return back()->with('success', 'Logo actualizado.');
    }

    public function destroyLogo(string $id)
    {
        if (! Str::isUuid($id)) abort(404);
        $org = DB::table('political_organizations')->where('id', $id)->first(['logo_path']);
        abort_unless($org, 404);

        DB::table('political_organizations')->where('id', $id)->update(['logo_path' => null, 'updated_at' => now()]);
        $this->deleteLogoFile($org->logo_path);

        PartyLogos::forget();
        return back()->with('success', 'Logo eliminado.');
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

        $logoPath = DB::table('political_organizations')->where('id', $id)->value('logo_path');

        // Move endorsements to null before deleting
        DB::table('candidacy_endorsements')->where('organization_id', $id)->delete();
        DB::table('lideres')->where('partido', DB::table('political_organizations')->where('id', $id)->value('canonical_name'))->update(['partido' => null]);
        DB::table('political_organizations')->where('id', $id)->delete();
        $this->deleteLogoFile($logoPath);

        Cache::forget('all_partidos');
        PartyLogos::forget();
        return back()->with('success', 'Partido eliminado permanentemente.');
    }

    private function validatePartido(Request $request, ?string $ignoreId = null): array
    {
        $request->merge(['name' => PoliticalOrganization::upperName((string) $request->input('name'))]);

        return $request->validate([
            'name' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) use ($ignoreId) {
                $taken = DB::table('political_organizations')
                    ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                    ->where(fn ($q) => $q->where('canonical_name', $value)->orWhereRaw('UPPER(canonical_name) = ?', [$value]))
                    ->exists();
                if ($taken) {
                    $fail('Ya existe un partido con ese nombre.');
                }
            }],
            'acronym' => 'nullable|string|max:20',
            'color' => 'nullable|string|max:7',
        ]);
    }

    // Only files inside the logos folder are ever deleted.
    private function deleteLogoFile(?string $path): void
    {
        if ($path && str_starts_with($path, PartyLogos::DIRECTORY.'/') && ! str_contains($path, '..')) {
            Storage::disk('public')->delete($path);
        }
    }
}
