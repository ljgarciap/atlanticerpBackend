<?php

declare(strict_types=1);

use App\Modules\VentasDiseno\Http\Controllers\ArchitectController;
use App\Modules\VentasDiseno\Http\Controllers\AssociateLinkController;
use App\Modules\VentasDiseno\Http\Controllers\AuditLogController;
use App\Modules\Integrations\Outlook\Http\Controllers\OutlookCalendarController;
use App\Modules\VentasDiseno\Http\Controllers\CalendarEventController;
use App\Modules\VentasDiseno\Http\Controllers\CatalogController;
use App\Modules\VentasDiseno\Http\Controllers\CatalogProductController;
use App\Modules\VentasDiseno\Http\Controllers\CatalogProductFamilyController;
use App\Modules\VentasDiseno\Http\Controllers\ClientController;
use App\Modules\VentasDiseno\Http\Controllers\ClientsController;
use App\Modules\VentasDiseno\Http\Controllers\ClientTaxDataController;
use App\Modules\VentasDiseno\Http\Controllers\HomeController;
use App\Modules\VentasDiseno\Http\Controllers\OrdersController;
use App\Modules\VentasDiseno\Http\Controllers\OrdersSettingsController;
use App\Modules\VentasDiseno\Http\Controllers\PipelineCardContactController;
use App\Modules\VentasDiseno\Http\Controllers\PipelineCardProductDeliveryController;
use App\Modules\VentasDiseno\Http\Controllers\PipelineController;
use App\Modules\VentasDiseno\Http\Controllers\PricingSettingsController;
use App\Modules\VentasDiseno\Http\Controllers\ProjectsListController;
use App\Modules\VentasDiseno\Http\Controllers\QuoteConditionsSettingsController;
use App\Modules\VentasDiseno\Http\Controllers\QuoteContactController;
use App\Modules\VentasDiseno\Http\Controllers\QuoteController;
use App\Modules\VentasDiseno\Http\Controllers\QuoteItemController;
use App\Modules\VentasDiseno\Http\Controllers\QuoteListController;
use App\Modules\VentasDiseno\Http\Controllers\QuotePartController;
use App\Modules\VentasDiseno\Http\Controllers\SalesCommissionTierController;
use App\Modules\VentasDiseno\Http\Controllers\CommissionsController;
use App\Modules\VentasDiseno\Http\Controllers\DashboardController;
use App\Modules\VentasDiseno\Http\Controllers\ReportsConfigController;
use App\Modules\VentasDiseno\Http\Controllers\ReportsController;
use App\Modules\VentasDiseno\Http\Controllers\SalesProjectController;
use App\Modules\VentasDiseno\Http\Controllers\SubClientContactController;
use Illuminate\Support\Facades\Route;

