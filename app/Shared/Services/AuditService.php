<?php

declare(strict_types=1);

namespace App\Shared\Services;

use App\Modules\Auth\Models\AuditLog;
use App\Shared\Auth\JwtActor;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Records security and business-process events to the tenant's audit_logs table.
 *
 * DESIGN CONTRACT: log() NEVER throws — it catches all exceptions and falls back
 * to a warning in the Laravel log. This prevents audit failures from interrupting
 * the primary business flow.
 */
final class AuditService
{
    // ── Auth ──────────────────────────────────────────────────────────────────
    public const AUTH_LOGIN           = 'auth.login';
    public const AUTH_LOGOUT          = 'auth.logout';
    public const AUTH_FAILED          = 'auth.failed';
    public const AUTH_PASSWORD_RESET  = 'auth.password_reset';
    // SCRUM-746, Threat Model Hallazgo 1 — issueRefreshToken() no loggeaba nada; agregado para
    // que SecurityAnalysisJob (Epic 11) tenga visibilidad sobre el patrón de sesiones largas.
    public const AUTH_TOKEN_REFRESHED = 'auth.token_refreshed';
    public const AUTH_REFRESH_TOKEN_REUSE = 'auth.refresh_token_reuse';
    public const AUTH_REGISTER_DUPLICATE  = 'auth.register_duplicate';
    // SCRUM-1158 — «Ver como usuario» (impersonación de solo lectura, ADR-010).
    public const AUTH_IMPERSONATION_STARTED       = 'auth.impersonation.started';
    public const AUTH_IMPERSONATION_ENDED         = 'auth.impersonation.ended';
    public const AUTH_IMPERSONATION_DENIED        = 'auth.impersonation.denied';
    public const AUTH_IMPERSONATION_WRITE_BLOCKED = 'auth.impersonation.write_blocked';
    public const AUTH_IMPERSONATION_REVOKED       = 'auth.impersonation.revoked';
    public const AUTH_THROTTLE_DEGRADED   = 'auth.throttle_degraded';

    // ── CRM — Documents ───────────────────────────────────────────────────────
    public const CRM_DOCUMENT_UPLOADED        = 'crm.document.uploaded';
    public const CRM_DOCUMENT_DOWNLOADED      = 'crm.document.downloaded';
    public const CRM_DOCUMENT_DELETED         = 'crm.document.deleted';
    public const CRM_DOCUMENT_VERSION_CREATED = 'crm.document.version_created';

    // ── CRM — Share links ─────────────────────────────────────────────────────
    public const CRM_SHARE_LINK_CREATED = 'crm.share_link.created';
    public const CRM_SHARE_LINK_REVOKED = 'crm.share_link.revoked';

    // ── CRM — Projects ────────────────────────────────────────────────────────
    public const CRM_PROJECT_CREATED       = 'crm.project.created';
    public const CRM_PROJECT_UPDATED       = 'crm.project.updated';
    public const CRM_PROJECT_STAGE_CHANGED = 'crm.project.stage_changed';
    public const CRM_PROJECT_DELETED       = 'crm.project.deleted';

    // ── CRM — Activities ─────────────────────────────────────────────────────
    public const CRM_ACTIVITY_CREATED = 'crm.activity.created';
    public const CRM_ACTIVITY_DELETED = 'crm.activity.deleted';

    // ── Security ─────────────────────────────────────────────────────────────
    // SCRUM-806 (REQ-631, Epic SCRUM-788) — CheckPermission no logeaba ningún 403, sin rastro de
    // reconocimiento previo a un posible fraude (probar rutas de Bodega/Admin&Cont sin permiso).
    public const SECURITY_ACCESS_DENIED     = 'security.access_denied';
    public const SECURITY_USER_CREATED      = 'security.user.created';
    public const SECURITY_USER_UPDATED      = 'security.user.updated';
    public const SECURITY_USER_DEACTIVATED  = 'security.user.deactivated';
    public const SECURITY_LEVEL_CREATED     = 'security.level.created';
    public const SECURITY_LEVEL_UPDATED     = 'security.level.updated';
    public const SECURITY_LEVEL_DELETED     = 'security.level.deleted';

    // ── Settings ─────────────────────────────────────────────────────────────
    public const SETTINGS_UPDATED = 'settings.updated';

    // ── Security — Epic 11, Seguridad Agéntica ──────────────────────────────
    public const SECURITY_ANOMALY_CONFIRMED = 'security.anomaly.confirmed';
    public const SECURITY_ANOMALY_DISMISSED = 'security.anomaly.dismissed';
    public const SECURITY_USER_BLOCKED      = 'security.user.blocked';
    public const SECURITY_USER_UNBLOCKED    = 'security.user.unblocked';
    public const SECURITY_MFA_RESET         = 'security.mfa.reset';

