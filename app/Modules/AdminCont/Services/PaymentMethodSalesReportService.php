<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentInvoice;
use App\Modules\AdminCont\Services\Billing\InvoiceTaxBreakdown;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;

/**
 * Batch 23 Grupo 3 (SCRUM-665→669, REQ-588→592) — "Ventas por medio de pago" (4M4). A diferencia
 * del Grupo 2 (4M1/4M2), acá "todos los clientes" es el default al ENTRAR (RN1 REQ-588) — nunca un
 * estado `sin_cliente`, `$masterClientId === null` significa directamente "todos".
 *
 * Columnas de método de pago: los 9 métodos REALES de `AdminContPayment::METODOS` (confirmado con
 * Luis — REQ-590 dice literalmente "7 métodos de pago posibles", pero el ticket quedó desactualizado
 * frente al sistema real, que agregó Tarjeta y Depósito bancario después), no los 7 del mockup.
 *
 * Caso borde sin cubrir por el ticket (la acceptance criteria asume 1 factura = 1 método): si una
 * factura tiene más de 1 pago CONFIRMADO con métodos distintos (ej. abono parcial + saldo con otro
 * método), el monto total de la factura se asigna a la columna del método del pago confirmado más
 * RECIENTE — simplificación deliberada, no una regla de negocio confirmada.
 *
 * @phpstan-type PaymentMethodSalesRow array{fecha: string, cliente: string, documento: string, base_imponible: float, itbms: float, total: float, transferencia: float, cheque: float, efectivo: float, tarjeta: float, deposito: float, yappy: float, link_pago: float, retencion_impuestos: float, ajuste_cuenta: float}
 * @phpstan-type PaymentMethodSalesPendiente array{fecha: string, documento: string, cliente: string, proyecto: string|null, monto: float}
 */
final class PaymentMethodSalesReportService
{
    /**
     * SCRUM-880 — orden de las columnas de método de pago DESPUÉS de "Total", en pantalla y en el
     * Excel. Deliberadamente distinto del orden de `AdminContPayment::METODOS` (vocabulario
     * canónico compartido con Arqueo/Cobros — nunca se reordena): negocio pidió, solo para este
     * reporte, que Yappy y Link de pago vayan antes de Tarjeta y Depósito bancario. Las columnas
     * previas a "Total" (Fecha, Cliente, N° Documento, Base Imponible, ITBMS, Total) no cambian.
     *
     * @var list<string>
     */
    public const COLUMNAS_METODO_ORDEN = [
        AdminContPayment::METODO_TRANSFERENCIA,
        AdminContPayment::METODO_CHEQUE,
        AdminContPayment::METODO_EFECTIVO,
        AdminContPayment::METODO_YAPPY,
        AdminContPayment::METODO_LINK_PAGO,
        AdminContPayment::METODO_TARJETA,
        AdminContPayment::METODO_DEPOSITO,
        AdminContPayment::METODO_RETENCION_IMPUESTOS,
        AdminContPayment::METODO_AJUSTE_CUENTA,
    ];

