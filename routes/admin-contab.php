<?php

declare(strict_types=1);

use App\Modules\AdminCont\Http\Controllers\AdminContAccountStatementController;
use App\Modules\AdminCont\Http\Controllers\AdminContArchitectCommissionController;
use App\Modules\AdminCont\Http\Controllers\AdminContBankAccountController;
use App\Modules\AdminCont\Http\Controllers\AdminContBankMovementController;
use App\Modules\AdminCont\Http\Controllers\AdminContCarteraCommissionController;
use App\Modules\AdminCont\Http\Controllers\AdminContCashPositionController;
use App\Modules\AdminCont\Http\Controllers\AdminContClientReportsController;
use App\Modules\AdminCont\Http\Controllers\AdminContCompanyProfileController;
use App\Modules\AdminCont\Http\Controllers\AdminContContactController;
use App\Modules\AdminCont\Http\Controllers\AdminContCreditNoteController;
use App\Modules\AdminCont\Http\Controllers\AdminContFiscalSettingsController;
use App\Modules\AdminCont\Http\Controllers\AdminContHomeController;
use App\Modules\AdminCont\Http\Controllers\AdminContInternalCommissionController;
use App\Modules\AdminCont\Http\Controllers\AdminContInvoiceBookController;
use App\Modules\AdminCont\Http\Controllers\AdminContInvoiceController;
use App\Modules\AdminCont\Http\Controllers\AdminContItbmsRateController;
use App\Modules\AdminCont\Http\Controllers\AdminContLocationController;
use App\Modules\AdminCont\Http\Controllers\AdminContPaymentController;
use App\Modules\AdminCont\Http\Controllers\AdminContPaymentMethodSalesController;
use App\Modules\AdminCont\Http\Controllers\AdminContPettyCashController;
use App\Modules\AdminCont\Http\Controllers\AdminContReportsController;
use App\Modules\Integrations\Outlook\Http\Controllers\OutlookCalendarController;
use Illuminate\Support\Facades\Route;

// SCRUM-632→637 (REQ-555→560) — Configuración Fiscal, exclusiva de Mark (ver
// App\Modules\AdminCont\Http\Middleware\EnsureIsMarkApprover, aliased 'mark_only').
Route::middleware('mark_only')->group(function (): void {
    Route::get('/fiscal-settings', [AdminContFiscalSettingsController::class, 'show']);
    Route::put('/fiscal-settings', [AdminContFiscalSettingsController::class, 'update']);

    Route::get('/itbms-rates', [AdminContItbmsRateController::class, 'index']);
    Route::post('/itbms-rates', [AdminContItbmsRateController::class, 'store']);
    Route::put('/itbms-rates/{id}', [AdminContItbmsRateController::class, 'update'])->whereNumber('id');
    Route::delete('/itbms-rates/{id}', [AdminContItbmsRateController::class, 'destroy'])->whereNumber('id');

    // SCRUM-638→642 (REQ-561→565) — Datos de la Empresa, mismo gate exclusivo de Mark.
    Route::get('/company-profile', [AdminContCompanyProfileController::class, 'show']);
    Route::put('/company-profile', [AdminContCompanyProfileController::class, 'update']);
    Route::post('/company-profile/logo', [AdminContCompanyProfileController::class, 'uploadLogo']);

    Route::get('/locations', [AdminContLocationController::class, 'index']);
    Route::post('/locations', [AdminContLocationController::class, 'store']);
    Route::put('/locations/{id}', [AdminContLocationController::class, 'update'])->whereNumber('id');

    Route::get('/contacts', [AdminContContactController::class, 'index']);
    Route::post('/contacts', [AdminContContactController::class, 'store']);
    Route::put('/contacts/{id}', [AdminContContactController::class, 'update'])->whereNumber('id');
});

// SCRUM-607→611 (REQ-530→534) — Cuentas Bancarias, Batch 1 del cuerpo principal de la épica (ver
// docs/architecture/admin-contab-batches.md). A diferencia del grupo `mark_only` de arriba, esta
// pantalla usa el sistema de roles/permisos estándar: visibilidad ya sembrada vía
// `role_module_visibility` (ADR-006, migración 2026_07_11_100001) para gerencia/lider_admin_contab/
// asistente_administrativa, sin seeding adicional. Las rutas de escritura usan `role:` (no
// `permission:admin_contab.edit`) porque el nivel de seguridad default de Yaneth
// (asistente_administrativa → "Solo Ver") no otorga capacidad de editar, pero los tickets la
// incluyen explícitamente junto a Felix para gestionar cuentas — `EnforceRole` es puramente por
// string de rol del JWT, no depende del nivel de seguridad.
Route::get('/bank-accounts', [AdminContBankAccountController::class, 'index'])
    ->middleware('permission:admin_contab.read');

