<?php

declare(strict_types=1);

use App\Modules\VentasDiseno\Http\Controllers\ArchitectController;
use App\Modules\VentasDiseno\Http\Controllers\AuditLogController;
use App\Modules\Integrations\Outlook\Http\Controllers\OutlookCalendarController;
use App\Modules\VentasDiseno\Http\Controllers\CalendarEventController;
use App\Modules\VentasDiseno\Http\Controllers\CatalogController;
use App\Modules\VentasDiseno\Http\Controllers\CatalogProductController;
use App\Modules\VentasDiseno\Http\Controllers\CatalogProductFamilyController;
use App\Modules\VentasDiseno\Http\Controllers\ClientController;
use App\Modules\VentasDiseno\Http\Controllers\ClientsController;
use App\Modules\VentasDiseno\Http\Controllers\HomeController;
use App\Modules\VentasDiseno\Http\Controllers\OrdersController;
use App\Modules\VentasDiseno\Http\Controllers\OrdersSettingsController;
use App\Modules\VentasDiseno\Http\Controllers\PipelineCardContactController;
use App\Modules\VentasDiseno\Http\Controllers\PipelineController;
use App\Modules\VentasDiseno\Http\Controllers\PricingSettingsController;
use App\Modules\VentasDiseno\Http\Controllers\ProjectsListController;
use App\Modules\VentasDiseno\Http\Controllers\QuoteConditionsSettingsController;
use App\Modules\VentasDiseno\Http\Controllers\QuoteContactController;
use App\Modules\VentasDiseno\Http\Controllers\QuoteController;
use App\Modules\VentasDiseno\Http\Controllers\QuoteItemController;
use App\Modules\VentasDiseno\Http\Controllers\QuoteListController;
use App\Modules\VentasDiseno\Http\Controllers\QuotePartController;
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
    Route::get('/', [PipelineController::class, 'index'])->middleware('permission:ventas_diseno.read');
    // Grupo 1d (REQ-019/020/023): creación manual — hasta acá la única forma de que
    // nazca una tarjeta era la regla de Lead automático (REQ-068) al crear un cliente.
    Route::post('/', [PipelineController::class, 'store'])->middleware('permission:ventas_diseno.write');
    Route::get('/{id}', [PipelineController::class, 'show'])->middleware('permission:ventas_diseno.read');
    Route::put('/{id}', [PipelineController::class, 'update'])->middleware('permission:ventas_diseno.edit');
    Route::patch('/{id}/stage', [PipelineController::class, 'changeStage'])->middleware('permission:ventas_diseno.edit');
    Route::post('/{id}/files', [PipelineController::class, 'uploadFile'])->middleware('permission:ventas_diseno.edit');
    // SCRUM-767 — ver/descargar un archivo ya cargado (URL presignada, lectura).
    Route::get('/{cardId}/files/{fileId}/url', [PipelineController::class, 'fileUrl'])->middleware('permission:ventas_diseno.read');

    Route::prefix('/{cardId}/contacts')->group(function (): void {
        Route::post('/', [PipelineCardContactController::class, 'store'])->middleware('permission:ventas_diseno.edit');
        Route::put('/{contactId}', [PipelineCardContactController::class, 'update'])->middleware('permission:ventas_diseno.edit');
        Route::delete('/{contactId}', [PipelineCardContactController::class, 'destroy'])->middleware('permission:ventas_diseno.edit');
    });
});

// SCRUM-690/691/693 (REQ-610/611/613, Batch D) — pantalla "Lista de Proyectos" (Epic CRM). Mismo
// alcance Mías/Equipo y mismo permiso que /pipeline — no es una superficie de datos nueva, es otra
// vista sobre PipelineCard. REQ-612/614 son 100% frontend (query params sobre /pipeline), sin ruta propia.
Route::prefix('projects')->group(function (): void {
    Route::get('/', [ProjectsListController::class, 'index'])->middleware('permission:ventas_diseno.read');
    Route::get('/export', [ProjectsListController::class, 'export'])->middleware('permission:ventas_diseno.read');
});

// REQ-010: buscar/crear Cliente Master y Subcliente desde el flujo de vinculación de Pipeline.
Route::prefix('master-clients')->group(function (): void {
    Route::get('/', [ClientController::class, 'masterClients'])->middleware('permission:ventas_diseno.read');
    Route::post('/', [ClientController::class, 'storeMasterClient'])->middleware('permission:ventas_diseno.write');
});

