<?php

declare(strict_types=1);

namespace App\Modules\Auth\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class SecurityAnomalyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $severity,
        public readonly string $reasoning,
        public readonly ?string $affectedUserName,
        public readonly string $panelUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Anomalía de seguridad detectada — AtlanticERP CRM');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.security-anomaly');
    }
}
