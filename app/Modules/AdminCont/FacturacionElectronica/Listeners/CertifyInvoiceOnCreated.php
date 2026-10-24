<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\Listeners;

use App\Modules\AdminCont\Events\InvoiceCreated;
use App\Modules\AdminCont\FacturacionElectronica\Services\FiscalCertificationService;
use App\Modules\AdminCont\Models\AdminContInvoice;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SCRUM-943 — segundo listener de `InvoiceCreated` (el primero, `MarkOrderInvoiceReady`, es un
 * no-op desde SCRUM-876). Síncrono a propósito, no `ShouldQueue` — mismo criterio que
 * `Bodega\Listeners\ReleaseOrdersAwaitingStock`: corre dentro del
 * mismo request/contexto de tenant que ya está activo, sin necesidad de restaurar tenant en un
 * worker aparte. `FiscalCertificationService` ya atrapa cualquier error de ensamblado/certificación
 * como `error_configuracion` — el try/catch de acá es una segunda capa, para que un bug genuinamente
 * inesperado en esta capa TAMPOCO pueda romper la confirmación de la factura que lo disparó.
 *
 * Nota de latencia aceptada: una llamada real a MSL agrega su tiempo de respuesta (típicamente
 * <2s, hasta 15s de timeout) al request de "+ Crear Factura" — mismo trade-off que el resto de los
 * listeners síncronos de este módulo, ninguno usa cola todavía.
 */
final class CertifyInvoiceOnCreated
{
    public function __construct(private readonly FiscalCertificationService $certification)
    {
    }

    public function handle(InvoiceCreated $event): void
    {
        try {
            $invoice = AdminContInvoice::find($event->invoiceId);
            if ($invoice === null) {
                return;
            }

            $this->certification->certificarFactura($invoice);
        } catch (Throwable $e) {
            Log::error('admin_contab.facturacion_electronica.certificacion_listener_fallo', [
                'invoice_id' => $event->invoiceId,
                'exception' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);
        }
    }
}
