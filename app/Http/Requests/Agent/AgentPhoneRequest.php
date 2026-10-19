<?php

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

class AgentPhoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    // Format is checked in the controller so a malformed phone gets the same 404 as an unknown one.
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:20'],
        ];
    }
}
