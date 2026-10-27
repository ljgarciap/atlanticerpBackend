<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services\Billing;

use App\Modules\AdminCont\Exceptions\InvoicePreviewException;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Services\ItbmsRateResolver;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\PricingSettings;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuoteItem;
use App\Modules\VentasDiseno\Models\QuotePart;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * SCRUM-929 (B2, ver docs/adr/ADR-SCRUM929-facturacion-parcial-o-completa.md §2/§3) — calcula qué se
 * factura y aplica los guards anti-duplicado. NO persiste nada ni toma locks: el llamador
 * (`InvoiceService::confirm()`) lo invoca dentro de su transacción, con la fila raíz ya bloqueada, y
 * es él quien crea la factura y sus líneas. Toda violación se lanza como `InvoicePreviewException`
 * con un mensaje claro en español — el rechazo real es del backend, la UI solo oculta opciones.
 *
 * @phpstan-import-type PlanLine from InvoicePlan
 *
 * Invariante que sostiene todo el diseño: Σ facturas activas <= total de la cotización, y = total al
 * centavo cuando ya se cerró la última cantidad sin facturar (la factura que cierra absorbe el
 * residual de redondeo), sin cobrar nunca mercadería que no salió.
 */
final class InvoiceBillingPlanner
{
    /** Etapas de Bodega desde las que una entrega puede facturarse — antes de `por_despachar` el
     *  pedido sigue armándose y no debe poder facturarse aunque tenga cotización/monto/cliente. */
    public const FACTURABLE_STAGES = [Order::STAGE_POR_DESPACHAR, Order::STAGE_DESPACHADO, Order::STAGE_ENTREGADO];

    /** ¿La entrega está en una etapa desde la que se puede facturar? Exige `FACTURABLE_STAGES`. */
    public static function etapaFacturable(Order $order): bool
    {
        return in_array($order->stage, self::FACTURABLE_STAGES, true);
    }

    /**
     * Misma regla que `etapaFacturable()` como filtro de consulta.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     * @param \Illuminate\Database\Eloquent\Builder<TModel> $query
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    public static function whereEtapaFacturable($query)
    {
        return $query->whereIn('stage', self::FACTURABLE_STAGES);
    }

    /** Épsilon numérico (NO un umbral de negocio): medio centavo, la mitad de la resolución de los
     *  importes, que se redondean a 2 decimales. Absorbe el ruido de punto flotante al comparar. */
    public const EPSILON = 0.005;

    /** F1 (Senior Review A-1) — el saldo por líneas no se puede calcular contra otra versión de la cotización. */
    public const MSG_COTIZACION_CAMBIO = 'La cotización del pedido cambió de versión después de facturar parcialmente; el saldo no se puede calcular automáticamente. Contacte a Administración.';

    /** Épsilon numérico de cantidades: la mitad de la resolución de `invoice_lines.cantidad` (3 dec.). */
    public const QTY_EPSILON = 0.0005;

    /**
     * Construye el contexto del pedido al que pertenece `$order`. `$members`/`$active` se pueden
     * pasar ya cargados (el listado los trae en lote para no hacer N+1 por pedido).
     *
     * @param Collection<int, Order>|null            $members
     * @param Collection<int, AdminContInvoice>|null $active
     */
    public function context(Order $order, ?Collection $members = null, ?Collection $active = null): BillingContext
    {
        $root  = $order->familyRoot();
        $card  = $root->pipelineCard;
        $quote = $root->linkedQuote();
        $quote?->loadMissing('parts.items');

        $members ??= Order::query()
            ->where(fn ($q) => $q->where('id', $root->id)->orWhere('family_id', $root->id))
            ->with('items')
            ->orderByRaw('COALESCE(sequence_in_family, 1)')
            ->orderBy('id')
            ->get();

        $active ??= AdminContInvoice::forOrder($root)->whereNull('anulada_at')->with('lines')->orderBy('id')->get();

        /** @var Collection<int, QuoteItem> $quoteItems */
        $quoteItems = $quote === null
            ? new Collection()
            : $quote->parts->flatMap(fn (QuotePart $part) => $part->items)->keyBy('id');

        $orderItems = $members->flatMap(fn (Order $o) => $o->items);
        $linkable   = $orderItems->isNotEmpty() && $orderItems->every(
            fn ($item): bool => $item->quote_item_id !== null && $quoteItems->has($item->quote_item_id),
        );

        // SCRUM-929 (B3b Punto 4) — Σ de los ajustes que dejaron las correcciones de ITBMS sobre
        // facturas `parcial` de este pedido (ver `CreditNoteService::executeCorreccion()`). El total
        // contra el que se guarda/cierra deja de ser `card->amount` a secas.
        $ajusteItbms = round((float) $active->sum('ajuste_itbms_delta'), 2);

        return new BillingContext(
            root: $root,
            quote: $quote,
            total: round($this->totalBase($root, $quote) + $ajusteItbms, 2),
            members: $members,
            active: $active,
            quoteItems: $quoteItems,
            linkable: $linkable,
            ajusteItbms: $ajusteItbms,
        );
    }

