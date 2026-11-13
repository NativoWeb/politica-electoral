<?php

namespace App\Http\Requests\Agent;

use App\Models\EventCompromiso;
use Illuminate\Validation\Rule;

// Query of GET /compromisos (read-only): the filters of the web follow-up screen with the agent's names, plus
// creadas_por_mi (compromisos of actas the user created).
class AgentListCompromisosRequest extends AgentFormRequest
{
    public const MAX_PER_PAGE = 20;

    public function rules(): array
    {
        $bool = ['sometimes', Rule::in(['1', '0', 'true', 'false'])];

        return [
            'estado' => ['sometimes', Rule::in(EventCompromiso::ESTADOS)],
            'vencidos' => $bool,
            'responsable' => ['sometimes', 'string', 'min:2', 'max:100'],
            'municipio_id' => ['sometimes', 'uuid'],
            'evento_id' => ['sometimes', 'uuid'],
            'organizacion_id' => ['sometimes', 'uuid'],
            'origen' => ['sometimes', Rule::in(['evento', 'organizacion'])],
            'desde' => ['sometimes', 'date_format:Y-m-d'],
            'hasta' => ['sometimes', 'date_format:Y-m-d'],
            'q' => ['sometimes', 'string', 'min:2', 'max:100'],
            'creadas_por_mi' => $bool,
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    // CompromisoListing::query() filters (every key present, null when not sent).
    public function listingFilters(): array
    {
        $data = $this->validated();
        $text = fn (?string $v) => $v !== null && trim($v) !== '' ? trim($v) : null;

        return [
            'estado' => $data['estado'] ?? null,
            'vencidos' => $this->flag('vencidos') ? '1' : null,
            'responsable' => $text($data['responsable'] ?? null),
            'municipio' => $data['municipio_id'] ?? null,
            'evento' => $data['evento_id'] ?? null,
            'organizacion' => $data['organizacion_id'] ?? null,
            'origen' => $data['origen'] ?? null,
            'desde' => $data['desde'] ?? null,
            'hasta' => $data['hasta'] ?? null,
            'q' => $text($data['q'] ?? null),
        ];
    }

    public function flag(string $key): bool
    {
        return filter_var($this->validated($key, false), FILTER_VALIDATE_BOOLEAN);
    }

    public function page(): int
    {
        return (int) $this->validated('page', 1);
    }

    // Anything above 20 is cut to 20, not rejected.
    public function perPage(): int
    {
        return min((int) $this->validated('per_page', self::MAX_PER_PAGE), self::MAX_PER_PAGE);
    }
}
