<?php

declare(strict_types=1);

use App\Modules\Compras\Http\Controllers\ApprovedProjectController;
use App\Modules\Compras\Http\Controllers\CatalogProductSearchController;
use App\Modules\Compras\Http\Controllers\ComprasHomeController;
use App\Modules\Compras\Http\Controllers\ComprasReportsController;
use App\Modules\Compras\Http\Controllers\ComprasSettingsController;
use App\Modules\Compras\Http\Controllers\ComprasZonaLibreRequestController;
use App\Modules\Compras\Http\Controllers\GoodsReceiptController;
use App\Modules\Compras\Http\Controllers\GoodsReceiptInvoiceController;
use App\Modules\Compras\Http\Controllers\InventoryController;
use App\Modules\Compras\Http\Controllers\LiquidationAgencyController;
use App\Modules\Compras\Http\Controllers\ProviderController;
use App\Modules\Compras\Http\Controllers\PurchaseOrderClaimController;
use App\Modules\Compras\Http\Controllers\PurchaseOrderController;
use App\Modules\Compras\Http\Controllers\PurchaseOrderDocumentController;
use App\Modules\Compras\Http\Controllers\PurchaseOrderPaymentController;
use App\Modules\Compras\Http\Controllers\ReplacementRequestController;
use App\Modules\Compras\Http\Controllers\ReplacementSearchController;
use App\Modules\Integrations\Outlook\Http\Controllers\OutlookCalendarController;
use Illuminate\Support\Facades\Route;

// SCRUM-175→182 (REQ-111→118) — Inicio de Compras.
Route::get('/home/summary', [ComprasHomeController::class, 'summary'])->middleware('permission:compras.read');

// REQ-113 (Mi calendario/Outlook) — desbloqueado 2026-07-21, ver OutlookCalendarService. Mismo
// endpoint compartido que Ventas & Diseño, gateado por el módulo `compras`.
Route::prefix('calendar')->group(function (): void {
    Route::get('/', [OutlookCalendarController::class, 'index'])
        ->defaults('module', 'compras')
        ->middleware('permission:compras.read');
});

// SCRUM-183→190 (REQ-120→127) — Proveedores.
Route::prefix('providers')->group(function (): void {
    Route::get('/', [ProviderController::class, 'index'])->middleware('permission:compras.read');
    Route::post('/', [ProviderController::class, 'store'])->middleware('permission:compras.write');
    Route::get('/{id}', [ProviderController::class, 'show'])->middleware('permission:compras.read');
    Route::put('/{id}', [ProviderController::class, 'update'])->middleware('permission:compras.edit');
});