    // ── AI — SCRUM-54, prompt injection ─────────────────────────────────────
    public const AI_PROMPT_SUSPICIOUS_PATTERN_DETECTED = 'ai.prompt.suspicious_pattern_detected';

    // ── AI — SCRUM-211, validación de confirmación de proveedor ─────────────
    public const AI_PROVIDER_CONFIRMATION_VALIDATED = 'ai.provider_confirmation.validated';

    // ── AI — SCRUM-245, comparación visual de sustitutos (ADR-SCRUM245) ─────
    public const AI_SUBSTITUTE_COMPARISON_COMPLETED = 'ai.substitute_comparison.completed';

    // ── AI — SCRUM-224, detección automática de factura en Ingreso de Mercancía (ADR-SCRUM224) ─
    // Cybersecurity gap #4: se loguea CADA corrida, incluidas las que resultan en
    // matched_order_id: null, para poder detectar un patrón de abuso/prueba-y-error después.
    public const AI_GOODS_RECEIPT_INVOICE_MATCHED = 'ai.goods_receipt_invoice.matched';

    // ── AI — SCRUM-819, extracción de datos bancarios desde la imagen de respaldo (ADR-SCRUM819) ─
    // Misma condición de Cybersecurity que AI_PROVIDER_CONFIRMATION_VALIDATED: se loguea CADA
    // corrida (éxito o falla) porque PromptSanitizer::looksLikeInjection() es texto-only y nunca
    // se ejecuta sobre esta imagen binaria.
    public const AI_PROVIDER_BANK_INFO_EXTRACTED = 'ai.provider_bank_info.extracted';

    // ── AI — SCRUM-814, trazabilidad de uso de IA (Epic SCRUM-788) ──────────
    // Se loguea una vez por Job (primer intento, nunca en reintentos — mismo criterio que
    // AI_PROMPT_SUSPICIOUS_PATTERN_DETECTED) para cualquier Job que extienda BaseAiJob, y también
    // desde SecurityAnalysisJob (que no extiende BaseAiJob, ver su propio docblock). entity_id es
    // el analysisId real para BaseAiJob, o la fecha (Y-m-d) de la ventana analizada para
    // SecurityAnalysisJob (que no tiene AiAnalysis). metadata siempre incluye un preview truncado
    // de contenido confiable/fijo del propio Job — nunca el prompt completo ni el contenido no
    // confiable del tenant, ver docblock de App\Shared\Support\AiUsageAuditor.
    public const AI_USAGE_REQUESTED = 'ai.usage.requested';

    // ── Compras — SCRUM-259, historial de estados de un reclamo ─────────────
    public const COMPRAS_CLAIM_STATUS_CHANGED = 'compras.claim.status_changed';

    // ── Compras — SCRUM-791, Epic SCRUM-788 (Logs y Telemetría) ──────────────
    public const COMPRAS_PURCHASE_ORDER_PDF_DOWNLOADED = 'compras.purchase_order.pdf_downloaded';
    public const COMPRAS_CLAIM_PDF_DOWNLOADED          = 'compras.claim.pdf_downloaded';
    // SCRUM-1127 — versión en inglés de la orden: generación (con IA) y envío por correo con su idioma.
    public const COMPRAS_PURCHASE_ORDER_TRANSLATION_GENERATED = 'compras.purchase_order.translation_generated';
    public const COMPRAS_PURCHASE_ORDER_EMAIL_SENT            = 'compras.purchase_order.email_sent';
    // SCRUM-1184 — proveedor asignado a un producto desde «Pendientes de reposición» (la pivote de proveedores no pasa por LogsAuditTrail).
    public const COMPRAS_REPLENISHMENT_PROVIDER_ASSIGNED      = 'compras.replenishment.provider_assigned';

    // ── CRM — SCRUM-791, Epic SCRUM-788 (Logs y Telemetría) ──────────────────
    public const CRM_REPORT_EXPORTED = 'crm.report.exported';

