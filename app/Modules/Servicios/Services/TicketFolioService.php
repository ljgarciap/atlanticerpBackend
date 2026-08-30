<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Services;

use App\Modules\Servicios\Models\Ticket;
use App\Modules\Servicios\Models\TicketFolioCounter;
use Illuminate\Support\Facades\DB;

/**
 * REQ-249 RN1 — folio único PREFIJO-AÑO-NNNN, consecutivo por (prefijo, año), asignado de forma
 * atómica para que dos tickets del mismo tipo creados al mismo tiempo nunca choquen. El año es
 * SIEMPRE `date('Y')` del servidor en el momento de la llamada — nunca hardcodeado (el mockup
 * entregado lo tenía fijo en "2026", gotcha señalado explícitamente en el ticket, no se repite acá).
 *
 * Debe llamarse siempre dentro de una transacción externa (`TicketService::create()`) para que el
 * `lockForUpdate()` de Postgres sea real — mismo contrato que `QuoteFolioService::next()`.
 *
 * A diferencia de `QuoteFolioCounter` (PK simple `year`), `ticket_folio_counters` tiene PK
 * compuesta (`prefijo`, `year`) — Eloquent no soporta esto de forma nativa, así que esta clase
 * opera sobre la tabla vía `DB::table()` en vez de los métodos de instancia del modelo.
 */
final class TicketFolioService
{
    public function next(string $tipo): string
    {
        $prefijo = Ticket::PREFIX_BY_TIPO[$tipo] ?? null;
        if ($prefijo === null) {
            throw new \InvalidArgumentException("Tipo de ticket desconocido [{$tipo}] — no tiene prefijo de numeración.");
        }

        $year  = (int) date('Y');
        $table = (new TicketFolioCounter())->getTable();

        // INSERT ... ON CONFLICT DO NOTHING asegura que la fila (prefijo, year) exista antes de
        // bloquearla — evita la carrera de "dos transacciones crean la primera fila a la vez" que
        // un firstOrCreate() común no resuelve de forma atómica (mismo mecanismo que QuoteFolioService).
        DB::statement(
            "INSERT INTO {$table} (prefijo, year, last_number, created_at, updated_at) VALUES (?, ?, 0, now(), now()) ON CONFLICT (prefijo, year) DO NOTHING",
            [$prefijo, $year],
        );

        $counter = DB::table($table)
            ->where('prefijo', $prefijo)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($counter === null) {
            throw new \RuntimeException("No se pudo bloquear el contador de folio para [{$prefijo}-{$year}].");
        }

        $nextNumber = ((int) $counter->last_number) + 1;

        DB::table($table)
            ->where('prefijo', $prefijo)
            ->where('year', $year)
            ->update(['last_number' => $nextNumber, 'updated_at' => now()]);

        return sprintf('%s-%d-%04d', $prefijo, $year, $nextNumber);
    }
}
