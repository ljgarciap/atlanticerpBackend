<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContCreditNoteFolioCounter;
use Illuminate\Support\Facades\DB;

/**
 * Batch 11 (SCRUM-559→564, REQ-482→487) — folio único NC-XXXX, asignado de forma atómica. Mismo
 * criterio que `AdminContInvoiceFolioService::next()` — debe llamarse siempre dentro de la
 * transacción externa que crea la nota, para que el `lockForUpdate()` de Postgres sea real.
 */
final class AdminContCreditNoteFolioService
{
    public function next(): string
    {
        $table = (new AdminContCreditNoteFolioCounter())->getTable();

        DB::statement("INSERT INTO {$table} (id, last_number, created_at, updated_at) VALUES (1, 0, now(), now()) ON CONFLICT (id) DO NOTHING");

        /** @var AdminContCreditNoteFolioCounter $counter */
        $counter = AdminContCreditNoteFolioCounter::where('id', 1)->lockForUpdate()->firstOrFail();
        $counter->increment('last_number');

        return sprintf('NC-%04d', $counter->last_number);
    }
}
