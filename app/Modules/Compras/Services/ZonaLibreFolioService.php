<?php

declare(strict_types=1);

namespace App\Modules\Compras\Services;

use App\Modules\Compras\Models\ZonaLibreFolioCounter;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-433 (REQ-369 RN2) — número consecutivo plano (sin prefijo ni año, ver docblock de la
 * migración `create_compras_bodega_zona_libre_folio_counters_table`) para
 * `bodega_zona_libre_requests.order_number`, asignado de forma atómica para que dos solicitudes
 * creadas al mismo tiempo nunca choquen. Mismo mecanismo exacto que
 * `App\Modules\VentasDiseno\Services\QuoteFolioService`/`App\Modules\Bodega\Services\
 * OrderFolioService` (INSERT ... ON CONFLICT DO NOTHING + lockForUpdate()), sin la fila-por-año
 * porque acá no hace falta ese nivel de control. Debe llamarse siempre dentro de una transacción
 * externa (`BodegaZonaLibreRequestController::store()`) para que el lockForUpdate() de Postgres
 * sea real.
 */
final class ZonaLibreFolioService
{
    public function next(): int
    {
        $table = (new ZonaLibreFolioCounter())->getTable();

        // INSERT ... ON CONFLICT DO NOTHING asegura que la fila única (id=1) exista antes de
        // bloquearla — evita la carrera de "dos transacciones crean la fila a la vez" que un
        // firstOrCreate() común no resuelve de forma atómica.
        DB::statement("INSERT INTO {$table} (id, last_number, created_at, updated_at) VALUES (1, 0, now(), now()) ON CONFLICT (id) DO NOTHING");

        /** @var ZonaLibreFolioCounter $counter */
        $counter = ZonaLibreFolioCounter::where('id', 1)->lockForUpdate()->firstOrFail();
        $counter->increment('last_number');

        return $counter->last_number;
    }
}
