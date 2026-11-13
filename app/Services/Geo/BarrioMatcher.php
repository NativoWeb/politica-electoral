<?php

namespace App\Services\Geo;

use Illuminate\Support\Facades\DB;

// Finds the comuna of a barrio text typed in a ficha of a municipio with comunas, in this order: the grupo político's
// alias for that exact text, a barrio with that exact normalized name, and last pg_trgm similarity >= 0.6 that beats by
// >= 0.1 the best candidate of ANOTHER comuna (two similar barrios of the same comuna are not ambiguous for the comuna).
// A text that names another municipio ("LA PAZ, FLORIDABLANCA") is never assigned unless an alias says so.
class BarrioMatcher
{
    public const ASIGNADO = 'asignado';

    public const AMBIGUO = 'ambiguo';

    public const SIN_MATCH = 'sin_match';

    public const OTRO_MUNICIPIO = 'otro_municipio';

    public const VACIO = 'vacio';

    public const MIN_SIMILARITY = 0.6;

    public const MIN_ADVANTAGE = 0.1;

    private array $memo = [];

    public function __construct(private readonly ComunaCatalog $catalog) {}

    public function normalizeFor(string $municipioId, ?string $text): string
    {
        return BarrioNormalizer::normalize($text, $this->catalog->ownMunicipioStrips($municipioId));
    }

    // ['status', 'texto' (normalized), 'comuna_id', 'barrio_id', 'barrio', 'metodo', 'score', 'candidatos' => [...]]
    public function match(string $municipioId, ?string $campaignId, ?string $text): array
    {
        $norm = $this->normalizeFor($municipioId, $text);

        return $this->memo[$municipioId.'|'.$campaignId.'|'.$norm] ??= $this->resolve($municipioId, $campaignId, $norm);
    }

    public function forget(): void
    {
        $this->memo = [];
    }

    private function resolve(string $municipioId, ?string $campaignId, string $norm): array
    {
        $result = ['status' => self::VACIO, 'texto' => $norm, 'comuna_id' => null, 'barrio_id' => null, 'barrio' => null,
            'metodo' => null, 'score' => null, 'otro_municipio' => null, 'candidatos' => []];
        if ($norm === '' || ! $this->catalog->hasComunas($municipioId)) {
            return $result;
        }

        if ($campaignId) {
            $alias = DB::table('barrio_aliases as a')->leftJoin('barrios as b', 'b.id', '=', 'a.barrio_id')
                ->where('a.campaign_id', $campaignId)->where('a.municipio_id', $municipioId)->where('a.texto_normalizado', $norm)
                ->first(['a.comuna_unit_id', 'a.barrio_id', 'b.nombre']);
            if ($alias && $this->catalog->belongsTo($alias->comuna_unit_id, $municipioId)) {
                return ['status' => self::ASIGNADO, 'comuna_id' => $alias->comuna_unit_id, 'barrio_id' => $alias->barrio_id,
                    'barrio' => $alias->nombre, 'metodo' => 'alias'] + $result;
            }
        }

        $exact = DB::table('barrios')->where('municipio_id', $municipioId)->where('nombre_normalizado', $norm)
            ->first(['id', 'nombre', 'comuna_unit_id']);
        if ($exact) {
            return ['status' => self::ASIGNADO, 'comuna_id' => $exact->comuna_unit_id, 'barrio_id' => $exact->id,
                'barrio' => $exact->nombre, 'metodo' => 'nombre'] + $result;
        }

        foreach ($this->catalog->otherMunicipioNames($municipioId) as $other) {
            if (BarrioNormalizer::containsPhrase($norm, $other)) {
                return ['status' => self::OTRO_MUNICIPIO, 'otro_municipio' => $other] + $result;
            }
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return ['status' => self::SIN_MATCH] + $result;
        }

        $candidates = DB::select(
            'SELECT b.id, b.nombre, b.comuna_unit_id, round(similarity(b.nombre_normalizado, ?)::numeric, 2)::float AS score
             FROM barrios b
             WHERE b.municipio_id = ? AND similarity(b.nombre_normalizado, ?) >= ?
             ORDER BY score DESC, b.nombre
             LIMIT 10',
            [$norm, $municipioId, $norm, self::MIN_SIMILARITY]
        );
        if ($candidates === []) {
            return ['status' => self::SIN_MATCH] + $result;
        }

        $list = array_map(fn ($c) => [
            'barrio_id' => $c->id, 'barrio' => $c->nombre, 'comuna_id' => $c->comuna_unit_id,
            'comuna' => $this->catalog->nameOf($c->comuna_unit_id, $municipioId), 'score' => (float) $c->score,
        ], $candidates);
        $best = $list[0];
        $rival = collect($list)->first(fn ($c) => $c['comuna_id'] !== $best['comuna_id']);

        if ($rival && self::MIN_ADVANTAGE - 1e-9 > $best['score'] - $rival['score']) {
            return ['status' => self::AMBIGUO, 'candidatos' => $list] + $result;
        }

        return ['status' => self::ASIGNADO, 'comuna_id' => $best['comuna_id'], 'barrio_id' => $best['barrio_id'],
            'barrio' => $best['barrio'], 'metodo' => 'similitud', 'score' => $best['score'], 'candidatos' => $list] + $result;
    }
}