// Grupo 1a/1b del plan de PM — ver memory/project_backlog_ventas_diseno_req.md.
// GET sin permission explícito: la autorización de objeto/alcance la resuelve el
// propio PipelineController/PipelineService por usuario (mismo patrón que crm.php).
Route::prefix('pipeline')->group(function (): void {
    Route::get('/', [PipelineController::class, 'index'])->middleware('permission:ventas_diseno.read,ventas_diseno.pipeline.read_all');
    // Grupo 1d (REQ-019/020/023): creación manual — hasta acá la única forma de que
    // nazca una tarjeta era la regla de Lead automático (REQ-068) al crear un cliente.
    Route::post('/', [PipelineController::class, 'store'])->middleware('permission:ventas_diseno.write,ventas_diseno.pipeline.edit');
    // SCRUM-1128 — `ventas_diseno.pipeline.read_all` (Contabilidad) abre SOLO estas lecturas del Pipeline.
    Route::get('/{id}', [PipelineController::class, 'show'])->middleware('permission:ventas_diseno.read,ventas_diseno.pipeline.read_all');
    // SCRUM-1128 — aprobación explícita "Pasar a Pedido" (única vía para generar el pedido en Bodega).
    Route::post('/{id}/pass-to-order', [PipelineController::class, 'passToOrder'])->middleware('permission:ventas_diseno.pass_to_order');
    Route::put('/{id}', [PipelineController::class, 'update'])->middleware('permission:ventas_diseno.edit,ventas_diseno.pipeline.edit');
    Route::patch('/{id}/stage', [PipelineController::class, 'changeStage'])->middleware('permission:ventas_diseno.edit,ventas_diseno.pipeline.edit');
    Route::post('/{id}/files', [PipelineController::class, 'uploadFile'])->middleware('permission:ventas_diseno.edit,ventas_diseno.pipeline.edit');
    // SCRUM-767 — ver/descargar un archivo ya cargado (URL presignada, lectura).
    Route::get('/{cardId}/files/{fileId}/url', [PipelineController::class, 'fileUrl'])->middleware('permission:ventas_diseno.read,ventas_diseno.pipeline.read_all');
    Route::delete('/{cardId}/files/{fileId}', [PipelineController::class, 'deleteFile'])->middleware('permission:ventas_diseno.edit,ventas_diseno.pipeline.edit');

    // SCRUM-1121 — asociados (base única de arquitectos, diseñadores, contratistas...) del proyecto de la tarjeta.
    Route::post('/{cardId}/associates', [AssociateLinkController::class, 'storeForCard'])->middleware('permission:ventas_diseno.edit,ventas_diseno.pipeline.edit');
    Route::delete('/{cardId}/associates/{architectId}', [AssociateLinkController::class, 'destroyForCard'])->middleware('permission:ventas_diseno.edit,ventas_diseno.pipeline.edit');

    Route::prefix('/{cardId}/contacts')->group(function (): void {
        Route::post('/', [PipelineCardContactController::class, 'store'])->middleware('permission:ventas_diseno.edit,ventas_diseno.pipeline.edit');
        Route::put('/{contactId}', [PipelineCardContactController::class, 'update'])->middleware('permission:ventas_diseno.edit,ventas_diseno.pipeline.edit');
        Route::delete('/{contactId}', [PipelineCardContactController::class, 'destroy'])->middleware('permission:ventas_diseno.edit,ventas_diseno.pipeline.edit');
    });

    // SCRUM-909 — modal "asignar productos a cada fecha de entrega parcial" (completa SCRUM-875).
    Route::get('/{cardId}/product-delivery-assignments', [PipelineCardProductDeliveryController::class, 'index'])->middleware('permission:ventas_diseno.read,ventas_diseno.pipeline.edit');
    Route::put('/{cardId}/product-delivery-assignments', [PipelineCardProductDeliveryController::class, 'update'])->middleware('permission:ventas_diseno.edit,ventas_diseno.pipeline.edit');
});

// SCRUM-690/691/693 (REQ-610/611/613, Batch D) — pantalla "Lista de Proyectos" (Epic CRM). Mismo
// alcance Mías/Equipo y mismo permiso que /pipeline — no es una superficie de datos nueva, es otra
// vista sobre PipelineCard. REQ-612/614 son 100% frontend (query params sobre /pipeline), sin ruta propia.
Route::prefix('projects')->group(function (): void {
    Route::get('/', [ProjectsListController::class, 'index'])->middleware('permission:ventas_diseno.read');
    Route::get('/export', [ProjectsListController::class, 'export'])->middleware('permission:ventas_diseno.read');
});

// REQ-010: buscar/crear Cliente Master y Subcliente desde el flujo de vinculación de Pipeline.
// SCRUM-1139 — `ventas_diseno.pipeline.edit` (Contabilidad) abre SOLO estas 4 rutas del flujo mínimo de vinculación («Nuevo proyecto»);
// la pantalla Clientes completa (`/clients`, contactos, datos fiscales) sigue exigiendo `ventas_diseno.read/.write/.edit`.
Route::prefix('master-clients')->group(function (): void {
    Route::get('/', [ClientController::class, 'masterClients'])->middleware('permission:ventas_diseno.read,ventas_diseno.pipeline.edit');
    Route::post('/', [ClientController::class, 'storeMasterClient'])->middleware('permission:ventas_diseno.write,ventas_diseno.pipeline.edit');
});