    /** Total facturable del pedido antes de los ajustes de ITBMS: monto de la tarjeta del pipeline. */
    private function totalBase(Order $root, ?Quote $quote): float
    {
        return (float) ($root->pipelineCard->amount ?? 0);
    }

    /**
     * Contexto "liviano" para los listados: sin cargar los ítems de la cotización (no calcula
     * importes), con `linkable` resuelto contra un mapa `quote_item_id => quote_id` traído en lote.
     * Sirve para decidir la forma de las filas, NO para `planEntrega()`/`planCompleto()` — esos
     * exigen `context()`. Los pedidos de entrega única no necesitan el vínculo (`linkable = false`).
     *
     * @param Collection<int, Order>            $members
     * @param Collection<int, AdminContInvoice> $active
     * @param array<int, int>                   $quoteIdByQuoteItem
     */
    public function lightContext(Order $root, Collection $members, Collection $active, ?Quote $quote, array $quoteIdByQuoteItem): BillingContext
    {
        $linkable = false;
        if ($members->count() > 1 && $quote !== null) {
            $orderItems = $members->flatMap(fn (Order $o) => $o->items);
            $linkable   = $orderItems->isNotEmpty() && $orderItems->every(
                fn ($item): bool => $item->quote_item_id !== null && ($quoteIdByQuoteItem[$item->quote_item_id] ?? null) === $quote->id,
            );
        }

        // SCRUM-929 (B3b Punto 4) — mismo ajuste que `context()`: sin esto, "monto_total_pedido"/
        // "restante" del listado quedarían desincronizados del monto real que calcula `planEntrega()`/
        // `planCompleto()` (vía `context()`) para ese mismo pedido.
        $ajusteItbms = round((float) $active->sum('ajuste_itbms_delta'), 2);

        return new BillingContext(
            root: $root,
            quote: $quote,
            total: round($this->totalBase($root, $quote) + $ajusteItbms, 2),
            members: $members,
            active: $active,
            quoteItems: new Collection(),
            linkable: $linkable,
            ajusteItbms: $ajusteItbms,
        );
    }

