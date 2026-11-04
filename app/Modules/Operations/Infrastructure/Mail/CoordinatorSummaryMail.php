<?php

namespace App\Modules\Operations\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Resumen diario de Coordinación: sílabos por revisar de cada carrera, con la vista de DailySummaryMail. */
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
        return new Envelope(subject: DailySummaryMail::SUBJECT);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.daily-summary', with: [
            'lines' => $this->lines(),
            'buttonLabel' => 'Abrir revisión',
        ]);
    }

    /** @return list<string> */
    public function lines(): array
    {
        $lines = [];
        foreach ($this->careers as $career) {
            if ($career['review'] > 0) {
                $lines[] = $career['review'] === 1
                    ? "{$career['career']}: 1 sílabo por revisar."
                    : "{$career['career']}: {$career['review']} sílabos por revisar.";
            }
        }

        return $lines;
    }
}
