<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Modules\Bodega\Models\OrderFolioCounter;
use Illuminate\Support\Facades\DB;

/**
 * HU-335: folio único PED-AAAA-NNNN, consecutivo por año calendario, asignado de forma atómica
 * para que dos pedidos creados al mismo tiempo (ej. 2 cotizaciones aprobadas casi simultáneamente)
 * nunca choquen. Mismo mecanismo que `App\Modules\VentasDiseno\Services\QuoteFolioService`, solo
 * que la tabla contadora vive en el schema `bodega` (`order_folio_counters`) en vez de
 * `ventas_diseno`. Debe llamarse siempre dentro de una transacción externa
 * (`OrderService::createFromApprovedQuote()`) para que el lockForUpdate() de Postgres sea real.
 */
final class OrderFolioService
{
    public function next(): string
    {
        $year  = (int) date('Y');
        $table = (new OrderFolioCounter())->getTable();

        // INSERT ... ON CONFLICT DO NOTHING asegura que la fila del año exista antes de
        // bloquearla — evita la carrera de "dos transacciones crean la primera fila del año a la
        // vez" que un firstOrCreate() común no resuelve de forma atómica.
        DB::statement("INSERT INTO {$table} (year, last_number, created_at, updated_at) VALUES (?, 0, now(), now()) ON CONFLICT (year) DO NOTHING", [$year]);

        /** @var OrderFolioCounter $counter */
        $counter = OrderFolioCounter::where('year', $year)->lockForUpdate()->firstOrFail();
        $counter->increment('last_number');

        return sprintf('PED-%d-%04d', $year, $counter->last_number);
    }
}
