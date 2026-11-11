<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Daily summary of a user's compromisos that need attention (CompromisoAlertas::summary()).
class CompromisosResumen extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $userName,
        public array $resumen,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Compromisos pendientes: {$this->resumen['total']} — Inteligencia Electoral Santander");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.compromisos-resumen');
    }
}
