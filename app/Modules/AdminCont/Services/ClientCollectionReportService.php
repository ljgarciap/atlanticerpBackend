<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentInvoice;
use App\Modules\VentasDiseno\Models\SalesProject;
use Carbon\CarbonImmutable;

/**
 * Batch 23 Grupo 2 (SCRUM-651→660, REQ-574→583) — "Reporte mensual por cliente" (4M1, agrupación
 * 'dia') y "Mensual por cliente — acumulado" (4M2, agrupación 'mes'). Ambos tickets son
 * prácticamente idénticos (mismo filtro, mismo resumen, misma tabla de cobros por método de
 * pago) — la única diferencia real es la agrupación de la tabla, por eso viven en un único
 * servicio con un parámetro `$agrupacion` en vez de duplicar la lógica.
 *
 * Decisiones de diseño (no están en ningún REQ explícito, documentadas acá):
 * - "Cobrado" = `saldo_aplicado >= monto`; "pendiente" = lo contrario. Mismo criterio que
 *   `InvoiceService::agingBuckets()`/`puede_registrar_cobro`.
 * - "Total pendiente" del resumen (y el monto de cada fila de `pendientes`) usa el MONTO TOTAL de
 *   la factura pendiente, no el saldo restante — coincide con el escenario literal del REQ-575/580
 *   ("1 pendiente por $6,200") y con el mockup, que no maneja cobros parciales.
 * - "Núm." de una fila de la tabla cuenta COBROS reales (`AdminContPayment` distintos aplicados
 *   ese día/mes a facturas de este filtro), no líneas de `payment_invoices` — un mismo pago que
 *   cubre 2 facturas del mismo cliente cuenta como 1 cobro, no 2 (más preciso que el mockup, cuyo
 *   dataset de ejemplo nunca tiene ese caso).
 *
 * @phpstan-type ClientCollectionRow array{fecha?: string, anio?: int, mes?: string, num: int, importe: float, transferencia: float, cheque: float, efectivo: float, yappy: float, otros: float, total_caja: float}
 * @phpstan-type ClientCollectionResumen array{registros: int, total_facturado: float, total_cobrado: float, total_pendiente: float}
 * @phpstan-type ClientCollectionPendiente array{fecha: string, factura: string, proyecto: string|null, monto: float}
 */
final class ClientCollectionReportService
{
    /** @var array<string, string> */
    private const METODOS_COLUMNA = [
        AdminContPayment::METODO_TRANSFERENCIA => 'transferencia',
        AdminContPayment::METODO_CHEQUE        => 'cheque',
        AdminContPayment::METODO_EFECTIVO      => 'efectivo',
        AdminContPayment::METODO_YAPPY         => 'yappy',
    ];

