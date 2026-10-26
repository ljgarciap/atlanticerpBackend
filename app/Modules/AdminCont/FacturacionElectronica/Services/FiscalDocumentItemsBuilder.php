<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\Services;

use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocumentItem;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContInvoiceLine;
use App\Modules\AdminCont\Services\ItbmsRateResolver;
use App\Modules\Bodega\Models\Order as BodegaOrder;
use App\Modules\VentasDiseno\Models\PricingSettings;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuoteItem;
use Illuminate\Support\Facades\Log;

/**
 * SCRUM-929 (B3b Punto 3) — construye los `FiscalDocumentItem[]` de una factura para certificar ante
 * cualquier PAC (Digifact/MSL, ver `NucJsonMapper`/los adapters). Es el ÚNICO punto de la capa
 * `FacturacionElectronica` que lee `AdminContInvoice`/`invoice_lines` — el resto (DTOs, mappers,
 * adapters) no conoce el modelo de facturación de AtlanticERP a propósito (ver docblock de `FiscalDocument`).
 * Arma solo los ÍTEMS: ensamblar el `FiscalDocument` completo (emisor/receptor/pagos/referencia,
 * selección de PAC) es un alcance más amplio, sin wiring propio todavía en ningún lado del código
 * (confirmado 2026-09-19 — ni un solo `new FiscalDocument(...)` fuera de los tests de esta capa),
 * fuera del alcance de SCRUM-929.
 *
 * Fuente, en orden (mismo criterio que `Billing\InvoiceTaxBreakdown`, B3b Punto 1):
 *  1. las `invoice_lines` de la factura — una `parcial` solo facturó lo que salió de SU entrega, así
 *     que derivar del ítem de la cotización ENTERA sobrefacturaría ante el PAC, igual que
 *     sobrestimaba el Libro de Facturas antes de B3b Punto 1;
 *  2. si no tiene líneas (facturas legadas, previas a SCRUM-929): los ítems de la cotización
 *     completa, a la tasa ÚNICA de la factura (`itbms_rate_id`) — como facturaba el sistema antes
 *     de este cambio.
 *
 * `precioUnitario` se recalcula SIN redondear (`subtotal / cantidad`) — nunca el `precio_unitario`
 * PERSISTIDO en la línea, que está redondeado a 2 decimales y puede no multiplicar exacto contra
 * `subtotal` (A-4 del ADR) — para que `cantidad × precioUnitario − descuento == subtotal` al
 * centavo: así `NucJsonMapper::mapItems()`/`Totals.TotalItem` cuadra con la base real de la línea.
 * `descuento` siempre viaja en 0: los descuentos (de línea y de totales de la cotización) ya están
 * aplicados DENTRO de `subtotal`, no hay un monto de descuento aparte que declarar.
 *
 * SCRUM-1185 (hotfix) — antes de armar los ítems las líneas pasan por `ItbmsLineBalancer`: si la suma del ITBMS redondeado por
 * línea se aleja más de un centavo del ITBMS exacto, la DGI rechaza el total (2507/2511). El balanceador mueve centavos de a pares entre
 * líneas de la MISMA tasa (el neto total y por tasa no cambian). Solo las líneas que el balanceador modifica llevan el precio unitario
 * redondeado a 6 decimales (`neto ÷ cantidad`); las demás conservan `subtotal ÷ cantidad` tal como siempre, para que las facturas que ya
 * certifican viajen idénticas.
 *
 * La tasa de ITBMS se traduce por `ItbmsCodeMapper`. La tasa "3.5% retención" NO se certifica todavía (guarda de
 * la opción A, 2026-10-05, ver docblock de `ItbmsCodeMapper`): el mapper lanza `UnmappableItbmsRateException` y
 * es responsabilidad del CALLER decidir qué hacer — `FiscalCertificationService::enviar()` la atrapa y deja el
 * documento en `error_configuracion` con el motivo en `fe_detalle`; este builder no la atrapa.
 */
final class FiscalDocumentItemsBuilder
{
    public function __construct(
        private readonly ItbmsCodeMapper $itbmsCodeMapper,
        private readonly ItbmsLineBalancer $balancer,
    ) {
    }

    /**
     * @throws \RuntimeException si la factura no tiene forma de resolver una cotización (ni líneas,
     *         ni `quote_id`, ni `Order::linkedQuote()`) o si una línea quedó sin tasa de ITBMS
     * @throws \App\Modules\AdminCont\FacturacionElectronica\Exceptions\UnmappableItbmsRateException
     *         si alguna línea/la factura usa una tasa de ITBMS sin código DGI (hoy la
     *         de retención 3.5% — ver ItbmsCodeMapper)
     * @return list<FiscalDocumentItem>
     */
    public function build(AdminContInvoice $invoice): array
    {
        return $this->buildConTotal($invoice)[0];
    }

    /**
     * Ídem `build()` y además el total fiscal del documento en centavos (neto + ITBMS redondeado por línea, ya balanceado): es lo que firma
     * el PAC como `dVTot`, y con lo que deben cuadrar los pagos.
     *
     * @return array{0: list<FiscalDocumentItem>, 1: int}
     */
    public function buildConTotal(AdminContInvoice $invoice): array
    {
        $invoice->loadMissing(['lines.itbmsRate', 'lines.quoteItem', 'itbmsRate']);

        $crudas = $invoice->lines->isNotEmpty()
            ? $this->crudasDeLineas($invoice)
            : $this->crudasDeCotizacion($invoice);

        return $this->balancear($crudas, $invoice);
    }

