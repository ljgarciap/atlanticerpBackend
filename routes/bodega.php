<?php

declare(strict_types=1);

use App\Modules\Bodega\Http\Controllers\AdjustmentRequestController;
use App\Modules\Bodega\Http\Controllers\BodegaHomeController;
use App\Modules\Bodega\Http\Controllers\BodegaInventoryController;
use App\Modules\Bodega\Http\Controllers\BodegaReportsController;
use App\Modules\Bodega\Http\Controllers\BodegaSettingsController;
use App\Modules\Bodega\Http\Controllers\BodegaTeamMembersController;
use App\Modules\Bodega\Http\Controllers\BodegaZonaLibreRequestController;
use App\Modules\Bodega\Http\Controllers\CustomerReturnController;
use App\Modules\Bodega\Http\Controllers\GeneralCountController;
use App\Modules\Bodega\Http\Controllers\KardexController;
use App\Modules\Bodega\Http\Controllers\OrderBoardController;
use App\Modules\Bodega\Http\Controllers\OrderDeliveryController;
use App\Modules\Bodega\Http\Controllers\OrderExportController;
use App\Modules\Bodega\Http\Controllers\OrderInvoiceController;
use App\Modules\Bodega\Http\Controllers\OrderPickingController;
use App\Modules\Bodega\Http\Controllers\OrderStatusController;
use App\Modules\Bodega\Http\Controllers\RelocationRequestController;
use App\Modules\Bodega\Http\Controllers\WarehouseAssistantMappingController;
use App\Modules\Bodega\Http\Controllers\WarehouseController;
use App\Modules\Bodega\Http\Controllers\WarehouseLocationController;
use App\Modules\Bodega\Http\Controllers\WarehouseSaleRulesController;
use App\Modules\Bodega\Http\Controllers\ZonaLibreCatalogoController;
use App\Modules\Integrations\Outlook\Http\Controllers\OutlookCalendarController;
use Illuminate\Support\Facades\Route;

// SCRUM-329 Oleada A / Batch B1 (REQ-294→304, SCRUM-363→374) — pantalla "Home de Bodega".
Route::prefix('home')->group(function (): void {
    Route::get('/team', [BodegaHomeController::class, 'team'])->middleware('permission:bodega.read');
    Route::get('/summary', [BodegaHomeController::class, 'summary'])->middleware('permission:bodega.read');
    // SCRUM-171 RN2 — "Rutas de entrega del día completo", destino del botón "Ver detalle" del
    // panel Agenda de Bodega de Gerencia.
    Route::get('/rutas-dia', [BodegaHomeController::class, 'rutasDia'])->middleware('permission:bodega.read');
    // SCRUM-908 — tarjeta "Aprobaciones Pendientes".
    Route::get('/approvals-pending', [BodegaHomeController::class, 'approvalsPending'])->middleware('permission:bodega.read');
});

// Fix de regresión cruzada (2026-07-28) — selector genérico de personas por rol, fuera del
// namespace `home` a propósito (no es un panel del Home, ver docblock de
// `BodegaTeamMembersController`). Reemplaza el reuso indebido de `/home/team` para "Asignar
// Repartidor" (SCRUM-396), "Quién entregó" (SCRUM-399) y "Asignar Picker" (SCRUM-383).
Route::get('/team-members', [BodegaTeamMembersController::class, 'index'])->middleware('permission:bodega.read');

// SCRUM-500 — umbrales configurables del Home de Bodega, ver `BodegaSettingsService`. Mismo
// patrón que `routes/compras.php` (`/settings`): GET con `bodega.read`, PUT con el permiso
// angosto `bodega.settings.configure` (ver migración de permission_catalog).
Route::prefix('settings')->group(function (): void {
    Route::get('/', [BodegaSettingsController::class, 'show'])->middleware('permission:bodega.read');
    Route::put('/', [BodegaSettingsController::class, 'update'])->middleware('permission:bodega.settings.configure');
    // SCRUM-1196 — «Reglas de bodega»: qué bodegas surten ventas, cuál es la de despacho por defecto y cuáles se excluyen del Disponible.
    // Solo superadmin (el ticket lo pide así): `bodega.settings.configure` lo tienen otros roles y cambiar qué bodegas venden afecta el stock de todos.
    Route::get('/warehouses', [WarehouseSaleRulesController::class, 'index'])->middleware('permission:superadmin.all');
    Route::put('/warehouses/{id}', [WarehouseSaleRulesController::class, 'update'])->whereNumber('id')->middleware('permission:superadmin.all');
});

