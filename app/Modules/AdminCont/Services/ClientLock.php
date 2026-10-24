<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1168 F1 — bloqueo por cliente: toda operación que mueve dinero o saldo de las facturas de un cliente (registrar/confirmar un cobro,
 * aplicar, reversar, compensar) lo toma ANTES de bloquear facturas y cobros. Serializa al cliente sin estorbar a los demás y cierra el
 * consumo doble del saldo a favor y la aplicación doble del mismo dinero. Es un advisory lock de transacción: se libera solo al terminar.
 *
 * Orden de bloqueos (siempre el mismo, para no cruzar esperas): cobro → cliente → facturas por id → cobros/abonos por id.
 */
final class ClientLock
{
    /** Espacio de claves propio de los cobros (la otra mitad de la clave es el cliente). */
    private const NAMESPACE = 11680;

    /** @throws \LogicException fuera de una transacción: el bloqueo no protegería nada. */
    public function lock(int $masterClientId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('ClientLock::lock() solo tiene sentido dentro de una transacción.');
        }

        DB::select('select pg_advisory_xact_lock(?, ?)', [self::NAMESPACE, $masterClientId]);
    }
}
