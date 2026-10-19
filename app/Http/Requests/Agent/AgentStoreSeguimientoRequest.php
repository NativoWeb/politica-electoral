<?php

namespace App\Http\Requests\Agent;

use App\Models\LiderSeguimiento;
use Illuminate\Validation\Rule;

class AgentStoreSeguimientoRequest extends AgentFormRequest
{
    public function rules(): array
    {
        return [
            'tipo' => ['required', 'string', Rule::in(LiderSeguimiento::TIPOS)],
            'nota' => ['required', 'string', 'max:2000'],
            'fecha' => ['sometimes', 'date', 'after:2000-01-01', 'before_or_equal:'.now()->addYear()->toDateTimeString()],
        ];
    }
}
