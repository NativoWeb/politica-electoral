<?php

namespace App\Http\Requests\Eventos;

use Illuminate\Foundation\Http\FormRequest;

// Route middleware already requires manage.eventos; campaign and territory are checked in the controller. The
// content is not trusted by its MIME type: the reader for the extension either parses it or rejects it.
class ImportUploadRequest extends FormRequest
{
    public const MAX_KB = 5120;

    public function authorize(): bool
    {
        return true;
    }

    // 5 MB, or less if PHP accepts less (upload_max_filesize / post_max_size), so the screen and the messages tell
    // the limit that really applies.
    public static function maxKb(): int
    {
        $limits = array_filter([self::iniKb('upload_max_filesize'), self::iniKb('post_max_size')]);

        return min(self::MAX_KB, ...($limits ?: [self::MAX_KB]));
    }

    public static function maxMb(): int|float
    {
        $mb = self::maxKb() / 1024;

        return floor($mb) == $mb ? (int) $mb : round($mb, 1);
    }

    private static function iniKb(string $key): ?int
    {
        $value = trim((string) ini_get($key));
        if ($value === '' || $value === '0' || $value === '-1') {
            return null;
        }
        $number = (float) $value;

        return (int) match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024,
            'm' => $number * 1024,
            'k' => $number,
            default => $number / 1024,
        };
    }

    public function rules(): array
    {
        return [
            'archivo' => ['required', 'file', 'max:'.self::maxKb(), 'extensions:xlsx,xls,csv'],
        ];
    }

    public function messages(): array
    {
        $mb = self::maxMb();

        return [
            'archivo.required' => 'Elija el archivo de Excel que quiere cargar.',
            'archivo.uploaded' => "No se pudo subir el archivo. Revise que pese menos de {$mb} MB e intente de nuevo.",
            'archivo.file' => 'No se pudo subir el archivo. Intente de nuevo.',
            'archivo.max' => "El archivo pesa más de {$mb} MB. Divídalo en varios archivos.",
            'archivo.extensions' => 'El archivo debe ser de Excel (.xlsx o .xls) o .csv.',
        ];
    }
}