    // ── Servicios — SCRUM-791, Epic SCRUM-788 (Logs y Telemetría). Vistas/exports de datos
    // sensibles — no cada GET (ver criterio de la epic); TicketController/ClaimSheetController/
    // InspectionReportController/ServiceQuoteController generan documentos formales con datos
    // reales del cliente, ExternalTechnician::show expone la tarifa del subcontratista.
    // InspectionReportController::pdfBlank() queda fuera a propósito: es una plantilla vacía por
    // tipo/subtipo, sin datos de negocio que auditar.
    public const SERVICIOS_TICKET_PDF_DOWNLOADED             = 'servicios.ticket.pdf_downloaded';
    public const SERVICIOS_CLAIM_SHEET_PDF_DOWNLOADED        = 'servicios.claim_sheet.pdf_downloaded';
    public const SERVICIOS_INSPECTION_REPORT_PDF_DOWNLOADED  = 'servicios.inspection_report.pdf_downloaded';
    public const SERVICIOS_SERVICE_QUOTE_PDF_DOWNLOADED      = 'servicios.service_quote.pdf_downloaded';
    public const SERVICIOS_EXTERNAL_TECHNICIAN_VIEWED        = 'servicios.external_technician.viewed';

    // ── Bodega — SCRUM-791, Epic SCRUM-788 (Logs y Telemetría) ───────────────
    public const BODEGA_ORDER_PICKING_SHEET_EXPORTED = 'bodega.order_picking_sheet.exported';
    public const BODEGA_ORDER_ITEM_DETAIL_EXPORTED   = 'bodega.order_item_detail.exported';
    public const BODEGA_ORDER_DOCUMENT_DOWNLOADED    = 'bodega.order_document.downloaded';
    // SCRUM-807 (REQ-632, extensión de la epic por riesgo de fraude económico) — stock actual por
    // bodega de un producto, la señal que alguien mira antes de decidir el tamaño de un ajuste.
    // Único endpoint de lectura sensible en Bodega que calificó para instrumentar (ver docblock
    // de AdjustmentRequestController::productWarehouseStock()) — el resto de los candidatos
    // originales del diseño (Kardex por producto, costo unitario en detalle) no existen como
    // endpoints discretos en el código real: /kardex no tiene filtro por producto (solo texto
    // libre) y BodegaInventoryController::show() documenta explícitamente "sin precio/costo".
    public const BODEGA_ADJUSTMENT_WAREHOUSE_STOCK_VIEWED = 'bodega.adjustment_request.warehouse_stock_viewed';

    // SCRUM-971 (épica SCRUM-969) — corrida real (no --dry-run) de lafayette:sync-productos.
    public const BODEGA_LAFAYETTE_SYNC_COMPLETED = 'bodega.lafayette_sync.completed';
    // SCRUM-990 — resumen de la limpieza única `lafayette:aislar-catalogo` (un solo evento, no uno por fila).
    public const BODEGA_LAFAYETTE_CATALOG_ISOLATED = 'bodega.lafayette_catalog.isolated';
    // SCRUM-1113 — resumen de `legacy:relink-clientes-promovidos` (un solo evento; trae el valor anterior de cada fila tocada, para revertir).
    public const LEGACY_RELINK_CLIENTES_PROMOVIDOS = 'legacy.relink_clientes_promovidos';
    // SCRUM-1137 F3 — resumen de `legacy:fix-clientes-ruc` (aplicar o revertir); el detalle por sub_client va en ventas_diseno.sub_client.updated.
    public const LEGACY_FIX_CLIENTES_RUC = 'legacy.fix_clientes_ruc';
    // SCRUM-1016 — resumen de `legacy:backfill-stock-bodega-central` (un solo evento, no uno por fila).
    public const LEGACY_BACKFILL_STOCK_BODEGA_CENTRAL = 'legacy.backfill_stock_bodega_central';
    public const LEGACY_BACKFILL_COSTO_PROVEEDOR = 'legacy.backfill_costo_proveedor';
    // SCRUM-1136 F5 — resumen de `legacy:crear-descatalogados-inactivos --apply` (un solo evento; trae los ids creados para poder revertir).
    public const LEGACY_CREAR_DESCATALOGADOS_INACTIVOS = 'legacy.crear_descatalogados_inactivos';
    // Resumen de `legacy:vincular-productos-proveedores` (un solo evento por corrida, con id → proveedores aplicados para revertir).
    public const LEGACY_VINCULAR_PRODUCTOS_PROVEEDORES = 'legacy.vincular_productos_proveedores';
    /** SCRUM-1151 — `lafayette:mapear-proveedor --apply`: asocia BK INTERPRISES S.A. a los productos de AtlanticERP que coinciden con Lafayette. */
    public const LAFAYETTE_MAPEAR_PROVEEDOR = 'lafayette.mapear_proveedor';
    // Resumen de `legacy:load-pedidos-gemelos --apply` (un evento por corrida: huella, conteos por hoja, omitidos con su motivo). Lo lee la vista de validación de gemelos.
    public const LEGACY_CARGAR_PEDIDOS_GEMELOS = 'legacy.cargar_pedidos_gemelos';
    // SCRUM-1114 — un evento resumen por corrida de `legacy:sync-incremental-stock` (aplicada o retenida)
    // y uno por `legacy:init-stock-baseline --apply` (no uno por producto: los movimientos de kardex
    // ya dejan el rastro fila por fila).
    public const LEGACY_SYNC_STOCK_DELTA = 'legacy.sync_stock_delta';
    public const LEGACY_INIT_STOCK_BASELINE = 'legacy.init_stock_baseline';
    public const LEGACY_REVERT_STOCK_SYNC = 'legacy.revert_stock_sync';
    // SCRUM-1136 F6 — resumen por almacén de `legacy:cargar-stock-bodegas --apply`, de `legacy:revertir-stock-bodegas --apply` y de `legacy:crear-articulos-sin-catalogo --apply`.
    public const LEGACY_CARGAR_STOCK_BODEGAS = 'legacy.cargar_stock_bodegas';
    public const LEGACY_REVERTIR_STOCK_BODEGAS = 'legacy.revertir_stock_bodegas';
    public const LEGACY_CREAR_ARTICULOS_SIN_CATALOGO = 'legacy.crear_articulos_sin_catalogo';

