<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Exceptions;

/**
 * SCRUM-837 — `KardexService::registrar()` la lanza cuando un movimiento de tipo `Reubicación`
 * dejaría la bodega de origen con saldo negativo. Nunca debería pasar en operación normal (toda
 * Reubicación parte de stock real ya confirmado en esa bodega); si pasa, es la señal de que algo
 * río arriba está moviendo stock que en realidad nunca estuvo ahí — exactamente el bug real
 * encontrado en `Servicios\Listeners\SyncInsumoReservationOnPurchaseOrderReceived` (reubicaba
 * ciegamente desde Bodega Central sin verificar si el stock había caído ahí de verdad).
 *
 * Los callers deciden qué hacer con esto — no todos deben abortar la operación completa. Ver ese
 * mismo listener, que la loguea (`Log::error`) y sigue procesando el resto de productos de la
 * misma orden en vez de dejar toda la recepción sin confirmar por un solo producto con datos
 * inconsistentes.
 */
final class InsufficientStockException extends \RuntimeException
{
}
