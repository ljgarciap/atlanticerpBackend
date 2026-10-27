<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContCompanyProfile;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Support\PdfFooterText;
use App\Shared\Support\PdfLogo;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * SCRUM-857 (REQ-456) — reemplaza el enfoque anterior (`window.print()` de la pantalla en vivo,
 * ver docblock viejo de `AccountStatementExportService`) por un documento real, igual que
 * Cotizaciones (`VentasDiseno\QuotePdfService`) y Facturas (`InvoicePdfService`, mismo módulo):
 * header solo con la marca Atlantic (nunca "AtlanticERP"), sin recuadro envolvente, datos previos
 * a la tabla en columnas alineadas a la derecha, tabla centrada con márgenes simétricos, y pie de
 * página real vía `page_text()` (único mecanismo de dompdf con paginación real — ver
 * `isPhpEnabled` abajo, mismo patrón que `quote-pdf.blade.php`).
 */
final class AccountStatementPdfService
{
    /** Mismo catálogo que `AccountStatementExportService` (Excel) — duplicado a propósito, mismo
     *  criterio ya establecido ahí (sin acceso a i18n del frontend desde acá). */
    /** @return array<string, string> Fase 6d: el nombre del impuesto sale del país de la empresa. */
    private static function regimenLabels(): array
    {
        return [
            'con_itbms' => 'Con '.\App\Shared\Multitenancy\TenantRegion::taxLabel(),
            'sin_itbms' => 'Sin '.\App\Shared\Multitenancy\TenantRegion::taxLabel(),
        ];
    }

    private const TARIFA_LABELS = [
        'public'  => 'Precio Público',
        'project' => 'Precio Proyecto',
        'special' => 'Precio Especial',
        'partner' => 'Precio Socio',
        'premium' => 'Tarifa Premium',
    ];

    private const TIPO_CUENTA_LABELS = [
        'corriente'       => 'Cuenta corriente',
        'ahorro'          => 'Cuenta de ahorro',
        'tarjeta_credito' => 'Tarjeta de crédito',
    ];

    /** @param array<string, mixed> $detail Salida de `AccountStatementService::detail()`. */
    public function generate(array $detail): PdfDocument
    {
        return Pdf::setOption('isPhpEnabled', true)
            ->loadView('admin-cont.account-statement-pdf', $this->viewData($detail))
            ->setPaper('letter');
    }

    /**
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    public function viewData(array $detail): array
    {
        $fiscal  = AdminContFiscalSettings::current();
        $company = AdminContCompanyProfile::current();

        // Pie de página (ver page_text() en la vista) — SCRUM-858 extrajo esto a un helper
        // compartido con PaymentReceiptPdfService, ver docblock de PdfFooterText.
        $footerCompanyText = PdfFooterText::forFiscal($fiscal);

        return [
            'detail'            => $detail,
            'fiscal'            => $fiscal,
            'moneda'            => $company->moneda,
            'logoDataUri'       => PdfLogo::dataUri(),
            'generatedAt'       => now(),
            'tarifaLabel'       => self::TARIFA_LABELS[$detail['tarifa'] ?? ''] ?? null,
            'regimenLabel'      => self::regimenLabels()[$detail['regimen_fiscal']] ?? (string) $detail['regimen_fiscal'],
            'tipoCuentaLabel'   => self::TIPO_CUENTA_LABELS[$detail['tipo_cuenta'] ?? ''] ?? null,
            'footerCompanyText' => $footerCompanyText,
        ];
    }
}
