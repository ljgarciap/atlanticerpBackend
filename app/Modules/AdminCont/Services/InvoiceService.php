<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Models\User;
use App\Modules\AdminCont\Events\InvoiceCreated;
use App\Modules\AdminCont\Exceptions\InvoicePreviewException;
use App\Modules\AdminCont\Exceptions\InvoiceStateException;
use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentInvoice;
use App\Modules\AdminCont\Support\ReportPeriodResolver;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderDocument;
use App\Modules\VentasDiseno\Models\PricingSettings;
use App\Modules\VentasDiseno\Models\Quote;
use App\Shared\Multitenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Batch 2 de Facturación (SCRUM-513→518, REQ-436→441). "Entrega" = `Bodega\Models\Order` — no
 * existe una tabla `entrega` propia, ver diseño del Arquitecto en la sesión que aprobó este
 * batch. `PipelineCard::amount` (no `Quote`) es la fuente de "monto": `Quote` no expone un
 * `total()` simple en este batch (solo `subtotal()`/`discountTotalsAmount()` con partidas), y
 * `PipelineCard.amount` ya es el valor de negocio del proyecto usado en el resto del sistema.
 *
 * Interpretación de los 3 estados de la REQ-437 (reconciliación con REQ-441 RN1 — decisión del
 * Arquitecto, ver diseño aprobado): "pendiente-entrega" = el Order todavía no llega a
 * `por_despachar` (Bodega sigue armándolo); "pendiente-facturar" = `por_despachar` sin factura
 * (el punto exacto donde `DeliveryService::blockingReasonsForDispatch()` lo bloquea);
 * "facturada" = existe `AdminContInvoice` para ese Order.
 */
final class InvoiceService
{
    /** Etapas de Bodega desde las que una entrega puede facturarse — antes de `por_despachar` el
     *  pedido sigue armándose y no debe poder facturarse aunque tenga cotización/monto/cliente. */
    private const FACTURABLE_STAGES = [Order::STAGE_POR_DESPACHAR, Order::STAGE_DESPACHADO, Order::STAGE_ENTREGADO];

    public function __construct(
        private readonly AdminContInvoiceFolioService $folio,
        private readonly CreditBalanceService $creditBalance,
    ) {}

    /** Cantidad de entregas en la pestaña `$tab` SIN ningún otro filtro — denominador real de
     *  "Mostrando X de Y" (REQ-445 RN4), independiente de búsqueda/cliente/proyecto/estado/fechas. */
    public function countInTab(string $tab): int
    {
        return count($this->list(['tab' => $tab, 'view' => 'plana']));
    }

    /** @return array{pendientes_entrega: int, pendientes_facturar: int, facturadas_mes: int, monto_facturado_mes: float} */
    public function summary(): array
    {
        $facturablesStages = self::FACTURABLE_STAGES;

        $pendientesEntrega = Order::query()->whereNotIn('stage', $facturablesStages)->count();

        $invoicedOrderIds = AdminContInvoice::query()->pluck('order_id');

        $pendientesFacturar = Order::query()
            ->where('stage', Order::STAGE_POR_DESPACHAR)
            ->whereNotIn('id', $invoicedOrderIds)
            ->count();

        $monthStart = now()->startOfMonth();
        $monthEnd   = now()->endOfMonth();

        $facturadasMes = AdminContInvoice::query()->whereBetween('created_at', [$monthStart, $monthEnd]);

        return [
            'pendientes_entrega'   => $pendientesEntrega,
            'pendientes_facturar'  => $pendientesFacturar,
            'facturadas_mes'       => (clone $facturadasMes)->count(),
            'monto_facturado_mes'  => (float) (clone $facturadasMes)->sum('monto'),
        ];
    }

    /**
     * @param array{tab?: string, view?: string, cliente?: string|null, proyecto?: string|null, estado?: string|null, desde?: string|null, hasta?: string|null, search?: string|null} $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $filters): array
    {
        $tab = $filters['tab'] ?? 'cobrable';

        $orders = Order::query()
            ->whereIn('stage', self::FACTURABLE_STAGES)
            ->with('pipelineCard.masterClient', 'pipelineCard.subClient', 'pipelineCard.salesProject')
            ->get();

        // Batch 12 — desde "Corrección de datos" puede haber 2 filas por `order_id` (la anulada +
        // su reemplazo); `orderBy('id')` asegura que `keyBy()` (que se queda con la ÚLTIMA
        // ocurrencia de cada key) termine quedándose con la más reciente, nunca con la anulada.
        $invoicesByOrder = AdminContInvoice::query()->orderBy('id')->get()->keyBy('order_id');

        $rows = [];
        foreach ($orders as $order) {
            /** @var AdminContInvoice|null $invoice */
            $invoice      = $invoicesByOrder->get($order->id);
            $esIncobrable = $invoice->es_incobrable ?? false;

            if ($tab === 'incobrable' && ! $esIncobrable) {
                continue;
            }
            if ($tab === 'cobrable' && $esIncobrable) {
                continue;
            }

            $card  = $order->pipelineCard;
            $quote = $order->linkedQuote();

