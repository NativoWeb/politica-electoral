<?php

namespace App\Http\Requests\Agent;

use App\Services\Agent\AgentIdentityService;
use Illuminate\Foundation\Http\FormRequest;

class AgentLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:16', 'regex:'.AgentIdentityService::PHONE_REGEX],
            'code' => ['required', 'string', 'digits:6'],
        ];
    }
}