    // ── Admin&Cont — SCRUM-808, Epic SCRUM-788 (Logs y Telemetría) ───────────
    // Prefijo 'admin_cont.' (no 'admin_contab.') a propósito — consistente con el prefijo que ya
    // usa LogsAuditTrail para este módulo (derivado del namespace App\Modules\AdminCont, ver
    // SCRUM-794), para que el filtro por módulo del visor de Logs no fragmente los eventos de
    // Admin&Cont en dos prefijos distintos. Documentos financieros sensibles (comisión externa a
    // arquitectos) — ver AdminContArchitectCommissionController::showCuentaCobro()/
    // showComprobanteRetencion(). "Detalle de comisión" del diseño original no calificó: no existe
    // un endpoint de detalle de una comisión puntual, solo el listado (summary()).
    public const ADMIN_CONT_CUENTA_COBRO_VIEWED            = 'admin_cont.cuenta_cobro.viewed';
    public const ADMIN_CONT_COMPROBANTE_RETENCION_VIEWED   = 'admin_cont.comprobante_retencion.viewed';
    // SCRUM-1007 — acciones fiscales manuales sobre un documento electrónico (Gerencia/superadmin).
    public const ADMIN_CONT_FE_DOCUMENTO_RECERTIFICADO     = 'admin_cont.fe_documento.recertificado';
    public const ADMIN_CONT_FE_DOCUMENTO_ESTADO_ACTUALIZADO = 'admin_cont.fe_documento.estado_actualizado';
    // SCRUM-1019 — "Reconciliar con MSL" (consulta/reenvío para recuperar el CUFE de un documento que MSL ya tiene).
    public const ADMIN_CONT_FE_DOCUMENTO_RECONCILIADO       = 'admin_cont.fe_documento.reconciliado';
    // SCRUM-1185 — el monto y las líneas de una factura aún sin certificar se alinean al total fiscal tras balancear centavos entre líneas.
    public const ADMIN_CONT_FE_TOTAL_ALINEADO               = 'admin_cont.fe_documento.total_alineado';
    // SCRUM-1140 — factura emitida a Consumidor Final por decisión de quien factura (el cliente conserva su documento).
    public const ADMIN_CONT_INVOICE_RECEPTOR_CONSUMIDOR_FINAL = 'admin_cont.invoice.receptor_consumidor_final';
    // SCRUM-1166 — Administración marca o quita la exención de ITBMS de un cliente fiscal (con el documento que la respalda).
    public const ADMIN_CONT_SUB_CLIENT_ITBMS_EXENTO         = 'admin_cont.sub_client.itbms_exento';
    public const ADMIN_CONT_SUB_CLIENT_ITBMS_EXENTO_QUITADO = 'admin_cont.sub_client.itbms_exento_quitado';
    // SCRUM-1166 — cambió el RUC, DV o tipo ante la DGI de un cliente exento: la exención se conserva pero hay que revisarla.
    public const ADMIN_CONT_SUB_CLIENT_ITBMS_EXENTO_REVISAR = 'admin_cont.sub_client.itbms_exento_revisar';
    // SCRUM-1140 — "Actualizar cliente" desde la vista previa de Crear factura: el cliente adopta la razón social de la DGI.
    public const ADMIN_CONT_SUB_CLIENT_RAZON_SOCIAL_ACTUALIZADA = 'admin_cont.sub_client.razon_social_actualizada';
    // SCRUM-1140 — el receptor congelado de una factura NO certificada se reemplazó antes de reenviarla (A1 de fe-panama).
    public const ADMIN_CONT_FE_RECEPTOR_REFRESCADO = 'admin_cont.fe_documento.receptor_refrescado';