    private const MESES_NOMBRE = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    /**
     * @return array{estado: 'sin_cliente'}|array{estado: 'ok', resumen: ClientCollectionResumen, filas: list<ClientCollectionRow>, totales: ClientCollectionRow, pendientes: list<ClientCollectionPendiente>}
     */
    public function report(?int $masterClientId, bool $todos, string $agrupacion, ?string $desde, ?string $hasta): array
    {
        if ($masterClientId === null && ! $todos) {
            return ['estado' => 'sin_cliente'];
        }

        // RN3 REQ-574/579 — el rango de fechas filtra por la fecha de la FACTURA, nunca por la del cobro.
        $query = AdminContInvoice::query()->whereNull('anulada_at');
        if (! $todos) {
            $query->where('master_client_id', $masterClientId);
        }
        if ($desde !== null) {
            $query->where('created_at', '>=', CarbonImmutable::parse($desde)->startOfDay());
        }
        if ($hasta !== null) {
            $query->where('created_at', '<=', CarbonImmutable::parse($hasta)->endOfDay());
        }
        $invoices = $query->get();

        $cobradas   = $invoices->filter(fn (AdminContInvoice $i) => $i->saldo_aplicado >= $i->monto)->values();
        $pendientes = $invoices->filter(fn (AdminContInvoice $i) => $i->saldo_aplicado < $i->monto)->values();

        $resumen = [
            'registros'       => (int) $invoices->count(),
            'total_facturado' => round((float) $invoices->sum('monto'), 2),
            'total_cobrado'   => round((float) $cobradas->sum('monto'), 2),
            'total_pendiente' => round((float) $pendientes->sum('monto'), 2),
        ];

        $filas = $this->buildFilas($cobradas, $agrupacion);

        $totales = $this->emptyGrupo();
        foreach ($filas as $fila) {
            // Campos explícitos (no un foreach por nombre de clave dinámico) para que PHPStan
            // conserve el tipo preciso de cada campo del shape (`num: int` vs el resto `float`) —
            // acceder con una clave variable colapsa esa precisión a una unión.
            $totales['num']           += $fila['num'];
            $totales['importe']       += $fila['importe'];
            $totales['transferencia'] += $fila['transferencia'];
            $totales['cheque']        += $fila['cheque'];
            $totales['efectivo']      += $fila['efectivo'];
            $totales['yappy']         += $fila['yappy'];
            $totales['otros']         += $fila['otros'];
            $totales['total_caja']    += $fila['total_caja'];
        }

        $projectIds = $pendientes->pluck('sales_project_id')->filter()->unique()->values();
        $projects   = SalesProject::whereIn('id', $projectIds)->pluck('name', 'id');

        $pendientesPayload = array_values($pendientes
            ->sortBy('created_at')
            ->map(function (AdminContInvoice $i) use ($projects): array {
                $projectName = $i->sales_project_id !== null ? $projects->get($i->sales_project_id) : null;

                return [
                    'fecha'    => $i->created_at->toDateString(),
                    'factura'  => $i->numero,
                    'proyecto' => $projectName !== null ? (string) $projectName : null,
                    'monto'    => round($i->monto, 2),
                ];
            })
            ->all());

        return [
            'estado'     => 'ok',
            'resumen'    => $resumen,
            'filas'      => $filas,
            'totales'    => $totales,
            'pendientes' => $pendientesPayload,
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int, AdminContInvoice> $cobradas
     * @return list<ClientCollectionRow>
     */
    private function buildFilas($cobradas, string $agrupacion): array
    {
        $invoiceIds = $cobradas->pluck('id');
        if ($invoiceIds->isEmpty()) {
            return [];
        }

        // `saldo_aplicado` solo se incrementa con pagos `estado=confirmado` (ver PaymentService::
        // confirm()), así que una factura "cobrada" siempre tiene AL MENOS 1 pago confirmado — pero
        // podría además tener OTRA línea de un pago todavía `esperando_confirmacion` (ej.
        // sobrepago/reintento) cuyo `AdminContPaymentInvoice` ya se creó al registrar el cobro,
        // antes de confirmar. Filtrar acá evita que ese monto no confirmado se sume igual a la
        // tabla — mismo criterio que `PaymentService::summaryForCurrentMonth()` y
        // `InvoiceService::over90DaysCollectedForCurrentMonth()`.
        $lines = AdminContPaymentInvoice::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->whereHas('payment', fn ($q) => $q->where('estado', AdminContPayment::ESTADO_CONFIRMADO))
            ->with('payment')
            ->get();

        /** @var array<string, array{grupo: ClientCollectionRow, payment_ids: array<int, true>}> $buckets */
        $buckets = [];
        foreach ($lines as $line) {
            $payment = $line->payment;
            if ($payment === null) {
                continue;
            }
            $key = $agrupacion === 'dia'
                ? $payment->created_at->toDateString()
                : $payment->created_at->format('Y-m');

            if (! isset($buckets[$key])) {
                $buckets[$key] = ['grupo' => $this->emptyGrupo(), 'payment_ids' => []];
            }

            $g = &$buckets[$key]['grupo'];
            $g['importe'] += $line->monto_aplicado;
            $columna = self::METODOS_COLUMNA[$payment->metodo_pago] ?? 'otros';
            $g[$columna] += $line->monto_aplicado;
            unset($g);

            $buckets[$key]['payment_ids'][$payment->id] = true;
        }

        ksort($buckets);

        $filas = [];
        foreach ($buckets as $key => $bucket) {
            $g              = $bucket['grupo'];
            $g['num']       = count($bucket['payment_ids']);
            $g['importe']   = round($g['importe'], 2);
            $g['total_caja'] = round($g['transferencia'] + $g['cheque'] + $g['efectivo'] + $g['yappy'] + $g['otros'], 2);
            foreach (['transferencia', 'cheque', 'efectivo', 'yappy', 'otros'] as $col) {
                $g[$col] = round($g[$col], 2);
            }

            if ($agrupacion === 'dia') {
                $filas[] = ['fecha' => $key] + $g;
            } else {
                [$anio, $mes] = explode('-', $key);
                $filas[] = ['anio' => (int) $anio, 'mes' => self::MESES_NOMBRE[(int) $mes]] + $g;
            }
        }

        return $filas;
    }

    /** @return ClientCollectionRow */
    private function emptyGrupo(): array
    {
        return ['num' => 0, 'importe' => 0.0, 'transferencia' => 0.0, 'cheque' => 0.0, 'efectivo' => 0.0, 'yappy' => 0.0, 'otros' => 0.0, 'total_caja' => 0.0];
    }
}
