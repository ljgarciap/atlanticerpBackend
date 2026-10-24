<?php

declare(strict_types=1);

use App\Modules\Integrations\Outlook\Http\Controllers\OutlookCalendarController;
use App\Modules\Servicios\Http\Controllers\ClaimSheetController;
use App\Modules\Servicios\Http\Controllers\ClientLookupController;
use App\Modules\Servicios\Http\Controllers\ExternalTechnicianController;
use App\Modules\Servicios\Http\Controllers\HomeController;
use App\Modules\Servicios\Http\Controllers\InspectionReportController;
use App\Modules\Servicios\Http\Controllers\InsumoController;
use App\Modules\Servicios\Http\Controllers\InternalTechnicianController;
use App\Modules\Servicios\Http\Controllers\ProductLookupController;
use App\Modules\Servicios\Http\Controllers\ReportsController;
use App\Modules\Servicios\Http\Controllers\ServiceQuoteController;
use App\Modules\Servicios\Http\Controllers\ServiceQuoteItemController;
use App\Modules\Servicios\Http\Controllers\ServiciosSettingsController;
use App\Modules\Servicios\Http\Controllers\TechnicianController;
use App\Modules\Servicios\Http\Controllers\TicketController;
use App\Modules\Servicios\Http\Controllers\ToolController;
use Illuminate\Support\Facades\Route;

// Fase 4 — Servicios, Batch 1 (REQ-216→221, REQ-249). Ver
// docs/architecture/servicios-fase4-diseno.md.
Route::get('/technicians/internal', [TechnicianController::class, 'internal'])
    ->middleware('permission:servicios.read');

// Batch 15 (REQ-207/208/210/211) — pantalla "Inicio". Lectura visible para todo el módulo (mismo
// criterio que el resto de los paneles de Inicio en Bodega/Compras/Ventas & Diseño); el override
// manual de la meta de instalaciones (REQ-211 RN2a-c) es exclusivo de Gerencia/superadmin, mismo
// criterio que "quién puede tocar SLA"/"Ajustes de Servicios".
Route::prefix('home')->group(function (): void {
    Route::get('/summary', [HomeController::class, 'summary'])->middleware('permission:servicios.read');
    Route::put('/installation-goal', [HomeController::class, 'updateInstallationGoal'])
        ->middleware('permission:servicios.settings.configure');
    // Grupo C — Home (REQ-215, SCRUM-278) — distribución de tickets por estado, con filtro
    // opcional `tipo`. Visible para todo el módulo, mismo criterio que /summary.
    Route::get('/estado-tickets', [HomeController::class, 'estadoTickets'])->middleware('permission:servicios.read');
    // SCRUM-908 — tarjeta "Aprobaciones Pendientes".
    Route::get('/approvals-pending', [HomeController::class, 'approvalsPending'])->middleware('permission:servicios.read');
});

// REQ-209 (Mi calendario, SCRUM-272) — mismo endpoint compartido con Ventas & Diseño/Compras/
// Bodega (ver `OutlookCalendarController`), cableado acá con `module=servicios`. Módulo `servicios`
// ya existe en `ModuleCatalog` — no hace falta ningún cambio ahí.
Route::prefix('calendar')->group(function (): void {
    Route::get('/', [OutlookCalendarController::class, 'index'])
        ->defaults('module', 'servicios')
        ->middleware('permission:servicios.read');
});

// Batch 10 (decisión de Luis 2026-08-11) creó esta ruta con lectura amplia y escritura exclusiva
// de Gerencia. SCRUM-780 (Gerencia, 2026-08-19) la reemplaza: los valores de Ajustes son
// parámetros administrativos que ningún perfil operativo debe poder leer, no solo editar — antes
// lider_servicios/tecnico_servicios/vendedor_disenador/garantias_servicios podían leerlos por API
// aunque el menú ya estuviera oculto para ellos en el frontend. Mismo gate que la escritura.
Route::get('/settings', [ServiciosSettingsController::class, 'index'])
    ->middleware('permission:servicios.settings.configure');
Route::patch('/settings', [ServiciosSettingsController::class, 'update'])
    ->middleware('permission:servicios.settings.configure');

