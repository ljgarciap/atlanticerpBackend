<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContAbonoFolioCounter;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-955 — folio único ABN-XXX para el Recibo de Abono, asignado de forma atómica para que dos
 * abonos registrados al mismo tiempo nunca choquen. Secuencia propia, independiente del REC-XXX de
 * Cobros (`AdminContPaymentFolioService`) — decisión confirmada con Luis. Debe llamarse siempre
 * dentro de una transacción externa para que el `lockForUpdate()` de Postgres sea real — mismo
 * criterio que `AdminContPaymentFolioService::next()`.
 */
final class AdminContAbonoFolioService
{
    public function next(): string
    {
        $table = (new AdminContAbonoFolioCounter())->getTable();

        DB::statement("INSERT INTO {$table} (id, last_number, created_at, updated_at) VALUES (1, 0, now(), now()) ON CONFLICT (id) DO NOTHING");

        /** @var AdminContAbonoFolioCounter $counter */
        $counter = AdminContAbonoFolioCounter::where('id', 1)->lockForUpdate()->firstOrFail();
        $counter->increment('last_number');

        return sprintf('ABN-%03d', $counter->last_number);
    }
}
