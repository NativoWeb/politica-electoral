<?php

namespace App\Http\Requests\Empresarios;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

// Body of POST /empresarios and PUT /empresarios/{id} (docs/empresarios.md). Campaign and territory are checked by
// the controller (OrganizacionScope) before the body.
class OrganizacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function organizacion(): array
    {
        [$data, $errors] = OrganizacionRules::check($this->all());
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }
}
