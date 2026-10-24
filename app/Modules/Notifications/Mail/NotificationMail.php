<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $body,
        public readonly ?string $ctaUrl = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    /**
     * SCRUM-1153 — `cta_url` es una ruta del frontend («/inventario?search=…», lo que consume la campana con navigate()); en un
     * correo un enlace relativo no lleva a ningún lado, así que aquí se completa con la URL del frontend. Una URL absoluta
     * se deja tal cual.
     */
    // OJO: no se llama `ctaUrl` en la vista — las propiedades públicas del Mailable pisan los datos de `with`.
    private function absoluteCtaUrl(): ?string
    {
        if ($this->ctaUrl === null || ! str_starts_with($this->ctaUrl, '/')) {
            return $this->ctaUrl;
        }

        return rtrim((string) config('app.frontend_url', ''), '/').$this->ctaUrl;
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.notification',
            with: ['subject' => $this->subjectLine, 'body' => $this->body, 'ctaHref' => $this->absoluteCtaUrl()],
        );
    }
}
