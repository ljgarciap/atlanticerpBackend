<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

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
        $counter->increment('last_number');

        return sprintf('F-%04d', $counter->last_number);
    }
}