    /**
     * Writes an audit entry. Never throws — any failure is silently logged as a warning.
     *
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     * @param array<string, mixed>|null $metadata
     */
    public function log(
        string $action,
        ?string $entityType = null,
        string|int|null $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null,
        ?int $actingUserId = null,
    ): void {
        try {
            // SCRUM-1158 (C1/C2) — en una sesión «Ver como usuario» el autor es SIEMPRE el actor real, aunque el llamador pase otro
            // `actingUserId` (p. ej. el `sub`, que es el suplantado); el objetivo y la sesión se añaden a la metadata automáticamente.
            $impersonation = $this->impersonationContext();

            AuditLog::create([
                // resolveUserId() lee el JWT de la request — no existe en endpoints públicos
                // (login, forgot-password). $actingUserId permite atribuir el evento igual
                // cuando el caller ya sabe a qué usuario corresponde (ej. login fallido).
                // Con contexto de impersonación el autor es SIEMPRE su `actor_id`, aunque sea null (nunca se cae a `$actingUserId`, que podría
                // ser el suplantado): un registro sin autor es mejor que uno a nombre de la persona equivocada.
                'user_id'     => $impersonation !== null ? $impersonation['actor_id'] : ($actingUserId ?? $this->resolveUserId()),
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId !== null ? (string) $entityId : null,
                'old_values'  => $oldValues,
                'new_values'  => $newValues,
                'ip_address'  => $this->resolveIp(),
                'user_agent'  => $this->resolveUserAgent(),
                'request_id'  => $this->resolveRequestId(),
                'http_method' => $this->resolveHttpMethod(),
                'http_path'   => $this->resolveHttpPath(),
                'http_status' => null, // populated post-response if needed
                'duration_ms' => null,
                'metadata'    => $this->withImpersonationMetadata($metadata ?? [], $impersonation),
            ]);
        } catch (\Throwable $e) {
            Log::warning('audit_log.write_failed', [
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function resolveRequestId(): string
    {
        try {
            $id = Context::get('request_id');

            return is_string($id) && $id !== '' ? $id : (string) Str::uuid();
        } catch (\Throwable) {
            return (string) Str::uuid();
        }
    }

    private function resolveUserId(): ?int
    {
        try {
            /** @var array<string, mixed>|null $payload */
            $payload = request()->attributes->get('jwt');

            // JwtActor::id() devuelve `act.sub` si hay impersonación y `sub` si no (SCRUM-1158, C1).
            return JwtActor::id(is_array($payload) ? $payload : null);
        } catch (\Throwable) {
            // Not in an HTTP context (queue, console, etc.)
        }

        return null;
    }

    /** @return array{actor_id: int|null, impersonation_id: string|null, impersonated_user_id: int|null}|null */
    private function impersonationContext(): ?array
    {
        try {
            $payload = request()->attributes->get('jwt');

            return JwtActor::impersonation(is_array($payload) ? $payload : null);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Condición C2 — `impersonated_user_id` e `impersonation_id` solo las escribe AuditService a partir del claim `act`; si el llamador las
     * pasa en su metadata se descartan SIEMPRE (así nadie puede fabricar ni borrar la huella de una impersonación desde un controlador).
     *
     * @param array<string, mixed> $metadata
     * @param array{actor_id: int|null, impersonation_id: string|null, impersonated_user_id: int|null}|null $impersonation
     * @return array<string, mixed>
     */
    private function withImpersonationMetadata(array $metadata, ?array $impersonation): array
    {
        unset($metadata['impersonated_user_id'], $metadata['impersonation_id']);

        if ($impersonation !== null) {
            $metadata['impersonated_user_id'] = $impersonation['impersonated_user_id'];
            $metadata['impersonation_id']     = $impersonation['impersonation_id'];
        }

        return $metadata;
    }

    private function resolveIp(): ?string
    {
        try {
            return request()->ip();
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveUserAgent(): ?string
    {
        try {
            return request()->userAgent();
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveHttpMethod(): ?string
    {
        try {
            return request()->method();
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveHttpPath(): ?string
    {
        try {
            return request()->path();
        } catch (\Throwable) {
            return null;
        }
    }
}