Route::middleware('role:superadmin,lider_admin_contab,asistente_administrativa,management')->group(function (): void {
    Route::post('/bank-accounts', [AdminContBankAccountController::class, 'store']);
    Route::put('/bank-accounts/{id}/deactivate', [AdminContBankAccountController::class, 'deactivate'])->whereNumber('id');
    Route::put('/bank-accounts/{id}/reactivate', [AdminContBankAccountController::class, 'reactivate'])->whereNumber('id');
});

Route::get('/bank-movements', [AdminContBankMovementController::class, 'index'])
    ->middleware('permission:admin_contab.read');

// REQ-534 — más angosto que el resto: "Disponible para Felix y Yaneth" únicamente, sin Mark/Gerencia.
Route::put('/bank-movements/{id}/assign-account', [AdminContBankMovementController::class, 'assignAccount'])
    ->whereNumber('id')
    ->middleware('role:superadmin,lider_admin_contab,asistente_administrativa');

// SCRUM-513→518 (REQ-436→441) — Facturación, Batch 2 del cuerpo principal (ver
// docs/architecture/admin-contab-batches.md). Mismo gate de rol estándar que Cuentas Bancarias —
// no es exclusiva de Mark (a diferencia de Configuración Fiscal/Datos de la Empresa).
Route::middleware('role:superadmin,lider_admin_contab,asistente_administrativa,management')->prefix('invoices')->group(function (): void {
    Route::get('/summary', [AdminContInvoiceController::class, 'summary']);
    Route::get('/pending', [AdminContInvoiceController::class, 'pending']);
    Route::get('/export', [AdminContInvoiceController::class, 'export']); // REQ-446, Batch 3 — antes de '/' para no matchear como {id} en ninguna ruta futura.
    Route::get('/aging', [AdminContInvoiceController::class, 'aging']); // REQ-450, Batch 4 — antes de '/' por el mismo motivo.
    Route::get('/', [AdminContInvoiceController::class, 'index']);
    Route::post('/preview', [AdminContInvoiceController::class, 'preview']);
    Route::post('/', [AdminContInvoiceController::class, 'store']);

    // Batch 4 (SCRUM-524→528, REQ-447/449/448 proponer) — mismo gate de rol estándar de Facturación.
    Route::get('/{order}/detail', [AdminContInvoiceController::class, 'detail'])->whereNumber('order');
    Route::get('/{order}/pdf', [AdminContInvoiceController::class, 'pdf'])->whereNumber('order');
    Route::post('/{order}/mark-uncollectible', [AdminContInvoiceController::class, 'markUncollectible'])->whereNumber('order');
});

// REQ-448 RN4 — aprobar/rechazar la propuesta de incobrable es EXCLUSIVO de Mark, mismo criterio
// que Configuración Fiscal/Datos de la Empresa (no el gate de rol estándar de arriba).
Route::middleware('mark_only')->prefix('invoices')->group(function (): void {
    Route::put('/{order}/uncollectible-decision', [AdminContInvoiceController::class, 'uncollectibleDecision'])->whereNumber('order');
});

// SCRUM-539→544 (REQ-462→467) — Cobros, Batch 5 del cuerpo principal (ver
// docs/architecture/admin-contab-batches.md). REQ-463 (tarjetas resumen) es más angosto que el
// resto del batch: "Visible para Felix, Mark y Gerencia" — sin Yaneth, a diferencia de todo lo
// demás de este módulo que sí la incluye.
Route::middleware('role:superadmin,lider_admin_contab,management')->prefix('payments')->group(function (): void {
    Route::get('/summary', [AdminContPaymentController::class, 'summary']);
});

