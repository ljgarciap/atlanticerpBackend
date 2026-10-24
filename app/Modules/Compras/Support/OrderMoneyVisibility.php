<?php

declare(strict_types=1);

namespace App\Modules\Compras\Support;

use App\Modules\Compras\Models\PurchaseOrderDocument;
use Illuminate\Http\Request;

/**
 * SCRUM-1013 (Pre-QA H2/H3, decisión de Luis 2026-10-02) — el vendedor entra a Logística y Envío con
 * `compras.logistica.view` y nunca ve costos de compra (regla SCRUM-818): con costo unitario, montos de
 * la orden o una factura/confirmación del proveedor se deduce el costo del producto y se anula el
 * enmascarado de Inventario. Quien tenga cualquier permiso "de verdad" de Compras/Bodega (o superadmin)
 * ve todo igual que antes.
 */
final class OrderMoneyVisibility
{
    /** Categorías de documento sin precios: el vendedor puede verlas (BL y permiso de importación). */
    public const DOCUMENT_CATEGORIES_WITHOUT_PRICES = [
        PurchaseOrderDocument::CATEGORY_BL,
        PurchaseOrderDocument::CATEGORY_PERMISO_IMPORTACION,
    ];

    public static function hiddenFor(Request $request): bool
    {
        /** @var list<string> $permissions */
        $permissions = is_array($request->attributes->get('jwt')['permissions'] ?? null)
            ? array_values(array_filter($request->attributes->get('jwt')['permissions'], 'is_string'))
            : [];

        return in_array('compras.logistica.view', $permissions, true)
            && array_intersect(['superadmin.all', 'compras.read', 'compras.limited.view', 'bodega.read'], $permissions) === [];
    }
}
