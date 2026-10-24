<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Jobs;

use App\Modules\AdminCont\Mail\CreditNoteConfirmedMail;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Services\AccountStatementPdfService;
use App\Modules\AdminCont\Services\AccountStatementService;
use App\Modules\AdminCont\Services\CreditNotePdfService;
use App\Modules\AdminCont\Services\CreditNoteService;
use App\Modules\VentasDiseno\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * REQ-486 — envío real al confirmarse una Nota de Crédito (antes solo existía el aviso
 * informativo del formulario, nunca se disparaba el correo). Mismo patrón de resiliencia que
 * SendPurchaseOrderEmailJob (retry+backoff, ver tabla de "Patrones de resiliencia" en
 * atlanticerp/CLAUDE.md) — un fallo de envío nunca bloquea ni revierte la nota ya aplicada.
 *
 * Destinatario: contactos de la cotización de origen (`AdminContInvoice::quote_id`) con email —
 * no hay un flag "principal" en el modelo, así que se notifica a todos. CC: vendedor responsable
 * (`Quote::owner`). Si la cotización no existe o no tiene ningún contacto con email, se registra
 * un warning y no se envía nada (no hay a quién mandarlo).
 */
final class SendCreditNoteConfirmedEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(private readonly int $creditNoteId)
    {
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(
        CreditNoteService $creditNotes,
        CreditNotePdfService $creditNotePdf,
        AccountStatementService $accountStatement,
        AccountStatementPdfService $accountStatementPdf,
    ): void {
        $note = AdminContCreditNote::with('facturaOrigen')->find($this->creditNoteId);
        if ($note === null || $note->estado !== AdminContCreditNote::ESTADO_APLICADA) {
            return;
        }

        $facturaOrigen = $note->facturaOrigen;
        $quote = $facturaOrigen?->quote_id !== null
            ? Quote::withIcgLegacy()->with(['contacts', 'owner'])->find($facturaOrigen->quote_id)
            : null;

        if ($facturaOrigen === null || $quote === null || $facturaOrigen->master_client_id === null) {
            Log::warning('CreditNoteConfirmedMail: nota sin factura de origen, cotización o cliente asociado, no se notifica al cliente.', [
                'credit_note_id' => $note->id,
                'numero'         => $note->numero,
            ]);

            return;
        }

        $contactEmails = $quote->contacts->pluck('email')->filter()->unique()->values()->all();
        if ($contactEmails === []) {
            Log::warning('CreditNoteConfirmedMail: la cotización de origen no tiene ningún contacto con email, no se notifica al cliente.', [
                'credit_note_id' => $note->id,
                'numero'         => $note->numero,
                'quote_id'       => $quote->id,
            ]);

            return;
        }

        $detail = $creditNotes->show($note->id);
        if ($detail === null) {
            return;
        }
        $creditNotePdfContent = $creditNotePdf->generate($detail)->output();

        $statementDetail = $accountStatement->detail(
            $facturaOrigen->master_client_id,
            $facturaOrigen->sales_project_id,
            null,
            null,
        );
        $accountStatementPdfContent = $accountStatementPdf->generate($statementDetail)->output();

        Mail::to($contactEmails)->cc($quote->owner->email)->send(new CreditNoteConfirmedMail(
            $note->numero,
            $note->cliente,
            $creditNotePdfContent,
            $accountStatementPdfContent,
        ));
    }
}