// SCRUM-191→196 (REQ-128→133) — Nueva Orden. SCRUM-201→209 (REQ-138→146) — Ver Órdenes.
Route::prefix('orders')->group(function (): void {
    // SCRUM-771 (corrección 2026-08-18) — compras.limited.view en el OR: Ver Órdenes/Logística
    // son la misma pantalla/endpoint para Líder de Operaciones, con acceso completo a Compras
    // gateado aparte (nunca amplía a compras.write/.edit, que estas rutas no tocan).
    Route::get('/', [PurchaseOrderController::class, 'index'])->middleware('permission:compras.read,compras.limited.view');
    Route::post('/', [PurchaseOrderController::class, 'store'])->middleware('permission:compras.write');
    // REQ-151 (SCRUM-214, hallazgo Pre-QA 2026-08-05) — buscador propio para el "Filtro de
    // Proyecto" de Logística, sin el gate de cotización aprobada de /approved-projects (ver
    // docblock de PurchaseOrderController::searchShipmentProjects()). Debe registrarse ANTES de
    // '/{id}' — si no, Laravel matchea "shipment-projects" como {id}.
    Route::get('/shipment-projects', [PurchaseOrderController::class, 'searchShipmentProjects'])->middleware('permission:compras.read,compras.limited.view');
    // bodega.read también puede leer el detalle (solo lectura) — la sirve el botón "Ver orden"
    // del panel "Por recibir" de Bodega Home (SCRUM-371/REQ-301), ver CheckPermission (OR de
    // permisos separados por coma).
    Route::get('/{id}', [PurchaseOrderController::class, 'show'])->middleware('permission:compras.read,bodega.read,compras.limited.view');
    Route::put('/{id}', [PurchaseOrderController::class, 'update'])->middleware('permission:compras.edit');
    Route::patch('/{id}/approve', [PurchaseOrderController::class, 'approve'])->middleware('permission:compras.approve');
    Route::patch('/{id}/advance', [PurchaseOrderController::class, 'advance'])->middleware('permission:compras.edit');
    // SCRUM-208 (rediseño 2026-08-15) — mismo permiso que 'advance', son la misma acción de
    // negocio (avanzar una etapa) aplicada al remanente en vez de al status de la orden.
    Route::patch('/{id}/advance-remainder', [PurchaseOrderController::class, 'advanceRemainder'])->middleware('permission:compras.edit');
    Route::patch('/{id}/confirm-pending-receipts', [PurchaseOrderController::class, 'confirmPendingReceipts'])->middleware('permission:compras.edit');
    // SCRUM-210 (REQ-147, alcance reducido) — asignar agencia de liquidación (solo Zona Libre).
    Route::patch('/{id}/liquidate', [PurchaseOrderController::class, 'liquidate'])->middleware('permission:compras.edit');
    // REQ-136/149 — PDF real (dompdf) + envío por correo al proveedor.
    Route::get('/{id}/pdf', [PurchaseOrderController::class, 'pdf'])->middleware('permission:compras.read');
    Route::post('/{id}/send-email', [PurchaseOrderController::class, 'sendEmail'])->middleware('permission:compras.write');

    // SCRUM-214→219 (REQ-151→156) — Logística.
    Route::patch('/{id}/shipping-info', [PurchaseOrderController::class, 'updateShippingInfo'])->middleware('permission:compras.edit');

    // SCRUM-250→253 (REQ-187→190) — Pagos a Proveedores.
    Route::prefix('{orderId}/payments')->group(function (): void {
        Route::get('/', [PurchaseOrderPaymentController::class, 'index'])->middleware('permission:compras.read');
        Route::post('/', [PurchaseOrderPaymentController::class, 'store'])->middleware('permission:compras.edit');
    });
    Route::patch('/{id}/request-payment', [PurchaseOrderPaymentController::class, 'requestPayment'])->middleware('permission:compras.edit');
    Route::patch('/{id}/request-amount-change', [PurchaseOrderPaymentController::class, 'requestAmountChange'])->middleware('permission:compras.edit');
    Route::patch('/{id}/approve-amount-change', [PurchaseOrderPaymentController::class, 'approveAmountChange'])->middleware('permission:compras.edit');
    Route::patch('/{id}/reject-amount-change', [PurchaseOrderPaymentController::class, 'rejectAmountChange'])->middleware('permission:compras.edit');
    Route::prefix('{orderId}/documents')->group(function (): void {
        Route::get('/', [PurchaseOrderDocumentController::class, 'index'])->middleware('permission:compras.read');
        Route::post('/', [PurchaseOrderDocumentController::class, 'store'])->middleware('permission:compras.edit');
        // SCRUM-211 (ADR-SCRUM211) — validación con IA del documento de confirmación de proveedor.
        Route::post('/{documentId}/validate', [PurchaseOrderDocumentController::class, 'validate'])->middleware('permission:compras.edit');
        Route::get('/{documentId}/validation', [PurchaseOrderDocumentController::class, 'validation'])->middleware('permission:compras.read');
    });
});

// Umbral de "calificación baja" (REQ-121/122) — parámetro configurable, no hardcodeado.
Route::prefix('settings')->group(function (): void {
    Route::get('/', [ComprasSettingsController::class, 'show'])->middleware('permission:compras.read');
    Route::put('/', [ComprasSettingsController::class, 'update'])->middleware('permission:compras.settings.configure');
});