// REQ-298 — "Mi calendario"/"Calendario del equipo", mismo endpoint compartido con Ventas & Diseño
// y Compras (ver `OutlookCalendarController`), cableado acá con `module=bodega`.
Route::prefix('calendar')->group(function (): void {
    Route::get('/', [OutlookCalendarController::class, 'index'])
        ->defaults('module', 'bodega')
        ->middleware('permission:bodega.read');
});

// SCRUM-451→456 (REQ-381→386) — pantalla "Bodegas". Visible para todos los perfiles de Bodega.
Route::prefix('warehouses')->group(function (): void {
    Route::get('/', [WarehouseController::class, 'index'])->middleware('permission:bodega.read,bodega.warehouses.view');
    Route::get('/{id}', [WarehouseController::class, 'show'])->middleware('permission:bodega.read,bodega.warehouses.view');

    // SCRUM-454 (REQ-384, Bloque B4) — catálogo editable de ubicaciones físicas por bodega, base
    // del chip "Espacio libre" de arriba. Ver ADR-SCRUM454-459-reubicacion-bodegas.md.
    Route::get('/{warehouseId}/locations', [WarehouseLocationController::class, 'index'])->middleware('permission:bodega.read');
    Route::post('/{warehouseId}/locations', [WarehouseLocationController::class, 'store'])->middleware('permission:bodega.write');
    Route::patch('/{warehouseId}/locations/{id}', [WarehouseLocationController::class, 'update'])->middleware('permission:bodega.write');
});

// SCRUM-457/458/459 (REQ-387/388/389, Bloque B4 "Reubicación entre bodegas") — Bodega solicita
// reubicar un producto de una bodega a otra, Gerencia aprueba/rechaza. Ver
// ADR-SCRUM454-459-reubicacion-bodegas.md y `AdjustmentRequestController` (mismo patrón).
Route::prefix('relocations')->group(function (): void {
    Route::get('/', [RelocationRequestController::class, 'index'])->middleware('permission:bodega.read');
    Route::post('/', [RelocationRequestController::class, 'store'])->middleware('permission:bodega.write');
    Route::post('/{id}/approve', [RelocationRequestController::class, 'approve'])->middleware('permission:bodega.approve');
    Route::post('/{id}/reject', [RelocationRequestController::class, 'reject'])->middleware('permission:bodega.approve');
});

// SCRUM-467→472 (REQ-397→402) — Kardex, solo lectura. Visible para todos los perfiles de Bodega.
Route::get('/kardex', [KardexController::class, 'index'])->middleware('permission:bodega.read');

// SCRUM-428/429/430/446/447/448/449/450 (REQ-358/359/360/376/377/378/379/380) — Solicitud de ajuste.
Route::prefix('adjustment-requests')->group(function (): void {
    Route::get('/', [AdjustmentRequestController::class, 'index'])->middleware('permission:bodega.read');
    Route::post('/', [AdjustmentRequestController::class, 'store'])->middleware('permission:bodega.write');
    Route::get('/products/search', [AdjustmentRequestController::class, 'searchProducts'])->middleware('permission:bodega.read');
    Route::get('/products/{productId}/warehouse-stock', [AdjustmentRequestController::class, 'productWarehouseStock'])->middleware('permission:bodega.read');
    Route::post('/lines/{id}/approve', [AdjustmentRequestController::class, 'approve'])->middleware('permission:bodega.approve');
    Route::post('/lines/{id}/reject', [AdjustmentRequestController::class, 'reject'])->middleware('permission:bodega.approve');
});

