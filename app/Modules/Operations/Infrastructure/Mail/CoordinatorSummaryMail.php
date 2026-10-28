<?php

namespace App\Modules\Operations\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CoordinatorSummaryMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param list<array{career: string, review: int}> $careers */
    public function __construct(
        public readonly string $name,
        public readonly array $careers,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tiene sílabos pendientes en el sistema');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.coordinator-summary');
    }
}