Route::prefix('sub-clients')->group(function (): void {
    Route::get('/', [ClientController::class, 'subClients'])->middleware('permission:ventas_diseno.read,ventas_diseno.pipeline.edit');
    Route::post('/', [ClientController::class, 'storeSubClient'])->middleware('permission:ventas_diseno.write,ventas_diseno.pipeline.edit');

    // REQ-066: contactos propios del Subcliente (reutilizables, distintos de los
    // contactos de tarjeta de /pipeline/{cardId}/contacts). El index (REQ-031) los
    // busca para vincularlos a una cotización.
    Route::prefix('/{subClientId}/contacts')->group(function (): void {
        Route::get('/', [SubClientContactController::class, 'index'])->middleware('permission:ventas_diseno.read');
        Route::post('/', [SubClientContactController::class, 'store'])->middleware('permission:ventas_diseno.edit');
        Route::put('/{contactId}', [SubClientContactController::class, 'update'])->middleware('permission:ventas_diseno.edit');
        Route::delete('/{contactId}', [SubClientContactController::class, 'destroy'])->middleware('permission:ventas_diseno.edit');
    });
});

// Grupo 2 del plan de PM — pantalla Clientes completa (REQ-063 a REQ-070). Distinto
// de /master-clients y /sub-clients arriba (esos cubren solo REQ-010, el flujo
// mínimo de vinculación desde Pipeline).
Route::prefix('clients')->group(function (): void {
    Route::get('/', [ClientsController::class, 'index'])->middleware('permission:ventas_diseno.read');
    Route::post('/', [ClientsController::class, 'store'])->middleware('permission:ventas_diseno.write');
    // SCRUM-1002 — directorio de datos fiscales (antes de `/{id}` para que no lo capture). Sin `ventas_diseno.read`: Contabilidad lo usa desde Admin&Cont.
    Route::get('/fiscal-directory', [ClientTaxDataController::class, 'directory'])->middleware('permission:ventas_diseno.edit,ventas_diseno.edit.client_tax_data,admin_contab.edit.client_itbms_exemption');
    Route::get('/{id}', [ClientsController::class, 'show'])->middleware('permission:ventas_diseno.read');
    Route::get('/{id}/projects', [ClientsController::class, 'projects'])->middleware('permission:ventas_diseno.read');
    Route::post('/{id}/sub-clients', [ClientsController::class, 'storeSubClient'])->middleware('permission:ventas_diseno.write');
    Route::patch('/sub-clients/{subClientId}/category', [ClientsController::class, 'updateSubClientCategory'])->middleware('permission:ventas_diseno.edit');
    // SCRUM-1002 — validar RUC/Cédula contra la DGI (vía MSL, 20/min por usuario) y editar los datos fiscales de un SubCliente.
    // Edición: quien ya edita en Ventas & Diseño (`.edit`) o tiene el permiso angosto (Gerencia/Contabilidad, SpecialPermissionSeeder).
    Route::post('/validate-tax-id', [ClientTaxDataController::class, 'validateTaxId'])->middleware(['permission:ventas_diseno.write,ventas_diseno.edit.client_tax_data', 'throttle:tax-lookup']);
    Route::patch('/sub-clients/{subClientId}/tax-data', [ClientTaxDataController::class, 'updateTaxData'])->middleware('permission:ventas_diseno.edit,ventas_diseno.edit.client_tax_data');
});

// SCRUM-684→689 (REQ-604 a REQ-609, Batch C) — Dashboard CRM, exclusivo de Gerencia
// (REQ-609): siempre alcance de equipo completo, sin selector Mío/Equipo.
Route::prefix('dashboard')->middleware('permission:platform.management')->group(function (): void {
    Route::get('/summary', [DashboardController::class, 'summary'])->middleware('permission:ventas_diseno.read');
    Route::post('/remind', [DashboardController::class, 'remind'])->middleware('permission:ventas_diseno.read');
});

// Grupo 3 del plan de PM — pantalla Inicio (REQ-056 a REQ-062). REQ-057 (Mi
// desempeño/comisiones) es shell de frontend puro (sin endpoint).
Route::prefix('home')->group(function (): void {
    Route::get('/vendors', [HomeController::class, 'vendors'])->middleware('permission:ventas_diseno.read');
    Route::get('/summary', [HomeController::class, 'summary'])->middleware('permission:ventas_diseno.read');
    // SCRUM-908 — tarjeta "Aprobaciones Pendientes" (hoy siempre vacía, ver docblock del controller).
    Route::get('/approvals-pending', [HomeController::class, 'approvalsPending'])->middleware('permission:ventas_diseno.read');
});

