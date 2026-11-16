<?php

namespace App\Http\Requests\Agent;

use App\Models\EventAttendee;
use Illuminate\Validation\Rule;

// Query of GET /eventos/{id}/asistentes and its export: the filters of the event's web page. Paging only on the list.
class AgentListAsistentesRequest extends AgentFormRequest
{
    public const MAX_PER_PAGE = 50;

    public const DEFAULT_PER_PAGE = 20;

    public const SOURCES = ['qr', 'operador', 'excel', 'ocr', 'acta'];

    public function rules(): array
    {
        $rules = [
            'q' => ['sometimes', 'string', 'min:2', 'max:100'],
            'link_status' => ['sometimes', Rule::in(EventAttendee::LINK_STATUSES)],
            'source' => ['sometimes', Rule::in(self::SOURCES)],
        ];
        if (! $this->routeIs('api.agent.eventos.asistentes.export')) {
            $rules['page'] = ['sometimes', 'integer', 'min:1', 'max:100000'];
            $rules['per_page'] = ['sometimes', 'integer', 'min:1'];
        }

        return $rules;
    }

    // EventoController::attendeesQuery() filters (every key present, null when not sent).
    public function filters(): array
    {
        $q = $this->validated('q');

        return [
            'link_status' => $this->validated('link_status'),
            'source' => $this->validated('source'),
            'q' => is_string($q) && trim($q) !== '' ? trim($q) : null,
        ];
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
