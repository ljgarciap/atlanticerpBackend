<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContInvoiceFolioCounter;
use Illuminate\Support\Facades\DB;

/**
 * REQ-441 RN4 — folio único F-XXXX, asignado de forma atómica para que dos facturas confirmadas
 * al mismo tiempo nunca choquen. Debe llamarse siempre dentro de una transacción externa
 * (`InvoiceService::confirm()`) para que el `lockForUpdate()` de Postgres sea real — mismo
 * criterio que `QuoteFolioService::next()`.
 */
final class AdminContInvoiceFolioService
{
    public function next(): string
    {
        $table = (new AdminContInvoiceFolioCounter())->getTable();

        DB::statement("INSERT INTO {$table} (id, last_number, created_at, updated_at) VALUES (1, 0, now(), now()) ON CONFLICT (id) DO NOTHING");

        /** @var AdminContInvoiceFolioCounter $counter */
        $counter = AdminContInvoiceFolioCounter::where('id', 1)->lockForUpdate()->firstOrFail();

        // Fase 13 (2.5): el contador se reconcilia con las facturas que YA existen. Una factura insertada fuera del servicio (importación, restauración
        // de un respaldo) dejaba el contador atrás y el siguiente alta chocaba con la unicidad del número (500). Se toma el mayor de los dos.
        $facturas = (new AdminContInvoice())->getTable();
        $maximoExistente = (int) DB::scalar("SELECT COALESCE(MAX(CAST(SUBSTRING(numero FROM 3) AS BIGINT)), 0) FROM {$facturas} WHERE numero ~ '^F-[0-9]+$'");
        $siguiente = max((int) $counter->last_number, $maximoExistente) + 1;
        $counter->update(['last_number' => $siguiente]);

        return sprintf('F-%04d', $siguiente);
    }
}