// Fase 4 — Servicios, pantalla "Reportes" (SCRUM-350→356, REQ-280→286). 7 endpoints GET de solo
// lectura, sin tablas nuevas. Lectura: mismo permiso que el resto del módulo
// (permission:servicios.read), salvo /comision-tecnico — RN2 (REQ-285) restringe la
// visibilidad al propio Tecnico Servicios y a Gerencia, mismo permiso angosto que ya usa
// /internal-technicians/{id}/commission (servicios.technicians.commission.view_own, Fase 2 de la
// auditoría de permisos — el chequeo fino "es este técnico o es Gerencia" vive en el controller,
// la ruta solo deja pasar a quien PODRÍA calificar).
Route::prefix('reportes')->group(function (): void {
    Route::get('/panorama-mes', [ReportsController::class, 'panoramaMes'])->middleware('permission:servicios.read');
    Route::get('/instalaciones-cotizadas-vs-realizadas', [ReportsController::class, 'instalacionesCotizadasVsRealizadas'])
        ->middleware('permission:servicios.read');
    Route::get('/distribucion-tipo', [ReportsController::class, 'distribucionTipo'])->middleware('permission:servicios.read');
    Route::get('/distribucion-tecnico', [ReportsController::class, 'distribucionTecnico'])->middleware('permission:servicios.read');
    Route::get('/completados-anio', [ReportsController::class, 'completadosAnio'])->middleware('permission:servicios.read');
    Route::get('/comision-tecnico', [ReportsController::class, 'comisionTecnico'])
        ->middleware('permission:servicios.technicians.commission.view_own');
    Route::get('/biblioteca', [ReportsController::class, 'biblioteca'])->middleware('permission:servicios.read');
});

// Batch 12 (REQ-250, SCRUM-313) — historial global de cotizaciones, transversal a todo el equipo
// (no anidado bajo /tickets/{id} — a diferencia de /tickets/{id}/quotes de abajo, que es por
// ticket). Lectura: mismo permiso que el resto del módulo.
Route::get('/quotes', [ServiceQuoteController::class, 'globalIndex'])
    ->middleware('permission:servicios.read');

// REQ-245 RN4 — quién puede crear un ticket: Lider Servicios/Líder de Servicios, superadmin, Gerencia
// (`management`) y Vendedor/Diseñador (`vendedor_disenador`) — este último tiene servicios.read
// pero NO servicios.write (solo lectura general, ver docblock de
// 2026_08_02_100003_grant_servicios_view_to_vendedor_disenador.php, que dejó esta excepción
// pendiente a propósito). Permiso angosto `servicios.tickets.create` (Fase 2 de la auditoría de
// permisos, 2026-08-30) en vez del genérico `servicios.write` porque el permiso de módulo no
// alcanza para expresar "puede crear pero no editar/agendar/cancelar" — mismo mecanismo que el
// resto de las restricciones angostas de este archivo, asignado por nivel de seguridad, no por
// lista de roles.
Route::middleware('permission:servicios.tickets.create')->group(function (): void {
    Route::get('/lookup/master-clients', [ClientLookupController::class, 'masterClients']);
    Route::get('/lookup/master-clients/{masterClientId}/sub-clients', [ClientLookupController::class, 'subClients']);
    Route::get('/lookup/sub-clients/{subClientId}/projects', [ClientLookupController::class, 'projects']);
    Route::get('/lookup/products', [ProductLookupController::class, 'index']);
});