            $row = [
                'order_id'         => $order->id,
                'order_number'     => $order->order_number,
                'sales_project_id' => $card?->sales_project_id,
                'proyecto'         => $card?->salesProject?->name,
                'master_client_id' => $card?->master_client_id,
                'cliente'          => $card?->subClient->business_name ?? $card?->masterClient->name ?? $order->customer_name,
                'cotizacion_folio' => $quote?->folio,
                'monto'            => $card?->amount,
                // Batch 4 (REQ-448/449) — Pre-QA encontró que esta proyección de fila nunca
                // consideraba `anulada_at` ni `incobrable_pendiente`, pese a que `detail()` ya los
                // exponía: la tabla principal mostraba "Facturada" para una propuesta de
                // incobrable pendiente (viola RN3 de REQ-448, "visible en la fila con una pastilla
                // de estado distinta") y para una factura anulada.
                'estado'           => match (true) {
                    $invoice === null              => 'pendiente-facturar',
                    $invoice->anulada_at !== null   => 'anulada',
                    default                          => 'facturada',
                },
                'numero_factura'   => $invoice?->numero,
                'es_incobrable'    => $esIncobrable,
                'incobrable_pendiente' => $invoice->incobrable_pendiente ?? false,
            ];

            if (($filters['cliente'] ?? null) !== null
                && ! str_contains(mb_strtolower((string) $row['cliente']), mb_strtolower((string) $filters['cliente']))) {
                continue;
            }
            if (($filters['proyecto'] ?? null) !== null
                && ! str_contains(mb_strtolower((string) $row['proyecto']), mb_strtolower((string) $filters['proyecto']))) {
                continue;
            }
            if (($filters['estado'] ?? null) !== null && $row['estado'] !== $filters['estado']) {
                continue;
            }
            // desde/hasta solo excluyen filas ya facturadas fuera del rango — una entrega
            // pendiente-facturar todavía no tiene fecha de factura, así que un filtro de fecha no
            // la descarta (default inclusivo, no hay "fecha" propia que comparar).
            if ($invoice !== null && ($filters['desde'] ?? null) !== null && $invoice->created_at->lt($filters['desde'])) {
                continue;
            }
            if ($invoice !== null && ($filters['hasta'] ?? null) !== null && $invoice->created_at->gt($filters['hasta'])) {
                continue;
            }
            if (($filters['search'] ?? null) !== null) {
                $needle    = mb_strtolower((string) $filters['search']);
                $haystack  = mb_strtolower(implode(' ', [$row['cliente'], (string) $row['proyecto'], $row['order_number'], (string) $row['numero_factura']]));
                if (! str_contains($haystack, $needle)) {
                    continue;
                }
            }