// REQ-058 (calendario) — desde 2026-07-21 lee eventos reales de Outlook (ver
// OutlookCalendarService), reemplazando /calendar-events como fuente de datos. Los endpoints de
// /calendar-events (tabla calendar_events, CRUD completo) quedan sin usar por el frontend — no se
// borran en este cambio (dato ya en producción/QA, cambio destructivo de BD requiere confirmación
// explícita de Luis aparte, ver atlanticerp/CLAUDE.md "Nunca").
Route::prefix('calendar')->group(function (): void {
    Route::get('/', [OutlookCalendarController::class, 'index'])
        ->defaults('module', 'ventas_diseno')
        ->middleware('permission:ventas_diseno.read');
});

Route::prefix('calendar-events')->group(function (): void {
    Route::get('/', [CalendarEventController::class, 'index'])->middleware('permission:ventas_diseno.read');
    Route::post('/', [CalendarEventController::class, 'store'])->middleware('permission:ventas_diseno.write');
    Route::put('/{id}', [CalendarEventController::class, 'update'])->middleware('permission:ventas_diseno.edit');
    Route::delete('/{id}', [CalendarEventController::class, 'destroy'])->middleware('permission:ventas_diseno.edit');
});

// Cotización-A (REQ-024 a 032) — ver memory/project_backlog_ventas_diseno_req.md
// para el desglose completo (Cotización-B/C/D, todavía sin implementar).
// Contactos, partidas e ítems de una cotización (rutas hijas de `/{quoteId}`).
$quoteChildRoutes = function (): void {
Route::prefix('/{quoteId}/contacts')->group(function (): void {
    Route::post('/', [QuoteContactController::class, 'store'])->middleware('permission:ventas_diseno.edit');
    Route::delete('/{contactId}', [QuoteContactController::class, 'destroy'])->middleware('permission:ventas_diseno.edit');
});

// Cotización-B (REQ-035/038/041/042) — partidas e ítems, todos personalizados
// por ahora (sin Catálogo real).
Route::prefix('/{quoteId}/parts')->group(function (): void {
    Route::post('/', [QuotePartController::class, 'store'])->middleware('permission:ventas_diseno.edit');
    // SCRUM-1161 — reordenar partidas (drag and drop). ANTES de "/{partId}" para que "reorder" no se lea como id.
    Route::put('/reorder', [QuotePartController::class, 'reorder'])->middleware('permission:ventas_diseno.edit');
    Route::put('/{partId}', [QuotePartController::class, 'update'])->middleware('permission:ventas_diseno.edit');
    Route::delete('/{partId}', [QuotePartController::class, 'destroy'])->middleware('permission:ventas_diseno.edit');
    // SCRUM-849.7 — duplicar partida completa (productos/referencias/descripciones).
    Route::post('/{partId}/duplicate', [QuotePartController::class, 'duplicate'])->middleware('permission:ventas_diseno.edit');

    Route::prefix('/{partId}/items')->group(function (): void {
        Route::post('/', [QuoteItemController::class, 'store'])->middleware('permission:ventas_diseno.edit');
        // REQ-037 — alta en lote de todos los productos de una familia.
        Route::post('/bulk', [QuoteItemController::class, 'bulkStore'])->middleware('permission:ventas_diseno.edit');
        // SCRUM-1161 — reordenar los productos de la partida (drag and drop). ANTES de "/{itemId}".
        Route::put('/reorder', [QuoteItemController::class, 'reorder'])->middleware('permission:ventas_diseno.edit');
        Route::put('/{itemId}', [QuoteItemController::class, 'update'])->middleware('permission:ventas_diseno.edit');
        Route::delete('/{itemId}', [QuoteItemController::class, 'destroy'])->middleware('permission:ventas_diseno.edit');
        // SCRUM-849.6 — foto ad-hoc de un ítem personalizado.
        Route::post('/{itemId}/photo', [QuoteItemController::class, 'uploadPhoto'])->middleware('permission:ventas_diseno.edit');
        // SCRUM-734 (RN9.1) — validación de margen en vivo para el modal de precio, sin persistir.
        Route::post('/{itemId}/preview-price', [QuoteItemController::class, 'previewPrice'])->middleware('permission:ventas_diseno.edit');
    });
});
};

