<?php

namespace App\Http\Requests\Agent;

use App\Models\EventActa;
use Illuminate\Validation\Rule;

// Query of GET /actas (read-only). origen narrows to events or organizations; each still needs its own permission.
class AgentListActasRequest extends AgentFormRequest
{
    public const MAX_PER_PAGE = 20;

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'min:2', 'max:100'],
            'origen' => ['sometimes', Rule::in(['evento', 'organizacion'])],
            'evento_id' => ['sometimes', 'uuid'],
            'organizacion_id' => ['sometimes', 'uuid'],
            'estado' => ['sometimes', Rule::in([EventActa::BORRADOR, EventActa::CONFIRMADA])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function filters(): array
    {
        $data = $this->validated();
        unset($data['page'], $data['per_page']);
        if (isset($data['q'])) {
            $data['q'] = trim($data['q']);
        }

        return $data;
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
