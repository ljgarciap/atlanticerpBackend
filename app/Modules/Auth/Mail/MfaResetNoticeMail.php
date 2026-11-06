<?php

declare(strict_types=1);

namespace App\Modules\Auth\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Aviso a la persona cuyo segundo factor fue reiniciado por un administrador (Fase 27a). */
final class MfaResetNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $firstName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Se reinició tu verificación en dos pasos — AtlanticERP');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.mfa-reset-notice');
    }
}
