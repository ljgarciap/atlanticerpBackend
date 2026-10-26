<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Services\Billing\InvoiceTaxBreakdown;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\Quote;

/**
 * Batch 23 Grupo 3 (SCRUM-661→664, REQ-584→587) — "Libro de Facturas" (4M3). Combina
 * `AdminContInvoice` (excluye `anulada_at` — ya no es un documento fiscal vigente; SÍ incluye
 * `es_incobrable`, sigue siendo un documento real) con `AdminContCreditNote`
 * (`estado = ESTADO_APLICADA`, mismo criterio que el resto del módulo), en orden cronológico
 * (RN1: empate por número de documento).
 *
 * RUC: no hay columna propia en `AdminContInvoice` — se deriva en vivo vía
 * `Order->pipelineCard->subClient->tax_id` (mismo camino que ya usa el resto del módulo para
 * resolver cliente/RUC), `null` si algún eslabón falta. El nombre de cliente usa el snapshot ya
 * guardado en `invoice.cliente`/`creditNote.cliente`, no se re-deriva.
 *
 * Base imponible/ITBMS de una FACTURA (SCRUM-929, B3b): se leen de sus `invoice_lines` (Σ `subtotal`/Σ
 * `itbms`) cuando las tiene — una factura `parcial` cubre solo una entrega, y derivarlas de la cotización
 * ENTERA sobrestimaba ×N el libro fiscal (ver `Billing\InvoiceTaxBreakdown`). Las facturas legadas, SIN
 * líneas, se derivan de `Quote::netTotal()`/`Quote::itbms()` (mismo
 * cálculo canónico que ya usa `InvoiceService::fiscalBreakdown()` en el resto del módulo) cuando
 * la factura tiene cotización vinculada — nunca una fórmula paralela por división
 * (`monto / (1 + tasa)`), que puede desviarse por redondeo acumulado en cotizaciones con varios
 * ítems de distinto precio. Fallback a la división solo para el caso borde de una factura sin
 * `quote_id` (no debería pasar en datos reales, pero el campo es nullable).
 *
 * @phpstan-type InvoiceBookRow array{fecha: string, ruc: string|null, cliente: string, tipo: string, motivo: string|null, documento: string, base_imponible: float, porcentaje: float, itbms: float, total: float, vendedor: string|null}
 */
final class InvoiceBookService
{
    /**
     * Devuelve `documentos` como array plano (`list<InvoiceBookRow>`) — a propósito, NO envuelto en
     * un Resource acá: este método lo consume tanto el controller (que sí envuelve `documentos` en
     * `InvoiceBookDocumentResource::collection()` antes de responder JSON, ver
     * `AdminContInvoiceBookController::index()`) como `InvoiceBookExportService` (que necesita el
     * array crudo para `array_map()` sobre las filas al armar el Excel) — envolver acá adentro
     * rompería ese segundo consumidor.
     *
     * @return array{resumen: array{facturas: int, notas_credito: int, base_imponible: float, itbms: float, total: float}, documentos: list<InvoiceBookRow>}
     */
    public function documents(?string $desde, ?string $hasta, ?string $tipo): array
    {
        $facturas = $tipo === null || $tipo === 'factura' ? $this->facturaRows($desde, $hasta) : [];
        $notas    = $tipo === null || $tipo === 'nota_credito' ? $this->notaRows($desde, $hasta) : [];

        $documentos = [...$facturas, ...$notas];
        usort($documentos, fn (array $a, array $b) => $a['fecha'] === $b['fecha']
            ? $a['documento'] <=> $b['documento']
            : $a['fecha'] <=> $b['fecha']);

        return [
            'resumen' => [
                'facturas'       => count($facturas),
                'notas_credito'  => count($notas),
                'base_imponible' => round(array_sum(array_column($documentos, 'base_imponible')), 2),
                'itbms'          => round(array_sum(array_column($documentos, 'itbms')), 2),
                'total'          => round(array_sum(array_column($documentos, 'total')), 2),
            ],
            'documentos' => $documentos,
        ];
    }

