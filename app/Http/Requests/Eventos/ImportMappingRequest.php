<?php

namespace App\Http\Requests\Eventos;

use App\Services\Eventos\ImportColumns;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

// {"columnas": {field: column index | null}}. The indexes must exist in the uploaded file (checked in the controller).
class ImportMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = ['columnas' => ['required', 'array']];
        foreach (array_keys(ImportColumns::FIELDS) as $field) {
            $rules["columnas.{$field}"] = ['nullable', 'integer', 'min:0', 'max:29'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $mapping = $this->mapping();
            if ($message = ImportColumns::missing($mapping)) {
                $validator->errors()->add('columnas', $message);
            }
        }];
    }

    public function mapping(): array
    {
        $mapping = [];
        foreach (array_keys(ImportColumns::FIELDS) as $field) {
            $value = $this->input("columnas.{$field}");
            $mapping[$field] = $value === null || $value === '' ? null : (int) $value;
        }

        return $mapping;
    }

    public function messages(): array
    {
        return [
            'columnas.required' => 'Elija las columnas del archivo.',
            'columnas.*.integer' => 'Elija una columna de la lista.',
            'columnas.*.min' => 'Elija una columna de la lista.',
            'columnas.*.max' => 'Elija una columna de la lista.',
        ];
    }
}