    /**
     * Plan de facturar UNA entrega (`tipo = parcial`): cantidad = `qty_delivered` de sus `OrderItem`,
     * enlazados a la cotización por `quote_item_id`. Guards ADR §3 (1), (2), (3), (4) y (5).
     *
     * `$registrarAvisos`: solo `confirm()` lo pasa en true — el `Log::warning` de un cierre con
     * advertencias se escribe al EMITIR, nunca al leer (listados, previews, `completoDisponible()`).
     *
     * @throws InvoicePreviewException
     */
    public function planEntrega(BillingContext $ctx, Order $entrega, bool $registrarAvisos = false): InvoicePlan
    {
        if (! $ctx->linkable) {
            throw new InvoicePreviewException([
                'Este pedido solo admite facturación de pedido completo: sus productos no están vinculados a la cotización.',
            ]);
        }
        $this->assertFacturadoVigente($ctx);
        if ($ctx->entrega($entrega->id) === null) {
            throw new InvoicePreviewException(['La entrega no pertenece a este pedido.']);
        }
        // (5) etapa facturable
        if (! self::etapaFacturable($entrega)) {
            throw new InvoicePreviewException(['Esta entrega todavía no llegó a la etapa "Por despachar" de Bodega.']);
        }
        // (1) ningún completo activo
        if ($ctx->completoActivo() !== null) {
            throw new InvoicePreviewException(['Este pedido ya tiene una factura generada.']);
        }
        // (2) la entrega no tiene parcial activa
        if ($ctx->parcialActivaDe($entrega->id) !== null) {
            throw new InvoicePreviewException(['Esta entrega ya tiene una factura generada.']);
        }

        /** @var array<int, float> $qtyByItem */
        $qtyByItem = [];
        /** @var array<int, int> $orderItemByItem */
        $orderItemByItem = [];
        foreach ($entrega->items as $item) {
            if ($item->qty_delivered <= 0 || $item->quote_item_id === null) {
                continue;
            }
            $qtyByItem[$item->quote_item_id]       = ($qtyByItem[$item->quote_item_id] ?? 0.0) + $item->qty_delivered;
            $orderItemByItem[$item->quote_item_id] ??= $item->id;
        }
        if ($qtyByItem === []) {
            throw new InvoicePreviewException(['Esta entrega no tiene cantidades entregadas para facturar.']);
        }

        $billed = $ctx->cantidadFacturada();
        foreach ($qtyByItem as $quoteItemId => $qty) {
            /** @var QuoteItem $quoteItem */
            $quoteItem = $ctx->quoteItems->get($quoteItemId);
            // (4) tope por ítem
            if (($billed[$quoteItemId] ?? 0.0) + $qty > $quoteItem->quantity + self::QTY_EPSILON) {
                throw new InvoicePreviewException([sprintf(
                    'El ítem "%s" excedería lo cotizado (%s de %s unidades ya facturadas o por facturar).',
                    $quoteItem->description,
                    $this->fmtQty(($billed[$quoteItemId] ?? 0.0) + $qty),
                    $this->fmtQty($quoteItem->quantity),
                )]);
            }
        }

        return $this->build($ctx, AdminContInvoice::TIPO_PARCIAL, $entrega->id, $qtyByItem, $orderItemByItem, $registrarAvisos);
    }

    /**
     * Plan de facturar el PEDIDO entero (`tipo = completo`): por cada ítem cotizado, lo que todavía
     * no se facturó (cantidad cotizada − facturada), aunque parte no se haya alistado (D-2). Sin
     * facturas previas es el total `card->amount`. Nunca excede el total de la cotización.
     *
     * @throws InvoicePreviewException
     */
    public function planCompleto(BillingContext $ctx, bool $registrarAvisos = false): InvoicePlan
    {
        // (1) ningún completo activo
        if ($ctx->completoActivo() !== null) {
            throw new InvoicePreviewException(['Este pedido ya tiene una factura generada.']);
        }
        if ($ctx->restante() <= self::EPSILON) {
            throw new InvoicePreviewException(['No queda saldo por facturar en este pedido.']);
        }
        $this->assertFacturadoVigente($ctx);

        $billed = $ctx->cantidadFacturada();
        /** @var array<int, float> $qtyByItem */
        $qtyByItem = [];
        foreach ($ctx->quoteItems as $quoteItem) {
            $remaining = $quoteItem->quantity - ($billed[$quoteItem->id] ?? 0.0);
            if ($quoteItem->quantity > 0 && $remaining > self::QTY_EPSILON) {
                $qtyByItem[$quoteItem->id] = $remaining;
            }
        }
        if ($qtyByItem === [] && $ctx->quoteItems->isNotEmpty()) {
            throw new InvoicePreviewException(['Todas las cantidades de la cotización ya están facturadas.']);
        }

        return $this->build($ctx, AdminContInvoice::TIPO_COMPLETO, $ctx->root->id, $qtyByItem, [], $registrarAvisos);
    }

