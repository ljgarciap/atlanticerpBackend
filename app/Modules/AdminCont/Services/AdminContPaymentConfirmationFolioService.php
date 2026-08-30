<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContPaymentConfirmationFolioCounter;
use Illuminate\Support\Facades\DB;

/**
 * REQ-475 RN2 — folio único CONF-XXX, independiente del REC-XXX del cobro. Mismo mecanismo
 * atómico que `AdminContPaymentFolioService::next()` — debe llamarse siempre dentro de una
 * transacción externa (`PaymentService::confirm()`) para que el `lockForUpdate()` sea real.
 */
final class AdminContPaymentConfirmationFolioService
{
    public function next(): string
    {
        $table = (new AdminContPaymentConfirmationFolioCounter())->getTable();

        DB::statement("INSERT INTO {$table} (id, last_number, created_at, updated_at) VALUES (1, 0, now(), now()) ON CONFLICT (id) DO NOTHING");

        /** @var AdminContPaymentConfirmationFolioCounter $counter */
        $counter = AdminContPaymentConfirmationFolioCounter::where('id', 1)->lockForUpdate()->firstOrFail();
        $counter->increment('last_number');

        return sprintf('CONF-%03d', $counter->last_number);
    }
}
