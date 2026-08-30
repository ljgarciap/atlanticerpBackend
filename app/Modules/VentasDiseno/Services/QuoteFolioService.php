<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Modules\VentasDiseno\Models\QuoteFolioCounter;
use Illuminate\Support\Facades\DB;

/**
 * REQ-051: folio único COT-AAAA-NNNN, consecutivo por año calendario, asignado de
 * forma atómica para que dos cotizaciones generadas al mismo tiempo nunca choquen.
 * Debe llamarse siempre dentro de una transacción externa (QuoteService::generate())
 * para que el lockForUpdate() de Postgres sea real.
 */
final class QuoteFolioService
{
    public function next(): string
    {
        $year  = (int) date('Y');
        $table = (new QuoteFolioCounter())->getTable();

        // INSERT ... ON CONFLICT DO NOTHING asegura que la fila del año exista antes
        // de bloquearla — evita la carrera de "dos transacciones crean la primera fila
        // del año a la vez" que un firstOrCreate() común no resuelve de forma atómica.
        DB::statement("INSERT INTO {$table} (year, last_number, created_at, updated_at) VALUES (?, 0, now(), now()) ON CONFLICT (year) DO NOTHING", [$year]);

        /** @var QuoteFolioCounter $counter */
        $counter = QuoteFolioCounter::where('year', $year)->lockForUpdate()->firstOrFail();
        $counter->increment('last_number');

        return sprintf('COT-%d-%04d', $year, $counter->last_number);
    }
}