// SCRUM-545→548 (REQ-468→471), Batch 6 de Cobros — comprobante/recibo+estado/historial/detalle.
// Mismos permisos que el resto de Cobros ("Disponible para Felix, Yaneth, Mark y Gerencia").
// SCRUM-549→552 (REQ-472→475), Batch 7 — ver comprobante/recibo formal/confirmación manual, mismo
// gate de rol (REQ-474, apertura automática, no necesita ruta propia, ver docblock del controller).
Route::middleware('role:superadmin,lider_admin_contab,asistente_administrativa,management')->prefix('payments')->group(function (): void {
    Route::get('/clients', [AdminContPaymentController::class, 'clients']);
    Route::get('/open-invoices', [AdminContPaymentController::class, 'openInvoices']);
    Route::get('/default-bank-account', [AdminContPaymentController::class, 'defaultBankAccount']);
    Route::get('/{id}/attachment', [AdminContPaymentController::class, 'attachment'])->whereNumber('id');
    Route::get('/{id}/pdf', [AdminContPaymentController::class, 'pdf'])->whereNumber('id');
    Route::put('/{id}/confirm', [AdminContPaymentController::class, 'confirm'])->whereNumber('id');
    Route::get('/{id}', [AdminContPaymentController::class, 'show'])->whereNumber('id');
    Route::get('/', [AdminContPaymentController::class, 'index']);
    Route::post('/', [AdminContPaymentController::class, 'store']);
});

// Batch 19 de Admin&Cont (SCRUM-602, REQ-525) — sube o reemplaza la constancia de retención. Más
// angosto que el resto de Cobros arriba: el REQ dice literal "Disponible para Felix y Yaneth", sin
// Mark/Gerencia (mismo criterio que `bank-movements/{id}/assign-account`).
Route::middleware('role:superadmin,lider_admin_contab,asistente_administrativa')->prefix('payments')->group(function (): void {
    Route::post('/{id}/retention-attachment', [AdminContPaymentController::class, 'retentionAttachment'])->whereNumber('id');
});

// SCRUM-529→533 (REQ-452→456), Batch 8 — Estado de Cuenta. PERMISOS del ticket: "Disponible para
// todos los roles con acceso a Admin & Contabilidad" — gate por permiso (como Cuentas Bancarias/
// Bank Movements), no por lista de roles (a diferencia de Cobros/Facturación).
Route::middleware('permission:admin_contab.read')->prefix('account-statement')->group(function (): void {
    Route::get('/clients', [AdminContAccountStatementController::class, 'clients']);
    Route::get('/projects', [AdminContAccountStatementController::class, 'projects']);
    Route::get('/export', [AdminContAccountStatementController::class, 'exportExcel']); // antes de '/' — no matchear como el GET base.
    Route::get('/', [AdminContAccountStatementController::class, 'show']);
});

// SCRUM-553→558 (REQ-476→481), Batch 10 — apertura de Notas Crédito y Devoluciones (ver
// docs/architecture/admin-contab-batches.md). SCRUM-559→564 (REQ-482→487), Batch 11 — agrega
// `clientes/{masterClient}/facturas` (REQ-483) y el POST real (`store`, REQ-482→487). Mismo gate
// de rol estándar que Facturación/Cobros ("Disponible para Felix, Yaneth, Mark y Gerencia" —
// confirmado en la Matriz de Permisos por Rol del cliente; Vendedor/Diseñador sin acceso).
// Batch 12 (SCRUM-565→570, REQ-488→493) agrega correccion/preview, correccion (submit real),
// devoluciones/{id} (precarga de la cola de Bodega), index y show (historial + detalle). Rutas
// literales (`/correccion*`, `/devoluciones/*`) van SIEMPRE antes de `/{creditNote}` para que el
// router no intente bindear "correccion"/"devoluciones" como el parámetro numérico.
Route::middleware('role:superadmin,lider_admin_contab,asistente_administrativa,management')->prefix('notas-credito')->group(function (): void {
    Route::get('/summary', [AdminContCreditNoteController::class, 'summary']);
    Route::get('/itbms-rates', [AdminContCreditNoteController::class, 'itbmsRates']);
    Route::get('/facturas/{order}/itbms-treatment', [AdminContCreditNoteController::class, 'itbmsTreatment'])->whereNumber('order');
    Route::get('/clientes/{masterClient}/facturas', [AdminContCreditNoteController::class, 'facturas'])->whereNumber('masterClient');
    Route::get('/devoluciones/{customerReturn}', [AdminContCreditNoteController::class, 'devolucionDetail'])->whereNumber('customerReturn');
    Route::post('/correccion/preview', [AdminContCreditNoteController::class, 'previewCorreccion']);
    Route::post('/correccion', [AdminContCreditNoteController::class, 'storeCorreccion']);
    Route::post('/', [AdminContCreditNoteController::class, 'store']);
    // Batch 13 (SCRUM-571→574, REQ-495/496) — mismo gate de rol estándar, "Disponible para Felix,
    // Yaneth, Mark y Gerencia" (a diferencia de /decision, abajo, que es exclusiva de Mark).
    Route::get('/{creditNote}/comprobante', [AdminContCreditNoteController::class, 'comprobante'])->whereNumber('creditNote');
    Route::get('/{creditNote}/pdf', [AdminContCreditNoteController::class, 'pdf'])->whereNumber('creditNote');
    Route::get('/{creditNote}', [AdminContCreditNoteController::class, 'show'])->whereNumber('creditNote');
    Route::get('/', [AdminContCreditNoteController::class, 'index']);
});

