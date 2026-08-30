<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SCRUM-224 (ADR-SCRUM224, Cybersecurity gap #2) — límite de tamaño PROPIO para el endpoint de
 * detección con IA, distinto y más chico que el límite genérico de 500MB de REQ-155 (checklist
 * de documentos del envío): mandar un archivo de ese tamaño a Sonnet 5 es un costo real sin techo,
 * y este endpoint es alcanzable con solo `compras.edit`, sin que exista una orden todavía. 10MB
 * cubre con margen una factura/proforma real (1-2 páginas u hojas de cálculo de líneas de
 * producto). La whitelist de extensiones también es más angosta que REQ-155: solo los formatos
 * que MatchInvoiceToOrderJob sabe procesar (PDF/imagen vía Claude vision, Excel/CSV vía
 * phpspreadsheet) — no tiene sentido aceptar zip/doc/ppt acá.
 */
class StoreGoodsReceiptInvoiceRequest extends FormRequest
{
    private const MAX_KILOBYTES = 10240;

    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'xlsx', 'xls', 'csv'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => [
                'required', 'file', 'max:' . self::MAX_KILOBYTES,
                'extensions:' . implode(',', self::ALLOWED_EXTENSIONS),
            ],
        ];
    }
}
