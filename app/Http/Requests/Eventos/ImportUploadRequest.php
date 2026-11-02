<?php

namespace App\Http\Requests\Eventos;

use Illuminate\Foundation\Http\FormRequest;

// Route middleware already requires manage.eventos; campaign and territory are checked in authorize() before the
// file is validated (AuthorizesEventImport). The content is not trusted by its MIME type: the reader for the
// extension either parses it or rejects it.
class ImportUploadRequest extends FormRequest
{
    use AuthorizesEventImport;

    public const MAX_KB = 5120;

    // 5 MB, or less if PHP accepts less (upload_max_filesize / post_max_size), so the screen and the messages tell
    // the limit that really applies.
    public static function maxKb(): int
    {
        return min(self::MAX_KB, self::phpMaxKb() ?? self::MAX_KB);
    }

    // What PHP accepts (upload_max_filesize / post_max_size), null when it does not limit it.
    public static function phpMaxKb(): ?int
    {
        $limits = array_filter([self::iniKb('upload_max_filesize'), self::iniKb('post_max_size')]);

        return $limits ? min($limits) : null;
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
