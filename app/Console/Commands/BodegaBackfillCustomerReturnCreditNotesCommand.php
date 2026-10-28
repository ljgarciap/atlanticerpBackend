<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\Bodega\Models\CustomerReturn;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;

/**
 * SCRUM-865 punto 2 — `CustomerReturnService::finalizeForCreditNote()` empezó a persistir
 * `customer_returns.credit_note_id` recién en el commit 14e3f69 (la columna se agrega en la misma
 * migración). Toda devolución finalizada ANTES de ese deploy quedó con `credit_note_id = NULL`, y
 * el listado de Devoluciones de Bodega no puede armar el enlace "Ver nota de crédito" para esas
 * filas (rebote de qa.qa 2026-09-09 — PED-2026-0019 / NC-0003).
 *
 * La correlación es exacta y sin FK cross-schema (mismo criterio del resto del proyecto):
 * `admin_contab.credit_notes.devolucion_bodega_id` apunta a `bodega.customer_returns.id`. Se
 * rellena la devolución FINALIZADA cuyo `credit_note_id` está en NULL con el id de la nota de
 * crédito NO rechazada que la referencia (la más reciente si por algún motivo hubiera más de una).
 *
 * Idempotente: solo toca filas con `credit_note_id IS NULL` en estado `finalizado`.
 */
final class BodegaBackfillCustomerReturnCreditNotesCommand extends Command
{
    /** @var string */
    protected $signature = 'bodega:backfill-customer-return-credit-notes
        {--tenant=atlantic : Slug del tenant a corregir}
        {--dry-run : Solo reporta lo que backfillearía, sin escribir}';

    /** @var string */
    protected $description = 'SCRUM-865 — backfill de customer_returns.credit_note_id para devoluciones finalizadas antes del fix';

    public function handle(): int
    {
        $tenantSlug = (string) $this->option('tenant');
        $dryRun     = (bool) $this->option('dry-run');

        $tenant = Tenant::where('slug', $tenantSlug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$tenantSlug}' no existe.");

            return self::FAILURE;
        }

        Tenant::forgetCurrent();
        $tenant->makeCurrent();

        try {
            $candidatas = CustomerReturn::query()
                ->where('status', CustomerReturn::STATUS_FINALIZADO)
                ->whereNull('credit_note_id')
                ->orderBy('id')
                ->get();

            if ($candidatas->isEmpty()) {
                $this->info('No hay devoluciones finalizadas sin credit_note_id — nada que backfillear.');

                return self::SUCCESS;
            }

            $backfilled = 0;
            $sinNota    = 0;

            foreach ($candidatas as $return) {
                $creditNote = AdminContCreditNote::query()
                    ->where('devolucion_bodega_id', $return->id)
                    ->where('estado', '!=', AdminContCreditNote::ESTADO_RECHAZADA)
                    ->orderByDesc('id')
                    ->first();

                if ($creditNote === null) {
                    $this->warn("  {$return->return_number} (id {$return->id}): sin nota de crédito asociada — se omite.");
                    $sinNota++;

                    continue;
                }

                $this->line("  {$return->return_number} (id {$return->id}) → NC {$creditNote->numero} (id {$creditNote->id})".($dryRun ? ' [dry-run]' : ''));

                if (! $dryRun) {
                    $return->update(['credit_note_id' => $creditNote->id]);
                }
                $backfilled++;
            }

            $this->info(($dryRun ? '[dry-run] ' : '')."Devoluciones actualizadas: {$backfilled} de {$candidatas->count()} candidatas (sin nota: {$sinNota}).");

            return self::SUCCESS;
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