// REQ-133 — buscador de Pedidos Aprobados (Ventas & Diseño) para asignar líneas de orden.
Route::get('/approved-projects', [ApprovedProjectController::class, 'index'])->middleware('permission:compras.read');

// SCRUM-262→268 (REQ-199→205) — Reportes avanzados. Ver docblock de ComprasReportsController
// para la definición de REQ-202 (Oportunidad de compra), tomada del Excel de requerimientos.
Route::prefix('reports')->middleware('permission:compras.read')->group(function (): void {
    Route::get('/purchased-by-period', [ComprasReportsController::class, 'purchasedByPeriod']);
    Route::get('/purchase-opportunity', [ComprasReportsController::class, 'purchaseOpportunity']);
    Route::get('/provider-rating', [ComprasReportsController::class, 'providerRating']);
    Route::get('/claims', [ComprasReportsController::class, 'claims']);
    Route::get('/inventory-attention', [ComprasReportsController::class, 'inventoryAttention']);
    Route::get('/liquidation-imports', [ComprasReportsController::class, 'liquidationImports']);
});

// SCRUM-245→249 (REQ-182→186) — Sustitutos. Búsqueda por foto/ficha con IA (ADR-SCRUM245),
// tabla de solicitudes + generar orden del original.
// SCRUM-771 (2026-08-18) — `compras.sustitutos.manage` agregado al OR de permisos de las 3 rutas
// de escritura: permiso angosto para Líder de Operaciones (acceso completo SOLO acá, solo lectura
// en el resto de Compras — ver migración 2026_08_18_100000_add_compras_sustitutos_manage_permission_to_catalog.php),
// sin ampliar lo que ya usa compras.write/.edit para el resto de roles.
Route::prefix('replacement-requests')->group(function (): void {
    // SCRUM-771 (corrección 2026-08-18, hallazgo de validación en dev.atlanticerp.ai) — las 2 rutas GET
    // se habían quedado solo con compras.read en el OR original: Líder de Operaciones perdía la
    // lista de solicitudes y el resultado de búsqueda con 403, pese a tener acceso completo real
    // a esta pantalla. compras.sustitutos.manage agregado acá también, no solo en las de escritura.
    Route::get('/', [ReplacementRequestController::class, 'index'])->middleware('permission:compras.read,compras.sustitutos.manage');
    Route::post('/', [ReplacementRequestController::class, 'store'])->middleware('permission:compras.write,compras.sustitutos.manage');
    Route::post('/{id}/generate-order', [ReplacementRequestController::class, 'generateOrder'])->middleware('permission:compras.write,compras.sustitutos.manage');
    // REQ-182/183 — gateado por compras.edit (no ai.use), mismo criterio que la validación de
    // SCRUM-211: no forzar un permiso de AI a quien ya puede editar Compras.
    Route::post('/search', [ReplacementSearchController::class, 'store'])->middleware('permission:compras.edit,compras.sustitutos.manage');
    Route::get('/search/{jobId}', [ReplacementSearchController::class, 'show'])->middleware('permission:compras.read,compras.sustitutos.manage');
});

// SCRUM-440 (REQ-370, Bloque B3 Zona Libre de Colón) — decisión de Yirena sobre una solicitud
// generada por Bodega (ver `routes/bodega.php`, prefijo `zona-libre`). Permiso angosto nuevo
// `compras.zona-libre.approve` — a propósito NO reusa `compras.approve` (aprobación de flete
// aéreo de Mark, REQ-143), ver ADR-SCRUM433-445-zona-libre-colon.md.
Route::prefix('zona-libre/requests')->group(function (): void {
    Route::post('/{id}/approve', [ComprasZonaLibreRequestController::class, 'approve'])->middleware('permission:compras.zona-libre.approve');
    Route::post('/{id}/reject', [ComprasZonaLibreRequestController::class, 'reject'])->middleware('permission:compras.zona-libre.approve');
});

