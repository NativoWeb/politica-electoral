<?php

namespace App\Http\Requests\Eventos;

use App\Services\Eventos\Actas\ActaFiles;
use Illuminate\Foundation\Http\FormRequest;

// The acta's file (read to prefill the form, or attached to a saved acta): at most 10 MB; the content decides the type.
class ActaFileRequest extends FormRequest
{
    use AuthorizesEventActa;

    public function rules(): array
    {
        return ['archivo' => ['required', 'file', 'max:'.ActaFiles::maxKb()]];
    }

    public function messages(): array
    {
        $mb = ActaFiles::maxMb();

        return [
            'archivo.required' => 'Elija el archivo del acta.',
            'archivo.uploaded' => "No se pudo subir el archivo. Revise que pese menos de {$mb} MB e intente de nuevo.",
            'archivo.file' => 'No se pudo subir el archivo. Intente de nuevo.',
            'archivo.max' => "El archivo pesa más de {$mb} MB.",
        ];
    }
}
