<?php

namespace App\Http\Requests\Eventos;

use App\Services\Eventos\ImportRowValidator;
use Illuminate\Foundation\Http\FormRequest;

// Correction of one preview row: any of the editable fields (the value is stored as typed and the row is validated
// again) and/or the decision. A wrong value is not a 422: it stays on the row with its error.
class ImportRowRequest extends FormRequest
{
    use AuthorizesEventImport;

    public function rules(): array
    {
        $rules = ['decision' => ['nullable', 'in:importar,omitir']];
        foreach (ImportRowValidator::EDITABLE as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'string', 'max:255'];
        }

        return $rules;
    }

    public function values(): array
    {
        $values = [];
        foreach (ImportRowValidator::EDITABLE as $field) {
            if ($this->exists($field)) {
                $values[$field] = ImportRowValidator::edited($field, $this->input($field));
            }
        }

        return $values;
    }

    public function messages(): array
    {
        return [
            'decision.in' => 'Elija si la fila se importa o no.',
            '*.string' => 'Escriba un texto.',
            '*.max' => 'El texto es demasiado largo (máximo 255 caracteres).',
        ];
    }
}
