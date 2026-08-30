<?php

declare(strict_types=1);

namespace App\Shared\Services;

use App\Modules\Auth\Models\AuditLog;
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

    // ── Compras — SCRUM-259, historial de estados de un reclamo ─────────────
    public const COMPRAS_CLAIM_STATUS_CHANGED = 'compras.claim.status_changed';

    // ── Compras — SCRUM-791, Epic SCRUM-788 (Logs y Telemetría) ──────────────
    public const COMPRAS_PURCHASE_ORDER_PDF_DOWNLOADED = 'compras.purchase_order.pdf_downloaded';
    public const COMPRAS_CLAIM_PDF_DOWNLOADED          = 'compras.claim.pdf_downloaded';

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
            AuditLog::create([
                // resolveUserId() lee el JWT de la request — no existe en endpoints públicos
                // (login, forgot-password). $actingUserId permite atribuir el evento igual
                // cuando el caller ya sabe a qué usuario corresponde (ej. login fallido).
                'user_id'     => $actingUserId ?? $this->resolveUserId(),
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
                'metadata'    => $metadata ?? [],
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
            /** @var array{sub?: int}|null $payload */
            $payload = request()->attributes->get('jwt');

            if (is_array($payload) && isset($payload['sub'])) {
                return (int) $payload['sub'];
            }
        } catch (\Throwable) {
            // Not in an HTTP context (queue, console, etc.)
        }

        return null;
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
