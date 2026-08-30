<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContPettyCashReportFolioCounter;
use Illuminate\Support\Facades\DB;

/**
 * Batch 20 (REQ-538) — folio `NNNN-AAAA` de reportes de Caja Chica, asignado de forma atómica y
 * particionado por año (a diferencia de `AdminContCreditNoteFolioService::next()`, que es global
 * sin año). Debe llamarse siempre dentro de la transacción externa que crea el reporte, para que
 * el `lockForUpdate()` de Postgres sea real.
 */
final class AdminContPettyCashReportFolioService
{
    public function next(): string
    {
        $table = (new AdminContPettyCashReportFolioCounter())->getTable();
        $year  = (int) now()->format('Y');

        DB::statement(
            "INSERT INTO {$table} (anio, last_number, created_at, updated_at) VALUES (?, 0, now(), now()) ON CONFLICT (anio) DO NOTHING",
            [$year],
        );

        /** @var AdminContPettyCashReportFolioCounter $counter */
        $counter = AdminContPettyCashReportFolioCounter::where('anio', $year)->lockForUpdate()->firstOrFail();
        $counter->increment('last_number');

        return sprintf('%04d-%d', $counter->last_number, $year);
    }
}
