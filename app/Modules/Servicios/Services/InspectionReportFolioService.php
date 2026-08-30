<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Services;

use App\Modules\Servicios\Models\InspectionReportFolioCounter;
use Illuminate\Support\Facades\DB;

/**
 * REQ-238 RN1 — folio único INF-AÑO-NNNN, consecutivo por año calendario, asignado de forma
 * atómica. Debe llamarse siempre dentro de una transacción externa
 * (`InspectionReportService::save()`) para que el `lockForUpdate()` de Postgres sea real — mismo
 * contrato que `QuoteFolioService`/`TicketFolioService`. El año es SIEMPRE `date('Y')` del
 * servidor, nunca hardcodeado (mismo gotcha ya señalado para `TicketFolioService`).
 */
final class InspectionReportFolioService
{
    public function next(): string
    {
        $year  = (int) date('Y');
        $table = (new InspectionReportFolioCounter())->getTable();

        DB::statement("INSERT INTO {$table} (year, last_number, created_at, updated_at) VALUES (?, 0, now(), now()) ON CONFLICT (year) DO NOTHING", [$year]);

        /** @var InspectionReportFolioCounter $counter */
        $counter = InspectionReportFolioCounter::where('year', $year)->lockForUpdate()->firstOrFail();
        $counter->increment('last_number');

        return sprintf('INF-%d-%04d', $year, $counter->last_number);
    }
}
