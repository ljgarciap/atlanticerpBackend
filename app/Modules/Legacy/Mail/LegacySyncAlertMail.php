<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * SCRUM-1114 — aviso de la migración ICG (corrida retenida por el circuit breaker, fallida, saltada o
 * sync apagado). Mismo formato que `SecurityAnomalyMail`. No se encola: el comando que lo dispara ya
 * corre en segundo plano y un fallo de envío nunca debe tumbar la corrida (ver `LegacySyncAlertService`).
 */
final class LegacySyncAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param list<string> $lineas detalle (una línea por dato) */
    public function __construct(
        public readonly string $entidad,
        public readonly string $titulo,
        public readonly array $lineas,
        public readonly string $entorno,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[{$this->entorno}] Migración ICG — {$this->titulo} ({$this->entidad})");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.legacy-sync-alert');
    }
}
