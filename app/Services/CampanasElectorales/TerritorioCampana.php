<?php

namespace App\Services\CampanasElectorales;

use App\Models\ElectoralCampaign;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// The territory of the electoral campaigns: Santander (config campanas_electorales.departamento_codigo), its municipios
// and the comunas/corregimientos below them. Loaded once per request (bound as scoped).
class TerritorioCampana
{
    private bool $departamentoCargado = false;

    private ?object $departamento = null;

    private array $municipios = [];

    private ?array $comunas = null;

    // {id, nombre} of the departamento, or null when it is not loaded.
    public function departamento(): ?object
    {
        $this->cargar();

        return $this->departamento;
    }

    // id => {id, nombre, codigo, provincia, parent_id}, by name. Directly below the departamento or below a provincia.
    public function municipios(): array
    {
        $this->cargar();

        return $this->municipios;
    }

    // The departamento and its municipios in one query (the departamento alone when it has none).
    private function cargar(): void
    {
        if ($this->departamentoCargado) {
            return;
        }
        $this->departamentoCargado = true;
        $codigo = (string) config('campanas_electorales.departamento_codigo');
        $rows = DB::table('geographic_units as g')
            ->join('geographic_units as p', 'p.id', '=', 'g.parent_id')
            ->join('geographic_units as d', fn ($j) => $j->on('d.id', '=', 'p.id')->orOn('d.id', '=', 'p.parent_id'))
            ->where('d.type', 'department')->where('d.official_code', $codigo)
            ->where('g.type', 'municipality')
            ->orderBy('g.canonical_name')->orderBy('g.id')
            ->get(['g.id', 'g.canonical_name', 'g.official_code', 'g.parent_id', 'p.canonical_name as provincia', 'p.type as parent_type',
                'd.id as dep_id', 'd.canonical_name as dep_nombre']);

        $this->municipios = [];
        foreach ($rows as $m) {
            $this->departamento ??= (object) ['id' => $m->dep_id, 'nombre' => $m->dep_nombre];
            $this->municipios[$m->id] = [
                'id' => $m->id, 'nombre' => $m->canonical_name, 'codigo' => $m->official_code, 'parent_id' => $m->parent_id,
                'provincia' => $m->parent_type === 'province' ? $m->provincia : null,
            ];
        }
        if ($this->departamento === null) {
            $row = DB::table('geographic_units')->where('type', 'department')->where('official_code', $codigo)->first(['id', 'canonical_name']);
            $this->departamento = $row ? (object) ['id' => $row->id, 'nombre' => $row->canonical_name] : null;
        }
    }

    // id => {id, nombre, tipo, codigo, municipio_id, municipio}: comunas first, then corregimientos, by code.
    public function comunas(): array
    {
        if ($this->comunas !== null) {
            return $this->comunas;
        }
        $municipios = $this->municipios();
        if (! $municipios) {
            return $this->comunas = [];
        }

        return $this->comunas = DB::table('geographic_units')
            ->whereIn('type', ['comuna', 'corregimiento'])
            ->whereIn('parent_id', array_keys($municipios))
            ->orderBy('type')->orderBy('official_code')->orderBy('canonical_name')
            ->get(['id', 'canonical_name', 'type', 'official_code', 'parent_id'])
            ->mapWithKeys(fn ($c) => [$c->id => [
                'id' => $c->id, 'nombre' => $c->canonical_name, 'tipo' => $c->type, 'codigo' => $c->official_code,
                'municipio_id' => $c->parent_id, 'municipio' => $municipios[$c->parent_id]['nombre'] ?? null,
            ]])->all();
    }

    public function comunasDe(string $municipioId): array
    {
        return array_values(array_filter($this->comunas(), fn ($c) => $c['municipio_id'] === $municipioId));
    }

