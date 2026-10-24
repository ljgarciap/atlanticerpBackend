<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContCompanyProfile;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContFeTransmision;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Support\InvoicePdfVariant;
use App\Modules\AdminCont\Support\InvoicePdfVariantResult;
use App\Modules\AdminCont\Support\PdfFooterText;
use App\Shared\Support\PdfLogo;
use App\Shared\Support\QrPng;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Support\Facades\Log;

/**
 * Batch 4 de Facturación (SCRUM-524→528, REQ-449) — primer documento fiscal real e imprimible de
 * Admin&Cont (hasta acá, la "vista previa" de Batch 3 solo vivía en pantalla). RN1: datos de
 * empresa (`AdminContFiscalSettings`) + cliente + tabla de ítems + subtotal/ITBMS/total + bloque de
 * pago. RN2: si se aplicó saldo a favor, "Total factura" Y "Total a pagar" visibles. RN3: marca de
 * agua "ANULADA" cuando `es_anulada` — implementada con `position: fixed` cubriendo la página
 * completa en una sola pieza (no depende de la altura de ningún contenido, así que no repite el
 * gotcha real de dompdf con `position:absolute` + `min-height` adivinado que sí desbordó 2 veces
 * en otras plantillas de este proyecto, ver memoria `feedback_dompdf_absolute_position_fixed_
 * minheight_overlap`). Logo vía `PdfLogo::dataUri()` — dompdf no renderiza `<svg>` inline.
 */
final class InvoicePdfService
{
    /** @param array<string, mixed> $detail Salida de `InvoiceService::detail()`. */
    public function generate(array $detail): PdfDocument
    {
        $data = $this->viewData($detail);

        // SCRUM-1156 — dos plantillas: la factura certificada (QR, clave de acceso) y la de siempre con el banner «Sin validez fiscal».
        $view = $data['variante']->isFiscal() ? 'admin-cont.invoice-pdf-fiscal' : 'admin-cont.invoice-pdf';

        // SCRUM-888/893 — isPhpEnabled requerido para el pie de página real (page_text(), ver
        // `pdf.partials.footer`), mismo mecanismo que ServiceQuotePdfService/QuotePdfService.
        return Pdf::setOption('isPhpEnabled', true)
            ->loadView($view, $data)
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

        $invoice = isset($detail['invoice_id']) ? AdminContInvoice::query()->find((int) $detail['invoice_id']) : null;
        $variant = $this->resolveVariant($detail, $invoice);

        // Datos que solo existen en la variante fiscal; se calculan únicamente cuando hace falta (una consulta a la transmisión).
        $transmision = $variant['variante']->isFiscal() && $invoice !== null ? $this->certificationTransmission($invoice) : null;

        return [
            'detail'            => $detail,
            'fiscal'            => $fiscal,
            'moneda'            => $company->moneda,
            'logoDataUri'       => PdfLogo::dataUri(),
            'footerCompanyText' => PdfFooterText::forFiscal($fiscal),
            'variante'          => $variant['variante'],
            'qrDataUri'         => $variant['qr'],
            'esPruebas'         => $variant['variante']->isFiscal()
                ? InvoicePdfVariant::isTestEnvironment($transmision?->ambiente, $detail['qr_content'] ?? null, $this->configHosts('production_hosts'))
                : false,
            'anulacion'         => $variant['variante']->isFiscal() && ($detail['es_anulada'] ?? false) && $invoice !== null
                ? $this->anulacionEstado($invoice)
                : null,
            'doc'               => [
                'serie'       => $invoice?->serie,
                // El número que viajó al PAC (`numero_fiscal`, ej. «0039» para el folio F-0039); el folio con letra solo si no hay transmisión.
                'numero'      => $transmision->numero_fiscal ?? ($detail['numero_factura'] ?? null),
                'emision'     => $invoice?->fe_emision_at,
                'receptor_dv' => $invoice?->receptor_dv,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $detail
     * @return array{variante: InvoicePdfVariantResult, qr: ?string}
     */
    private function resolveVariant(array $detail, ?AdminContInvoice $invoice): array
    {
        if ($invoice === null) {
            return ['variante' => new InvoicePdfVariantResult(InvoicePdfVariant::SIN_VALIDEZ, InvoicePdfVariant::MOTIVO_SIN_EMITIR, null, null), 'qr' => null];
        }

        $variante = InvoicePdfVariant::resolve(
            $detail['fe_estado'] ?? null,
            $detail['cufe'] ?? null,
            $detail['qr_content'] ?? null,
            QrPng::isAvailable(),
            $this->configHosts('allowed_hosts'),
        );

        $qr = null;
        if ($variante->isFiscal()) {
            $qr = QrPng::dataUri((string) $detail['qr_content']);
            if ($qr === null) {
                $variante = new InvoicePdfVariantResult(InvoicePdfVariant::SIN_VALIDEZ, InvoicePdfVariant::MOTIVO_DATOS_INCOMPLETOS, null, null);
            }
        }

        // RN2 — una factura `certificado` que no se puede pintar como tal es una inconsistencia de datos, no un caso normal.
        if (($detail['fe_estado'] ?? null) === 'certificado' && ! $variante->isFiscal()) {
            Log::warning('SCRUM-1156: factura certificada sin datos fiscales íntegros para el PDF; se imprime sin validez fiscal.', [
                'invoice_id' => $invoice->id,
                'tiene_cufe' => trim((string) $invoice->cufe) !== '',
                'tiene_qr'   => trim((string) $invoice->qr_content) !== '',
                'gd'         => QrPng::isAvailable(),
            ]);
        }

        return ['variante' => $variante, 'qr' => $qr];
    }

    /**
     * Transmisión exitosa más reciente del documento: de ahí salen el ambiente y el número fiscal que se enviaron al PAC. Una factura que
     * quedó `pendiente` y se certificó por polling deja su fila `certificado` con otra operación (`consultar_estado`), así que se acepta
     * cualquiera, prefiriendo la de `certificar`.
     */
    private function certificationTransmission(AdminContInvoice $invoice): ?AdminContFeTransmision
    {
        return AdminContFeTransmision::query()
            ->where('documento_tipo', 'factura')
            ->where('documento_id', $invoice->id)
            ->where('resultado', 'certificado')
            ->orderByRaw("case when operacion = 'certificar' then 0 else 1 end")
            ->orderByDesc('id')
            ->first();
    }

    /**
     * RN5 — `anulada_at` se fija al EJECUTAR la corrección, antes de que la nota de crédito se certifique ante el PAC; mientras no
     * esté certificada la factura sigue vigente ante la DGI, así que no se imprime «ANULADA» a secas.
     *
     * @return 'anulada'|'en_tramite'
     */
    private function anulacionEstado(AdminContInvoice $invoice): string
    {
        $certificada = AdminContCreditNote::query()
            ->where('factura_origen_id', $invoice->id)
            ->where('fe_estado', 'certificado')
            ->exists();

        return $certificada ? 'anulada' : 'en_tramite';
    }

    /** @return list<string> */
    private function configHosts(string $key): array
    {
        /** @var list<string> $hosts */
        $hosts = array_values((array) config("fiscal-pdf.{$key}", []));

        return $hosts;
    }
}