Route::prefix('tickets')->group(function (): void {
    Route::get('/', [TicketController::class, 'index'])->middleware('permission:servicios.read');
    Route::get('/board', [TicketController::class, 'board'])->middleware('permission:servicios.read');
    // REQ-222 RN5 — rutas literales (/board, /stats) SIEMPRE antes de /{id}, o Laravel captura
    // "stats"/"board" como el parámetro {id}. Mismo motivo para /attachments/{attachmentId}/url
    // (3 segmentos, sin colisión real con /{id}, pero se agrupa acá por prolijidad).
    Route::get('/stats', [TicketController::class, 'stats'])->middleware('permission:servicios.read');
    Route::get('/attachments/{attachmentId}/url', [TicketController::class, 'attachmentUrl'])
        ->middleware('permission:servicios.read');
    Route::post('/', [TicketController::class, 'store'])
        ->middleware('permission:servicios.tickets.create');

    // REQ-248 — adjuntar foto/video/archivo de referencia. Mismo criterio de rol que crear el
    // ticket (RN: "Todos los roles que pueden crear tickets tienen acceso a estas secciones").
    Route::post('/{id}/attachments', [TicketController::class, 'storeAttachment'])
        ->middleware('permission:servicios.tickets.create');

    // REQ-224 RN — modal de detalle: "visible para todos los roles del módulo, sin restricciones
    // de lectura" — mismo permiso que el listado.
    Route::get('/{id}', [TicketController::class, 'show'])->middleware('permission:servicios.read');

    // REQ-228 RN2 — disponible para cualquier rol que pueda ver el detalle, mismo permiso.
    Route::get('/{id}/pdf', [TicketController::class, 'pdf'])->middleware('permission:servicios.read');

    // REQ-218 RN7 / REQ-221 RN6 — el cambio de estado (select inline o arrastre en el tablero) es
    // EXCLUSIVO de Lider Servicios/Líder de Servicios: técnico interno y Gerencia ven, no modifican, pese a
    // que ambos tienen `servicios.edit` en general (ver migración
    // 2026_08_02_100004_grant_servicios_edit_to_technician_roles.php). Permiso angosto
    // `servicios.tickets.manage` (Fase 2, 2026-08-30) — restricción más fina que el permiso de
    // módulo, asignada solo al nivel de lider_servicios.
    Route::patch('/{id}/estado', [TicketController::class, 'updateStage'])
        ->middleware('permission:servicios.tickets.manage');

    // REQ-225 RN1 / REQ-226 RN1 — edición global y Agendar/Reagendar son EXCLUSIVOS de
    // Lider Servicios/Líder de Servicios, mismo criterio y mismo permiso angosto que el cambio de estado.
    Route::patch('/{id}', [TicketController::class, 'update'])
        ->middleware('permission:servicios.tickets.manage');
    Route::patch('/{id}/agendar', [TicketController::class, 'schedule'])
        ->middleware('permission:servicios.tickets.manage');

    // SCRUM-781 (punto 1, REQ-225 RN2) — agregar/editar/quitar productos reclamados/afectados
    // desde la edición del ticket. Mismo permiso que la edición global (arriba).
    Route::post('/{id}/products', [TicketController::class, 'storeProduct'])
        ->middleware('permission:servicios.tickets.manage');
    Route::patch('/{id}/products/{productId}', [TicketController::class, 'updateProduct'])
        ->middleware('permission:servicios.tickets.manage');
    Route::delete('/{id}/products/{productId}', [TicketController::class, 'destroyProduct'])
        ->middleware('permission:servicios.tickets.manage');

    // REQ-227 RN1 — cancelar, mismo criterio de permiso que estado/edición/agendar.
    Route::patch('/{id}/cancelar', [TicketController::class, 'cancel'])
        ->middleware('permission:servicios.tickets.manage');

    // Fase 4 — Servicios, Batch 8 (REQ-238→243). Lectura: mismo permiso que el resto del módulo —
    // visible para todos los roles que ven Tickets (Gerencia incluida, "Solo ver" según la Matriz
    // de Permisos por Rol). Escritura: permission:servicios.field_work.edit cubre "quién puede
    // intentar generar/editar" a nivel de nivel de seguridad — el ownership real ("SOLO su propio
    // ticket asignado" para técnico interno, REQ-238
    // RN5) se valida dentro de InspectionReportService::assertCanEdit(), porque una ruta no puede
    // expresar esa condición por ticket puntual.
    Route::prefix('/{id}/inspection-report')->group(function (): void {
        Route::get('/fields', [InspectionReportController::class, 'fields'])->middleware('permission:servicios.read');
        // REQ-222 RN5 — rutas literales antes de "/", mismo motivo que /board y /stats arriba
        // (acá "/pdf" y "/pdf/blank" son ambas literales, sin comodín — el orden entre ellas no
        // afecta el matching, solo importa que ambas vayan antes de "/").
        Route::get('/pdf/blank', [InspectionReportController::class, 'pdfBlank'])
            ->middleware('permission:servicios.claim_sheets.manage');
        Route::get('/pdf', [InspectionReportController::class, 'pdf'])->middleware('permission:servicios.read');
        Route::get('/', [InspectionReportController::class, 'show'])->middleware('permission:servicios.read');

        Route::middleware('permission:servicios.field_work.edit')->group(function (): void {
            Route::put('/', [InspectionReportController::class, 'save']);
            // POST, no PUT — REQ-243 sube un archivo real, ver docblock de InspectionReportService::save().
            Route::post('/upload', [InspectionReportController::class, 'saveArchivoMode']);
            Route::post('/photos', [InspectionReportController::class, 'storePhoto']);
            // Batch 10 (REQ-251→254) — Informe móvil, un solo POST al confirmar la firma.
            Route::post('/mobile', [InspectionReportController::class, 'saveMobile']);
        });
    });

    // Fase 4 — Servicios, Batch 9 (REQ-278/279). Hoja de Reclamo — contraparte de
    // Informe de Inspección para tickets tipo=claim (REQ-220 RN1). Lectura: mismo permiso que el
    // resto del módulo. Escritura: más angosta que Informe de Inspección a propósito — sin
    // garantias_servicios, ver docblock de ClaimSheetService::assertCanEdit().
    Route::prefix('/{id}/claim-sheet')->group(function (): void {
        Route::get('/pdf', [ClaimSheetController::class, 'pdf'])->middleware('permission:servicios.read');
        Route::get('/', [ClaimSheetController::class, 'show'])->middleware('permission:servicios.read');

        Route::put('/', [ClaimSheetController::class, 'save'])
            ->middleware('permission:servicios.claim_sheets.manage');
    });

    // Fase 4 — Servicios, Batch 11 (REQ-229→234). Cotización de Servicio. Lectura: mismo permiso
    // que el resto del módulo (visible para todos los roles de Servicios, ownership real
    // verificado dentro de `ServiceQuoteService::assertCanEdit()` para las rutas de escritura).
    // Escritura general (generar/guardar/enviar/ítems): Lider Servicios/Líder de Servicios + los 2 roles de
    // técnico interno — mismo criterio que Informe de Inspección (permission:servicios.field_work.edit
    // cubre "quién puede intentar", el ownership por ticket puntual se valida dentro del
    // servicio). Decidir
    // (Aprobar/Rechazar): EXCLUSIVO de Lider Servicios/Líder de Servicios (REQ-234 RN), más angosto que el
    // resto — un técnico interno nunca decide su propia cotización.
    Route::prefix('/{id}/quote')->group(function (): void {
        Route::get('/', [ServiceQuoteController::class, 'show'])->middleware('permission:servicios.read');

        // Batch 12 (REQ-235, SCRUM-298) — documento formal de una versión puntual del historial.
        // `{quoteId}/document` tiene 2 segmentos, así que no colisiona con las rutas literales de 1
        // segmento (/enviar, /decidir) sin importar el orden de declaración.
        Route::get('/{quoteId}/document', [ServiceQuoteController::class, 'document'])
            ->middleware('permission:servicios.read');

        Route::middleware('permission:servicios.field_work.edit')->group(function (): void {
            Route::post('/', [ServiceQuoteController::class, 'store']);
            Route::put('/', [ServiceQuoteController::class, 'save']);
            Route::patch('/enviar', [ServiceQuoteController::class, 'send']);

            Route::post('/items', [ServiceQuoteItemController::class, 'store']);
            Route::patch('/items/{itemId}', [ServiceQuoteItemController::class, 'update']);
            Route::delete('/items/{itemId}', [ServiceQuoteItemController::class, 'destroy']);
        });

        Route::patch('/decidir', [ServiceQuoteController::class, 'decide'])
            ->middleware('permission:servicios.tickets.manage');
    });

    // Batch 12 (REQ-236, SCRUM-299) — historial completo del ticket. Ruta hermana de /{id}/quote
    // (singular, cotización vigente) — /{id}/quotes (plural) es la lista de TODAS las versiones.
    Route::get('/{id}/quotes', [ServiceQuoteController::class, 'history'])
        ->middleware('permission:servicios.read');
});

