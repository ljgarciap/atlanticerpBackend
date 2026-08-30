<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Services;

use App\Modules\Servicios\Models\ToolCodeCounter;
use Illuminate\Support\Facades\DB;

/**
 * REQ-271 RN3 — código único COD-HER-NNN, consecutivo de por vida (sin corte por año/tipo, a
 * diferencia de `TicketFolioService`/`InspectionReportFolioService`), asignado de forma atómica.
 * Debe llamarse siempre dentro de una transacción externa (`ToolService::receivePurchaseRequest()`)
 * para que el `lockForUpdate()` de Postgres sea real — mismo contrato que el resto de los
 * `*FolioService` del módulo.
 */
final class ToolCodeService
{
    private const COUNTER_ROW_ID = 1;

    public function next(): string
    {
        $table = (new ToolCodeCounter())->getTable();

        // INSERT ... ON CONFLICT DO NOTHING asegura que la fila única exista antes de bloquearla
        // — mismo mecanismo que TicketFolioService/InspectionReportFolioService.
        DB::statement(
            "INSERT INTO {$table} (id, last_number, created_at, updated_at) VALUES (?, 0, now(), now()) ON CONFLICT (id) DO NOTHING",
            [self::COUNTER_ROW_ID],
        );

        $counter = DB::table($table)->where('id', self::COUNTER_ROW_ID)->lockForUpdate()->first();
        if ($counter === null) {
            throw new \RuntimeException('No se pudo bloquear el contador de código de herramientas.');
        }

        $nextNumber = ((int) $counter->last_number) + 1;

        DB::table($table)->where('id', self::COUNTER_ROW_ID)->update([
            'last_number' => $nextNumber,
            'updated_at'  => now(),
        ]);

        return sprintf('COD-HER-%03d', $nextNumber);
    }
}
