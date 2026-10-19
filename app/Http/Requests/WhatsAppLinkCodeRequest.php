<?php

namespace App\Http\Requests;

use App\Services\Agent\AgentIdentityService;
use Illuminate\Foundation\Http\FormRequest;

class WhatsAppLinkCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:16', 'regex:'.AgentIdentityService::PHONE_REGEX],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'El número debe estar en formato internacional, por ejemplo +573001234567.',
        ];
    }
}
