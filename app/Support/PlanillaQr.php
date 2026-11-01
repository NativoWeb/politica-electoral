<?php

namespace App\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

// QR printed on each page of an event's paper sign-in sheet so a photo of the page can be matched to its event
// and page number (phase 5 reads it). It carries the event id, never the public registration token.
class PlanillaQr
{
    public static function payload(string $eventId, int $page): string
    {
        return json_encode(['e' => $eventId, 'p' => $page], JSON_UNESCAPED_SLASHES);
    }

    // PNG as a data URI (dompdf embeds it without remote access).
    public static function png(string $payload): string
    {
        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'eccLevel' => EccLevel::M,
            'scale' => 8,
            'addQuietzone' => true,
            'quietzoneSize' => 2,
            'outputBase64' => true,
        ]);

        return (new QRCode($options))->render($payload);
    }
}
