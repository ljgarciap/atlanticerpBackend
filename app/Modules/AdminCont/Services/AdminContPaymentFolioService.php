<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContPaymentFolioCounter;
use Illuminate\Support\Facades\DB;

/**
 * REQ-469 RN1 — folio único REC-XXX, asignado de forma atómica para que dos cobros registrados al
 * mismo tiempo nunca choquen. Debe llamarse siempre dentro de una transacción externa
 * (`PaymentService::register()`) para que el `lockForUpdate()` de Postgres sea real — mismo
 * criterio que `AdminContInvoiceFolioService::next()`.
 */
final class AdminContPaymentFolioService
{
    public function next(): string
    {
        $table = (new AdminContPaymentFolioCounter())->getTable();

        DB::statement("INSERT INTO {$table} (id, last_number, created_at, updated_at) VALUES (1, 0, now(), now()) ON CONFLICT (id) DO NOTHING");

        /** @var AdminContPaymentFolioCounter $counter */
        $counter = AdminContPaymentFolioCounter::where('id', 1)->lockForUpdate()->firstOrFail();
        $counter->increment('last_number');

        return sprintf('REC-%03d', $counter->last_number);
    }
}
