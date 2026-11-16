<?php

namespace App\Http\Requests\Agent;

use App\Models\CampaignEvent;
use Illuminate\Validation\Rule;

// Query of GET /eventos (read-only): the filters of the web list with the agent's names; tipo is text, matched by
// the controller against the names of the visible event types.
class AgentListEventosRequest extends AgentFormRequest
{
    public const MAX_PER_PAGE = 20;

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'min:2', 'max:100'],
            'municipio_id' => ['sometimes', 'uuid'],
            'provincia_id' => ['sometimes', 'uuid'],
            'barrio' => ['sometimes', 'string', 'min:2', 'max:100'],
            'tipo' => ['sometimes', 'string', 'min:2', 'max:100'],
            'status' => ['sometimes', Rule::in(CampaignEvent::STATUSES)],
            'desde' => ['sometimes', 'date_format:Y-m-d'],
            'hasta' => ['sometimes', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    // EventoController::eventsQuery() filters (every key present, null when not sent), tipo still as text.
    public function filters(): array
    {
        $data = $this->validated();
        $text = fn (?string $v) => $v !== null && trim($v) !== '' ? trim($v) : null;

        return [
            'provincia' => $data['provincia_id'] ?? null,
            'municipio' => $data['municipio_id'] ?? null,
            'barrio' => $text($data['barrio'] ?? null),
            'tipo' => $text($data['tipo'] ?? null),
            'status' => $data['status'] ?? null,
            'desde' => $data['desde'] ?? null,
            'hasta' => $data['hasta'] ?? null,
            'q' => $text($data['q'] ?? null),
            'campana' => null,
        ];
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