// Batch 13 (SCRUM-571, REQ-494 RN1) — aprobar/rechazar una nota pendiente es EXCLUSIVO de Mark,
// mismo criterio que `invoices/{order}/uncollectible-decision`.
Route::middleware('mark_only')->prefix('notas-credito')->group(function (): void {
    Route::put('/{creditNote}/decision', [AdminContCreditNoteController::class, 'decision'])->whereNumber('creditNote');
});

// SCRUM-575→579 (REQ-498→502) — Comisiones Internas, Batch 14 (ver
// docs/adr/ADR-SCRUM575-579-batch14-comisiones-internas.md). CORREGIDO en Senior Review: la
// primera versión gateaba /tiers y / con `permission:admin_contab.read` solo — eso exige que
// vendedor_disenador tenga `admin_contab.read`, que a su vez desbloquea TODO Admin&Cont (mismo
// bug que SCRUM-771 encontró en Compras). Acá el OR es con el permiso angosto
// `admin_contab.commissions_internal.view` (ver migración
// `add_admin_contab_commissions_internal_view_permission` + `SpecialPermissionSeeder`), nunca con
// `admin_contab.read` puro — el scoping fino view/view_team (qué ve cada quien DENTRO de la
// respuesta) vive en el controller (`canViewTeamV2('admin_contab')`), no en la ruta.
Route::middleware('permission:admin_contab.read,admin_contab.commissions_internal.view')
    ->prefix('commissions/internal')->group(function (): void {
        Route::get('/tiers', [AdminContInternalCommissionController::class, 'tiers']);
        // REQ-507 (Batch 15) — estado de cuenta, autorización propio-vs-ajeno verificada
        // server-side dentro del controller (un vendedor solo puede pedir el suyo), no acá.
        // `/{vendedorId}/account-statement/pdf` (no una ruta estática `/account-statement/export`
        // con `vendedor_id` en query) — coincide con lo que el frontend ya llama
        // (`adminContabApi.ts::downloadAccountStatementPdf`), gap de contrato corregido en Senior
        // Review, mismo patrón que Batch 14.
        Route::get('/{vendedorId}/account-statement', [AdminContInternalCommissionController::class, 'accountStatement'])->whereNumber('vendedorId');
        Route::get('/{vendedorId}/account-statement/pdf', [AdminContInternalCommissionController::class, 'accountStatementExport'])->whereNumber('vendedorId');
        Route::get('/', [AdminContInternalCommissionController::class, 'summary']);
    });

// Exportar reporte + opciones del selector de vendedor — exclusivo Felix/Mark/Gerencia (REQ-498
// RN, "cada vendedor... no exportar el reporte completo"; el selector de vendedor tampoco aplica
// a un vendedor, que ya ve solo su propia fila auto-filtrada). Deliberadamente FUERA del OR de
// arriba, defensa en profundidad — el controller también aborta 403 en export() vía
// canViewTeamV2() si alguien llega igual.
Route::middleware('permission:admin_contab.read')->prefix('commissions/internal')->group(function (): void {
    Route::get('/export', [AdminContInternalCommissionController::class, 'export']);
    Route::get('/vendors', [AdminContInternalCommissionController::class, 'vendorOptions']);
});

// CRUD de tramos — exclusivo de Mark, mismo gate que Configuración Fiscal/Datos de la Empresa.
Route::middleware('mark_only')->prefix('commissions/internal')->group(function (): void {
    Route::post('/tiers', [AdminContInternalCommissionController::class, 'storeTier']);
    Route::put('/tiers/{id}', [AdminContInternalCommissionController::class, 'updateTier'])->whereNumber('id');
    Route::delete('/tiers/{id}', [AdminContInternalCommissionController::class, 'destroyTier'])->whereNumber('id');
});

