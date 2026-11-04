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

    /** Asunto común de los resúmenes diarios; el de Coordinación usa el mismo asunto y la misma vista. */
    public const SUBJECT = 'Resumen del día: sílabos pendientes';

    /** @param list<string> $lines */
    public function __construct(
        public readonly string $name,
        public readonly array $lines,
        public readonly string $buttonLabel,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: self::SUBJECT);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.daily-summary');
    }
}
