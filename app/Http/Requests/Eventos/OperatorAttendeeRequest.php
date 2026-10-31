<?php

namespace App\Http\Requests\Eventos;

use Illuminate\Foundation\Http\FormRequest;

// Operator mode: route middleware already requires register.asistentes or manage.eventos.
class OperatorAttendeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->replace(AttendeeInput::normalize($this->only(AttendeeInput::FIELDS)));
    }

    public function rules(): array
    {
        return AttendeeInput::rules();
    }

    public function messages(): array
    {
        return AttendeeInput::messages();
    }

    public function attributes(): array
    {
        return AttendeeInput::attributes();
    }
}