// SCRUM-585→590 (REQ-508→513) — Comisiones Externas (arquitectos), Batch 16 (ver
// docs/adr/ADR-SCRUM585-590-batch16-comisiones-externas.md). Audiencia de lectura Felix/Yaneth/
// Mark/Gerencia únicamente (a diferencia de Comisiones Internas, ningún vendedor/arquitecto la
// consulta) — `permission:admin_contab.read` alcanza, sin permiso angosto adicional (mismo
// criterio que `bank-accounts` GET, role_module_visibility ya cubre a los 4 roles sin seeding).
Route::middleware('permission:admin_contab.read')->prefix('commissions/external')->group(function (): void {
    Route::get('/', [AdminContArchitectCommissionController::class, 'summary']);
    Route::get('/architects', [AdminContArchitectCommissionController::class, 'architectOptions']);
    Route::get('/bank-accounts', [AdminContArchitectCommissionController::class, 'bankAccountOptions']);
    Route::get('/projects/{pipelineCardId}/cuenta-cobro', [AdminContArchitectCommissionController::class, 'showCuentaCobro'])->whereNumber('pipelineCardId');
    Route::get('/projects/{pipelineCardId}/comprobante-retencion', [AdminContArchitectCommissionController::class, 'showComprobanteRetencion'])->whereNumber('pipelineCardId');
});

// Datos fiscales (REQ-510) — Felix/Yaneth "y Mark/Gerencia si lo necesitan" (texto explícito del
// REQ), mismo roster que el grupo de escritura de `bank-accounts` de arriba.
Route::middleware('role:superadmin,lider_admin_contab,asistente_administrativa,management')
    ->prefix('commissions/external')->group(function (): void {
        Route::put('/architects/{architectId}/fiscal-profile', [AdminContArchitectCommissionController::class, 'updateFiscalProfile'])->whereNumber('architectId');
    });

// Cuenta de cobro (REQ-513), comprobante de retención (REQ-514) y cuenta de pago (REQ-517) — el
// REQ dice literal "Disponible para Felix y Yaneth" SIN el carve-out de Mark/Gerencia — grupo
// separado, más angosto a propósito (a diferencia de REQ-510/515/516 abajo).
Route::middleware('role:superadmin,lider_admin_contab,asistente_administrativa')
    ->prefix('commissions/external')->group(function (): void {
        Route::post('/projects/{pipelineCardId}/cuenta-cobro', [AdminContArchitectCommissionController::class, 'uploadCuentaCobro'])->whereNumber('pipelineCardId');
        Route::post('/projects/{pipelineCardId}/comprobante-retencion', [AdminContArchitectCommissionController::class, 'uploadComprobanteRetencion'])->whereNumber('pipelineCardId');
        Route::put('/projects/{pipelineCardId}/cuenta-pago', [AdminContArchitectCommissionController::class, 'updateCuentaPago'])->whereNumber('pipelineCardId');
    });

// Marcar pagado (REQ-515) y proponer % (REQ-516 RN3) — mismo roster amplio que REQ-510
// (Felix/Yaneth/Mark/Gerencia, el REQ lo dice explícito para ambos).
Route::middleware('role:superadmin,lider_admin_contab,asistente_administrativa,management')
    ->prefix('commissions/external')->group(function (): void {
        Route::post('/projects/{pipelineCardId}/mark-paid', [AdminContArchitectCommissionController::class, 'markPaid'])->whereNumber('pipelineCardId');
        Route::post('/projects/{pipelineCardId}/percent/propose', [AdminContArchitectCommissionController::class, 'proposePercent'])->whereNumber('pipelineCardId');
        Route::post('/projects/{pipelineCardId}/remind', [AdminContArchitectCommissionController::class, 'sendReminder'])->whereNumber('pipelineCardId');
    });

// Decidir % propuesto (REQ-516 RN4) — exclusivo de Mark (aprobación real de un ajuste de
// comisión), mismo middleware que el grupo `mark_only` de Configuración Fiscal arriba.
Route::middleware('mark_only')->prefix('commissions/external')->group(function (): void {
    Route::post('/projects/{pipelineCardId}/percent/decide', [AdminContArchitectCommissionController::class, 'decidePercent'])->whereNumber('pipelineCardId');
});

// SCRUM-597→601 (REQ-520→524), Batch 18 — Arqueo / Flujo de Caja, parte 1 (ver
// docs/adr/ADR-SCRUM597-601-batch18-arqueo-caja.md, gates exactos en la tabla "Contrato de API").
// REQ-520/522 (encabezado, toggle) — Felix/Yaneth/Mark/Gerencia, mismo grupo amplio que
// bank-accounts/facturación/cobros/notas-credito.
Route::middleware('role:superadmin,lider_admin_contab,asistente_administrativa,management')
    ->prefix('cash-position')->group(function (): void {
        Route::get('/', [AdminContCashPositionController::class, 'header']);
    });

