<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Modules\Bodega\Models\ReturnFolioCounter;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-473→489 (Bloque B6 "Devoluciones") — folio único DEV-AAAA-NNNN, consecutivo por año
 * calendario, asignado de forma atómica para que dos devoluciones creadas casi al mismo tiempo
 * nunca choquen. Mismo mecanismo que `App\Modules\Bodega\Services\OrderFolioService`, solo que
 * la tabla contadora es `return_folio_counters`. Debe llamarse siempre dentro de una transacción
 * externa (`CustomerReturnService::create()`) para que el `lockForUpdate()` de Postgres sea real.
 */
final class ReturnFolioService
{
    public function next(): string
    {
        $year  = (int) date('Y');
        $table = (new ReturnFolioCounter())->getTable();

        // INSERT ... ON CONFLICT DO NOTHING asegura que la fila del año exista antes de
        // bloquearla — evita la carrera de "dos transacciones crean la primera fila del año a la
        // vez" que un firstOrCreate() común no resuelve de forma atómica.
        DB::statement("INSERT INTO {$table} (year, last_number, created_at, updated_at) VALUES (?, 0, now(), now()) ON CONFLICT (year) DO NOTHING", [$year]);

        /** @var ReturnFolioCounter $counter */
        $counter = ReturnFolioCounter::where('year', $year)->lockForUpdate()->firstOrFail();
        $counter->increment('last_number');

        return sprintf('DEV-%d-%04d', $year, $counter->last_number);
    }
}