// SCRUM-329 Oleada A / Batch A2 (REQ-305 a 341) — tablero "Pedidos" y su flujo de Kanban completo.
Route::prefix('orders')->group(function (): void {
    // Rutas de segmento fijo ANTES de "/{id}" — de lo contrario Laravel matchea "assistant-load"
    // (o "consolidated-picking") como si fuera el {id} de la ruta de detalle.
    Route::get('/', [OrderBoardController::class, 'index'])->middleware('permission:bodega.read');
    Route::get('/assistant-load', [OrderBoardController::class, 'assistantLoad'])->middleware('permission:bodega.read');
    // SCRUM-854, rebote de Pre-QA — vendedores para el filtro seller_ids, gateado por bodega.read.
    Route::get('/sellers', [OrderBoardController::class, 'sellers'])->middleware('permission:bodega.read');
    // SCRUM-391 (REQ-321) — consolidado del día por picker, ver `PickingConsolidationService`.
    Route::get('/consolidated-picking', [OrderPickingController::class, 'consolidatedPicking'])->middleware('permission:bodega.read');

    // SCRUM-329 Oleada A / Batch A4 (REQ-337 a 343) — pantalla "Status de Pedidos", compartida
    // entre Bodega (ve todo) y Ventas & Diseño (ve solo lo propio). Sin middleware `permission:` —
    // el gate real vive en `OrderStatusController::resolveAccess()` (mismo patrón que
    // `InventoryController::resolveAccess()`, ver el comentario en `routes/compras.php`): no se
    // puede expresar "bodega.read O ventas_diseno.read restringido" con un solo string.
    // ANTES de "/{id}" por la misma razón que "/assistant-load"/"/consolidated-picking" arriba.
    Route::get('/status', [OrderStatusController::class, 'index']);
    // SCRUM-1171 — `whereNumber`: un id no numérico (`/status/abc`) daba 500 (TypeError con la ruta del archivo en el cuerpo); ahora 404. Hasta 18 dígitos: un id que no cabe en int64 daba 500 (TypeError por strict_types), ahora 404.
    Route::get('/status/{id}', [OrderStatusController::class, 'show'])->where('id', '[0-9]{1,18}');
    Route::get('/status/{id}/document', [OrderStatusController::class, 'document'])->where('id', '[0-9]{1,18}');

    // SCRUM-930 — sin middleware `permission:` a propósito, mismo criterio que "/status/{id}"
    // arriba: Admin&Contab (`GuiaEntregaModal` desde Estado de Cuenta) también necesita este
    // detalle, sin tener `bodega.read`. El gate real es `OrderBoardController::resolveAccess()`.
    Route::get('/{id}', [OrderBoardController::class, 'show']);

    // REQ-313/314/315/316/317/318 — flujo de picking.
    Route::post('/{id}/assign-picker', [OrderPickingController::class, 'assignPicker'])->middleware('permission:bodega.write');
    Route::post('/{id}/start-picking', [OrderPickingController::class, 'startPicking'])->middleware('permission:bodega.write');
    Route::get('/{id}/picking-sheet', [OrderPickingController::class, 'pickingSheet'])->middleware('permission:bodega.read');
    Route::patch('/{id}/picking-sheet', [OrderPickingController::class, 'updatePickingSheet'])->middleware('permission:bodega.write');
    // SCRUM-390 (REQ-320 RN1) — export a Excel de la Hoja de Picking y de la tabla de detalle.
    Route::get('/{id}/picking-sheet/export', [OrderExportController::class, 'pickingSheet'])->middleware('permission:bodega.read');
    Route::get('/{id}/items/export', [OrderExportController::class, 'itemDetail'])->middleware('permission:bodega.read');
    Route::post('/{id}/complete-picking', [OrderPickingController::class, 'completePicking'])->middleware('permission:bodega.write');
    Route::post('/{id}/resolve-inventory-review', [OrderPickingController::class, 'resolveInventoryReview'])->middleware('permission:bodega.write');

    // REQ-323/324/325/326/327/328/329/330 — Packing -> Por despachar -> Despachado -> Entregado.
    Route::post('/{id}/register-delivery', [OrderDeliveryController::class, 'registerDelivery'])->middleware('permission:bodega.write');
    Route::post('/{id}/assign-courier', [OrderDeliveryController::class, 'assignCourier'])->middleware('permission:bodega.write');
    Route::post('/{id}/dispatch', [OrderDeliveryController::class, 'dispatch'])->middleware('permission:bodega.write');
    Route::post('/{id}/register-signed-guide', [OrderDeliveryController::class, 'registerSignedGuide'])->middleware('permission:bodega.write');
    Route::get('/{id}/documents/{documentId}/download', [OrderDeliveryController::class, 'downloadDocument'])->middleware('permission:bodega.read');

    // SCRUM-403 (REQ-333) — "Ver bodegas" agregado por pedido (todos los artículos a la vez).
    Route::get('/{id}/warehouse-breakdown', [OrderBoardController::class, 'warehouseBreakdown'])->middleware('permission:bodega.read');
    // SCRUM-401 (REQ-331) — placeholder pasivo de Factura, ver docblock de `OrderInvoiceController`.
    // SCRUM-832 — `show()` ahora expone el detalle fiscal completo cuando invoice_ready=true.
    Route::get('/{id}/invoice', [OrderInvoiceController::class, 'show'])->middleware('permission:bodega.read');
    Route::get('/{id}/invoice/pdf', [OrderInvoiceController::class, 'pdf'])->middleware('permission:bodega.read');
});