// /export SIN Yaneth — el PDF/Excel siempre lleva el detalle línea por línea (mismo dato crudo de
// /projected o /real), y su propia UI nunca le ofrece el botón (RN3: oculto en Real+Hoy, el único
// estado al que ella llega). Nada que la stripping de `projected()` resuelva acá — el archivo
// exportado no pasa por esa limpieza, así que la ruta se le niega directamente.
Route::middleware('role:superadmin,lider_admin_contab,management')
    ->prefix('cash-position')->group(function (): void {
        Route::get('/export', [AdminContCashPositionController::class, 'export']);
    });

// REQ-520/521 aclarado por Luis 2026-08-25 (Pre-QA Batch 18) — el permiso de REQ-521 ("Visible
// para Felix, Mark y Gerencia") es sobre el PANEL de detalle (facturas/comisiones línea por
// línea), no sobre los 3 totales agregados que REQ-520 sí le da a Yaneth en el encabezado. Yaneth
// puede pedir /projected (para poblar sus tarjetas), pero el controller le devuelve
// entradas/salidas vacíos — ver AdminContCashPositionController::projected().
Route::middleware('role:superadmin,lider_admin_contab,asistente_administrativa,management')
    ->prefix('cash-position')->group(function (): void {
        Route::get('/projected', [AdminContCashPositionController::class, 'projected']);
    });

// REQ-523 (Vista real 30-90d) — SIN Yaneth, sin excepción: a diferencia de /projected, acá no hay
// una versión "solo totales" que el REQ le dé — el REQ-523 nunca la menciona.
Route::middleware('role:superadmin,lider_admin_contab,management')
    ->prefix('cash-position')->group(function (): void {
        Route::get('/real', [AdminContCashPositionController::class, 'real']);
    });

// REQ-524 (Arqueo del día) — "Disponible para Felix y Yaneth" únicamente, sin Mark/Gerencia (mismo
// roster angosto ya usado para `commissions/external/projects/{id}/cuenta-cobro` etc. en Batch 17).
// Batch 19 (SCRUM-603, REQ-526/529) agrega `close`/`export` — mismo gate angosto (ver ADR-SCRUM602-606,
// reconciliación punto 9: Mark/Gerencia nunca llegan a esta pantalla, así que tampoco a su export).
Route::middleware('role:superadmin,lider_admin_contab,asistente_administrativa')
    ->prefix('cash-position/daily-count')->group(function (): void {
        Route::get('/', [AdminContCashPositionController::class, 'dailyCount']);
        Route::put('/entries', [AdminContCashPositionController::class, 'updateDailyCashCountEntry']);
        Route::put('/general-observation', [AdminContCashPositionController::class, 'updateDailyCashCountGeneralObservation']);
        Route::post('/close', [AdminContCashPositionController::class, 'closeDailyCashCount']);
        Route::get('/export', [AdminContCashPositionController::class, 'dailyCountExport']);
    });

// Batch 19 (SCRUM-605/606, REQ-528/529) — historial de arqueos cerrados. Gate amplio, mismo roster
// que bank-accounts/facturación/cobros/notas-credito (Felix, Yaneth, Mark, Gerencia) — acá SÍ
// llegan Mark/Gerencia de forma natural, a diferencia del arqueo activo de arriba.
Route::middleware('role:superadmin,lider_admin_contab,asistente_administrativa,management')
    ->prefix('cash-position/history')->group(function (): void {
        Route::get('/', [AdminContCashPositionController::class, 'history']);
        Route::get('/{id}', [AdminContCashPositionController::class, 'historyDetail'])->whereNumber('id');
        Route::get('/{id}/export', [AdminContCashPositionController::class, 'historyExport'])->whereNumber('id');
    });

// Batch 19 (SCRUM-605, REQ-528 RN2/RN4) — aprobar como Gerencia es EXCLUSIVO de Mark, mismo
// criterio que `notas-credito/{id}/decision`/`invoices/{order}/uncollectible-decision`.
Route::middleware('mark_only')->prefix('cash-position/history')->group(function (): void {
    Route::post('/{id}/approve', [AdminContCashPositionController::class, 'approveDailyCashCount'])->whereNumber('id');
});

