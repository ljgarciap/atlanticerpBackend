<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Modules\AdminCont\FacturacionElectronica\Exceptions\RecertificacionNoPermitidaException;
use App\Modules\AdminCont\FacturacionElectronica\Services\FiscalCertificationService;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SCRUM-1007 — acciones fiscales manuales sobre un documento electrónico: reintentar la certificación de uno
 * `rechazado`/`error_configuracion` y actualizar el estado de uno `pendiente`; SCRUM-1019 agrega "Reconciliar con MSL". Las rutas viven bajo el
 * middleware `primary_approver_only` (solo Mark/superadmin, decisión de Luis) con `throttle:6,1`; ver
 * `routes/admin-contab.php` y `docs/specs/scrum-1007-recertificar-documentos-fe.md`.
 *
 * Un resultado fiscal negativo (la DGI vuelve a rechazar) NO es un error HTTP: responde 200 con el estado
 * real y el motivo. Solo las precondiciones fallidas (estado, FE apagada, anulada, en curso) son 409.
 */
class AdminContFeReintentoController
{
    public function __construct(
        private readonly FiscalCertificationService $certification,
        private readonly InvoiceService $invoices,
    ) {
    }

    /** POST /api/admin-contab/invoices/{order}/fe-reintentar?invoice_id= */
    public function reintentarFactura(int $order, Request $request): JsonResponse
    {
        return $this->conFactura($order, $request, fn (AdminContInvoice $f): array => $this->certification->recertificar($f, 'factura'));
    }

    /** POST /api/admin-contab/invoices/{order}/fe-actualizar-estado?invoice_id= */
    public function actualizarEstadoFactura(int $order, Request $request): JsonResponse
    {
        return $this->conFactura($order, $request, fn (AdminContInvoice $f): array => $this->certification->actualizarEstado($f, 'factura'));
    }

    /** POST /api/admin-contab/notas-credito/{creditNote}/fe-reintentar */
    public function reintentarNotaCredito(int $creditNote): JsonResponse
    {
        return $this->conNotaCredito($creditNote, fn (AdminContCreditNote $n): array => $this->certification->recertificar($n, 'nota_credito'));
    }

    /** POST /api/admin-contab/notas-credito/{creditNote}/fe-actualizar-estado */
    public function actualizarEstadoNotaCredito(int $creditNote): JsonResponse
    {
        return $this->conNotaCredito($creditNote, fn (AdminContCreditNote $n): array => $this->certification->actualizarEstado($n, 'nota_credito'));
    }

    /** POST /api/admin-contab/invoices/{order}/fe-reconciliar?invoice_id= — SCRUM-1019 */
    public function reconciliarFactura(int $order, Request $request): JsonResponse
    {
        return $this->conFactura($order, $request, fn (AdminContInvoice $f): array => $this->certification->reconciliar($f, 'factura'));
    }

    /** POST /api/admin-contab/notas-credito/{creditNote}/fe-reconciliar — SCRUM-1019 */
    public function reconciliarNotaCredito(int $creditNote): JsonResponse
    {
        return $this->conNotaCredito($creditNote, fn (AdminContCreditNote $n): array => $this->certification->reconciliar($n, 'nota_credito'));
    }

    /** @param \Closure(AdminContInvoice): array<string, mixed> $accion */
    private function conFactura(int $order, Request $request, \Closure $accion): JsonResponse
    {
        $request->validate(['invoice_id' => ['sometimes', 'nullable', 'integer', 'min:1']]);

        $invoice = $this->invoices->resolveInvoice($order, $request->filled('invoice_id') ? $request->integer('invoice_id') : null);
        if ($invoice === null) {
            return response()->json(['message' => 'No hay una factura generada para esta entrega.'], 404);
        }

        return $this->ejecutar(fn (): array => $accion($invoice));
    }

    /** @param \Closure(AdminContCreditNote): array<string, mixed> $accion */
    private function conNotaCredito(int $creditNote, \Closure $accion): JsonResponse
    {
        /** @var AdminContCreditNote|null $note */
        $note = AdminContCreditNote::find($creditNote);
        if ($note === null) {
            return response()->json(['message' => 'Nota de crédito no encontrada.'], 404);
        }

        return $this->ejecutar(fn (): array => $accion($note));
    }

    /** @param \Closure(): array<string, mixed> $accion */
    private function ejecutar(\Closure $accion): JsonResponse
    {
        try {
            return response()->json($accion());
        } catch (RecertificacionNoPermitidaException $e) {
            return response()->json(['message' => $e->getMessage(), 'codigo' => $e->codigo], 409);
        }
    }
}
