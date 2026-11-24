<?php

namespace App\Services\Geo;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Finds the comuna of the polling station a ficha says, among the puestos_votacion of its municipio: the normalized
// name or a curated alias exactly; then the same without the kind of institution in front ("I.E. INEM CUSTODIO GARCIA
// ROVIRA SEDE A" = alias "INEM CUSTODIO GARCIA ROVIRA SEDE A"), only if every puesto it meets is of one comuna; or else trigram similarity >= 0.6 that beats by >= 0.1 the best candidate of ANOTHER
// comuna (two sedes of the same comuna are not ambiguous for the comuna). The list is small (~90 per municipio) and is
// read once.
class PuestoMatcher
{
    public const ASIGNADO = 'asignado';

    public const AMBIGUO = 'ambiguo';

    public const SIN_MATCH = 'sin_match';

    public const VACIO = 'vacio';

    public const MIN_SIMILARITY = 0.6;

    public const MIN_ADVANTAGE = 0.1;

    private array $puestos = [];

    private array $memo = [];

    private ?bool $table = null;

    // ['status', 'texto' (normalized), 'comuna_id', 'puesto_id', 'puesto', 'metodo', 'score', 'candidatos']
    public function match(string $municipioId, ?string $text): array
    {
        $norm = PuestoNormalizer::normalize($text);

        return $this->memo[$municipioId.'|'.$norm] ??= $this->resolve($municipioId, $norm);
    }

    public function forget(): void
    {
        $this->puestos = [];
        $this->memo = [];
    }

    public function hasPuestos(string $municipioId): bool
    {
        return $this->puestosOf($municipioId) !== [];
    }

    private function resolve(string $municipioId, string $norm): array
    {
        $result = ['status' => self::VACIO, 'texto' => $norm, 'comuna_id' => null, 'puesto_id' => null, 'puesto' => null,
            'metodo' => null, 'score' => null, 'candidatos' => []];
        if ($norm === '') {
            return $result;
        }
        $puestos = $this->puestosOf($municipioId);

        foreach ($puestos as $p) {
            if ($p['norm'] === $norm || in_array($norm, $p['alias'], true)) {
                return $this->found($result, $p, $p['norm'] === $norm ? 'nombre' : 'alias', null);
            }
        }

        $core = PuestoNormalizer::core($norm);
        $same = array_values(array_filter($puestos, fn ($p) => in_array($core, array_map(
            fn ($n) => PuestoNormalizer::core($n), [$p['norm'], ...$p['alias']]), true)));
        if ($same !== []) {
            if (count(array_unique(array_column($same, 'comuna_id'))) === 1) {
                return $this->found($result, $same[0], 'nombre', null);
            }

            return ['status' => self::AMBIGUO, 'candidatos' => array_map(fn ($p) => ['puesto_id' => $p['id'], 'puesto' => $p['nombre'],
                'comuna_id' => $p['comuna_id'], 'score' => 1.0], $same)] + $result;
        }

        $list = [];
        foreach ($puestos as $p) {
            $score = max(array_map(fn ($name) => Trigram::similarity($norm, $name), [$p['norm'], ...$p['alias']]));
            if ($score >= self::MIN_SIMILARITY) {
                $list[] = ['puesto_id' => $p['id'], 'puesto' => $p['nombre'], 'comuna_id' => $p['comuna_id'], 'score' => round($score, 2)];
            }
        }
        if ($list === []) {
            return ['status' => self::SIN_MATCH] + $result;
        }
        usort($list, fn ($a, $b) => [$b['score'], $a['puesto']] <=> [$a['score'], $b['puesto']]);
        $list = array_slice($list, 0, 10);
        $best = $list[0];
        $rival = collect($list)->first(fn ($c) => $c['comuna_id'] !== $best['comuna_id']);

        if ($rival && self::MIN_ADVANTAGE - 1e-9 > $best['score'] - $rival['score']) {
            return ['status' => self::AMBIGUO, 'candidatos' => $list] + $result;
        }

        return $this->found(['candidatos' => $list] + $result, $puestos[$best['puesto_id']], 'similitud', $best['score']);
    }

    private function found(array $result, array $p, string $metodo, ?float $score): array
    {
        if ($p['comuna_id'] === null) {
            return ['status' => self::SIN_MATCH, 'puesto_id' => $p['id'], 'puesto' => $p['nombre'], 'metodo' => $metodo] + $result;
        }

        return ['status' => self::ASIGNADO, 'comuna_id' => $p['comuna_id'], 'puesto_id' => $p['id'], 'puesto' => $p['nombre'],
            'metodo' => $metodo, 'score' => $score] + $result;
    }

    // id => {id, nombre, norm, alias (normalized), comuna_id}
    private function puestosOf(string $municipioId): array
    {
        if (isset($this->puestos[$municipioId])) {
            return $this->puestos[$municipioId];
        }
        // Schemas built by hand in older tests lack the table; the real schema always has it.
        if (! ($this->table ??= Schema::hasTable('puestos_votacion'))) {
            return $this->puestos[$municipioId] = [];
        }
        $rows = DB::table('puestos_votacion')->where('municipio_id', $municipioId)->orderBy('nombre_normalizado')
            ->get(['id', 'nombre', 'nombre_normalizado', 'comuna_unit_id', 'alias']);
        $out = [];
        foreach ($rows as $r) {
            $alias = json_decode((string) $r->alias, true) ?: [];
            $out[$r->id] = ['id' => $r->id, 'nombre' => $r->nombre, 'norm' => $r->nombre_normalizado, 'comuna_id' => $r->comuna_unit_id,
                'alias' => array_values(array_filter(array_map(fn ($a) => PuestoNormalizer::normalize($a), $alias)))];
        }

        return $this->puestos[$municipioId] = $out;
    }
}