// Fase 4 — Servicios, Batch 5 (REQ-263→267). Técnicos externos.
// Lectura: permiso angosto `servicios.external_technicians.view` (Fase 2, 2026-08-30), no el
// genérico `servicios.read` — RN5 (REQ-263) solo otorga solo-consulta a Lider Servicios/técnicos
// internos/Gerencia — vendedor_disenador tiene servicios.read por otras razones del módulo
// (crear/ver tickets), pero el matriz de permisos del Excel marca N/A para Vendedor/Diseñador en
// las 5 filas de Técnicos Externos (hallazgo Pre-QA 2026-08-09, ver
// docs/pre-qa/scrum333-337-servicios-batch5-tecnicos-externos-20260809.md). Escritura (alta,
// tarifa, activar/inactivar): permiso angosto `servicios.field_ops.admin`, Lider Servicios/Líder de
// Servicios y Gerencia (RN1 repetida en REQ-264/265/266).
Route::prefix('external-technicians')->group(function (): void {
    Route::get('/', [ExternalTechnicianController::class, 'index'])->middleware('permission:servicios.external_technicians.view');
    Route::get('/{id}', [ExternalTechnicianController::class, 'show'])->middleware('permission:servicios.external_technicians.view');

    Route::middleware('permission:servicios.field_ops.admin')->group(function (): void {
        Route::post('/', [ExternalTechnicianController::class, 'store']);
        Route::patch('/{id}/tarifa', [ExternalTechnicianController::class, 'updateTarifa']);
        Route::patch('/{id}/estado', [ExternalTechnicianController::class, 'updateEstado']);
    });
});