    /** @return list<array{codigo: string, descripcion: string, cantidad: float, subtotal: float, tasaCode: string, bp: int}> */
    private function crudasDeLineas(AdminContInvoice $invoice): array
    {
        // Orden por id: el balanceador es determinista respecto al orden de las líneas y `FiscalTotalAligner` usa el mismo.
        $crudas = $invoice->lines->sortBy('id')->values()->map(function (AdminContInvoiceLine $line) use ($invoice): array {
            $rate = $line->itbmsRate;
            if ($rate === null) {
                throw new \RuntimeException("La línea {$line->id} de la factura {$invoice->numero} no tiene tasa de ITBMS asignada.");
            }
            $quoteItem = $line->quoteItem;

            return [
                'codigo'      => $quoteItem !== null ? (string) $quoteItem->reference : "LINEA-{$line->id}",
                'descripcion' => (string) $line->descripcion,
                'cantidad'    => (float) $line->cantidad,
                'subtotal'    => (float) $line->subtotal,
                'tasaCode'    => $this->itbmsCodeMapper->map($rate->porcentaje),
                'bp'          => (int) round($rate->porcentaje * 100),
            ];
        })->all();

        return array_values($crudas);
    }

    /** @return list<array{codigo: string, descripcion: string, cantidad: float, subtotal: float, tasaCode: string, bp: int}> */
    private function crudasDeCotizacion(AdminContInvoice $invoice): array
    {
        $quote = $invoice->quote_id !== null ? Quote::query()->with('parts.items')->find($invoice->quote_id) : null;
        $quote ??= BodegaOrder::find($invoice->order_id)?->linkedQuote()?->loadMissing('parts.items');
        if ($quote === null) {
            throw new \RuntimeException("La factura {$invoice->numero} no tiene una cotización vinculada — no se pueden armar sus ítems fiscales.");
        }

        $rate          = $invoice->itbmsRate ?? ItbmsRateResolver::defaultRate();
        $tasaItbmsCode = $this->itbmsCodeMapper->map($rate->porcentaje);
        $bp            = (int) round($rate->porcentaje * 100);
        $settings      = PricingSettings::current();

        $crudas = [];
        foreach ($quote->parts as $part) {
            foreach ($part->items as $item) {
                /** @var QuoteItem $item */
                if ($item->quantity <= 0) {
                    continue;
                }

                $crudas[] = [
                    'codigo'      => (string) $item->reference,
                    'descripcion' => (string) $item->description,
                    'cantidad'    => (float) $item->quantity,
                    'subtotal'    => (float) $item->lineTotal($quote->discount_mode, $quote->global_discount_percent, $quote->price_type, $settings),
                    'tasaCode'    => $tasaItbmsCode,
                    'bp'          => $bp,
                ];
            }
        }

        return $crudas;
    }

    /**
     * SCRUM-1185 — balancea los netos en centavos y arma los `FiscalDocumentItem`. Una línea que el balanceador no tocó se arma EXACTAMENTE
     * como antes (`subtotal ÷ cantidad`); una que sí tocó lleva `neto balanceado ÷ cantidad` a 6 decimales (el PAC acepta hasta 6).
     *
     * @param list<array{codigo: string, descripcion: string, cantidad: float, subtotal: float, tasaCode: string, bp: int}> $crudas
     * @return array{0: list<FiscalDocumentItem>, 1: int}
     */
    private function balancear(array $crudas, AdminContInvoice $invoice): array
    {
        $netos     = array_map(static fn (array $c): int => (int) round($c['subtotal'] * 100), $crudas);
        $resultado = $this->balancer->balancear(array_map(
            static fn (array $c, int $neto): array => ['neto' => $neto, 'bp' => $c['bp']],
            $crudas,
            $netos,
        ));

        if ($resultado->cambiado()) {
            Log::info('Facturación electrónica: centavos balanceados entre líneas para cumplir la validación de totales de la DGI', [
                'invoice_id'      => $invoice->id,
                'lineas'          => count($crudas),
                'lineas_movidas'  => count($resultado->modificadas),
                'pares'           => $resultado->pares,
                'delta_antes_ct'  => $resultado->deltaInicial,
                'delta_despues_ct' => $resultado->deltaFinal,
                'dentro_del_limite' => $resultado->dentroDelLimite,
            ]);
        } elseif (! $resultado->dentroDelLimite) {
            Log::warning('Facturación electrónica: la factura excede la tolerancia de totales de la DGI y no se pudo balancear', [
                'invoice_id' => $invoice->id,
                'delta_ct'   => $resultado->deltaInicial,
            ]);
        }

        $items = [];
        foreach ($crudas as $i => $c) {
            $tocada = in_array($i, $resultado->modificadas, true);
            $items[] = new FiscalDocumentItem(
                codigo: $c['codigo'],
                descripcion: $c['descripcion'],
                cantidad: $c['cantidad'],
                precioUnitario: $c['cantidad'] > 0
                    ? ($tocada ? round($resultado->netos[$i] / 100 / $c['cantidad'], 6) : $c['subtotal'] / $c['cantidad'])
                    : 0.0,
                tasaItbmsCode: $c['tasaCode'],
                descuento: 0.0,
            );
        }

        return [$items, $resultado->totalCentavos()];
    }
}