// Batch 20 (SCRUM-612→617, REQ-535→540) — Caja Chica. Lectura + aprobar visibles para Felix,
// Yaneth, Mark y Gerencia (mismo roster amplio que bank-accounts/facturación/cobros/notas-credito
// arriba); "aprobar" en sí es exclusivo de Mark vía `mark_only`, no por este `role:` (que solo
// controla quién puede ver el botón/pedir el detalle — mismo criterio que
// `cash-position/history/{id}` vs `cash-position/history/{id}/approve`).
Route::middleware('role:superadmin,lider_admin_contab,asistente_administrativa,management')
    ->prefix('petty-cash')->group(function (): void {
        Route::get('/summary', [AdminContPettyCashController::class, 'summary']);
        Route::get('/pending', [AdminContPettyCashController::class, 'pending']);
        Route::get('/rejected', [AdminContPettyCashController::class, 'rejected']);
        Route::get('/reports', [AdminContPettyCashController::class, 'reports']);
        Route::get('/reports/{numero}', [AdminContPettyCashController::class, 'reportDetail']);
        Route::get('/reports/{numero}/pdf', [AdminContPettyCashController::class, 'reportPdf']);
        Route::get('/expenses/{expenseId}/attachments/{attachmentId}/url', [AdminContPettyCashController::class, 'attachmentUrl'])
            ->whereNumber(['expenseId', 'attachmentId']);
        // Batch 21 (REQ-545) — detalle unificado de línea, visible al mismo roster amplio.
        Route::get('/expenses/{id}', [AdminContPettyCashController::class, 'expenseDetail'])->whereNumber('id');
    });

// REQ-535/538 — "Disponible para Felix y Yaneth", SIN Mark/Gerencia (mismo roster angosto ya usado
// para bank-movements/assign-account y commissions/external/.../cuenta-cobro).
Route::middleware('role:superadmin,lider_admin_contab,asistente_administrativa')
    ->prefix('petty-cash')->group(function (): void {
        Route::post('/expenses', [AdminContPettyCashController::class, 'storeExpenses']);
        Route::post('/reports', [AdminContPettyCashController::class, 'storeReport']);
        // Batch 21 (REQ-545) — editar línea + soporte adicional, mismo roster que registrar gastos.
        Route::put('/expenses/{id}', [AdminContPettyCashController::class, 'updateExpense'])->whereNumber('id');
        Route::post('/expenses/{id}/attachments', [AdminContPettyCashController::class, 'addExpenseAttachment'])->whereNumber('id');
        // Batch 21 (REQ-544) — reabrir NO es de Mark, es de Felix/Yaneth (distinto de rechazar).
        Route::put('/expenses/{id}/reopen', [AdminContPettyCashController::class, 'reopenExpense'])->whereNumber('id');
    });

// REQ-540 — aprobar reporte completo, exclusivo de Mark.
Route::middleware('mark_only')->prefix('petty-cash')->group(function (): void {
    Route::put('/reports/{numero}/approve', [AdminContPettyCashController::class, 'approveReport']);
    // Batch 21 (REQ-541) — rechazar línea puntual o reporte completo, exclusivo de Mark.
    Route::put('/expenses/{id}/reject', [AdminContPettyCashController::class, 'rejectExpense'])->whereNumber('id');
    Route::put('/reports/{numero}/reject', [AdminContPettyCashController::class, 'rejectReport']);
});

// Batch 22 (SCRUM-643→647, REQ-566→570) — home de Reportes. Roster amplio Felix/Yaneth/Mark/
// Gerencia (Excel de requerimientos, hoja "Matriz de Permisos por Rol", fila "Ver pantalla
// Reportes" — CONFIRMADO con el cliente que Yaneth ve el dashboard completo, solo lectura), mismo
// grupo ya usado en `cash-position`/`petty-cash/summary`. Ninguno de estos 4 endpoints acepta
// `window`/período propio distinto del selector — Felix-commission y cartera son fijos (RN4
// REQ-567/RN3 REQ-568), ventas/flujo-caja sí toman `periodo` (RN3 REQ-566: se recalculan juntas).
Route::middleware('role:superadmin,lider_admin_contab,asistente_administrativa,management')
    ->prefix('reports')->group(function (): void {
        Route::get('/felix-commission', [AdminContReportsController::class, 'felixCommission']);
        Route::get('/cartera', [AdminContReportsController::class, 'cartera']);
        Route::get('/ventas', [AdminContReportsController::class, 'ventas']);
        Route::get('/flujo-caja', [AdminContReportsController::class, 'flujoCaja']);
        // Batch 23 (SCRUM-648/649, REQ-571/572) — comisiones sí toma `periodo` (RN1 REQ-571, se
        // recalcula junto con ventas/flujo-caja); notas-crédito nunca lo toma (RN2 REQ-572, mismo
        // criterio fijo-al-mes-actual que Comisión Felix/Cartera).
        Route::get('/comisiones', [AdminContReportsController::class, 'comisiones']);
        Route::get('/notas-credito', [AdminContReportsController::class, 'notasCredito']);
    });