Route::prefix('quotes')->group(function () use ($quoteChildRoutes): void {
    // Grupo 5 (REQ-079 a 085) — listado con tarjetas resumen, búsqueda y filtros.
    Route::get('/', [QuoteListController::class, 'index'])->middleware('permission:ventas_diseno.read');
    Route::post('/', [QuoteController::class, 'store'])->middleware('permission:ventas_diseno.write');
    // SCRUM-930/931/932 (rebote Gerencia 2026-09-20) — sin middleware `permission:` de ruta a
    // propósito, mismo patrón que `OrderStatusController` (ver su docblock): Cobros/Estado de
    // Cuenta/Notas de Crédito (Admin&Contab) abren el detalle/PDF de UNA cotización puntual desde
    // `QuoteViewerModal`, pero `lider_admin_contab`/`asistente_administrativa` no tienen (ni deben
    // tener) `ventas_diseno.read` — eso expondría todo el módulo Ventas y Diseño, más superficie de
    // la que estas pantallas necesitan. El gate real es `canAccess()`/`canViewFromAdminContab()`
    // dentro del controller, solo para show()/pdf() — el resto de rutas de Cotización (write, listado
    // completo) siguen exigiendo `ventas_diseno.read/.write/.edit` sin cambios.
    Route::get('/{id}', [QuoteController::class, 'show']);
    // SCRUM-766 — PDF real (plantilla única interna/externa/descarga, ver QuotePdfService).
    Route::get('/{id}/pdf', [QuoteController::class, 'pdf']);
    Route::put('/{id}', [QuoteController::class, 'update'])->middleware('permission:ventas_diseno.edit');
    // REQ-047 (Cotización-C): lista de campos obligatorios que faltan antes de "generar".
    Route::get('/{id}/validate', [QuoteController::class, 'validateForGenerating'])->middleware('permission:ventas_diseno.read');
    // Cotización-D (REQ-047/048/051/086): "Guardar borrador" (bloquea si faltan
    // campos generales, ítems opcionales) y "Guardar y generar cotización" (exige
    // todo incl. ítems, asigna folio atómico).
    Route::post('/{id}/save-draft', [QuoteController::class, 'saveDraft'])->middleware('permission:ventas_diseno.edit');
    Route::post('/{id}/generate', [QuoteController::class, 'generate'])->middleware('permission:ventas_diseno.edit');
    // SCRUM-723: botón "Guardar" — registra oficialmente y mueve la tarjeta de
    // Pipeline vinculada a "quote" con el total ya calculado.
    Route::post('/{id}/confirm', [QuoteController::class, 'confirm'])->middleware('permission:ventas_diseno.edit');
    // SCRUM-734: historial de versiones del proyecto (RN1/RN3) y "usar como base
    // para nueva versión" (RN2.3) — duplica una cotización confirmada en un
    // Borrador nuevo sobre el mismo proyecto.
    Route::get('/{id}/versions', [QuoteController::class, 'versions'])->middleware('permission:ventas_diseno.read');
    Route::post('/{id}/duplicate', [QuoteController::class, 'duplicate'])->middleware('permission:ventas_diseno.edit');

    // SCRUM-1121 — asociados de la cotización (con proyecto: los del proyecto; sin proyecto: los de su borrador).
    Route::post('/{quoteId}/associates', [AssociateLinkController::class, 'storeForQuote'])->middleware('permission:ventas_diseno.edit');
    Route::delete('/{quoteId}/associates/{architectId}', [AssociateLinkController::class, 'destroyForQuote'])->middleware('permission:ventas_diseno.edit');

    $quoteChildRoutes();
});

