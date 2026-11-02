<?php

namespace App\Http\Requests\Eventos;

use App\Services\Eventos\Ocr\PageImage;
use Illuminate\Foundation\Http\FormRequest;

// One file per request (the screen sends the photos one after the other, so PHP's post_max_size is never the sum of
// them). The real type is checked by its content in OcrImportService (PageImage::mime), not by its name.
// "importacion" = the OCR load the file is added to (none = a new load).
class OcrUploadRequest extends FormRequest
{
    use AuthorizesEventImport;

    public static function maxKb(): int
    {
        return min((int) config('eventos.ocr.max_file_kb'), ImportUploadRequest::phpMaxKb() ?? PHP_INT_MAX);
    }

    public static function maxMb(): int|float
    {
        $mb = self::maxKb() / 1024;

        return floor($mb) == $mb ? (int) $mb : round($mb, 1);
    }

    public function rules(): array
    {
        return [
            'foto' => ['required', 'file', 'max:'.self::maxKb(), 'extensions:jpg,jpeg,png,webp,pdf,heic,heif'],
            'importacion' => ['nullable', 'uuid'],
        ];
    }

    public function messages(): array
    {
        $mb = self::maxMb();

        return [
            'foto.required' => 'Elija las fotos de las planillas.',
            'foto.uploaded' => "No se pudo subir el archivo. Revise que pese menos de {$mb} MB e intente de nuevo.",
            'foto.file' => 'No se pudo subir el archivo. Intente de nuevo.',
            'foto.max' => "La foto pesa más de {$mb} MB. Tómela de nuevo con menos resolución.",
            'foto.extensions' => PageImage::WRONG_TYPE,
            'importacion.uuid' => 'La carga no existe.',
        ];
    }
}