// SCRUM-257→261 (REQ-194→198) — Garantías y Reclamos.
Route::prefix('claims')->group(function (): void {
    Route::get('/', [PurchaseOrderClaimController::class, 'index'])->middleware('permission:compras.read');
    Route::post('/', [PurchaseOrderClaimController::class, 'store'])->middleware('permission:compras.write');
    Route::get('/{id}', [PurchaseOrderClaimController::class, 'show'])->middleware('permission:compras.read');
    Route::patch('/{id}/status', [PurchaseOrderClaimController::class, 'updateStatus'])->middleware('permission:compras.edit');
    Route::patch('/{id}/resolution', [PurchaseOrderClaimController::class, 'updateResolution'])->middleware('permission:compras.edit');
    Route::post('/{id}/photos', [PurchaseOrderClaimController::class, 'storePhoto'])->middleware('permission:compras.edit');
    Route::get('/{id}/pdf', [PurchaseOrderClaimController::class, 'pdf'])->middleware('permission:compras.read');
});

// SCRUM-210 (REQ-147, alcance reducido) — buscador + alta de agencias de liquidación.
// SCRUM-254/255/256 (REQ-191/192/193) — resumen, detalle + pedidos, gestión de contactos.
Route::prefix('liquidation-agencies')->group(function (): void {
    Route::get('/', [LiquidationAgencyController::class, 'index'])->middleware('permission:compras.read');
    Route::post('/', [LiquidationAgencyController::class, 'store'])->middleware('permission:compras.write');
    Route::get('/summary', [LiquidationAgencyController::class, 'summary'])->middleware('permission:compras.read');
    Route::get('/{id}', [LiquidationAgencyController::class, 'show'])->middleware('permission:compras.read');
    Route::put('/{id}', [LiquidationAgencyController::class, 'update'])->middleware('permission:compras.edit');
    Route::post('/{id}/contacts', [LiquidationAgencyController::class, 'storeContact'])->middleware('permission:compras.edit');
    Route::put('/{id}/contacts/{contactId}', [LiquidationAgencyController::class, 'updateContact'])->middleware('permission:compras.edit');
    Route::delete('/{id}/contacts/{contactId}', [LiquidationAgencyController::class, 'destroyContact'])->middleware('permission:compras.edit');
});

// REQ-130 — buscador de productos del Catálogo filtrado por proveedor, CON costo (a diferencia
// del buscador de Ventas & Diseño) — ver docblock de CatalogProductSearchController.
// SCRUM-434 (Bloque B3 Zona Libre de Colón) — Bodega reusa este mismo endpoint (filtrado por el
// `provider_id` fijo de Zona Libre) para armar su carrito de líneas, en vez de un buscador
// duplicado. `bodega.read` agregado al OR de permisos (mismo mecanismo ya usado en
// `orders/{id}` arriba, ver CheckPermission) — más simple que replicar
// `InventoryController::resolveAccess()` acá: ese patrón existe para un caso distinto (ocultar
// costo en modo restringido), mientras que acá Bodega SÍ necesita ver el costo para armar el
// carrito (mismo criterio que Compras, confirmado en el desglose de PM).
// SCRUM-771 — compras.sustitutos.manage en el OR: pestaña "Buscar" de Sustitutos usa este mismo
// buscador de catálogo para proponer productos sin IA.
Route::get('/products', [CatalogProductSearchController::class, 'index'])->middleware('permission:compras.read,bodega.read,compras.sustitutos.manage');

