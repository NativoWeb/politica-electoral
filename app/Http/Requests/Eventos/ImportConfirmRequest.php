<?php

namespace App\Http\Requests\Eventos;

use Illuminate\Foundation\Http\FormRequest;

// "declaracion" = the operator states they hold the signed sheets that back the authorizations of the file.
// The controller requires it when the import has rows with SÍ (same rule as the QR and the operator); a list without
// authorization registers no consent, so it does not need it.
class ImportConfirmRequest extends FormRequest
{
    use AuthorizesEventImport;

    public const MESSAGE = 'Para importar, marque «Tengo las planillas firmadas que respaldan las autorizaciones de este archivo».';

    public function rules(): array
    {
        return ['declaracion' => ['nullable', 'boolean']];
    }

    public function messages(): array
    {
        return ['declaracion.boolean' => self::MESSAGE];
    }
}