// Fase 4 — Servicios, Batch 6 (REQ-255→260). Técnicos internos.
// Lectura: permission:servicios.read — visible para todos los roles del módulo (REQ-255/256/257
// RN de permisos), incluye vendedor_disenador (REQ-260 RN5, solo-vista para Agenda equipo, mismo
// criterio ya usado para Rutas del día en Inicio).
Route::prefix('internal-technicians')->group(function (): void {
    Route::get('/', [InternalTechnicianController::class, 'index'])->middleware('permission:servicios.read');
    // Rutas literales /stats, /agenda, /sla-settings SIEMPRE antes de /{id}, mismo motivo que
    // /board y /stats en tickets arriba — Laravel capturaría el segmento literal como {id} si
    // fuera al revés.
    Route::get('/stats', [InternalTechnicianController::class, 'stats'])->middleware('permission:servicios.read');
    Route::get('/agenda', [InternalTechnicianController::class, 'agenda'])->middleware('permission:servicios.read');

    // REQ-292 RN2 — lectura visible para el módulo, edición exclusiva de Gerencia ("ajustables
    // por Gerencia sin intervención de Tecnología" — Lider Servicios no está en esa lista, a diferencia de
    // la captura mensual en sí).
    Route::get('/sla-settings', [InternalTechnicianController::class, 'slaSettings'])
        ->middleware('permission:servicios.read');
    Route::patch('/sla-settings', [InternalTechnicianController::class, 'updateSlaSettings'])
        ->middleware('permission:servicios.settings.configure');

    Route::get('/{id}/visitas-hoy', [InternalTechnicianController::class, 'visitsToday'])
        ->middleware('permission:servicios.read');

    // REQ-292 RN5 — Lider Servicios y Gerencia capturan/editan.
    Route::middleware('permission:servicios.field_ops.admin')->group(function (): void {
        Route::get('/{id}/commission-capture', [InternalTechnicianController::class, 'commissionCapture']);
        Route::put('/{id}/commission-capture', [InternalTechnicianController::class, 'saveCommissionCapture']);
    });

    // REQ-258 RN8 — tarjeta+detalle de comisión. `permission:` acá solo deja pasar a quien PODRÍA
    // calificar (Gerencia o cualquier técnico interno) — la restricción real ("es este técnico o
    // es Gerencia", nunca Lider Servicios ni otro técnico) vive en el controller, ver su docblock.
    Route::get('/{id}/commission', [InternalTechnicianController::class, 'commission'])
        ->middleware('permission:servicios.technicians.commission.view_own');

    // REQ-259 RN5 — exclusivo de Lider Servicios/Líder de Servicios (superadmin incluido por consistencia
    // con el resto del módulo).
    Route::post('/', [InternalTechnicianController::class, 'store'])
        ->middleware('permission:servicios.tickets.manage');
    // SCRUM-1015 — editar/eliminar: mismo gate que el alta.
    Route::put('/{id}', [InternalTechnicianController::class, 'update'])
        ->middleware('permission:servicios.tickets.manage');
    Route::delete('/{id}', [InternalTechnicianController::class, 'destroy'])
        ->middleware('permission:servicios.tickets.manage');
});