// SCRUM-329 Bloque B2 (REQ-344→362, SCRUM-414→432) — "Ver Inventario" de Bodega, solo lectura
// sobre el catálogo que administra Compras. Rutas de segmento fijo ANTES de "/{id}" por la misma
// razón que en "orders/" arriba (Laravel matchea "/families" como si fuera el {id} de detalle).
Route::prefix('inventory')->group(function (): void {
    Route::get('/', [BodegaInventoryController::class, 'index'])->middleware('permission:bodega.read');
    Route::get('/families', [BodegaInventoryController::class, 'families'])->middleware('permission:bodega.read');
    Route::get('/families/{id}', [BodegaInventoryController::class, 'familyDetail'])->middleware('permission:bodega.read');
    Route::get('/{id}', [BodegaInventoryController::class, 'show'])->middleware('permission:bodega.read');
    Route::get('/{id}/warehouse-breakdown', [BodegaInventoryController::class, 'warehouseBreakdown'])->middleware('permission:bodega.read');
    Route::get('/{id}/commitment-detail', [BodegaInventoryController::class, 'commitmentDetail'])->middleware('permission:bodega.read');
    Route::get('/{id}/en-camino-detail', [BodegaInventoryController::class, 'enCaminoDetail'])->middleware('permission:bodega.read');
    // REQ-357/427 — Paso 1 de "Confirmar llegada física" (Bodega); Paso 2 (factura al inventario)
    // sigue siendo exclusivo de Compras, ver `GoodsReceiptController::confirm()`.
    Route::post('/{id}/confirm-arrival', [BodegaInventoryController::class, 'confirmArrival'])->middleware('permission:bodega.write');
});

// SCRUM-329 Oleada A / Batch A2 (REQ-307 RN3) — mapeo vendedor→Asistente de Bodega, editable.
Route::prefix('warehouse-assistant-mapping')->group(function (): void {
    Route::get('/', [WarehouseAssistantMappingController::class, 'index'])->middleware('permission:bodega.read');
    Route::post('/', [WarehouseAssistantMappingController::class, 'store'])->middleware('permission:bodega.write');
});