    /** @return list<InvoiceBookRow> */
    private function facturaRows(?string $desde, ?string $hasta): array
    {
        $query = AdminContInvoice::query()->whereNull('anulada_at')->with('itbmsRate', 'lines');
        $this->applyDateRange($query, $desde, $hasta);
        $invoices = $query->get();

        // Precarga en lote (evita N+1): órdenes con la cadena de relaciones para RUC, y
        // cotizaciones con `netTotal()/itbms()` ya calculables (`parts.items`, mismo eager-load
        // que usa `InvoiceService::fiscalBreakdown()`).
        $orders = Order::whereIn('id', $invoices->pluck('order_id')->filter()->unique())
            ->with('pipelineCard.subClient')
            ->get()->keyBy('id');
        // SCRUM-1175 — vendedores responsables de todas las tarjetas, en una sola consulta.
        $vendedoresUsers = InvoiceSellers::usersFor($orders->map(fn (Order $o) => $o->pipelineCard));
        // Solo las facturas SIN líneas (legadas) necesitan la cotización para su base/ITBMS.
        $quotes = Quote::query()->whereIn('id', $invoices->filter(fn (AdminContInvoice $i): bool => $i->lines->isEmpty())->pluck('quote_id')->filter()->unique())
            ->with('parts.items')
            ->get()->keyBy('id');

        $rows = $invoices->map(function (AdminContInvoice $invoice) use ($orders, $quotes, $vendedoresUsers): array {
            /** @var Quote|null $quote */
            $quote = $invoice->quote_id !== null ? $quotes->get($invoice->quote_id) : null;
            [$base, $itbms, $porcentaje] = InvoiceTaxBreakdown::of($invoice, $quote);

            /** @var Order|null $order */
            $order = $invoice->order_id !== null ? $orders->get($invoice->order_id) : null;

            return [
                'fecha'           => $invoice->created_at->toDateString(),
                'ruc'             => $order?->pipelineCard?->subClient?->tax_id ?: null, // SCRUM-1003 — '' (Consumidor Final) = sin RUC
                'cliente'         => $invoice->cliente,
                'tipo'            => 'Factura',
                'motivo'          => null,
                'documento'       => $invoice->numero,
                'base_imponible'  => $base,
                'porcentaje'      => $porcentaje,
                'itbms'           => $itbms,
                'total'           => round($invoice->monto, 2),
                'vendedor'        => self::vendedorTexto($order?->pipelineCard, $vendedoresUsers),
            ];
        })->all();

        return array_values($rows);
    }

    /** @return list<InvoiceBookRow> */
    private function notaRows(?string $desde, ?string $hasta): array
    {
        $query = AdminContCreditNote::query()
            ->where('estado', AdminContCreditNote::ESTADO_APLICADA)
            ->with(['facturaOrigen.itbmsRate', 'nuevoTratamientoItbms']);
        $this->applyDateRange($query, $desde, $hasta, column: 'fecha');

        $notas = $query->get();
        // SCRUM-1175 — la nota hereda el vendedor del pedido de su factura de origen.
        $ordersOrigen = Order::whereIn('id', $notas->map(fn (AdminContCreditNote $n) => $n->facturaOrigen?->order_id)->filter()->unique())
            ->with('pipelineCard')->get()->keyBy('id');
        $vendedoresUsers = InvoiceSellers::usersFor($ordersOrigen->map(fn (Order $o) => $o->pipelineCard));

        $rows = $notas->map(function (AdminContCreditNote $nota) use ($ordersOrigen, $vendedoresUsers): array {
            $facturaOrigen = $nota->facturaOrigen;
            $nuevoRate     = $nota->nuevoTratamientoItbms;
            $origenRate    = $facturaOrigen?->itbmsRate;
            $porcentaje    = $nuevoRate !== null ? $nuevoRate->porcentaje : ($origenRate !== null ? $origenRate->porcentaje : 0.0);
            $base          = round($nota->subtotal, 2);
            $itbms         = round($nota->itbms, 2);

            return [
                'fecha'          => $nota->fecha->toDateString(),
                'ruc'            => $this->resolveRuc($facturaOrigen?->order_id),
                'cliente'        => $nota->cliente,
                'tipo'           => 'Nota de Crédito',
                'motivo'         => CreditNoteService::motivoLabel($nota),
                'documento'      => $nota->numero,
                // RN2 REQ-586 — siempre negativos.
                'base_imponible' => -abs($base),
                'porcentaje'     => $porcentaje,
                'itbms'          => -abs($itbms),
                'total'          => -abs(round($nota->monto, 2)),
                'vendedor'       => self::vendedorTexto($ordersOrigen->get((int) $nota->facturaOrigen?->order_id)?->pipelineCard, $vendedoresUsers),
            ];
        })->all();

        return array_values($rows);
    }

    /** @param \Illuminate\Support\Collection<int, \App\Models\User> $users */
    private static function vendedorTexto(?\App\Modules\VentasDiseno\Models\PipelineCard $card, \Illuminate\Support\Collection $users): ?string
    {
        $names = InvoiceSellers::namesFor($card, $users);

        return $names === [] ? null : implode(', ', $names);
    }

    /** @param \Illuminate\Database\Eloquent\Builder<AdminContInvoice>|\Illuminate\Database\Eloquent\Builder<AdminContCreditNote> $query */
    private function applyDateRange($query, ?string $desde, ?string $hasta, string $column = 'created_at'): void
    {
        if ($desde !== null) {
            $query->where($column, '>=', $desde);
        }
        if ($hasta !== null) {
            $query->where($column, '<=', $hasta.' 23:59:59');
        }
    }

    private function resolveRuc(?int $orderId): ?string
    {
        if ($orderId === null) {
            return null;
        }

        $order = Order::find($orderId);

        return $order?->pipelineCard?->subClient?->tax_id ?: null; // SCRUM-1003 — '' (Consumidor Final) = sin RUC
    }
}
