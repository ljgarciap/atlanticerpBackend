<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContCompensationFolioCounter;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1168 F3 — folio único CMP-XXX del documento de compensación, asignado de forma atómica. Secuencia propia (como REC-/ABN-/CONF-). Debe
 * llamarse dentro de una transacción para que el `lockForUpdate()` sea real.
 */
final class AdminContCompensationFolioService
{
    public function next(): string
    {
        $table = (new AdminContCompensationFolioCounter())->getTable();

        DB::statement("INSERT INTO {$table} (id, last_number, created_at, updated_at) VALUES (1, 0, now(), now()) ON CONFLICT (id) DO NOTHING");

        /** @var AdminContCompensationFolioCounter $counter */
        $counter = AdminContCompensationFolioCounter::where('id', 1)->lockForUpdate()->firstOrFail();
        $counter->increment('last_number');

        return sprintf('CMP-%03d', $counter->last_number);
    }
}