            $rows[] = $row;
        }

        if (($filters['view'] ?? 'agrupado') === 'plana') {
            return $rows;
        }

        $grouped = [];
        foreach ($rows as $row) {
            $key = $row['sales_project_id'] ?? 'sin-proyecto';
            $grouped[$key]['sales_project_id'] ??= $row['sales_project_id'];
            $grouped[$key]['proyecto'] ??= $row['proyecto'];
            $grouped[$key]['cliente'] ??= $row['cliente'];
            $grouped[$key]['master_client_id'] ??= $row['master_client_id'];
            $grouped[$key]['entregas'][] = $row;
        }

        return array_values($grouped);
    }

    /** @return list<array<string, mixed>> entregas con cotización+monto+cliente completos, sin factura, del proyecto dado */
    public function pendingForProject(int $salesProjectId): array
    {
        $invoicedOrderIds = AdminContInvoice::query()->pluck('order_id');

        $orders = Order::query()
            ->where('stage', Order::STAGE_POR_DESPACHAR)
            ->whereNotIn('id', $invoicedOrderIds)
            ->with('pipelineCard.masterClient', 'pipelineCard.subClient')
            ->whereHas('pipelineCard', fn ($q) => $q->where('sales_project_id', $salesProjectId))
            ->get()
            ->map(fn (Order $order): array => $this->previewPayload($order))
            ->all();

        return array_values($orders);
    }

    /** @return array<string, mixed> */
    public function preview(Order $order): array
    {
        $missing = $this->missingRequirements($order);
        if ($missing !== []) {
            throw new InvoicePreviewException($missing);
        }

        return $this->previewPayload($order);
    }

    /**
     * Batch 3 (REQ-442/443) — vista previa de 1..N entregas a la vez. RN3: el saldo a favor solo
     * se ofrece aplicar sobre `order_ids[0]` — el resto del lote nunca lo muestra, aunque el
     * cliente tenga saldo disponible.
     *
     * @param list<int> $orderIds
     * @return array{results: list<array<string, mixed>>, aviso_saldo_solo_primera: bool}
     */
    public function previewBatch(array $orderIds, bool $aplicarSaldoFavor): array
    {
        $results               = [];
        $avisoSaldoSoloPrimera = false;

        foreach ($orderIds as $index => $orderId) {
            $order = Order::find((int) $orderId);
            if ($order === null) {
                $results[] = ['order_id' => $orderId, 'ok' => false, 'missing' => ['Entrega no encontrada']];
                continue;
            }

            try {
                $payload = ['order_id' => $orderId, 'ok' => true] + $this->preview($order);
            } catch (InvoicePreviewException $e) {
                $results[] = ['order_id' => $orderId, 'ok' => false, 'missing' => $e->missing()];
                continue;
            }

            $masterClientId = $order->pipelineCard?->master_client_id;

            if ($index === 0 && $masterClientId !== null) {
                $saldoDisponible = $this->creditBalance->forMasterClient($masterClientId);
                $saldoAplicable  = $aplicarSaldoFavor ? min($saldoDisponible, (float) ($payload['monto'] ?? 0)) : 0.0;

                $payload['saldo_disponible'] = $saldoDisponible;
                $payload['saldo_aplicable']  = round($saldoAplicable, 2);
                $payload['total_a_pagar']    = round((float) ($payload['monto'] ?? 0) - $saldoAplicable, 2);

                if (count($orderIds) > 1 && $saldoDisponible > 0) {
                    $avisoSaldoSoloPrimera = true;
                }
            } else {
                $payload['saldo_disponible'] = 0.0;
                $payload['saldo_aplicable']  = 0.0;
                $payload['total_a_pagar']    = $payload['monto'] ?? 0;
            }

            $results[] = $payload;
        }

        return ['results' => $results, 'aviso_saldo_solo_primera' => $avisoSaldoSoloPrimera];
    }

    public function confirm(Order $order, User $actor, bool $aplicarSaldoFavor = false): AdminContInvoice
    {
        return DB::transaction(function () use ($order, $actor, $aplicarSaldoFavor): AdminContInvoice {
            /** @var Order $locked */
            $locked = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            $missing = $this->missingRequirements($locked);
            if ($missing !== []) {
                throw new InvoicePreviewException($missing);
            }

            if (AdminContInvoice::where('order_id', $locked->id)->whereNull('anulada_at')->exists()) {
                throw new InvoicePreviewException(['Esta entrega ya tiene una factura generada.']);
            }

            $card  = $locked->pipelineCard;
            $quote = $locked->linkedQuote();
            $monto = (float) ($card->amount ?? 0);

            $saldoAplicado = 0.0;
            if ($aplicarSaldoFavor && $card?->master_client_id !== null) {
                $saldoAplicado = min($this->creditBalance->forMasterClient($card->master_client_id), $monto);
            }

            $invoice = AdminContInvoice::create([
                'numero'            => $this->folio->next(),
                'order_id'          => $locked->id,
                'quote_id'          => $quote?->id,
                'itbms_rate_id'     => ItbmsRateResolver::defaultRate()->id,
                'sales_project_id'  => $card?->sales_project_id,
                'master_client_id'  => $card?->master_client_id,
                'cliente'           => $card?->subClient->business_name ?? $card?->masterClient->name ?? $locked->customer_name,
                'monto'             => $monto,
                'saldo_aplicado'    => round($saldoAplicado, 2),
                'created_by'        => $actor->id,
            ]);

            if ($saldoAplicado > 0 && $card?->master_client_id !== null) {
                $this->creditBalance->apply($card->master_client_id, $saldoAplicado);
            }

            $invoice->setAttribute('total_a_pagar', round($monto - $saldoAplicado, 2));

            Event::dispatch(new InvoiceCreated(
                invoiceId: $invoice->id,
                orderId: $locked->id,
                numero: $invoice->numero,
                tenant: Tenant::current()->slug ?? '',
            ));

            return $invoice;
        });
    }

    /**
     * Batch 3 (REQ-442 RN3) — confirma 1..N entregas de una vez; el saldo a favor solo se aplica
     * sobre `order_ids[0]`.
     *
     * @param list<int> $orderIds
     * @return array{invoices: list<AdminContInvoice>, errors: list<array<string, mixed>>}
     */
    public function confirmBatch(array $orderIds, User $actor, bool $aplicarSaldoFavor): array
    {
        $created = [];
        $errors  = [];

        foreach ($orderIds as $index => $orderId) {
            $order = Order::find((int) $orderId);
            if ($order === null) {
                $errors[] = ['order_id' => $orderId, 'missing' => ['Entrega no encontrada']];
                continue;
            }

            try {
                $created[] = $this->confirm($order, $actor, $aplicarSaldoFavor && $index === 0);
            } catch (InvoicePreviewException $e) {
                $errors[] = ['order_id' => $orderId, 'missing' => $e->missing()];
            }
        }

        return ['invoices' => $created, 'errors' => $errors];
    }

    /**
     * Batch 4 (REQ-447) — payload completo del modal de detalle/trazabilidad de una entrega.
     * Trazabilidad en el orden correcto (RN1): cotización completa si tiene folio; factura
     * completa si existe `AdminContInvoice`; guía de entrega SOLO completa si además de existir
     * la factura hay un `OrderDocument` de tipo `guia_entrega` — nunca antes (RN2/Escenario 3),
     * incluso si por algún motivo se subiera "fuera de orden" (no debería poder pasar por el gate
     * ya existente de Bodega, pero se defiende igual, mismo criterio que `missingRequirements()`).
     *
     * @return array<string, mixed>
     */
    public function detail(int $orderId): array
    {
        $order   = Order::findOrFail($orderId);
        $card    = $order->pipelineCard;
        $quote   = $order->linkedQuote();
        // Batch 12 — desde que una "Corrección de datos" puede dejar 2 filas para el mismo Order
        // (la original anulada + la nueva reemplazo, ver migración
        // relax_order_id_unique_on_admin_contab_invoices_table), la más reciente es siempre la que
        // refleja el estado vigente de la entrega — una anulada nunca es más nueva que su reemplazo.
        $invoice = AdminContInvoice::where('order_id', $orderId)->latest('created_at')->first();

        $estado = 'pendiente-facturar';
        if ($invoice !== null) {
            $estado = $invoice->anulada_at !== null ? 'anulada' : 'facturada';
        }

        $guiaEntrega = ['existe' => false, 'fecha' => null];
        if ($invoice !== null) {
            $guia = $order->documents()->where('tipo', OrderDocument::TIPO_GUIA_ENTREGA)->latest('created_at')->first();
            if ($guia !== null) {
                $guiaEntrega = ['existe' => true, 'fecha' => $guia->created_at->toDateString()];
            }
        }

        $cobrabilidad = 'normal';
        if ($invoice?->incobrable_pendiente) {
            $cobrabilidad = 'pendiente_aprobacion';
        } elseif ($invoice?->es_incobrable) {
            $cobrabilidad = 'incobrable';
        }

        $monto = $card === null ? 0.0 : ($card->amount ?? 0.0);

        if ($invoice !== null) {
            $saldoAplicado = $invoice->saldo_aplicado;
            $totalAPagar   = round($invoice->monto - $invoice->saldo_aplicado, 2);
        } else {
            $saldoAplicado = 0.0;
            $totalAPagar   = $monto;
        }

        $payload = [
            'order_id'              => $order->id,
            'order_number'          => $order->order_number,
            'master_client_id'      => $card?->master_client_id,
            'cliente'               => $card?->subClient->business_name ?? $card?->masterClient->name ?? $order->customer_name,
            'monto'                 => $monto,
            'saldo_aplicado'        => $saldoAplicado,
            'total_a_pagar'         => $totalAPagar,
            'cotizacion_folio'      => $quote?->folio,
            'fecha_cotizacion'      => $quote?->generated_at?->toDateString(),
            'numero_factura'        => $invoice?->numero,
            'fecha_factura'         => $invoice?->created_at?->toDateString(),
            'estado'                => $estado,
            'es_anulada'            => $invoice?->anulada_at !== null,
            'guia_entrega'          => $guiaEntrega,
            'cobrabilidad'          => $cobrabilidad,
            // RN4 REQ-448 — el motivo original queda guardado como registro aunque se rechace,
            // pero solo se expone acá cuando hay algo vigente que mostrar (pendiente o aprobada).
            'motivo_incobrable'     => $cobrabilidad !== 'normal' ? $invoice?->motivo_incobrable : null,
            // REQ-451 RN1/RN2 — facturada y no incobrable APROBADA. Una propuesta pendiente sigue
            // contando (RN2: "sigue siendo técnicamente cobrable hasta que Mark decida"). No hay
            // ningún concepto de "pagada" persistido todavía (depende de Cobros, batch futuro) —
            // hoy es simplemente "true" para toda factura vigente no incobrable.
            'puede_registrar_cobro' => $invoice !== null && $invoice->anulada_at === null && ! $invoice->es_incobrable,
        ];

        if ($invoice !== null) {
            $payload += $this->pagoBlock();
        }

        if ($invoice !== null && $quote !== null) {
            $payload += self::fiscalBreakdown($quote);
        }

        return $payload;
    }

    /**
     * REQ-448 — Felix/Mark/Gerencia proponen, solo Mark decide (gate en el middleware de la ruta).
     * RN1: solo sobre facturas emitidas vigentes (ni pendientes, ni ya anuladas). RN5: no se puede
     * proponer un segundo cambio mientras el primero sigue sin resolver.
     */
    public function proposeUncollectible(int $orderId, string $motivo): AdminContInvoice
    {
        return DB::transaction(function () use ($orderId, $motivo): AdminContInvoice {
            /** @var AdminContInvoice|null $invoice */
            $invoice = AdminContInvoice::where('order_id', $orderId)->whereNull('anulada_at')->lockForUpdate()->first();

            if ($invoice === null) {
                throw new InvoiceStateException('Esta entrega todavía no tiene una factura emitida.');
            }
            if ($invoice->anulada_at !== null) {
                throw new InvoiceStateException('No se puede proponer incobrable sobre una factura anulada.');
            }
            if ($invoice->es_incobrable) {
                throw new InvoiceStateException('Esta factura ya está aprobada como incobrable.');
            }
            if ($invoice->incobrable_pendiente) {
                throw new InvoiceStateException('Ya existe una propuesta de incobrable pendiente de aprobación para esta factura.');
            }

            $invoice->update(['incobrable_pendiente' => true, 'motivo_incobrable' => $motivo]);

            return $invoice->fresh() ?? $invoice;
        });
    }

    /** REQ-448 RN4 — aprobar pasa a cartera incobrable; rechazar regresa exactamente al estado
     *  anterior (el motivo queda guardado como registro, sin marca visible en la UI normal). */
    public function decideUncollectible(int $orderId, bool $approve): AdminContInvoice
    {
        return DB::transaction(function () use ($orderId, $approve): AdminContInvoice {
            /** @var AdminContInvoice|null $invoice */
            $invoice = AdminContInvoice::where('order_id', $orderId)->whereNull('anulada_at')->lockForUpdate()->first();

            if ($invoice === null || ! $invoice->incobrable_pendiente) {
                throw new InvoiceStateException('No hay ninguna propuesta de incobrable pendiente para esta factura.');
            }

            $invoice->update(['incobrable_pendiente' => false, 'es_incobrable' => $approve]);

            return $invoice->fresh() ?? $invoice;
        });
    }

    /**
     * REQ-450 — 4 rangos fijos de antigüedad (convención estándar de reporte de cartera, no un
     * umbral de negocio ajustable — lo configurable es el plazo de crédito que define la fecha de
     * vencimiento, `dias_credito_factura`). RN1: excluye `es_incobrable` aprobada (una propuesta
     * `incobrable_pendiente` SIGUE contando, mismo criterio que REQ-448 RN3). RN2: antigüedad
     * desde la fecha de VENCIMIENTO, nunca desde la de emisión.
     *
     * @return array{ranges: list<array{desde_dias: int, hasta_dias: int|null, cantidad: int, monto: float}>}
     */
    public function agingBuckets(): array
    {
        $diasCredito = AdminContFiscalSettings::current()->dias_credito_factura;
        $today       = now();

        $ranges = [
            ['desde_dias' => 0,  'hasta_dias' => 30,   'cantidad' => 0, 'monto' => 0.0],
            ['desde_dias' => 31, 'hasta_dias' => 60,   'cantidad' => 0, 'monto' => 0.0],
            ['desde_dias' => 61, 'hasta_dias' => 90,   'cantidad' => 0, 'monto' => 0.0],
            ['desde_dias' => 91, 'hasta_dias' => null, 'cantidad' => 0, 'monto' => 0.0],
        ];

        $invoices = AdminContInvoice::where('es_incobrable', false)->whereNull('anulada_at')->get();

        foreach ($invoices as $invoice) {
            $vencimiento = $invoice->created_at->copy()->addDays($diasCredito);
            $diasVencido = max(0, (int) $vencimiento->diffInDays($today, false));
            $saldo       = $invoice->monto - $invoice->saldo_aplicado;

            $index = match (true) {
                $diasVencido <= 30 => 0,
                $diasVencido <= 60 => 1,
                $diasVencido <= 90 => 2,
                default             => 3,
            };

            $ranges[$index]['cantidad']++;
            $ranges[$index]['monto'] = round($ranges[$index]['monto'] + $saldo, 2);
        }

        return ['ranges' => $ranges];
    }

    /**
     * Batch final de Admin&Cont — "Inicio" (SCRUM-503→512, REQ-426→435). Mismo universo de
     * facturas que `agingBuckets()` (`es_incobrable=false`, `anulada_at=null`) y la misma fórmula
     * de vencimiento (`invoice.created_at + dias_credito_factura`), pero SIN el `max(0, ...)` que
     * `agingBuckets()` aplica — acá el signo importa: negativo = todavía no vence, 0 = vence hoy,
     * positivo = vencida hace esos días. `agingBuckets()` no puede reusarse tal cual porque sus 4
     * buckets solo existen para facturas YA vencidas, nunca hubo un bucket "no vencida" ahí.
     *
     * Público y reusable a propósito: lo consumen `cuentasAlDia()`/`carteraPorCobrar()` en este
     * mismo batch (Grupo 1, REQ-428/429), y lo va a reusar el próximo Grupo ("Vencidos y por
     * vencer", REQ-434) sin duplicar la fórmula de vencimiento una tercera vez.
     *
     * Batch final Grupo 3 (REQ-433) agregó `fecha_vencimiento` a la fila — el panel "Pendientes"
     * la necesita como `fecha_referencia` de sus alertas de factura, y calcularla ahí de nuevo
     * hubiera sido la misma fórmula por tercera vez.
     *
     * @return Collection<int, array{invoice: AdminContInvoice, dias_vencido: int, saldo: float, fecha_vencimiento: \Carbon\Carbon}>
     */
    public function cobrablesConVencimientoSigned(): Collection
    {
        $diasCredito = AdminContFiscalSettings::current()->dias_credito_factura;
        $today       = now();

        return AdminContInvoice::where('es_incobrable', false)->whereNull('anulada_at')->get()
            ->map(function (AdminContInvoice $invoice) use ($diasCredito, $today): array {
                $vencimiento = $invoice->created_at->copy()->addDays($diasCredito);

                return [
                    'invoice'           => $invoice,
                    'dias_vencido'      => (int) $vencimiento->diffInDays($today, false),
                    'saldo'             => $invoice->monto - $invoice->saldo_aplicado,
                    'fecha_vencimiento' => $vencimiento,
                ];
            });
    }

    /**
     * REQ-434 (SCRUM-511) — panel "Vencidos y por vencer" de Inicio. Reusa
     * `cobrablesConVencimientoSigned()` (RN1: mismo universo cobrable, nunca incobrables) sin
     * recalcular el vencimiento una cuarta vez.
     *
     * RN2/RN3 + borde "vence hoy" (decisión del Arquitecto, el ticket no lo resolvía): "Vencidos"
     * exige que la fecha YA PASÓ (`dias_vencido > 0`, estrictamente); "Por vencer" es una ventana
     * de 7 días que incluye "vence hoy" (`-7 <= dias_vencido <= 0`) — una factura que vence
     * exactamente hoy no cuenta todavía como vencida. Una factura fuera de ambos rangos (vence en
     * más de 7 días) no aparece en ninguna lista, a propósito.
     *
     * RN4: cada sección ordenada de más urgente a menos urgente.
     *
     * @return array{
     *   vencidos: list<array{numero: string, cliente: string, dias_vencido: int, monto: float, fecha_vencimiento: string}>,
     *   por_vencer: list<array{numero: string, cliente: string, dias_para_vencer: int, monto: float, fecha_vencimiento: string}>,
     * }
     */
    public function vencidosYPorVencer(): array
    {
        $rows = $this->cobrablesConVencimientoSigned();

        $vencidos = $rows
            ->filter(fn (array $r) => $r['dias_vencido'] > 0)
            ->sortByDesc('dias_vencido')
            ->map(fn (array $r) => [
                'numero'            => $r['invoice']->numero,
                'cliente'           => $r['invoice']->cliente,
                'dias_vencido'      => $r['dias_vencido'],
                'monto'             => round($r['saldo'], 2),
                'fecha_vencimiento' => $r['fecha_vencimiento']->toDateString(),
            ])->values();

        $porVencer = $rows
            ->filter(fn (array $r) => $r['dias_vencido'] <= 0 && $r['dias_vencido'] >= -7)
            ->sortBy(fn (array $r) => -$r['dias_vencido'])
            ->map(fn (array $r) => [
                'numero'            => $r['invoice']->numero,
                'cliente'           => $r['invoice']->cliente,
                'dias_para_vencer'  => -$r['dias_vencido'],
                'monto'             => round($r['saldo'], 2),
                'fecha_vencimiento' => $r['fecha_vencimiento']->toDateString(),
            ])->values();

        return [
            'vencidos'   => array_values($vencidos->all()),
            'por_vencer' => array_values($porVencer->all()),
        ];
    }

    /**
     * REQ-428 — "Cuentas al día" del panel Resumen del mes de Inicio. RN2 (decisión del
     * Arquitecto): una factura que vence EXACTAMENTE hoy (`dias_vencido === 0`) ya cuenta como
     * mora, no como al día — "al día" es estrictamente `dias_vencido < 0`.
     *
     * @return array{porcentaje: float, monto_al_dia: float, monto_con_mora: float}
     */
    public function cuentasAlDia(): array
    {
        $montoAlDia   = 0.0;
        $montoConMora = 0.0;

        foreach ($this->cobrablesConVencimientoSigned() as $row) {
            if ($row['dias_vencido'] < 0) {
                $montoAlDia += $row['saldo'];
            } else {
                $montoConMora += $row['saldo'];
            }
        }

        $total = $montoAlDia + $montoConMora;

        return [
            // Sin cuentas cobrables no hay mora que reportar — 100% "al día" por vacuidad, nunca
            // división por cero.
            'porcentaje'     => $total > 0 ? round($montoAlDia / $total * 100, 1) : 100.0,
            'monto_al_dia'   => round($montoAlDia, 2),
            'monto_con_mora' => round($montoConMora, 2),
        ];
    }

    /**
     * REQ-429 — "Cartera por cobrar" del panel Resumen del mes. `monto` reusa exactamente la suma
     * de `agingBuckets()` (mismo universo cobrable) para no recalcular el mismo total con una
     * fórmula paralela; `monto_incobrable_excluido` es puramente informativo — cuánto quedó
     * afuera de `monto` por estar marcado incobrable, nunca se resta de ningún otro total.
     *
     * @return array{monto: float, monto_incobrable_excluido: float}
     */
    public function carteraPorCobrar(): array
    {
        $monto = round((float) collect($this->agingBuckets()['ranges'])->sum('monto'), 2);

        $incobrable = AdminContInvoice::where('es_incobrable', true)->whereNull('anulada_at')->get()
            ->sum(fn (AdminContInvoice $i) => $i->monto - $i->saldo_aplicado);

        return [
            'monto'                     => $monto,
            'monto_incobrable_excluido' => round((float) $incobrable, 2),
        ];
    }

    /**
     * REQ-430 — "Ventas de ayer" del panel Resumen del mes. Mismo campo/criterio que `summary()`
     * (`AdminContInvoice.monto`, sin excluir anuladas — mismo comentario ya dejado en
     * `ventasSeries()` para que el total coincida con lo que ya se ve en Facturación), acotado al
     * día calendario de ayer.
     *
     * @return array{monto: float, cantidad: int, fecha: string}
     */
    public function ventasDeAyer(): array
    {
        $ayer = now()->subDay();

        $facturas = AdminContInvoice::whereBetween('created_at', [$ayer->copy()->startOfDay(), $ayer->copy()->endOfDay()])->get();

        return [
            'monto'    => round((float) $facturas->sum('monto'), 2),
            'cantidad' => $facturas->count(),
            'fecha'    => $ayer->toDateString(),
        ];
    }

    /**
     * Batch 22 (SCRUM-645, REQ-568) — "Cobrado de cartera +90 días" del mes en curso, para la
     * tarjeta de Reportes (y la base de la comisión de Felix, REQ-567). RN3 REQ-568/RN4 REQ-567:
     * nunca depende del selector de período de Reportes, siempre mes calendario en curso.
     *
     * Antigüedad calculada AL MOMENTO DEL PAGO (no "hoy") — misma fórmula de vencimiento que
     * `agingBuckets()` (`invoice.created_at + dias_credito_factura`), pero los días vencidos se
     * miden contra la fecha del cobro, no contra la fecha actual: lo que importa es si esa factura
     * YA estaba +90 días vencida cuando se cobró, no si lo está hoy. Solo cobros `estado =
     * confirmado` (mismo criterio que `PaymentService::summaryForCurrentMonth()`) y facturas no
     * `es_incobrable` (mismo criterio que `agingBuckets()`).
     *
     * @return array{cobrado_mes: float, pendiente: float}
     */
    public function over90DaysCollectedForCurrentMonth(): array
    {
        $diasCredito = AdminContFiscalSettings::current()->dias_credito_factura;
        $monthStart  = now()->startOfMonth();
        $monthEnd    = now()->endOfMonth();

        $lines = AdminContPaymentInvoice::query()
            ->whereHas('payment', fn ($q) => $q
                ->where('estado', AdminContPayment::ESTADO_CONFIRMADO)
                ->whereBetween('created_at', [$monthStart, $monthEnd]))
            ->whereHas('invoice', fn ($q) => $q->where('es_incobrable', false))
            ->with(['payment', 'invoice'])
            ->get();

        $cobradoMes = 0.0;
        foreach ($lines as $line) {
            $invoice = $line->invoice;
            $payment = $line->payment;
            if ($invoice === null || $payment === null) {
                continue;
            }

            // Positivo cuando el pago llegó DESPUÉS del vencimiento (atrasado) — a diferencia de
            // `CashPositionService::diasHasta()`, acá no se invierte el signo: lo que interesa es
            // la cantidad de días de atraso en sí, no "días hasta vencer".
            $vencimiento  = CarbonImmutable::parse($invoice->created_at)->addDays($diasCredito);
            $diasVencidos = (int) $vencimiento->diffInDays(CarbonImmutable::parse($payment->created_at), false);

            if ($diasVencidos > 90) {
                $cobradoMes += $line->monto_aplicado;
            }
        }

        return [
            'cobrado_mes' => round($cobradoMes, 2),
            'pendiente'   => $this->agingBuckets()['ranges'][3]['monto'],
        ];
    }

    /**
     * Batch 22 (SCRUM-646, REQ-569) — tarjeta "Ventas" de Reportes. RN1: "Hoy" muestra el total del
     * día + promedio diario del ÚLTIMO mes calendario ya cerrado. Decisión de diseño: ese promedio
     * se divide por días HÁBILES (lunes-viernes) del mes anterior, no por días calendario — replica
     * el criterio del mockup real (`4M__Admin_Contabilidad_Reportes.html`, `62300 / 22`: 22 es la
     * cantidad de días hábiles de junio en el dato de ejemplo, no sus 30 días calendario). RN2/RN3:
     * en cualquier otro período, serie mensual vía `ReportPeriodResolver` (siempre termina en el mes
     * actual en curso). Mismo campo/criterio que `summary()` (sin excluir facturas anuladas) para
     * que coincida exactamente con el total que ya se ve en Facturación.
     *
     * @return array{periodo: string, tipo: 'hoy', hoy: float, promedio_diario_mes_anterior: float}|array{periodo: string, tipo: 'meses', meses: list<array{mes: string, anio: int, total: float}>}
     */
    public function ventasSeries(string $periodo): array
    {
        if ($periodo === 'hoy') {
            $hoy = (float) AdminContInvoice::query()->whereDate('created_at', now()->toDateString())->sum('monto');

            $mesAnterior      = now()->subMonthNoOverflow();
            $totalMesAnterior = (float) AdminContInvoice::query()
                ->whereBetween('created_at', [$mesAnterior->copy()->startOfMonth(), $mesAnterior->copy()->endOfMonth()])
                ->sum('monto');
            $diasHabiles = self::diasHabilesEnMes($mesAnterior->year, $mesAnterior->month);

            return [
                'periodo' => 'hoy',
                'tipo'    => 'hoy',
                'hoy'     => round($hoy, 2),
                'promedio_diario_mes_anterior' => $diasHabiles > 0 ? round($totalMesAnterior / $diasHabiles, 2) : 0.0,
            ];
        }

        $meses = ReportPeriodResolver::meses($periodo);

        return [
            'periodo' => $periodo,
            'tipo'    => 'meses',
            'meses'   => array_map(fn (array $m): array => [
                'mes'   => $m['mes'],
                'anio'  => $m['anio'],
                'total' => round((float) AdminContInvoice::query()
                    ->whereBetween('created_at', [$m['inicio'], $m['fin']])
                    ->sum('monto'), 2),
            ], $meses),
        ];
    }

    private static function diasHabilesEnMes(int $anio, int $mes): int
    {
        $inicio = CarbonImmutable::parse(sprintf('%04d-%02d-01', $anio, $mes))->startOfMonth();
        $fin    = $inicio->endOfMonth();

        $count = 0;
        for ($d = $inicio; $d->lte($fin); $d = $d->addDay()) {
            if (! $d->isWeekend()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * RN2 de REQ-441 — cotización, monto>0 y cliente completos. También defiende server-side que
     * la entrega haya llegado a una etapa facturable de Bodega (`FACTURABLE_STAGES`) — la UI nunca
     * ofrece "Generar factura" antes de eso, pero un `order_id` de un pedido todavía en picking no
     * debe poder facturarse vía API directa (hallazgo de Pre-QA, corregido en el mismo batch).
     *
     * @return list<string>
     */
    private function missingRequirements(Order $order): array
    {
        $card    = $order->pipelineCard;
        $quote   = $order->linkedQuote();
        $missing = [];

        if (! in_array($order->stage, self::FACTURABLE_STAGES, true)) {
            $missing[] = 'La entrega todavía no llega a la etapa "Por despachar" de Bodega';
        }
        if ($quote === null || $quote->folio === null) {
            $missing[] = 'N° de Cotización';
        }
        if (($card->amount ?? 0) <= 0) {
            $missing[] = 'Monto';
        }
        if ($card?->master_client_id === null) {
            $missing[] = 'Cliente';
        }

        return $missing;
    }

    /**
     * Batch 3 (REQ-443 RN1) — agrega el desglose fiscal (subtotal/descuentos/ITBMS/total/items)
     * de la cotización de origen, para que la vista previa en pantalla tenga el mismo contenido
     * que un futuro documento fiscal (Batch 4/REQ-449 todavía no existe — ese batch reusará este
     * mismo desglose para su propia vista de impresión, en vez de recalcularlo de nuevo).
     *
     * @return array<string, mixed>
     */
    private function previewPayload(Order $order): array
    {
        $card  = $order->pipelineCard;
        $quote = $order->linkedQuote();

        $payload = [
            'order_id'         => $order->id,
            'order_number'     => $order->order_number,
            'cotizacion_folio' => $quote?->folio,
            'cliente'          => $card?->subClient->business_name ?? $card?->masterClient->name ?? $order->customer_name,
            'monto'            => $card?->amount,
        ];

        if ($quote !== null) {
            $payload += self::fiscalBreakdown($quote);
        }

        return $payload;
    }

    /**
     * REQ-449 RN1 — bloque de datos de pago del documento fiscal (Pago a / Cuenta número /
     * Responsable). No hay todavía ningún concepto de "cuenta de cobro asignada a esta factura"
     * (eso depende de Cobros, batch futuro) — se muestra la primera cuenta bancaria activa como
     * cuenta de referencia (nunca el número completo, solo últimos 4, RN4 de REQ-530).
     *
     * "Responsable" — corregido en Batch 9 de Estado de Cuenta (REQ-458 RN3): antes usaba el
     * `created_by` de ESTA factura puntual, lo que hacía imposible que Estado de Cuenta (un
     * documento por CLIENTE, que agrega facturas de posiblemente distintos creadores) mostrara el
     * mismo valor "idéntico entre pantallas" que exige RN3. Ahora lee
     * `AdminContFiscalSettings::responsable_administracion_user_id` — un único responsable a nivel
     * empresa, no por factura — mismo campo que usa `AccountStatementService`.
     *
     * @return array{cuenta_pago: string|null, responsable: string|null}
     */
    private function pagoBlock(): array
    {
        return self::paymentBlock();
    }

    /**
     * Bloque de datos de pago, a nivel EMPRESA (no por factura ni por cliente) — compartido tal
     * cual por `AccountStatementService` (Batch 9, REQ-458 RN3: "idéntico entre pantallas").
     *
     * @return array{cuenta_pago: string|null, responsable: string|null}
     */
    public static function paymentBlock(): array
    {
        $cuenta = AdminContBankAccount::where('activa', true)->oldest('id')->first();
        $cuentaPago = $cuenta !== null
            ? "{$cuenta->banco} ****{$cuenta->ultimos_4_digitos}"
            : null;

        $userId = AdminContFiscalSettings::current()->responsable_administracion_user_id;
        $responsable = null;
        if ($userId !== null) {
            $user = User::find($userId);
            $responsable = $user !== null ? trim("{$user->first_name} {$user->last_name}") : null;
        }

        return ['cuenta_pago' => $cuentaPago, 'responsable' => $responsable];
    }

    /**
     * Batch 12 (REQ-489) — pasa a `public static` (era `private`) para que
     * `CreditNoteService::previewCorreccion()` arme la vista previa de la factura nueva de
     * "Corrección de datos" con el MISMO cálculo de ítems que cualquier otra factura del sistema
     * (RN2 de REQ-489) — sin duplicar esta lógica. No depende de `$this`, cambio de visibilidad
     * puro, ningún comportamiento existente cambia.
     *
     * @return array{subtotal: float, descuentos: float, itbms: float, total: float, items: list<array<string, mixed>>}
     */
    public static function fiscalBreakdown(Quote $quote): array
    {
        $quote->loadMissing('parts.items');
        $settings = PricingSettings::current();

        $items = [];
        foreach ($quote->parts as $part) {
            foreach ($part->items as $item) {
                $lineTotal = $item->lineTotal($quote->discount_mode, $quote->global_discount_percent, $quote->price_type, $settings);

                $items[] = [
                    'descripcion'     => $item->description,
                    'cantidad'        => $item->quantity,
                    'precio_unitario' => $item->quantity > 0 ? round($lineTotal / $item->quantity, 2) : 0.0,
                    'subtotal'        => round($lineTotal, 2),
                ];
            }
        }

        return [
            'subtotal'   => $quote->subtotal(),
            'descuentos' => $quote->discountTotalsAmount(),
            'itbms'      => $quote->itbms(),
            'total'      => round($quote->netTotal() + $quote->itbms(), 2),
            'items'      => $items,
        ];
    }
}
