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
        public readonly string $lang = 'es',
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: trans('purchase_order_pdf.mail.subject', ['id' => $this->orderId], $this->lang));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.purchase-order', with: [
            'orderId'      => $this->orderId,
            'providerName' => $this->providerName,
            'lang'         => $this->lang,
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfContent, ($this->lang === 'en' ? "purchase-order-{$this->orderId}.pdf" : "orden-compra-{$this->orderId}.pdf"))
                ->withMime('application/pdf'),
        ];
    }
}
