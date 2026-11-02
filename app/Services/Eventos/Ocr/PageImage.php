<?php

namespace App\Services\Eventos\Ocr;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Str;

// Files of an OCR load: what they really are (by content, not by name), the pages of a PDF, the copy of a photo that
// is sent to the API (at most max_image_px on the long edge and 3.7 MB, upright when PHP can read the EXIF) and the
// QR of the printed sheet, decoded here so a page of another event is refused before paying for it.
class PageImage
{
    public const MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    public const HEIC = 'Las fotos HEIC del iPhone no se pueden leer. Súbalas desde la galería con el botón de esta pantalla (el iPhone las convierte a JPG) o cambie en Ajustes > Cámara > Formatos a «Más compatible».';

    public const WRONG_TYPE = 'Solo se aceptan fotos JPG, PNG o WEBP, o un PDF.';

    // The API limit is 5 MB per image; base64 adds a third, so the raw bytes stay under 3.7 MB.
    private const API_MAX_BYTES = 3_700_000;

    // Measured worst case (decode + API-sized copy + rotation, EventOcrQaFixesTest): 24 MP takes +118 MB (peak 146 MB
    // with Laravel booted) of the 256 MB a reading request has; 36 MP took +178 MB. Phones take 12-16 MP.
    public const MAX_PIXELS = 24_000_000;

    // A scanned page weighs well under this; more means huge embedded images (and a huge request).
    public const MAX_PDF_BYTES_PER_PAGE = 3_000_000;

    public const PDF_UNREADABLE = 'No se pudieron contar las páginas de este PDF. Suba fotos de las páginas (JPG o PNG).';

    private const QR_PX = 1600;

    // The real type of the file ('image/jpeg', ...). Throws OcrException for HEIC and anything not accepted.
    public static function mime(string $path): string
    {
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (in_array($mime, ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'], true)) {
            throw new OcrException(self::HEIC);
        }
        if (! isset(self::MIMES[$mime])) {
            throw new OcrException(self::WRONG_TYPE);
        }
        if ($mime === 'application/pdf') {
            self::pdfCheck((string) file_get_contents($path));

            return $mime;
        }
        $size = @getimagesize($path);
        if ($size === false) {
            throw new OcrException('La imagen está dañada o no se puede abrir. Tómela de nuevo.');
        }
        // Checked from the header, before GD decodes the pixels (a tiny file can declare a gigantic image).
        if ((int) $size[0] * (int) $size[1] > self::MAX_PIXELS) {
            throw new OcrException('La foto tiene demasiada resolución para leerla (más de 24 megapíxeles). Tómela de nuevo con menos resolución.');
        }

        return $mime;
    }

    // [pages] of an acceptable PDF; throws when its pages cannot be counted or it is too heavy per page.
    public static function pdfCheck(string $content): int
    {
        $pages = self::pdfPages($content);
        if ($pages === null) {
            throw new OcrException(self::PDF_UNREADABLE);
        }
        if (strlen($content) / $pages > self::MAX_PDF_BYTES_PER_PAGE) {
            throw new OcrException('Este PDF pesa demasiado por página. Suba fotos de las páginas (JPG o PNG).');
        }

        return $pages;
    }

    // Pages of a PDF counted from its page objects; null when they are inside compressed object streams (refused:
    // without the count neither the 20-page limit nor the cost can be checked).
    public static function pdfPages(string $content): ?int
    {
        $count = preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $content);

        return $count > 0 ? $count : null;
    }

    // [[content, mime] to send to the API, the planilla QR {"e", "p"} or null]. A PDF goes as it is (no QR). A photo is
    // decoded ONCE: first scaled down to the API size and the original freed, then turned upright (EXIF, or $angle)
    // and reduced again for the QR, so the peak memory is the decoded original plus one API-sized copy.
    public static function prepare(string $content, string $mime, ?int $angle = null): array
    {
        if ($mime === 'application/pdf') {
            return [[$content, $mime], null];
        }
        $image = self::load($content, $mime);
        if ($image === null) {
            throw new OcrException('La imagen está dañada o no se puede abrir. Tómela de nuevo.');
        }
        $angle ??= self::exifAngle($content, $mime);
        $max = (int) config('eventos.ocr.max_image_px');
        $big = max(imagesx($image), imagesy($image)) > $max;

        $work = $big ? self::scale($image, $max) : $image;
        $image = null;
        if ($angle !== 0) {
            $work = imagerotate($work, $angle, 0) ?: $work;
        }

        $api = ! $big && $angle === 0 && strlen($content) <= self::API_MAX_BYTES ? [$content, $mime] : [self::jpeg($work), 'image/jpeg'];
        $small = self::scale($work, self::QR_PX);
        $work = null;

        return [$api, self::decodeQr($small)];
    }

    private static function jpeg(\GdImage $image): string
    {
        $quality = 88;
        do {
            ob_start();
            imagejpeg($image, null, $quality);
            $jpeg = (string) ob_get_clean();
            $quality -= 10;
        } while (strlen($jpeg) > self::API_MAX_BYTES && $quality >= 48);

        return $jpeg;
    }

    // The JSON {"e": event id, "p": page} of the planilla QR, or null when it cannot be read (blurred, cut).
    private static function decodeQr(\GdImage $image): ?array
    {
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        $image = null;

        try {
            $text = (string) (new QRCode(new QROptions(['readerUseImagickIfAvailable' => false])))->readFromBlob($png);
        } catch (\Throwable) {
            return null;
        }
        $data = json_decode($text, true);
        if (! is_array($data) || ! is_string($data['e'] ?? null) || ! Str::isUuid($data['e'])) {
            return null;
        }

        return ['e' => strtolower($data['e']), 'p' => is_int($data['p'] ?? null) ? $data['p'] : null];
    }

    private static function load(string $content, string $mime): ?\GdImage
    {
        $image = @imagecreatefromstring($content);

        return $image instanceof \GdImage ? $image : null;
    }

    private static function scale(\GdImage $image, int $max): \GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        if (max($w, $h) <= $max) {
            return $image;
        }
        $ratio = $max / max($w, $h);
        $width = max(1, (int) round($w * $ratio));
        $height = max(1, (int) round($h * $ratio));
        // imagecopyresampled writes straight into the small copy (imagescale keeps a half-scaled intermediate image).
        $small = imagecreatetruecolor($width, $height);
        imagecopyresampled($small, $image, 0, 0, 0, 0, $width, $height, $w, $h);

        return $small;
    }

    // Phones store the rotation in EXIF; GD ignores it. Without the exif extension the photo goes as taken (the model
    // reads it sideways too).
    private static function exifAngle(string $content, string $mime): int
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return 0;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($content));

        return match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180, 6 => -90, 8 => 90, default => 0,
        };
    }
}