// SCRUM-433→439, 442→445 (REQ-363→369, 371→375) — Bloque B3 "Zona Libre de Colón": Bodega crea la
// solicitud y hace seguimiento; la decisión (aprobar/rechazar) vive en Compras, ver
// `routes/compras.php` (`zona-libre/requests`). "Ver bodegas" (REQ-437) reusa el endpoint ya
// existente de Ver Inventario, ver `BodegaInventoryController::warehouseBreakdown` arriba — sin
// ruta nueva.
// SCRUM-797 — PUT agregado (revierte SCRUM-440, ver docblock del controller): gate amplio acá es
// solo "bodega.write O compras.zona-libre.approve" (para que la Líder de Compras, que no tiene
// bodega.write, pueda llegar al endpoint) — el gate angosto real (Líder de Bodega o Líder de
// Compras puntual, más el guard de estado `pendiente`) vive dentro de `update()`.
Route::prefix('zona-libre')->group(function (): void {
    // SCRUM-990 — catálogo de Lafayette de SOLO CONSULTA (tabla propia `lafayette_productos`). Lectura únicamente: no hay POST/PUT/PATCH/DELETE
    // sobre `/productos` (lo afirma un test de rutas). Las demás rutas de este grupo son las de "Solicitudes Zona Libre" (SCRUM-440).
    Route::get('/productos', [ZonaLibreCatalogoController::class, 'productos'])->middleware(['throttle:zona-libre-lectura', 'permission:bodega.read,bodega.warehouses.view']);
    // Bodega no tiene `compras.read` (GET /compras/settings) — sin esto, 3C no puede resolver
    // qué `provider_id` pasarle a GET /compras/products para el buscador de productos.
    Route::get('/provider', [BodegaZonaLibreRequestController::class, 'provider'])->middleware('permission:bodega.read');
    Route::get('/requests', [BodegaZonaLibreRequestController::class, 'index'])->middleware('permission:bodega.read');
    Route::post('/requests', [BodegaZonaLibreRequestController::class, 'store'])->middleware('permission:bodega.write');
    Route::get('/requests/{id}', [BodegaZonaLibreRequestController::class, 'show'])->middleware('permission:bodega.read');
    Route::put('/requests/{id}', [BodegaZonaLibreRequestController::class, 'update'])->middleware('permission:bodega.write,compras.zona-libre.approve');
    Route::post('/requests/{id}/remind', [BodegaZonaLibreRequestController::class, 'remind'])->middleware('permission:bodega.write');
});

// SCRUM-460→466 (REQ-390→396, Bloque B5 "Conteos generales de inventario") — ver
// ADR-SCRUM460-466-conteos-generales.md. GET /{id} agregado más allá de la lista explícita del
// ADR (que solo listaba GET /) porque SCRUM-461 exige el detalle de un conteo puntual con sus
// líneas — ver docblock de `GeneralCountController::show()`.
Route::prefix('general-counts')->group(function (): void {
    Route::get('/', [GeneralCountController::class, 'index'])->middleware('permission:bodega.read');
    Route::post('/', [GeneralCountController::class, 'store'])->middleware('permission:bodega.write');
    Route::get('/{id}', [GeneralCountController::class, 'show'])->middleware('permission:bodega.read');
    Route::post('/{id}/evaluate', [GeneralCountController::class, 'evaluate'])->middleware('permission:bodega.write');
    Route::post('/{id}/submit', [GeneralCountController::class, 'submit'])->middleware('permission:bodega.write');
    Route::post('/{id}/approve', [GeneralCountController::class, 'approve'])->middleware('permission:bodega.approve');
    Route::post('/{id}/reject', [GeneralCountController::class, 'reject'])->middleware('permission:bodega.approve');
    Route::post('/{id}/apply', [GeneralCountController::class, 'apply'])->middleware('permission:bodega.write');
    // SCRUM-462 (rebote de Gerencia Test 2026-08-14) — un conteo en borrador (pendiente_evaluacion)
    // o evaluado pero nunca enviado a aprobación quedaba inerte en la bandeja, sin ninguna acción
    // disponible. "Continuar" retoma la evaluación (mismo estado, el frontend navega al detalle ya
    // cargado con lo que haya). "Eliminar" borra el borrador completo — solo si NO fue enviado a
    // aprobación (pendiente_aprobacion/aprobada/rechazada quedan afuera, mismo criterio que el
    // resto del módulo: una vez que Gerencia entra en el flujo, ya no es "solo un borrador").
    Route::post('/{id}/continue', [GeneralCountController::class, 'continueDraft'])->middleware('permission:bodega.write');
    Route::delete('/{id}', [GeneralCountController::class, 'destroy'])->middleware('permission:bodega.write');
});

