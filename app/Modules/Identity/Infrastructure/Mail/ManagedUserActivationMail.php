<?php

namespace App\Modules\Identity\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Envía el enlace de activación sin transportar ni revelar una contraseña. */
class ManagedUserActivationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $roleName,
        public readonly string $activationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Active su cuenta en '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.managed-user-activation');
    }
}