    /** @return array{resumen: array{facturas_cobradas: int, base_imponible: float, itbms: float, total: float}, filas: list<PaymentMethodSalesRow>, pendientes: list<PaymentMethodSalesPendiente>} */
    public function report(?int $masterClientId, ?string $desde, ?string $hasta): array
    {
        $query = AdminContInvoice::query()->whereNull('anulada_at')->with('itbmsRate', 'lines');
        if ($masterClientId !== null) {
            $query->where('master_client_id', $masterClientId);
        }
        if ($desde !== null) {
            $query->where('created_at', '>=', $desde);
        }
        if ($hasta !== null) {
            $query->where('created_at', '<=', $hasta.' 23:59:59');
        }
        $invoices = $query->get();

        $cobradas   = $invoices->filter(fn (AdminContInvoice $i) => $i->saldo_aplicado >= $i->monto)->values();
        $pendientes = $invoices->filter(fn (AdminContInvoice $i) => $i->saldo_aplicado < $i->monto)->values();

        $metodoPorFactura = $this->metodoPrincipalPorFactura($cobradas->pluck('id'));
        // Misma fuente que InvoiceBookService::facturaRows() (`Billing\InvoiceTaxBreakdown`) — las
        // `invoice_lines` de la factura si las tiene (SCRUM-929, B3b: una parcial no puede mostrar la base
        // y el ITBMS de la cotización ENTERA) y, solo para las legadas sin líneas, `Quote::netTotal()`/
        // `itbms()`, nunca una división paralela (monto/(1+tasa)) que puede desviarse por redondeo
        // acumulado y, más importante acá, mostrar una base/ITBMS distinta a la que ya ve la misma
        // factura en Libro de Facturas.
        $quotes = Quote::query()->whereIn('id', $cobradas->filter(fn (AdminContInvoice $i): bool => $i->lines->isEmpty())->pluck('quote_id')->filter()->unique())
            ->with('parts.items')->get()->keyBy('id');

        $filas = $cobradas->sortBy('created_at')->map(function (AdminContInvoice $invoice) use ($metodoPorFactura, $quotes): array {
            /** @var Quote|null $quote */
            $quote = $invoice->quote_id !== null ? $quotes->get($invoice->quote_id) : null;
            [$base, $itbms] = InvoiceTaxBreakdown::of($invoice, $quote);
            $metodo = $metodoPorFactura[$invoice->id] ?? null;

            $row = [
                'fecha'          => $invoice->created_at->toDateString(),
                'cliente'        => $invoice->cliente,
                'documento'      => $invoice->numero,
                'base_imponible' => $base,
                'itbms'          => $itbms,
                'total'          => round($invoice->monto, 2),
            ];
            foreach (self::COLUMNAS_METODO_ORDEN as $m) {
                $row[$m] = $metodo === $m ? round($invoice->monto, 2) : 0.0;
            }

            return $row;
        })->all();
        $filas = array_values($filas);

        $projectIds = $pendientes->pluck('sales_project_id')->filter()->unique()->values();
        $projects   = SalesProject::whereIn('id', $projectIds)->pluck('name', 'id');
        $pendientesPayload = array_values($pendientes
            ->sortBy('created_at')
            ->map(fn (AdminContInvoice $i): array => [
                'fecha'    => $i->created_at->toDateString(),
                'documento' => $i->numero,
                'cliente'   => $i->cliente,
                'proyecto'  => $i->sales_project_id !== null ? (string) $projects->get($i->sales_project_id) : null,
                'monto'     => round($i->monto, 2),
            ])
            ->all());

        return [
            'resumen' => [
                'facturas_cobradas' => count($filas),
                'base_imponible'    => round(array_sum(array_column($filas, 'base_imponible')), 2),
                'itbms'             => round(array_sum(array_column($filas, 'itbms')), 2),
                'total'             => round(array_sum(array_column($filas, 'total')), 2),
            ],
            'filas'      => $filas,
            'pendientes' => $pendientesPayload,
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int, int> $invoiceIds
     * @return array<int, string> invoice_id => metodo_pago (del pago confirmado más reciente)
     */
    private function metodoPrincipalPorFactura($invoiceIds): array
    {
        if ($invoiceIds->isEmpty()) {
            return [];
        }

        $lines = AdminContPaymentInvoice::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->whereHas('payment', fn ($q) => $q->where('estado', AdminContPayment::ESTADO_CONFIRMADO))
            ->with('payment')
            ->get();

        /** @var array<int, array{fecha: \Carbon\Carbon, metodo: string}> $masReciente */
        $masReciente = [];
        foreach ($lines as $line) {
            $payment = $line->payment;
            if ($payment === null) {
                continue;
            }
            $actual = $masReciente[$line->invoice_id] ?? null;
            if ($actual === null || $payment->created_at->gt($actual['fecha'])) {
                $masReciente[$line->invoice_id] = ['fecha' => $payment->created_at, 'metodo' => $payment->metodo_pago];
            }
        }

        return array_map(fn (array $r): string => $r['metodo'], $masReciente);
    }
}
