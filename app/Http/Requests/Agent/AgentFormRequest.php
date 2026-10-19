<?php

namespace App\Http\Requests\Agent;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

// Agent payloads are a strict whitelist: any key without a rule is rejected (422), never silently dropped.
abstract class AgentFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $unknown = array_diff(array_keys($this->all()), array_keys($this->rules()));
                foreach ($unknown as $field) {
                    $validator->errors()->add((string) $field, 'Campo no permitido.');
                }
            },
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Datos inválidos.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