// SCRUM-231→244 (REQ-168→181) — Inventario. index/show/warehouse-stock chequean acceso adentro
// del controller (compras.read completo O ventas_diseno.read restringido, sin costos) — no se
// puede expresar un OR de 2 permisos vía el middleware `permission:`, ver InventoryController::resolveAccess().
Route::prefix('inventory')->group(function (): void {
    Route::get('/', [InventoryController::class, 'index']);
    // SCRUM-237 — chequeo de unicidad en vivo, antes de la ruta '/{id}' para no matchear "check-reference" como un id.
    Route::get('/check-reference', [InventoryController::class, 'checkReference'])->middleware('permission:compras.read');
    Route::get('/{id}', [InventoryController::class, 'show']);
    Route::post('/', [InventoryController::class, 'store'])->middleware('permission:compras.write');
    Route::put('/{id}', [InventoryController::class, 'update'])->middleware('permission:compras.edit');
    Route::patch('/{id}/toggle-active', [InventoryController::class, 'toggleActive'])->middleware('permission:compras.edit');
    Route::get('/{id}/warehouse-stock', [InventoryController::class, 'warehouseBreakdown']);
    Route::get('/{id}/order-prefill', [InventoryController::class, 'orderPrefill'])->middleware('permission:compras.read');
    Route::get('/{id}/technical-sheet', [InventoryController::class, 'technicalSheetUrl'])->middleware('permission:compras.read,compras.limited.view');
    Route::post('/{id}/technical-sheet', [InventoryController::class, 'uploadTechnicalSheet'])->middleware('permission:compras.edit');
});
// SCRUM-771 (corrección 2026-08-18) — compras.limited.view en el OR, usado por la pantalla Inventario.
Route::get('/warehouses', [InventoryController::class, 'warehouses'])->middleware('permission:compras.read,compras.limited.view');
// SCRUM-764 — listado, mismo acceso interno (resolveAccess()) que familyDetail() de abajo: sin
// middleware `permission:` explícito a propósito, el controller ya resuelve compras.read completo
// O ventas_diseno.read restringido.
Route::get('/families', [InventoryController::class, 'families']);
// SCRUM-237/240 (REQ-174/177) — crear una familia escribiendo un nombre nuevo, reusado por el
// selector de Familia de ambos modales (edición y creación de producto). OR de compras.write
// (modal de creación de producto) y compras.edit (modal de edición) — mismo mecanismo que el
// resto de este archivo, ver CheckPermission.
Route::post('/families', [InventoryController::class, 'storeFamily'])->middleware('permission:compras.write,compras.edit');
Route::get('/families/{id}', [InventoryController::class, 'familyDetail']);
Route::post('/families/{id}/generate-purchase', [InventoryController::class, 'generateFamilyPurchase'])->middleware('permission:compras.edit');

// SCRUM-220→230 (REQ-157/159/161→167/173) — Ingreso de Mercancía.
Route::prefix('goods-receipts')->group(function (): void {
    Route::get('/eligible-orders', [GoodsReceiptController::class, 'eligibleOrders'])->middleware('permission:compras.read');
    Route::get('/orders/{orderId}', [GoodsReceiptController::class, 'orderExpectedProducts'])->middleware('permission:compras.read');
    // SCRUM-224 (ADR-SCRUM224, REQ-161) — Paso 0: adjuntar factura + detección automática con IA.
    Route::post('/invoice', [GoodsReceiptInvoiceController::class, 'store'])->middleware('permission:compras.edit');
    Route::get('/invoice/{jobId}', [GoodsReceiptInvoiceController::class, 'show'])->middleware('permission:compras.read');
    // SCRUM-771 (corrección 2026-08-18) — compras.limited.view en el OR: Ver Registros de Ingreso
    // es una de las 4 pantallas de solo lectura de Líder de Operaciones.
    Route::get('/', [GoodsReceiptController::class, 'index'])->middleware('permission:compras.read,compras.limited.view');
    Route::get('/{id}', [GoodsReceiptController::class, 'show'])->middleware('permission:compras.read,compras.limited.view');
    Route::post('/', [GoodsReceiptController::class, 'store'])->middleware('permission:compras.edit');
    Route::put('/{id}', [GoodsReceiptController::class, 'update'])->middleware('permission:compras.edit');
    Route::post('/lines/{lineId}/confirm', [GoodsReceiptController::class, 'confirm'])->middleware('permission:compras.edit');
    Route::post('/products/{productId}/confirm-pending', [GoodsReceiptController::class, 'confirmPendingForProduct'])->middleware('permission:compras.edit');
});
