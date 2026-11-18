<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\Lider;
use App\Services\CachedQueries;
use App\Services\Eventos\EventScope;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class LiderController extends Controller
{
    use ScopesTerritory;

    // Fichas listed for a municipio; the stats still count all of them.
    public const MAX_LIDERES = 1000;

    public function index()
    {
        // The first Santander municipio of the territory (then any other), not "Abejorral" of the national catalog.
        $allowed = $this->allowedGeoIds();
        $mine = array_values(array_filter(CachedQueries::allMunicipios(), fn ($m) => $allowed === null || in_array($m['id'], $allowed, true)));
        $first = (collect($mine)->first(fn ($m) => CachedQueries::isSantander($m)) ?? $mine[0] ?? null)['id'] ?? null;
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
        $this->authorizeGeo($municipio->id);

        // Voters (Electo) are not lideres: they have their own section in the Mapa and the Votantes screen.
        $notVotante = fn ($q) => $q->where(fn ($w) => $w->where('tipo', '!=', Lider::TIPO_VOTANTE)->orWhereNull('tipo'));
        $lideres = $this->visibleLideres()
            ->where('geographic_unit_id', $munId)
            ->where($notVotante)
            ->orderByRaw("CASE WHEN cargo = 'CONCEJAL CD' THEN 1 WHEN cargo = 'COORDINADOR MUNICIPAL' THEN 2 WHEN cargo = 'MIEMBRO DIRECTORIO' THEN 3 WHEN cargo = 'REPRESENTANTE JOVENES' THEN 4 WHEN cargo = 'REPRESENTANTE RESERVA' THEN 5 WHEN cargo = 'VEEDOR' THEN 6 ELSE 7 END")
            ->orderBy('nombre')
            ->limit(self::MAX_LIDERES)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'nombre' => $r->nombre,
                'cargo' => $r->cargo,
                'tipo' => EventScope::visibleTipo($r->tipo, request()->user()),
                'telefono' => $r->telefono,
                'email' => $r->email,
            ])
            ->toArray();

        // Stats por cargo
        $statsByCargo = $this->visibleLideres()
            ->where('geographic_unit_id', $munId)
            ->where($notVotante)
            ->select('cargo', DB::raw('COUNT(*) as total'))
            ->groupBy('cargo')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['cargo' => $r->cargo, 'total' => $r->total])
            ->toArray();

        // Stats globales
        $totalLideres = $this->scopeToTerritory($this->visibleLideres())->where($notVotante)->count();
        $totalMunicipios = $this->scopeToTerritory($this->visibleLideres())->where($notVotante)->distinct('geographic_unit_id')->count('geographic_unit_id');

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
