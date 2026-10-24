<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;

/**
 * SCRUM-877 parte 1 — rellena `credit_notes.monto_aplicado_saldo` para notas registradas antes de
 * que `CreditNoteService::applyToBalance()` empezara a persistirlo. Notas nuevas no lo necesitan.
 *
 * El valor exacto ("cuánto redujo la factura al aplicarse") no se puede reconstruir con precisión
 * si la factura recibió otros movimientos después — se usa la mejor aproximación disponible:
 *   - `descuento_comercial` / resultado `aplicado_saldo` / `saldo_favor`  →  `monto` completo,
 *     acotado al `monto` de la factura de origen.
 *   - `devolucion_mercancia` con resultado `devuelto`  →  `min(monto, factura.monto)` menos el
 *     excedente que ya se registró como `bank_movement` (SOURCE_CREDIT_NOTE), si existe.
 *
 * Idempotente: solo toca notas con `monto_aplicado_saldo IS NULL` en estado `aplicada`.
 */
final class AdminContBackfillCreditNoteAppliedAmountCommand extends Command
{
    /** @var string */
    protected $signature = 'admin-contab:backfill-credit-note-applied-amount
        {--tenant=atlantic : Slug del tenant}
        {--dry-run : Solo reporta, sin escribir}';

    /** @var string */
    protected $description = 'SCRUM-877 — backfill de credit_notes.monto_aplicado_saldo para notas previas al fix';

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

        $notes = AdminContCreditNote::query()
            ->where('estado', AdminContCreditNote::ESTADO_APLICADA)
            ->whereNull('monto_aplicado_saldo')
            ->with('facturaOrigen')
            ->get();

        $updated = 0;
        foreach ($notes as $note) {
            $facturaMonto = (float) ($note->facturaOrigen->monto ?? $note->monto);
            $aplicado     = round(min($note->monto, $facturaMonto), 2);

            if ($note->resultado === AdminContCreditNote::RESULTADO_DEVUELTO) {
                $excedente = (float) \App\Modules\AdminCont\Models\AdminContBankMovement::query()
                    ->where('source_type', \App\Modules\AdminCont\Models\AdminContBankMovement::SOURCE_CREDIT_NOTE)
                    ->where('source_id', $note->id)
                    ->sum('monto');
                $aplicado = round(max(0.0, $aplicado - $excedente), 2);
            }

            $this->line("NC {$note->numero}: monto_aplicado_saldo = {$aplicado}".($dryRun ? ' (dry-run)' : ''));

            if (! $dryRun) {
                $note->update(['monto_aplicado_saldo' => $aplicado]);
            }
            $updated++;
        }

        $this->info(($dryRun ? '[dry-run] ' : '')."Notas actualizadas: {$updated} de {$notes->count()} candidatas.");

        return self::SUCCESS;
    }
}
