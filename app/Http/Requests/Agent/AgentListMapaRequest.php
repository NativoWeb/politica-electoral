<?php

namespace App\Http\Requests\Agent;

use App\Http\Controllers\MapaPoliticoController;
use App\Services\CachedQueries;
use Closure;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

// Query of GET /mapa and /mapa/export. `solo_conteo` only exists on the list and `formato` only on the export.
class AgentListMapaRequest extends AgentFormRequest
{
    public const MAX_PER_PAGE = 50;

    public const DEFAULT_PER_PAGE = 20;

    public const TIPOS = ['alcaldia', 'concejo', 'lideres', 'nuevos_lideres', 'directorio', 'senado', 'camara', 'asamblea',
        MapaPoliticoController::ASISTENTES_EVENTO, 'todos'];

    public function rules(): array
    {
        $rules = [
            'municipio_id' => ['sometimes', 'uuid', 'exists:geographic_units,id'],
            'provincia' => ['sometimes', 'string', 'max:100', function (string $attribute, mixed $value, Closure $fail) {
                if (is_string($value) && $this->canonicalProvincia($value) === null) {
                    $fail('Provincia no encontrada.');
                }
            }],
            'tipo' => ['sometimes', 'string', 'max:200', function (string $attribute, mixed $value, Closure $fail) {
                if (is_string($value) && array_diff($this->tipos($value), self::TIPOS) !== []) {
                    $fail('Tipo no válido.');
                }
            }],
            'cargo' => ['sometimes', 'string', 'max:200'],
            'q' => ['sometimes', 'string', 'min:2', 'max:100'],
            'partido' => ['sometimes', 'string', 'max:150'],
            'barrio' => ['sometimes', 'string', 'max:200'],
            'destacado' => ['sometimes', Rule::in(['1', '0', 'true', 'false'])],
            'profesion' => ['sometimes', 'string', 'max:150'],
            'estado' => ['sometimes', Rule::in(['activos', 'inactivos', 'todos'])],
            'campana' => ['sometimes', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ];
        if ($this->routeIs('api.agent.mapa.export')) {
            $rules['formato'] = ['sometimes', Rule::in(['xlsx', 'pdf'])];
        }
        if ($this->routeIs('api.agent.mapa.index')) {
            $rules['solo_conteo'] = ['sometimes', Rule::in(['1', '0', 'true', 'false'])];
        }

        return $rules;
    }

    public function filters(): array
    {
        $data = $this->validated();
        if (array_key_exists('destacado', $data)) {
            $data['destacado'] = filter_var($data['destacado'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['q'])) {
            $data['q'] = trim($data['q']);
        }
        if (isset($data['provincia'])) {
            $data['provincia'] = $this->canonicalProvincia($data['provincia']);
        }
        if (isset($data['tipo'])) {
            $data['tipo'] = implode(',', $this->tipos($data['tipo']));
        }
        unset($data['page'], $data['per_page'], $data['formato'], $data['solo_conteo']);

        return $data;
    }

    public function formato(): string
    {
        return (string) $this->validated('formato', 'xlsx');
    }

    public function soloConteo(): bool
    {
        return filter_var($this->validated('solo_conteo', false), FILTER_VALIDATE_BOOLEAN);
    }

    public function page(): int
    {
        return (int) $this->validated('page', 1);
    }

    // Anything above 50 is cut to 50, not rejected.
    public function perPage(): int
    {
        return min((int) $this->validated('per_page', self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE);
    }

    private function tipos(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), fn ($t) => $t !== ''));
    }

    // The spelling the web filter compares against, ignoring case and accents. An unknown name is refused here
    // because MapaPoliticoController::rows() would read it as "no province" and answer with the whole department.
    private function canonicalProvincia(string $name): ?string
    {
        $normalize = fn (string $v) => Str::lower(Str::ascii(trim($v)));
        $known = collect(CachedQueries::allMunicipios())->pluck('provincia')->filter()->unique()->push(MapaPoliticoController::AREA_METROPOLITANA);

        return $known->first(fn (string $p) => $normalize($p) === $normalize($name));
    }
}
