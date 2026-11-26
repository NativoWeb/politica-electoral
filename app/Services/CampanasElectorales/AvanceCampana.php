<?php

namespace App\Services\CampanasElectorales;

use App\Models\ElectoralCampaign;
use App\Support\EsLider;
use Illuminate\Support\Facades\DB;

// Basic progress of a campaign: the fichas of its owner group only (never its subgroups), active and alive.
// Votantes = fichas in the unit (municipio by geographic_unit_id, comuna by comuna_id); líderes = the same ones that
// count as leaders (App\Support\EsLider: cargo «líder», tipo lider/nuevo_lider or the box); por_origen = the votantes
// of the unit by how their comuna was found (auto = barrio, texto, puesto, manual; docs/geografia.md), so a comuna can say "incluye N por puesto de votación". The comunas plus "sin comuna" add up to
// the municipio. One grouped query per campaign.
class AvanceCampana
{
    /**
     * @return array{unidades: array<string, array{votantes: int, lideres: int, por_origen: array<string, int>}>, sin_comuna: array<string, array{votantes: int, lideres: int}>}
     */
    public function calcular(ElectoralCampaign $c, array $filas): array
    {
        $municipios = array_values(array_unique(array_column($filas, 'municipio_id')));
        $out = ['unidades' => [], 'sin_comuna' => []];
        foreach ($filas as $f) {
            $out['unidades'][$f['id']] = ['votantes' => 0, 'lideres' => 0, 'por_origen' => self::origenes()];
        }
        if (! $municipios) {
            return $out;
        }
        [$esLider, $bindings] = EsLider::sql();
        $rows = DB::table('lideres')
            ->where('campaign_id', $c->campaign_id)
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('fallecido', false)->orWhereNull('fallecido'))
            ->whereIn('geographic_unit_id', $municipios)
            ->groupBy('geographic_unit_id', 'comuna_id', 'comuna_source')
            ->selectRaw("geographic_unit_id, comuna_id, comuna_source, COUNT(*) AS votantes, SUM(CASE WHEN {$esLider} THEN 1 ELSE 0 END) AS lideres", $bindings)
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
                    if ($r->comuna_id !== null && isset($out['unidades'][$unidad]['por_origen'][$r->comuna_source])) {
                        $out['unidades'][$unidad]['por_origen'][$r->comuna_source] += $n['votantes'];
                    }
                }
            }
            if ($r->comuna_id === null && isset($conComunas[$r->geographic_unit_id])) {
                $prev = $out['sin_comuna'][$r->geographic_unit_id] ?? ['votantes' => 0, 'lideres' => 0];
                $out['sin_comuna'][$r->geographic_unit_id] = ['votantes' => $prev['votantes'] + $n['votantes'], 'lideres' => $prev['lideres'] + $n['lideres']];
            }
        }

        return $out;
    }

    private static function origenes(): array
    {
        return ['auto' => 0, 'texto' => 0, 'direccion' => 0, 'puesto' => 0, 'manual' => 0];
    }
}
