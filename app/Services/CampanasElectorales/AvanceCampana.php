<?php

namespace App\Services\CampanasElectorales;

use App\Models\ElectoralCampaign;
use Illuminate\Support\Facades\DB;

// Basic progress of a campaign: the fichas of its owner group only (never its subgroups), active and alive.
// Votantes = fichas in the unit (municipio by geographic_unit_id, comuna by comuna_id); líderes = the same ones marked
// es_lider. One grouped query per campaign.
class AvanceCampana
{
    /**
     * @return array{unidades: array<string, array{votantes: int, lideres: int}>, sin_comuna: array<string, array{votantes: int, lideres: int}>}
     */
    public function calcular(ElectoralCampaign $c, array $filas): array
    {
        $municipios = array_values(array_unique(array_column($filas, 'municipio_id')));
        $out = ['unidades' => [], 'sin_comuna' => []];
        foreach ($filas as $f) {
            $out['unidades'][$f['id']] = ['votantes' => 0, 'lideres' => 0];
        }
        if (! $municipios) {
            return $out;
        }
        $rows = DB::table('lideres')
            ->where('campaign_id', $c->campaign_id)
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('fallecido', false)->orWhereNull('fallecido'))
            ->whereIn('geographic_unit_id', $municipios)
            ->groupBy('geographic_unit_id', 'comuna_id')
            ->selectRaw('geographic_unit_id, comuna_id, COUNT(*) AS votantes, SUM(CASE WHEN es_lider THEN 1 ELSE 0 END) AS lideres')
            ->get();

        $conComunas = [];
        foreach ($filas as $f) {
            if ($f['padre_id'] !== null) {
                $conComunas[$f['padre_id']] = true;
            }
        }
        foreach ($rows as $r) {
            $n = ['votantes' => (int) $r->votantes, 'lideres' => (int) $r->lideres];
            foreach ([$r->geographic_unit_id, $r->comuna_id] as $unidad) {
                if ($unidad !== null && isset($out['unidades'][$unidad])) {
                    $out['unidades'][$unidad]['votantes'] += $n['votantes'];
                    $out['unidades'][$unidad]['lideres'] += $n['lideres'];
                }
            }
            if ($r->comuna_id === null && isset($conComunas[$r->geographic_unit_id])) {
                $out['sin_comuna'][$r->geographic_unit_id] = $n;
            }
        }

        return $out;
    }
}