// Fase 4 — Servicios, Batch 13 parte 1 (REQ-268→272, Grupo D "Herramientas"). Lectura: mismo
// permiso que el resto del módulo (visible para todos los roles, RN de REQ-268). Escritura
// (cambio de estado, reasignación, solicitudes de compra/reposición/recepción): exclusiva de
// Lider Servicios/Líder de Servicios (RN5 repetida en las 5 historias) — superadmin incluido por
// consistencia con el resto del módulo.
Route::prefix('tools')->group(function (): void {
    Route::get('/', [ToolController::class, 'index'])->middleware('permission:servicios.read');
    // Ruta literal antes de cualquier futuro /{id} — mismo motivo que /board y /stats en tickets
    // (no hay colisión hoy porque este prefix no tiene ningún GET /{id}, pero se agrupa acá por
    // prolijidad y para no depender de que eso siga siendo cierto).
    Route::get('/purchase-requests', [ToolController::class, 'purchaseRequests'])
        ->middleware('permission:servicios.read');
    // Grupo D parte 2 (REQ-276, SCRUM-346) — Kardex de herramientas. Senior Review 2026-08-13:
    // el ticket restringe esto a "Lider Servicios y Gerencia" explícitamente, NO a todo el módulo (a
    // diferencia de `/` y `/purchase-requests` arriba) — el frontend ya lo gateaba por rol, pero
    // el backend quedaba abierto a `servicios.read` (técnico/garantías/vendedor podían pegarle
    // directo a la API). Mismo string de rol ya usado en el resto del módulo para "Lider Servicios y
    // Gerencia" (ver Técnicos Internos/comisión más abajo).
    Route::get('/movements', [ToolController::class, 'movements'])
        ->middleware('permission:servicios.field_ops.admin');

    Route::middleware('permission:servicios.tickets.manage')->group(function (): void {
        Route::patch('/{id}/estado', [ToolController::class, 'updateEstado']);
        Route::patch('/{id}/asignacion', [ToolController::class, 'updateAsignacion']);
        Route::post('/purchase-requests', [ToolController::class, 'storePurchaseRequest']);
        Route::post('/purchase-requests/{id}/recibir', [ToolController::class, 'receivePurchaseRequest']);
    });
});

// Fase 4 — Servicios, Grupo D parte 2 (REQ-273→275/291, SCRUM-343/344/345/361) — "Reserva
// Servicios" de insumos. Ver docblock de InsumoService: reusa Kardex/PurchaseOrder de
// Bodega/Compras, sin tabla de stock propia. Lectura: mismo permiso que el resto del módulo.
// Escritura (agregar insumo, solicitar compra): exclusiva de Lider Servicios/Líder de Servicios, mismo
// criterio que Herramientas (Grupo D parte 1).
Route::prefix('insumos')->group(function (): void {
    Route::get('/', [InsumoController::class, 'index'])->middleware('permission:servicios.read');

    Route::middleware('permission:servicios.tickets.manage')->group(function (): void {
        Route::post('/', [InsumoController::class, 'store']);
        Route::post('/{id}/solicitar', [InsumoController::class, 'storePurchaseRequest']);
    });
});