// SCRUM-703→710 (REQ-623→630, Epic CRM Batch F) — "Pedidos". SCRUM-1119: muestra márgenes, montos y
// estado de compra, así que ya no cuelga de `ventas_diseno.read` (lo tiene todo vendedor por el
// Pipeline): el gate es de ROL — Compras, Gerencia y superadmin, y nadie más. `lider_compras` no
// tiene `ventas_diseno.read`, por eso tampoco se exige acá; `OrdersController` le da alcance de
// equipo completo (ver `OrdersService::list()`).
Route::prefix('orders')->middleware('permission:ventas_diseno.orders.access')->group(function (): void {
    Route::get('/', [OrdersController::class, 'index']);
    Route::get('/{id}', [OrdersController::class, 'show'])->whereNumber('id');
});

// REQ-625: umbral de margen bajó/mejoró de Pedidos — mismo permiso angosto que pricing-settings.
Route::prefix('orders-settings')->group(function (): void {
    Route::get('/', [OrdersSettingsController::class, 'show'])->middleware('permission:ventas_diseno.pricing.configure');
    Route::put('/', [OrdersSettingsController::class, 'update'])->middleware('permission:ventas_diseno.pricing.configure');
});

// REQ-033: porcentajes/divisores de las fórmulas de Tipo de Precio — antes
// constantes PHP, ahora configurables sin deploy vía `ventas_diseno.pricing.
// configure` (mismo patrón que reports.configure: grant individual, no atado
// a ningún security_level).
Route::prefix('pricing-settings')->group(function (): void {
    Route::get('/', [PricingSettingsController::class, 'show'])->middleware('permission:ventas_diseno.pricing.configure');
    Route::put('/', [PricingSettingsController::class, 'update'])->middleware('permission:ventas_diseno.pricing.configure');
});

// SCRUM-944/945 — tramos de comisión escalonada de vendedores (antes hardcodeados en
// SalesTotalsService::percentFor()). Exclusivo de superadmin (sin pantalla propia todavía, solo
// la vista consolidada) — ver SalesCommissionTierController.
Route::prefix('sales-commission-tiers')->middleware('permission:superadmin.all')->group(function (): void {
    Route::get('/', [SalesCommissionTierController::class, 'index']);
    Route::put('/', [SalesCommissionTierController::class, 'replace']);
});

// SCRUM-138: texto global de Condiciones — reemplaza la edición por-cotización de
// REQ-046 (Gerencia pidió cambiarlo una vez y que aplique a toda cotización nueva desde
// ese momento). Mismo permiso individual que antes gateaba la edición por-cotización.
Route::prefix('quote-conditions-settings')->group(function (): void {
    Route::get('/', [QuoteConditionsSettingsController::class, 'show'])->middleware('permission:ventas_diseno.edit.conditions');
    Route::put('/', [QuoteConditionsSettingsController::class, 'update'])->middleware('permission:ventas_diseno.edit.conditions');
});

// REQ-036: buscador de productos del Catálogo (referencia/descripción, con
// tolerancia a tipeo) — abierto a ventas_diseno.read, igual que sales-projects
// y architects (cualquiera armando una cotización necesita poder buscar).
Route::prefix('catalog-products')->group(function (): void {
    Route::get('/', [CatalogProductController::class, 'index'])->middleware('permission:ventas_diseno.read');
    Route::get('/{id}', [CatalogProductController::class, 'show'])->middleware('permission:ventas_diseno.read');
});

// SCRUM-695→701 (REQ-615→621, Batch E del Epic CRM SCRUM-332) — pantalla "Catálogo" transversal
// (abierta desde Pipeline/Clientes, sin subtab propio). Distinto de /catalog-products de arriba
// (REQ-036, buscador dentro de Cotización) — mismo permiso `ventas_diseno.read` para TODO,
// incluido el upload de ficha técnica (REQ-618 PERMISOS: "sin restricción de rol específica").
// Ruta fija (send-pdf) antes de la wildcard {id} para que no colisione.
Route::prefix('catalog')->group(function (): void {
    Route::get('/', [CatalogController::class, 'index'])->middleware('permission:ventas_diseno.read');
    // SCRUM-702 (REQ-622) — PDF dinámico real (completo/seleccionados).
    Route::post('/send-pdf', [CatalogController::class, 'sendPdf'])->middleware('permission:ventas_diseno.read');
    // SCRUM-998 — estado/descarga de la exportación en segundo plano (ZIP de volúmenes); solo el solicitante o superadmin.
    Route::get('/pdf-exports/{uuid}', [CatalogController::class, 'pdfExport'])->whereUuid('uuid')->middleware('permission:ventas_diseno.read');
    Route::get('/{id}', [CatalogController::class, 'show'])->whereNumber('id')->middleware('permission:ventas_diseno.read');
    Route::get('/{id}/technical-sheet', [CatalogController::class, 'technicalSheetUrl'])->whereNumber('id')->middleware('permission:ventas_diseno.read');
    Route::post('/{id}/technical-sheet', [CatalogController::class, 'uploadTechnicalSheet'])->whereNumber('id')->middleware('permission:ventas_diseno.read');
});