// SCRUM-473→489 (REQ-403→419, Bloque B6 "Devoluciones") — ver
// ADR-SCRUM473-489-devoluciones.md. Ciclo completo de devolución de cliente: bandeja, buscador de
// guía de entrega origen, creación, detalle, carga de documento firmado, formulario/guía de
// entrega PDF, confirmar recepción física (suma inventario), rechazar (potestad exclusiva de
// Bodega, sin aprobación de Gerencia) y finalizar (nota de crédito simulada). Permiso estándar en
// todas — sin permiso angosto nuevo, todas las tickets dicen "Visible para todos los perfiles de
// Bodega" (a diferencia de Zona Libre, que sí necesitó uno para el aprobador de Compras).
// Rutas de segmento fijo ANTES de "/{id}" por la misma razón que en "orders/"/"inventory/" arriba.
Route::prefix('returns')->group(function (): void {
    Route::get('/', [CustomerReturnController::class, 'index'])->middleware('permission:bodega.read');
    Route::get('/search-orders', [CustomerReturnController::class, 'searchOrders'])->middleware('permission:bodega.read');
    Route::post('/', [CustomerReturnController::class, 'store'])->middleware('permission:bodega.write');
    Route::get('/{id}', [CustomerReturnController::class, 'show'])->middleware('permission:bodega.read');
    Route::post('/{id}/signed-document', [CustomerReturnController::class, 'uploadSignedDocument'])->middleware('permission:bodega.write');
    // SCRUM-478 (rebote de Gerencia Test 2026-08-16) — subir el documento funcionaba, pero no
    // había ninguna forma de verlo/descargarlo después: mismo patrón GET presignado que /form y
    // /delivery-guide, pero sobre el archivo YA subido (sin generar nada nuevo).
    Route::get('/{id}/signed-document', [CustomerReturnController::class, 'signedDocumentUrl'])->middleware('permission:bodega.read');
    // SCRUM-1180 (hijo de SCRUM-1132; antes GET, ver C6 de SCRUM-1158) — generan un PDF, lo suben a S3 y (la guía) crean una fila en `order_documents`:
    // escriben, así que son POST (sin cuerpo; misma respuesta `{url}` firmada y mismo permiso). Dejaron de ser GET: el GET viejo responde 405. El modo
    // solo lectura de «Ver como usuario» las bloquea por método (403 `impersonation_read_only`), sin necesitar el alias `no_impersonation`.
    Route::post('/{id}/form', [CustomerReturnController::class, 'form'])->middleware('permission:bodega.read');
    Route::post('/{id}/delivery-guide', [CustomerReturnController::class, 'deliveryGuide'])->middleware('permission:bodega.read');
    // SCRUM-865 punto 2 — "Ver nota de crédito" en la fila Finalizada, sin exigir permiso de
    // Notas de Crédito (mismo criterio que /signed-document arriba, sentido inverso).
    Route::get('/{id}/credit-note-pdf', [CustomerReturnController::class, 'creditNotePdf'])->middleware('permission:bodega.read');
    Route::post('/{id}/confirm-reception', [CustomerReturnController::class, 'confirmReception'])->middleware('permission:bodega.write');
    // REQ-412 (SCRUM-482, rebote 2026-08-17) — "Simular finalización" eliminado por completo: el
    // requerimiento original ya lo marcaba exclusivo del mockup, nunca debió implementarse como
    // funcionalidad real. Bodega no tiene ninguna vía (UI ni API) para marcar una devolución
    // "finalizada" — ese paso es exclusivo de Administración & Contabilidad, pendiente de una
    // integración real que todavía no existe.
    Route::post('/{id}/reject', [CustomerReturnController::class, 'reject'])->middleware('permission:bodega.write');
});

// SCRUM-490→495 (REQ-420→425, "Reportes de Bodega") — 4 tarjetas + selector de período
// compartido (RN1 de REQ-420: el período aplica a los 4 a la vez, ver `ReportPeriod`). Todas
// "Visible para todos los perfiles de Bodega" — mismo gate simple `bodega.read` que el resto del
// módulo, sin permiso angosto nuevo.
Route::prefix('reports')->group(function (): void {
    Route::get('/productivity', [BodegaReportsController::class, 'productivity'])->middleware('permission:bodega.read');
    Route::get('/adjustment-accuracy', [BodegaReportsController::class, 'adjustmentAccuracy'])->middleware('permission:bodega.read');
    Route::get('/warehouse-capacity', [BodegaReportsController::class, 'warehouseCapacity'])->middleware('permission:bodega.read');
    Route::get('/inventory-summary', [BodegaReportsController::class, 'inventorySummary'])->middleware('permission:bodega.read');
});
