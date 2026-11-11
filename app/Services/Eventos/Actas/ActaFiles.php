<?php

namespace App\Services\Eventos\Actas;

use App\Http\Requests\Eventos\ImportUploadRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

// Files of actas and avances: what they really are (by content, never by name or by the browser's MIME), stored on
// the private disk and served only through authenticated routes.
class ActaFiles
{
    public const DISK = 'local';

    public const MAX_KB = 10240;

    public const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public const EXTENSIONS = [self::DOCX => 'docx', 'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    // 10 MB, or less when PHP accepts less (upload_max_filesize / post_max_size), so the messages tell the real limit.
    public static function maxKb(): int
    {
        return min(self::MAX_KB, ImportUploadRequest::phpMaxKb() ?? self::MAX_KB);
    }

    public static function maxMb(): int|float
    {
        $mb = self::maxKb() / 1024;

        return floor($mb) == $mb ? (int) $mb : round($mb, 1);
    }

    // The real type of the file, or ActaFileException. $allowDocx: an avance only takes photos and PDF.
    public static function mime(UploadedFile $file, bool $allowDocx = true): string
    {
        $path = (string) $file->getRealPath();
        $head = (string) @file_get_contents($path, false, null, 0, 8);
        if (str_starts_with($head, '%PDF-')) {
            return 'application/pdf';
        }
        if (str_starts_with($head, "\xFF\xD8\xFF") || str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
            $size = @getimagesize($path);
            if ($size !== false && in_array($size['mime'] ?? '', ['image/jpeg', 'image/png'], true)) {
                return $size['mime'];
            }
        }
        if ($allowDocx && str_starts_with($head, "PK\x03\x04")) {
            $zip = new ZipArchive;
            if ($zip->open($path) === true) {
                $isWord = $zip->statName('word/document.xml') !== false;
                $zip->close();
                if ($isWord) {
                    return self::DOCX;
                }
            }
        }

        throw new ActaFileException($allowDocx
            ? 'El archivo debe ser un documento de Word (.docx), un PDF o una foto (JPG o PNG).'
            : 'El archivo debe ser una foto (JPG o PNG) o un PDF.');
    }

    // [path, mime, nombre original]
    public static function store(UploadedFile $file, string $dir, bool $allowDocx = true): array
    {
        $mime = self::mime($file, $allowDocx);
        $name = Str::uuid().'.'.self::EXTENSIONS[$mime];
        $path = $file->storeAs($dir, $name, self::DISK);
        if ($path === false) {
            throw new ActaFileException('No se pudo guardar el archivo. Intente de nuevo.');
        }
        $original = Str::limit(trim(preg_replace('#[\x00-\x1F/\\\\]+#', ' ', $file->getClientOriginalName())), 200, '') ?: 'archivo';

        return [$path, $mime, $original];
    }

    public static function delete(?string $path): void
    {
        if ($path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    // Download name: the original name with the extension of what the file really is.
    public static function downloadName(?string $original, string $mime): string
    {
        $base = pathinfo((string) $original, PATHINFO_FILENAME) ?: 'archivo';

        return Str::limit($base, 150, '').'.'.(self::EXTENSIONS[$mime] ?? 'bin');
    }
}
