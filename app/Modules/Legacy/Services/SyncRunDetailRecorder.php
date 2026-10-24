<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Models\SyncRunDetail;
use Illuminate\Support\Carbon;

/**
 * SCRUM-816 — persiste el detalle fila-por-fila (excluded/orphan/ambiguous/error) de una corrida
 * ya registrada en `legacy.sync_runs`. Compartido por los 6 comandos `legacy:sync-*` que calculan
 * detalle real (Productos/Clientes/Facturas/Albaranes/Cotizaciones/Pedidos) para no repetir el
 * bulk-insert chunkeado en cada uno — Pagos/Proveedores nunca tienen nada que grabar acá
 * (`excluidos` siempre 0, sin criterio de exclusión) y ProductosFotos es un mecanismo aparte
 * basado en Jobs.
 */
final class SyncRunDetailRecorder
{
    private const CHUNK_SIZE = 500;

    /**
     * @param list<array{referencia: string, status: string, motivo: ?string}> $rows
     */
    public function record(SyncRun $syncRun, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $now = Carbon::now();

        $payload = array_map(
            static fn (array $row): array => [
                'sync_run_id' => $syncRun->id,
                'referencia'  => $row['referencia'],
                'status'      => $row['status'],
                'motivo'      => $row['motivo'],
                'created_at'  => $now,
            ],
            $rows,
        );

        foreach (array_chunk($payload, self::CHUNK_SIZE) as $chunk) {
            SyncRunDetail::query()->insert($chunk);
        }
    }
}