Route::prefix('sub-clients')->group(function (): void {
    Route::get('/', [ClientController::class, 'subClients'])->middleware('permission:ventas_diseno.read');
    Route::post('/', [ClientController::class, 'storeSubClient'])->middleware('permission:ventas_diseno.write');

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
    Route::get('/{id}', [ClientsController::class, 'show'])->middleware('permission:ventas_diseno.read');
    Route::get('/{id}/projects', [ClientsController::class, 'projects'])->middleware('permission:ventas_diseno.read');
    Route::post('/{id}/sub-clients', [ClientsController::class, 'storeSubClient'])->middleware('permission:ventas_diseno.write');
    Route::patch('/sub-clients/{subClientId}/category', [ClientsController::class, 'updateSubClientCategory'])->middleware('permission:ventas_diseno.edit');
});

// SCRUM-684→689 (REQ-604 a REQ-609, Batch C) — Dashboard CRM, exclusivo de Gerencia
// (REQ-609): siempre alcance de equipo completo, sin selector Mío/Equipo.
Route::prefix('dashboard')->middleware('role:superadmin,management')->group(function (): void {
    Route::get('/summary', [DashboardController::class, 'summary'])->middleware('permission:ventas_diseno.read');
    Route::post('/remind', [DashboardController::class, 'remind'])->middleware('permission:ventas_diseno.read');
});

// Grupo 3 del plan de PM — pantalla Inicio (REQ-056 a REQ-062). REQ-057 (Mi
// desempeño/comisiones) es shell de frontend puro (sin endpoint).
Route::prefix('home')->group(function (): void {
    Route::get('/vendors', [HomeController::class, 'vendors'])->middleware('permission:ventas_diseno.read');
    Route::get('/summary', [HomeController::class, 'summary'])->middleware('permission:ventas_diseno.read');
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
Route::prefix('quotes')->group(function (): void {
    // Grupo 5 (REQ-079 a 085) — listado con tarjetas resumen, búsqueda y filtros.
    Route::get('/', [QuoteListController::class, 'index'])->middleware('permission:ventas_diseno.read');
    Route::post('/', [QuoteController::class, 'store'])->middleware('permission:ventas_diseno.write');
    Route::get('/{id}', [QuoteController::class, 'show'])->middleware('permission:ventas_diseno.read');
    // SCRUM-766 — PDF real (plantilla única interna/externa/descarga, ver QuotePdfService).
    Route::get('/{id}/pdf', [QuoteController::class, 'pdf'])->middleware('permission:ventas_diseno.read');
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

    Route::prefix('/{quoteId}/contacts')->group(function (): void {
        Route::post('/', [QuoteContactController::class, 'store'])->middleware('permission:ventas_diseno.edit');
        Route::delete('/{contactId}', [QuoteContactController::class, 'destroy'])->middleware('permission:ventas_diseno.edit');
    });

    // Cotización-B (REQ-035/038/041/042) — partidas e ítems, todos personalizados
    // por ahora (sin Catálogo real).
    Route::prefix('/{quoteId}/parts')->group(function (): void {
        Route::post('/', [QuotePartController::class, 'store'])->middleware('permission:ventas_diseno.edit');
        Route::put('/{partId}', [QuotePartController::class, 'update'])->middleware('permission:ventas_diseno.edit');
        Route::delete('/{partId}', [QuotePartController::class, 'destroy'])->middleware('permission:ventas_diseno.edit');

        Route::prefix('/{partId}/items')->group(function (): void {
            Route::post('/', [QuoteItemController::class, 'store'])->middleware('permission:ventas_diseno.edit');
            // REQ-037 — alta en lote de todos los productos de una familia.
            Route::post('/bulk', [QuoteItemController::class, 'bulkStore'])->middleware('permission:ventas_diseno.edit');
            Route::put('/{itemId}', [QuoteItemController::class, 'update'])->middleware('permission:ventas_diseno.edit');
            Route::delete('/{itemId}', [QuoteItemController::class, 'destroy'])->middleware('permission:ventas_diseno.edit');
            // SCRUM-734 (RN9.1) — validación de margen en vivo para el modal de precio, sin persistir.
            Route::post('/{itemId}/preview-price', [QuoteItemController::class, 'previewPrice'])->middleware('permission:ventas_diseno.edit');
        });
    });
});

// SCRUM-703→710 (REQ-623→630, Epic CRM Batch F) — "Pedidos". Mismo permiso ventas_diseno.read
// que Pipeline/Proyectos/Clientes — el alcance Mías/Equipo (REQ-629 RN1/RN2) y el bloqueo total
// para Felix/Yaneth (RN3, sin ventas_diseno.read) ya vienen resueltos por ese permiso + el scope
// de OrdersService::buildOrders(), sin gate adicional.
Route::prefix('orders')->group(function (): void {
    Route::get('/', [OrdersController::class, 'index'])->middleware('permission:ventas_diseno.read');
    Route::get('/{id}', [OrdersController::class, 'show'])->whereNumber('id')->middleware('permission:ventas_diseno.read');
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

// SCRUM-138: texto global de Condiciones — reemplaza la edición por-cotización de
// REQ-046 (Daniela pidió cambiarlo una vez y que aplique a toda cotización nueva desde
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
