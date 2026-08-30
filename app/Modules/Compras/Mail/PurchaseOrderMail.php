<?php

declare(strict_types=1);

namespace App\Modules\Compras\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** REQ-136 — orden de compra adjunta como PDF real (dompdf), no un link a imprimir. */
final class PurchaseOrderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly int $orderId,
        public readonly string $providerName,
        private readonly string $pdfContent,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Illuminations — Orden de Compra #{$this->orderId}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.purchase-order', with: [
            'orderId'      => $this->orderId,
            'providerName' => $this->providerName,
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfContent, "orden-compra-{$this->orderId}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