    /**
     * F1 — las líneas de las facturas activas previas deben apuntar a ítems de la cotización VIGENTE.
     * Si no (la cotización se re-versionó después de facturar, o se borró un `QuoteItem`), lo ya
     * facturado no se puede descontar de la cantidad cotizada y el saldo por líneas saldría inventado
     * (incluso con líneas de total negativo): se rechaza en vez de emitir un snapshot incorrecto. Un
     * pedido re-versionado SIN facturas previas no pasa por acá y se factura normal.
     *
     * @throws InvoicePreviewException
     */
    private function assertFacturadoVigente(BillingContext $ctx): void
    {
        foreach (array_keys($ctx->cantidadFacturada()) as $quoteItemId) {
            if (! $ctx->quoteItems->has($quoteItemId)) {
                throw new InvoicePreviewException([self::MSG_COTIZACION_CAMBIO]);
            }
        }
    }

    /**
     * Arma líneas + monto y aplica los guards (3) de saldo. La factura que CIERRA la última cantidad
     * sin facturar de todos los ítems toma `monto = total − Σ activas` y reparte por separado la
     * base y el ITBMS que le faltan para que Σ facturas = cotización al centavo (ADR §2, F7).
     *
     * @param array<int, float> $qtyByItem      quote_item_id => cantidad a facturar
     * @param array<int, int>   $orderItemByItem quote_item_id => order_item_id (solo trazabilidad)
     *
     * @throws InvoicePreviewException
     */
    private function build(BillingContext $ctx, string $tipo, int $orderId, array $qtyByItem, array $orderItemByItem, bool $registrarAvisos): InvoicePlan
    {
        $lines    = $this->buildLines($ctx, $qtyByItem, $orderItemByItem);
        $cierra   = $this->cierraTodo($ctx, $qtyByItem);
        $restante = $ctx->restante();
        $advertencias = [];

        if ($lines === []) {
            // Cotización sin ítems (dato legado): no hay desglose posible, solo el total. Únicamente
            // el modo completo llega acá (la entrega exige líneas), y factura todo el saldo.
            $monto = $restante;
        } elseif ($cierra) {
            $monto = $restante;
            [$lines, $advertencias] = $this->cerrarPedido($ctx, $lines, $monto, $registrarAvisos);
        } else {
            $monto = round((float) array_sum(array_column($lines, 'total')), 2);
        }

        // (3) Σ activas + nuevo <= total. En el cierre `monto = restante`, así que esto solo puede
        // fallar cuando ya no hay saldo real.
        if ($monto <= self::EPSILON) {
            throw new InvoicePreviewException(['No queda saldo por facturar en este pedido.']);
        }
        if ($ctx->facturado() + $monto > $ctx->total + self::EPSILON) {
            throw new InvoicePreviewException([sprintf(
                'El monto de esta factura (%s) supera el saldo por facturar del pedido (%s).',
                number_format($monto, 2),
                number_format($restante, 2),
            )]);
        }

        return new InvoicePlan(
            tipo: $tipo,
            orderId: $orderId,
            familyRootId: $ctx->root->id,
            monto: $monto,
            lines: $lines,
            cierraPedido: $cierra,
            restanteDespues: max(0.0, round($restante - $monto, 2)),
            advertencias: $advertencias,
        );
    }