// REQ-037: tab "Familias" del buscador — mismo permiso de lectura que catalog-products.
Route::prefix('catalog-product-families')->group(function (): void {
    Route::get('/', [CatalogProductFamilyController::class, 'index'])->middleware('permission:ventas_diseno.read');
    Route::get('/{id}', [CatalogProductFamilyController::class, 'show'])->middleware('permission:ventas_diseno.read');
});

// REQ-030: directorio global de arquitectos (BD propia).
Route::prefix('architects')->group(function (): void {
    Route::get('/', [ArchitectController::class, 'index'])->middleware('permission:ventas_diseno.read');
    Route::post('/', [ArchitectController::class, 'store'])->middleware('permission:ventas_diseno.write');
    // SCRUM-907 — detalle + proyectos asociados, pantalla "Arquitectos" del menú CRM.
    Route::get('/{id}', [ArchitectController::class, 'show'])->whereNumber('id')->middleware('permission:ventas_diseno.read');
    // SCRUM-734 (RN8.5) — edición in-place, mismo patrón que sub-clients/{id}/contacts/{id}.
    Route::put('/{id}', [ArchitectController::class, 'update'])->middleware('permission:ventas_diseno.edit');
});

// REQ-027: buscar/crear Proyecto (sales_project) acotado a un Subcliente, desde Cotización.
Route::prefix('sales-projects')->group(function (): void {
    Route::get('/', [SalesProjectController::class, 'index'])->middleware('permission:ventas_diseno.read');
    Route::post('/', [SalesProjectController::class, 'store'])->middleware('permission:ventas_diseno.write');
});

// Grupo 4 del plan de PM — pantalla Reportes (REQ-071 a 079).
Route::prefix('reports')->group(function (): void {
    Route::get('/summary', [ReportsController::class, 'summary'])->middleware('permission:ventas_diseno.read');

    // REQ-072/075/076/SCRUM-146 — sección "Configuración" (Meta por vendedor +
    // umbral/meses/probabilidades). settings/GET queda abierto a ventas_diseno.read
    // porque Forecast necesita mostrar el 40%/70% configurado a cualquiera que vea
    // Reportes, no solo a quien puede editarlo.
    Route::get('/goals', [ReportsConfigController::class, 'goalsIndex'])->middleware('permission:ventas_diseno.reports.configure');
    Route::put('/goals/{userId}', [ReportsConfigController::class, 'goalsUpsert'])->middleware('permission:ventas_diseno.reports.configure');
    Route::get('/settings', [ReportsConfigController::class, 'settingsShow'])->middleware('permission:ventas_diseno.read');
    Route::put('/settings', [ReportsConfigController::class, 'settingsUpdate'])->middleware('permission:ventas_diseno.reports.configure');

    // REQ-073 — panel Comisiones.
    Route::get('/commissions', [CommissionsController::class, 'summary'])->middleware('permission:ventas_diseno.read');
    Route::patch('/commissions/{cohortId}/mark-paid', [CommissionsController::class, 'markPaid'])->middleware('permission:ventas_diseno.commissions.mark_paid');
});

// REQ-053: historial y auditoría de cambios — "al menos Líder/Gerencia" (permiso
// angosto ventas_diseno.audit, grant individual, mismo patrón que reports.configure).
Route::get('/audit-log', [AuditLogController::class, 'index'])->middleware('permission:ventas_diseno.audit');
