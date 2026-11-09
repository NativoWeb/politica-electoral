<?php

namespace App\Http\Requests\Eventos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

// A new event type for the own campaign, created from the event form.
class EventTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('nombre'))) {
            $this->merge(['nombre' => Str::squish($this->input('nombre'))]);
        }
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:80'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'Escriba el nombre del tipo de evento.',
            'nombre.string' => 'El nombre del tipo de evento debe ser un texto.',
            'nombre.max' => 'El nombre del tipo de evento no puede tener más de 80 caracteres.',
        ];
    }
}
