<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Observers;

use App\Modules\Legacy\Models\Cliente;
use App\Modules\VentasDiseno\Models\SubClient;

/**
 * SCRUM-763 — legacy.clientes.sub_client_id tiene nullOnDelete() contra ventas_diseno.sub_clients,
 * pero match_status se queda en "matched" cuando esa FK cascadea (queda consistente recién en la
 * próxima corrida semanal de legacy:sync-clientes). Este observer corre en `deleting` — antes del
 * DELETE real, mientras sub_client_id todavía apunta al SubClient que se está por borrar — para
 * dejar match_status correcto en el mismo momento, sin esperar a esa corrida.
 */
final class SubClientObserver
{
    public function deleting(SubClient $subClient): void
    {
        Cliente::query()
            ->where('sub_client_id', $subClient->id)
            ->update(['match_status' => Cliente::MATCH_UNMATCHED]);
    }
}
