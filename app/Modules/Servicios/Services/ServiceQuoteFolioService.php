<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Services;

use App\Modules\Servicios\Models\ServiceQuoteFolioCounter;
use Illuminate\Support\Facades\DB;

/**
 * REQ-229 RN — folio único COT-SERV-AÑO-NNNN, consecutivo por año calendario, asignado de forma
 * atómica. Debe llamarse siempre dentro de una transacción externa (`ServiceQuoteService`) para
 * que el `lockForUpdate()` de Postgres sea real — mismo contrato que
 * `InspectionReportFolioService`/`TicketFolioService`. El año es SIEMPRE `date('Y')` del servidor,
 * nunca hardcodeado (mismo gotcha ya señalado para `TicketFolioService`).
 */
final class ServiceQuoteFolioService
{
    public function next(): string
    {
        $year  = (int) date('Y');
        $table = (new ServiceQuoteFolioCounter())->getTable();

        DB::statement("INSERT INTO {$table} (year, last_number, created_at, updated_at) VALUES (?, 0, now(), now()) ON CONFLICT (year) DO NOTHING", [$year]);

        /** @var ServiceQuoteFolioCounter $counter */
        $counter = ServiceQuoteFolioCounter::where('year', $year)->lockForUpdate()->firstOrFail();
        $counter->increment('last_number');

        return sprintf('COT-SERV-%d-%04d', $year, $counter->last_number);
    }
}