    // The units stored for a campaign of $cargo with $scope: the departamento, the municipios chosen or the comuna.
    public function normalizar(string $cargo, string $scope, array $ids): array
    {
        $cargoDef = ElectoralCampaign::CARGOS[$cargo] ?? null;
        if (! $cargoDef) {
            self::fail('cargo', 'Elige el cargo.');
        }
        if (! in_array($scope, $cargoDef['scopes'], true)) {
            self::fail('scope_level', 'Ese alcance no corresponde al cargo.');
        }
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => is_string($id) && $id !== '')));

        if ($scope === ElectoralCampaign::SCOPE_DEPARTMENT) {
            $dep = $this->departamento();
            if (! $dep) {
                self::fail('territorio_ids', 'No está cargado el departamento de Santander.');
            }
            if ($ids !== [] && $ids !== [$dep->id]) {
                self::fail('territorio_ids', 'Para '.$cargoDef['label'].' el territorio es todo el departamento.');
            }

            return [$dep->id];
        }

        if ($scope === ElectoralCampaign::SCOPE_COMUNA) {
            if ($ids === []) {
                self::fail('territorio_ids', 'Elige una comuna o corregimiento.');
            }
            if (count($ids) > 1) {
                self::fail('territorio_ids', 'Elige una sola comuna o corregimiento.');
            }
            if (! isset($this->comunas()[$ids[0]])) {
                self::fail('territorio_ids', 'Elige una comuna o corregimiento.');
            }

            return $ids;
        }

        if ($ids === []) {
            self::fail('territorio_ids', 'Elige un municipio.');
        }
        if (in_array($cargo, ElectoralCampaign::UN_MUNICIPIO, true) && count($ids) > 1) {
            self::fail('territorio_ids', 'Para '.$cargoDef['label'].' elige un solo municipio.');
        }
        foreach ($ids as $id) {
            if (! isset($this->municipios()[$id])) {
                self::fail('territorio_ids', 'Ese territorio no está en Santander.');
            }
        }

        return $ids;
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    // The rows of the goals table in screen order: each municipio followed by its comunas, or the comuna alone.
    // Each row: id, nombre, tipo, codigo, padre_id (the municipio row above a comuna), municipio_id (where it is).
    public function filas(string $scope, array $territoryIds): array
    {
        if ($scope === ElectoralCampaign::SCOPE_COMUNA) {
            $filas = [];
            foreach ($territoryIds as $id) {
                if ($c = $this->comunas()[$id] ?? null) {
                    $filas[] = ['id' => $c['id'], 'nombre' => $c['nombre'], 'tipo' => $c['tipo'], 'codigo' => $c['codigo'],
                        'padre_id' => null, 'municipio_id' => $c['municipio_id']];
                }
            }

            return $filas;
        }

        $municipios = $scope === ElectoralCampaign::SCOPE_DEPARTMENT
            ? $this->municipios()
            : array_intersect_key($this->municipios(), array_flip($territoryIds));
        $porMunicipio = [];
        foreach ($this->comunas() as $c) {
            $porMunicipio[$c['municipio_id']][] = $c;
        }
        $filas = [];
        foreach ($municipios as $m) {
            $filas[] = ['id' => $m['id'], 'nombre' => $m['nombre'], 'tipo' => 'municipality', 'codigo' => $m['codigo'],
                'padre_id' => null, 'municipio_id' => $m['id']];
            foreach ($porMunicipio[$m['id']] ?? [] as $c) {
                $filas[] = ['id' => $c['id'], 'nombre' => $c['nombre'], 'tipo' => $c['tipo'], 'codigo' => $c['codigo'],
                    'padre_id' => $m['id'], 'municipio_id' => $m['id']];
            }
        }

        return $filas;
    }

    // The municipios a campaign touches (a comuna campaign touches its municipio).
    public function municipiosDe(string $scope, array $territoryIds): array
    {
        return match ($scope) {
            ElectoralCampaign::SCOPE_DEPARTMENT => array_keys($this->municipios()),
            ElectoralCampaign::SCOPE_COMUNA => array_values(array_unique(array_filter(array_map(
                fn ($id) => $this->comunas()[$id]['municipio_id'] ?? null, $territoryIds)))),
            default => array_values(array_intersect($territoryIds, array_keys($this->municipios()))),
        };
    }

    // "Santander · 87 municipios", "Bucaramanga", "3 municipios", "Comuna 4 - Occidental · Bucaramanga".
    public function resumen(string $scope, array $territoryIds): string
    {
        if ($scope === ElectoralCampaign::SCOPE_DEPARTMENT) {
            return ($this->departamento()->nombre ?? 'Departamento').' · '.self::miles(count($this->municipios())).' municipios';
        }
        if ($scope === ElectoralCampaign::SCOPE_COMUNA) {
            $c = $this->comunas()[$territoryIds[0] ?? ''] ?? null;

            return $c ? $c['nombre'].' · '.$c['municipio'] : 'Sin territorio';
        }
        $nombres = array_values(array_map(fn ($m) => $m['nombre'], array_intersect_key($this->municipios(), array_flip($territoryIds))));

        return count($nombres) === 1 ? $nombres[0] : self::miles(count($nombres)).' municipios';
    }

    // [{id, nombre, tipo}] of the stored units, for the card and the form.
    public function unidades(array $territoryIds): array
    {
        $dep = $this->departamento();
        $out = [];
        foreach ($territoryIds as $id) {
            if ($dep && $id === $dep->id) {
                $out[] = ['id' => $id, 'nombre' => $dep->nombre, 'tipo' => 'department'];
            } elseif ($m = $this->municipios()[$id] ?? null) {
                $out[] = ['id' => $id, 'nombre' => $m['nombre'], 'tipo' => 'municipality'];
            } elseif ($c = $this->comunas()[$id] ?? null) {
                $out[] = ['id' => $id, 'nombre' => $c['nombre'], 'tipo' => $c['tipo']];
            }
        }

        return $out;
    }

    public static function miles(int $n): string
    {
        return number_format($n, 0, ',', '.');
    }
}