// Batch 23 Grupo 2 (SCRUM-651→660, REQ-574→583) — "Reporte mensual por cliente" (4M1, agrupación
// por día) y "Mensual por cliente — acumulado" (4M2, agrupación por año-mes). Mismo roster amplio
// que el resto de Reportes (permisos "Felix, Yaneth, Mark y Gerencia" del ticket).
Route::middleware('role:superadmin,lider_admin_contab,asistente_administrativa,management')
    ->prefix('reports')->group(function (): void {
        Route::get('/mensual-cliente', [AdminContClientReportsController::class, 'mensual']);
        Route::get('/mensual-cliente/excel', [AdminContClientReportsController::class, 'mensualExcel']);
        Route::get('/mensual-cliente-acumulado', [AdminContClientReportsController::class, 'acumulado']);
        Route::get('/mensual-cliente-acumulado/excel', [AdminContClientReportsController::class, 'acumuladoExcel']);
    });

// Batch 23 Grupo 3 (SCRUM-661→669, REQ-584→592) — "Libro de Facturas" (4M3) y "Ventas por medio
// de pago" (4M4). Mismo roster amplio que el resto de Reportes.
Route::middleware('role:superadmin,lider_admin_contab,asistente_administrativa,management')
    ->prefix('reports')->group(function (): void {
        Route::get('/libro-facturas', [AdminContInvoiceBookController::class, 'index']);
        Route::get('/libro-facturas/excel', [AdminContInvoiceBookController::class, 'excel']);
        Route::get('/ventas-medio-pago', [AdminContPaymentMethodSalesController::class, 'index']);
        Route::get('/ventas-medio-pago/excel', [AdminContPaymentMethodSalesController::class, 'excel']);
    });

// Batch final de Admin&Cont — "Inicio" (SCRUM-503→512, REQ-426→435), Grupo 1 (SCRUM-504→508,
// REQ-427→431) — panel "Resumen del mes". Mismo roster amplio que el resto de Reportes/Inicio.
Route::middleware('role:superadmin,lider_admin_contab,asistente_administrativa,management')
    ->prefix('home')->group(function (): void {
        Route::get('/resumen-mes', [AdminContHomeController::class, 'resumenMes']);
        Route::get('/pendientes', [AdminContHomeController::class, 'pendientes']);
        Route::get('/vencidos-por-vencer', [AdminContHomeController::class, 'vencidosPorVencer']);
    });

// Grupo 2 (SCRUM-509, REQ-432) — "Mi calendario". Mismo endpoint compartido con Ventas & Diseño/
// Compras/Bodega/Servicios (OutlookCalendarController, module parametrizado). `admin_contab` ya
// existe en ModuleCatalog — no hace falta ningún cambio ahí. Deliberadamente sin toggle
// Mío/Equipo: `admin_contab` queda AFUERA de OutlookCalendarController::MODULES_WITH_TEAM_CALENDAR
// (mismo criterio que `servicios`, ver docblock de esa constante — SCRUM-272, IDOR real).
Route::middleware('role:superadmin,lider_admin_contab,asistente_administrativa,management')
    ->prefix('calendar')->group(function (): void {
        Route::get('/', [OutlookCalendarController::class, 'index'])
            ->defaults('module', 'admin_contab');
    });

// Tramos de la comisión de Felix (REQ-567) — lectura con el mismo roster amplio de arriba,
// escritura exclusiva de Mark (mismo criterio que `commissions/internal/tiers`, Batch 14).
Route::middleware('role:superadmin,lider_admin_contab,asistente_administrativa,management')
    ->prefix('commissions/cartera')->group(function (): void {
        Route::get('/tiers', [AdminContCarteraCommissionController::class, 'tiers']);
    });

Route::middleware('mark_only')->prefix('commissions/cartera')->group(function (): void {
    Route::post('/tiers', [AdminContCarteraCommissionController::class, 'storeTier']);
    Route::put('/tiers/{id}', [AdminContCarteraCommissionController::class, 'updateTier'])->whereNumber('id');
    Route::delete('/tiers/{id}', [AdminContCarteraCommissionController::class, 'destroyTier'])->whereNumber('id');
});
