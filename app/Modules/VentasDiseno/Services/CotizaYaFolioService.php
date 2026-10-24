<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Modules\VentasDiseno\Models\CotizaYaFolioCounter;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1120: folio único COTY-AAAA-NNNN, consecutivo por año calendario, asignado de forma atómica. Serie propia:
 * no consume ni interrumpe la numeración COT- de las cotizaciones comerciales. Mismo mecanismo que
 * `QuoteFolioService` — debe llamarse dentro de una transacción para que el `lockForUpdate()` sea real.
 */
final class CotizaYaFolioService
{
    public function next(): string
    {
        $year  = (int) date('Y');
        $table = (new CotizaYaFolioCounter())->getTable();

        DB::statement("INSERT INTO {$table} (year, last_number, created_at, updated_at) VALUES (?, 0, now(), now()) ON CONFLICT (year) DO NOTHING", [$year]);

        /** @var CotizaYaFolioCounter $counter */
        $counter = CotizaYaFolioCounter::where('year', $year)->lockForUpdate()->firstOrFail();
        $counter->increment('last_number');

        return sprintf('COTY-%d-%04d', $year, $counter->last_number);
    }
}
