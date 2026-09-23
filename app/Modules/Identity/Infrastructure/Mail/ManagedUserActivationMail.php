<?php

namespace App\Modules\Identity\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Mime\Email;

/** Envía el enlace de activación sin transportar ni revelar una contraseña. */
class ManagedUserActivationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $roleName,
        public readonly string $activationUrl,
        public readonly ?string $careerName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Active su cuenta en '.config('app.name'),
            using: [function (Email $message): void {
                // Clientes como Gmail pueden usar estos encabezados como señal de
                // prioridad, aunque la etiqueta final siempre depende del receptor.
                $message->getHeaders()->addTextHeader('Importance', 'high');
                $message->getHeaders()->addTextHeader('X-Priority', '1');
                $message->getHeaders()->addTextHeader('Priority', 'urgent');
            }],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.managed-user-activation');
    }
}
