<?php

namespace App\Http\Requests\Agent;

use Illuminate\Validation\Rule;

// Query of GET /votantes and /votantes/export. Booleans come as query strings: 1/0/true/false.
class AgentListVotantesRequest extends AgentFormRequest
{
    public const MAX_PER_PAGE = 50;

    public const DEFAULT_PER_PAGE = 20;

    private const BOOLEANS = ['gran_elector', 'militante', 'verificado', 'is_active'];

    public function rules(): array
    {
        $rules = [
            'municipio_id' => ['sometimes', 'uuid', 'exists:geographic_units,id'],
            'q' => ['sometimes', 'string', 'min:2', 'max:100'],
            'nivel_confianza' => ['sometimes', 'string', Rule::in(AgentVotanteRules::NIVELES_CONFIANZA)],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ];
        foreach (self::BOOLEANS as $flag) {
            $rules[$flag] = ['sometimes', Rule::in(['1', '0', 'true', 'false'])];
        }

        return $rules;
    }

    // Validated filters with real booleans; is_active defaults to true (only active votantes).
    public function filters(): array
    {
        $data = $this->validated();
        foreach (self::BOOLEANS as $flag) {
            if (array_key_exists($flag, $data)) {
                $data[$flag] = filter_var($data[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }
        $data['is_active'] ??= true;
        if (isset($data['q'])) {
            $data['q'] = trim($data['q']);
        }
        unset($data['page'], $data['per_page']);

        return $data;
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
}