    /**
     * Línea = importe del ítem cotizado (`lineTotal`, ya con descuento de línea/global) prorrateado por
     * `cantidad / cantidad_cotizada`, más la parte proporcional del descuento de TOTALES de la
     * cotización (factor `netTotal / subtotal`), con ITBMS a la tasa vigente. Redondeo a 2 decimales
     * por línea. Cuando la cantidad es la cotizada completa no se prorratea (evita ruido de división).
     *
     * @param array<int, float> $qtyByItem
     * @param array<int, int>   $orderItemByItem
     * @return list<PlanLine>
     */
    private function buildLines(BillingContext $ctx, array $qtyByItem, array $orderItemByItem): array
    {
        $quote = $ctx->quote;
        if ($quote === null || $qtyByItem === []) {
            return [];
        }

        $settings = PricingSettings::current();
        $subtotal = $quote->subtotal();
        $factor   = $subtotal > 0 ? $quote->netTotal() / $subtotal : 1.0;
        // SCRUM-1166 — 0 si la cotización es de un cliente exento.
        $itbmsPct = $quote->itbmsPercentage();

        $lines = [];
        foreach ($qtyByItem as $quoteItemId => $qty) {
            /** @var QuoteItem $quoteItem */
            $quoteItem = $ctx->quoteItems->get($quoteItemId);
            $quoted    = $quoteItem->quantity;
            $fraction  = $qty >= $quoted - self::QTY_EPSILON ? 1.0 : $qty / $quoted;

            $lineTotal = $quoteItem->lineTotal($quote->discount_mode, $quote->global_discount_percent, $quote->price_type, $settings);
            $bruto     = round($lineTotal * $fraction, 2);
            $base      = round($lineTotal * $factor * $fraction, 2);
            $itbms     = round($base * $itbmsPct / 100, 2);

            $lines[] = [
                'quote_item_id'   => $quoteItemId,
                'order_item_id'   => $orderItemByItem[$quoteItemId] ?? null,
                'descripcion'     => $quoteItem->description,
                'cantidad'        => round($qty, 3),
                'precio_unitario' => $qty > 0 ? round($base / $qty, 2) : 0.0,
                'subtotal'        => $base,
                'itbms'           => $itbms,
                'total'           => round($base + $itbms, 2),
                'bruto'           => $bruto,
            ];
        }

        return $lines;
    }

    /**
     * ¿Con esta factura queda facturada TODA la cantidad cotizada de todos los ítems? Solo entonces
     * la factura absorbe el residual de redondeo — y solo entonces es legítimo cobrar "lo que falta
     * del total": si algo nunca se despacha/factura, no hay residual que cobrar sobre eso.
     *
     * @param array<int, float> $qtyByItem
     */
    private function cierraTodo(BillingContext $ctx, array $qtyByItem): bool
    {
        if ($ctx->quoteItems->isEmpty()) {
            return true;
        }

        $billed = $ctx->cantidadFacturada();
        foreach ($ctx->quoteItems as $quoteItem) {
            if ($quoteItem->quantity <= 0) {
                continue;
            }
            $after = ($billed[$quoteItem->id] ?? 0.0) + ($qtyByItem[$quoteItem->id] ?? 0.0);
            if ($after < $quoteItem->quantity - self::QTY_EPSILON) {
                return false;
            }
        }

        return true;
    }

