<?php

namespace App\Modules\Operations\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Resumen diario de Docente y de Biblioteca: lo pendiente, en una lista corta y un botón. */
class DailySummaryMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param list<string> $lines */
    public function __construct(
        public readonly string $name,
        public readonly array $lines,
        public readonly string $buttonLabel,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tiene sílabos pendientes en el sistema');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.daily-summary');
    }
}
