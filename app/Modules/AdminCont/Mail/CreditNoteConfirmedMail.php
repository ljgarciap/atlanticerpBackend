<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * REQ-486 — al confirmarse una Nota de Crédito con factura de origen, se notifica al cliente con
 * la Nota de Crédito y su Estado de Cuenta ya actualizado. RN1: la factura original nunca se
 * reenvía ni cambia, solo estos 2 documentos.
 */
final class CreditNoteConfirmedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $numero,
        public readonly string $cliente,
        private readonly string $creditNotePdf,
        private readonly string $accountStatementPdf,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Atlantic — Nota de Crédito {$this->numero}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.credit-note-confirmed', with: [
            'numero'  => $this->numero,
            'cliente' => $this->cliente,
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->creditNotePdf, "nota-credito-{$this->numero}.pdf")
                ->withMime('application/pdf'),
            Attachment::fromData(fn () => $this->accountStatementPdf, "estado-cuenta-{$this->cliente}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
