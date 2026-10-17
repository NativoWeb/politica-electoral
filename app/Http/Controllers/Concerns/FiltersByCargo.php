<?php

namespace App\Http\Controllers\Concerns;

trait FiltersByCargo
{
    // Elected/candidate cargo labels per corporation, as shown and filtered in Mapa Político.
    protected const CORP_CARGOS = [
        'Senado' => ['electo' => 'Senador', 'candidato' => 'Candidato Senado'],
        'Cámara de Representantes' => ['electo' => 'Representante', 'candidato' => 'Candidato Cámara'],
        'Asamblea' => ['electo' => 'Diputado', 'candidato' => 'Candidato Asamblea'],
    ];

    protected function parseCargos(?string $raw): array
    {
        return $raw ? array_values(array_filter(array_map('trim', explode(',', $raw)))) : [];
    }

    // Exact match against any comma-separated cargo of the row, ignoring case and accents.
    protected function whereCargoIn($query, array $cargos): void
    {
        if (empty($cargos)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($cargos), 'lower(unaccent(?))'));
        $query->whereRaw(
            "EXISTS (SELECT 1 FROM unnest(string_to_array(cargo, ',')) AS t(v) WHERE lower(unaccent(trim(t.v))) IN ({$placeholders}))",
            $cargos
        );
    }

    protected function whereOutcomeCargoIn($query, string $electo, string $candidato, array $cargos): void
    {
        if (empty($cargos)) {
            return;
        }
        $query->whereRaw(
            "CASE WHEN c.outcome = 'elected' THEN ? ELSE ? END IN (".implode(',', array_fill(0, count($cargos), '?')).')',
            array_merge([$electo, $candidato], $cargos)
        );
    }
}