    /**
     * F7 — la factura que CIERRA el pedido reparte el residual por SEPARADO en base e ITBMS, de modo
     * que Σ base y Σ ITBMS de TODAS las facturas del pedido == base e ITBMS de la cotización al centavo
     * (`fiscalBreakdown`, ajustado por `ajusteItbms` si hubo correcciones — ver B3b Punto 4), y el total
     * == `ctx->total` (`card->amount + ajusteItbms`, ver `context()`): lo que le falta a esta factura es
     * `cotización − Σ previas` (calculado desde las `invoice_lines` de las facturas activas), y esa
     * diferencia con lo proporcional se vuelca a la ÚLTIMA línea (base a su `subtotal`, ITBMS a su
     * `itbms`). En un pedido de entrega única (o un completo sin facturas previas) el desglose sale
     * idéntico al de la cotización.
     *
     * Si además `ctx->total` no coincide con base + ITBMS de la cotización recalculada hoy (típico:
     * cambió la tasa de ITBMS o `PricingSettings` después de aprobar), se sigue facturando
     * `ctx->total` (fuente de verdad del total, decisión C-7, ajustada por `ajusteItbms`) y esa diferencia
     * se absorbe en la base de la última línea — pero ya no en silencio: queda en `advertencias` (F6),
     * además del log. Cualquier diferencia de base/ITBMS mayor que el ruido de redondeo (≤ medio centavo
     * por línea de todo el pedido, más el redondeo propio de la cotización) también se advierte.
     *
     * @param list<PlanLine> $lines
     * @return array{0: list<PlanLine>, 1: list<string>}
     */
    private function cerrarPedido(BillingContext $ctx, array $lines, float $monto, bool $registrarAvisos): array
    {
        $quote = $ctx->quote;
        if ($quote === null || $lines === []) {
            return [$lines, []];
        }

        $advertencias = [];
        $prevBase = $prevItbms = 0.0;
        $prevLineas = 0;
        $prevSinLineas = false;
        foreach ($ctx->active as $invoice) {
            if ($invoice->lines->isEmpty()) {
                $prevSinLineas = true;
                continue;
            }
            $prevBase   += (float) $invoice->lines->sum('subtotal');
            $prevItbms  += (float) $invoice->lines->sum('itbms');
            $prevLineas += $invoice->lines->count();
        }

        $sumBase  = round((float) array_sum(array_column($lines, 'subtotal')), 2);
        $sumItbms = round((float) array_sum(array_column($lines, 'itbms')), 2);

        if ($prevSinLineas) {
            // Una factura activa previa sin líneas no permite saber cuánta base/ITBMS ya se facturó
            // (p. ej. una factura hecha a mano): no se puede repartir por separado, todo el ajuste va a la base.
            $dBase  = round($monto - $sumBase - $sumItbms, 2);
            $dItbms = 0.0;
            $advertencias[] = 'Hay facturas previas de este pedido sin detalle de líneas: el desglose de base e '.\App\Shared\Multitenancy\TenantRegion::taxLabel().' de esta factura no se pudo cuadrar contra la cotización.';
        } else {
            // SCRUM-929 (B3b Punto 4) — el objetivo de ITBMS también se ajusta por `ajusteItbms`: sin
            // esto, una corrección de tasa sobre una parcial (que YA cambió `prevItbms`) se veía como
            // un "descuadre contra la cotización" y este reparto intentaba devolver la diferencia a
            // las líneas de la factura que cierra — exactamente el recobro en silencio que el ajuste
            // de `total` (arriba) por sí solo no evitaba, porque este reparto opera a nivel de LÍNEAS,
            // no de `monto`.
            $dBase  = round(round($quote->netTotal(), 2) - $prevBase - $sumBase, 2);
            $dItbms = round(round($quote->itbms(), 2) + $ctx->ajusteItbms - $prevItbms - $sumItbms, 2);

            $ruido = self::EPSILON * (count($lines) + $prevLineas + 1);
            if (abs($dBase) > $ruido || abs($dItbms) > $ruido) {
                $advertencias[] = sprintf(
                    'La base o el impuesto de esta factura difieren del cálculo de la cotización en más de lo que explica el redondeo (base %s, impuesto %s).',
                    number_format($dBase, 2),
                    number_format($dItbms, 2),
                );
            }
        }

        // N-1 — el ajuste se reparte SIN dejar ninguna línea con base o ITBMS negativos (ver InvoiceLineMath).
        [$lines, $sobranteBase]  = InvoiceLineMath::repartir($lines, 'subtotal', $dBase);
        [$lines, $sobranteItbms] = InvoiceLineMath::repartir($lines, 'itbms', $dItbms);

        // El total facturado siempre es `ctx->total − Σ previas` (`ctx->total` = `card->amount +
        // ajusteItbms`): lo que quede de diferencia es un descuadre real entre la tarjeta y la
        // cotización, no redondeo.
        $drift = round($monto - (float) array_sum(array_column($lines, 'total')), 2);
        if (abs($drift) >= self::EPSILON) {
            $advertencias[] = sprintf(
                'El total del pedido (%s) no coincide con la cotización recalculada hoy; la diferencia de %s se absorbió en la base de las líneas. Revisar la tasa de impuesto y los precios configurados.',
                number_format($ctx->total, 2),
                number_format($drift, 2),
            );
            [$lines, $sobranteDrift] = InvoiceLineMath::repartir($lines, 'subtotal', $drift);
            $sobranteBase += $sobranteDrift;
        }
        if ($sobranteBase >= self::EPSILON || $sobranteItbms >= self::EPSILON) {
            $advertencias[] = 'El ajuste no se pudo repartir sin dejar una línea con importe negativo: revisar la factura antes de enviarla al PAC.';
        }

        if ($registrarAvisos && $advertencias !== []) {
            Log::warning('admin_cont.invoice.cierre_con_advertencias', ['advertencias' => $advertencias, 'monto' => $monto, 'lineas' => count($lines)]);
        }

        return [$lines, $advertencias];
    }

    private function fmtQty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
    }
}
