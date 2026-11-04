<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// What /gobernador shows and what its Excel/PDF export: one source for both, so the file always matches the screen.
// Only department totals are loaded for Gobernación: a municipio shows the candidates without votes, never the
// Santander totals as if they were its own.
class GobernacionResultados
{
    public const SIN_RESULTADOS_MUNICIPIO = 'No hay resultados de Gobernación por municipio cargados. Los votos solo están disponibles para el total de Santander.';

    public function officeId(): ?string
    {
        return DB::table('offices')->where('name', 'Gobernación')->value('id');
    }

    // Only a municipio: the department or a province would read "SANTANDER / SANTANDER".
    public function municipio(?string $id): ?object
    {
        if (! $id || ! Str::isUuid($id)) {
            return null;
        }

        return DB::table('geographic_units as g')
            ->leftJoin('geographic_units as prov', 'g.parent_id', '=', 'prov.id')
            ->where('g.id', $id)
            ->where('g.type', 'municipality')
            ->select('g.id', 'g.canonical_name as name', 'prov.canonical_name as provincia')
            ->first();
    }

    public function candidatos(?string $officeId, bool $conVotos): array
    {
        return DB::table('candidacies as c')
            ->join('contests as con', 'c.contest_id', '=', 'con.id')
            ->join('persons as p', 'c.person_id', '=', 'p.id')
            ->where('con.office_id', $officeId)
            ->leftJoin('candidacy_endorsements as ce', fn ($j) => $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true))
            ->leftJoin('political_organizations as po', 'ce.organization_id', '=', 'po.id')
            ->leftJoinSub(
                DB::table('electoral_results')->whereIn('metric_type', ['votes', 'nominal_votes'])
                    ->select('candidacy_id', DB::raw('SUM(value) as votos'))->groupBy('candidacy_id'),
                'res', 'res.candidacy_id', '=', 'c.id'
            )
            ->select('p.id', 'p.full_name as name', 'po.canonical_name as partido', 'po.acronym', 'po.color_hex',
                'c.outcome', DB::raw('COALESCE(res.votos, 0) as votos'))
            ->orderByDesc('res.votos')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'partido' => $r->partido,
                'acronym' => $r->acronym, 'color' => $r->color_hex,
                'votos' => $conVotos ? (int) $r->votos : null, 'electo' => $r->outcome === 'elected',
            ])->toArray();
    }

    public function resumen(?string $officeId): ?array
    {
        $t = DB::table('turnout_metrics as t')
            ->join('contests as con', 't.contest_id', '=', 'con.id')
            ->leftJoin('source_files as sf', 't.source_id', '=', 'sf.id')
            ->where('con.office_id', $officeId)
            ->where('t.grain', 'department')
            ->select('t.electoral_potential', 't.total_voters', 't.valid_votes', 't.blank_votes', 't.null_votes',
                't.unmarked_votes', 't.participation_pct', 'sf.original_name as fuente', 'sf.storage_path as fuente_url')
            ->first();
        if (! $t) {
            return null;
        }

        $int = fn ($v) => $v === null ? null : (int) $v;

        return [
            'potencial' => $int($t->electoral_potential),
            'votantes' => $int($t->total_voters),
            'validos' => $int($t->valid_votes),
            'blanco' => $int($t->blank_votes),
            'nulos' => $int($t->null_votes),
            'noMarcados' => $int($t->unmarked_votes),
            'participacion' => $t->participation_pct === null ? null : (float) $t->participation_pct,
            'fuente' => $t->fuente,
            'fuenteUrl' => $t->fuente_url && str_starts_with($t->fuente_url, 'https://') ? $t->fuente_url : null,
        ];
    }

    // Percentages over valid votes (candidates + blank), as the Registraduría publishes them.
    public static function base(array $candidatos, ?array $resumen): int
    {
        return $resumen['validos'] ?? array_sum(array_map(fn ($c) => $c['votos'] ?? 0, $candidatos)) + ($resumen['blanco'] ?? 0);
    }

    // Truncated, not rounded, to 2 decimals: the same figure as pctTrunc() on screen ("38,97").
    public static function porcentaje(?int $votos, int $base): ?string
    {
        if ($votos === null || $base <= 0) {
            return null;
        }

        return number_format(intdiv($votos * 10000, $base) / 100, 2, ',', '');
    }
}
