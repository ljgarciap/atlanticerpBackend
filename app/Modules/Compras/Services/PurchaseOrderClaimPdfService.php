<?php

declare(strict_types=1);

namespace App\Modules\Compras\Services;

use App\Modules\Compras\Models\PurchaseOrderClaim;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/** SCRUM-259 (REQ-196) — PDF real del reclamo, mismo mecanismo que PurchaseOrderPdfService. */
final class PurchaseOrderClaimPdfService
{
    public function generate(PurchaseOrderClaim $claim): PdfDocument
    {
        $claim->loadMissing(['purchaseOrder.provider', 'lines.orderLine.catalogProduct']);

        return Pdf::loadView('compras.claim-pdf', ['claim' => $claim])->setPaper('letter');
    }
}
