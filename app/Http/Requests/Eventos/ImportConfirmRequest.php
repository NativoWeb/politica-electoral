<?php

namespace App\Http\Requests\Eventos;

use Illuminate\Foundation\Http\FormRequest;

// Confirming needs nothing more since 2026-11-07 (no authorization is asked). An old screen may still send
// "declaracion": it is accepted and ignored.
class ImportConfirmRequest extends FormRequest
{
    use AuthorizesEventImport;

    public function rules(): array
    {
        return [];
    }
}
