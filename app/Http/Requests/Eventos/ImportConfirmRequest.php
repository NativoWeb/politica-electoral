<?php

namespace App\Http\Requests\Eventos;

use Illuminate\Foundation\Http\FormRequest;

// "declaracion" = the operator states they hold the signed sheets that back the authorizations of the file.
// Required: only authorized people are imported (same rule as the QR and the operator).
class ImportConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['declaracion' => ['accepted']];
    }

    public function messages(): array
    {
        $message = 'Para importar, marque «Tengo las planillas firmadas que respaldan las autorizaciones de este archivo».';

        return ['declaracion.accepted' => $message, 'declaracion.required' => $message];
    }
}
