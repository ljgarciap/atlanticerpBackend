<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Requests;

/**
 * REQ-144 — editar una orden mientras "Por aprobar": mismos campos y mismas reglas de líneas
 * que crear (RN6: una línea nueva agregada al editar respeta la misma unicidad de referencia),
 * salvo que no se permite cambiar de proveedor (no hay flujo de UI para eso, fuera de alcance).
 * `provider_id` sigue siendo obligatorio en el payload (el frontend lo manda sin cambios) — el
 * controller nunca lo usa para reasignar el proveedor de la orden, solo lo necesita para validar
 * unicidad de referencias de producto nuevo, igual que en creación.
 */
class UpdatePurchaseOrderRequest extends StorePurchaseOrderRequest
{
}
