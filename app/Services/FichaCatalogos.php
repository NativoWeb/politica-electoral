<?php

namespace App\Services;

use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Http\Controllers\MapaPoliticoController;
use App\Models\CargoCategory;
use App\Services\Eventos\EventScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

// What the ficha of the Mapa político (PersonaPanel) needs to offer its cargos and municipios: the Mapa político
// and Reportes (when the user has view.mapa) pass the very same lists.
class FichaCatalogos
{
    use ScopesTerritory;

    // Cargos offered per tipo: the fixed electoral ones plus the catalog and every cargo the visible lideres have.
    public function cargosPorTipo(): array
    {
        $seesEventos = EventScope::canSeeAttendees(request()->user());
        $catalogCargos = CargoCategory::active()->pluck('name')->toArray();
        $liderCargos = self::uniqueCargos(collect($catalogCargos)
            ->merge($this->visibleLideres()->select('cargo')->distinct()->pluck('cargo')
                ->flatMap(fn ($c) => array_map('trim', explode(',', $c)))->filter()))
            ->values()->toArray();

        return [
            'alcaldia' => ['Alcalde Electo', 'Candidato Alcaldía'],
            'concejo' => ['Concejal', 'Candidato Concejo'],
            'lideres' => $liderCargos,
            'nuevos_lideres' => $liderCargos,
            'directorio' => $liderCargos,
            MapaPoliticoController::VOTANTES => $liderCargos,
            ...($seesEventos ? [MapaPoliticoController::ASISTENTES_EVENTO => $liderCargos] : []),
            'senado' => ['Senador', 'Candidato Senado'],
            'camara' => ['Representante', 'Candidato Cámara'],
            'asamblea' => ['Diputado', 'Candidato Asamblea'],
            'todos' => self::uniqueCargos(collect(['Alcalde Electo', 'Candidato Alcaldía', 'Concejal', 'Candidato Concejo', 'Senador', 'Candidato Senado', 'Representante', 'Candidato Cámara', 'Diputado', 'Candidato Asamblea'])
                ->merge($liderCargos))->sort()->values()->toArray(),
        ];
    }

    public function cargoCategorias(): Collection
    {
        return CargoCategory::active()->get(['id', 'name', 'display_order', 'is_highlighted']);
    }

    // Santander plus the municipios of other departamentos with fichas of the visible grupos, inside the territory.
    public function municipios(): array
    {
        $municipios = CachedQueries::filterMunicipios($this->campaign()->visibleIds());
        $allowed = $this->allowedGeoIds();

        return $allowed ? array_values(array_filter($municipios, fn ($m) => in_array($m['id'], $allowed))) : $municipios;
    }

    // "Lider" and "Líder" are one option: the first spelling in the list wins (catalog or fixed list first).
    private static function uniqueCargos(Collection $cargos): Collection
    {
        return $cargos->unique(fn ($c) => mb_strtolower(Str::ascii($c)));
    }
}
