<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Models\User;
use App\Modules\Bodega\Models\CustomerReturn;
use App\Shared\Support\CircuitBreaker;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * SCRUM-476 (REQ-406, Bloque B6 "Devoluciones") — hermano de
 * `App\Modules\Bodega\Services\OrderDocumentService`, mismo mecanismo (dompdf + S3 vía
 * CircuitBreaker). Para REQ-407 ("ver guía de entrega original") NO se genera nada nuevo acá —
 * el Controller reutiliza `OrderDocumentService::generateGuiaEntrega($return->order, $actor)`
 * tal cual (ver ADR-SCRUM473-489-devoluciones.md).
 */
final class CustomerReturnDocumentService
{
    private const DISK = 's3-private';

    /**
     * REQ-406 — formulario de devolución, mismo estilo institucional que Guía de Entrega/Factura.
     * Si `signed_document_path` ya no es null, la vista muestra el nombre de `contact_name` en
     * cursiva en el espacio de firma (ver `resources/views/bodega/devolucion-formulario-pdf.blade.php`).
     */
    public function generateFormulario(CustomerReturn $customerReturn, User $actor): string
    {
        $customerReturn->loadMissing(['order', 'lines.orderItem.catalogProduct']);

        $pdf = Pdf::loadView('bodega.devolucion-formulario-pdf', ['customerReturn' => $customerReturn])->setPaper('letter');
        $key = "bodega/customer-returns/{$customerReturn->id}/formulario-".now()->format('YmdHis').'-'.Str::uuid()->toString().'.pdf';

        (new CircuitBreaker(self::DISK))->call(function () use ($key, $pdf): void {
            $ok = Storage::disk(self::DISK)->put($key, $pdf->output());
            if ($ok === false) {
                throw new \RuntimeException('S3 upload failed for disk ['.self::DISK."] key [{$key}]");
            }
        });

        return $key;
    }

    public function presignUrl(string $storageKey): string
    {
        return Storage::disk(self::DISK)->temporaryUrl($storageKey, now()->addMinutes(15));
    }
}
