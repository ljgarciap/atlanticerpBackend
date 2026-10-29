<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Models\User;
use App\Modules\AdminCont\Events\InvoiceCreated;
use App\Modules\AdminCont\Exceptions\InvoicePreviewException;
use App\Modules\AdminCont\Exceptions\InvoiceStateException;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\ReceptorFiscalSnapshot;
use App\Modules\AdminCont\FacturacionElectronica\Services\ReceptorFiscalGuard;
use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContCompensation;
use App\Modules\AdminCont\Models\AdminContCompensationLine;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentInvoice;
use App\Modules\AdminCont\Services\Billing\BillingContext;
use App\Modules\AdminCont\Services\Billing\InvoiceBillingPlanner;
use App\Modules\AdminCont\Services\Billing\InvoicePlan;
use App\Modules\AdminCont\Support\AgingBucketCalculator;
use App\Modules\AdminCont\Support\ReportPeriodResolver;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderDocument;
use App\Modules\VentasDiseno\Models\PricingSettings;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Support\ReceptorTipo;
use App\Modules\VentasDiseno\Models\QuoteItem;
use App\Modules\VentasDiseno\Models\QuotePart;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
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

    /**
     * SCRUM-929 — modo de facturación. `entrega`: factura de UNA entrega con lo que realmente salió
     * de Bodega (`tipo = parcial`). `completo`: factura del pedido entero, o de lo que falta si ya
     * hay parciales (`tipo = completo`). Sin `modo` en el request el default es `completo`: un
     * cliente anterior a SCRUM-929 (que solo manda `order_ids`) sigue obteniendo UNA factura por el
     * total del pedido, exactamente como en SCRUM-876. En un pedido de entrega única ambos modos
     * producen la misma factura.
     */
    public const MODO_ENTREGA  = 'entrega';
    public const MODO_COMPLETO = 'completo';

    public function __construct(
        private readonly AdminContInvoiceFolioService $folio,
        private readonly AbonoService $abonos,
        private readonly InvoiceBillingPlanner $planner,
        private readonly ReceptorFiscalGuard $receptorGuard,
        private readonly AuditService $audit,
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
        // SCRUM-876 — un pedido está "pendiente de entrega" mientras ninguna de sus entregas llegó a
        // una etapa facturable; pasa a "pendiente de facturar" en cuanto la primera lo hace.
        $facturableRootIds = InvoiceBillingPlanner::whereEtapaFacturable(Order::query())
            ->get(['id', 'family_id'])
            ->map(fn (Order $o): int => $o->familyRootId())
            ->unique()
            ->values();

        $pendientesEntrega = Order::query()
            ->whereNull('family_id')
            ->where('order_type', Order::ORDER_TYPE_PEDIDO)
            ->whereNotIn('id', $facturableRootIds->isEmpty() ? [0] : $facturableRootIds->all())
            ->count();

        // SCRUM-929 — "pendiente de facturar" cuenta lo que hoy es facturable: en un pedido de
        // entrega única (o que solo admite pedido completo) sigue siendo 1 por pedido; en uno
        // facturable por entrega, 1 por cada entrega facturable todavía sin su factura.
        $pendientesFacturar = 0;
        if ($facturableRootIds->isNotEmpty()) {
            $membersByRoot = Order::query()
                ->where(fn ($q) => $q->whereIn('id', $facturableRootIds->all())->orWhereIn('family_id', $facturableRootIds->all()))
                ->get()
                ->groupBy(fn (Order $o): int => $o->familyRootId());
            $activeByRoot = AdminContInvoice::query()
                ->whereNull('anulada_at')
                ->whereIn('family_root_id', $facturableRootIds->all())
                ->get()
                ->groupBy('family_root_id');

            foreach ($facturableRootIds as $rootId) {
                /** @var Collection<int, Order> $members */
                $members = $membersByRoot->get($rootId, new Collection());
                /** @var Collection<int, AdminContInvoice> $active */
                $active = $activeByRoot->get($rootId, new Collection());
                $pendientesFacturar += $this->pendientesDelPedido($rootId, $members, $active);
            }
        }

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
     * Cuántas facturas nuevas admite un pedido para el contador "pendientes de facturar". Barato a
     * propósito (el resumen se pide en cada carga de la pantalla): solo consulta la cotización de
     * los pedidos con más de una entrega, que son los únicos donde importa saber si se puede
     * facturar por entrega.
     *
     * @param Collection<int, Order>            $members
     * @param Collection<int, AdminContInvoice> $active
     */
    private function pendientesDelPedido(int $rootId, Collection $members, Collection $active): int
    {
        if ($active->contains(fn (AdminContInvoice $i): bool => $i->tipo === AdminContInvoice::TIPO_COMPLETO)) {
            return 0;
        }

        if ($members->count() <= 1) {
            return $active->isEmpty() ? 1 : 0;
        }

        $root = $members->first(fn (Order $o): bool => $o->id === $rootId);
        if ($root === null) {
            return 0;
        }

        // Solo los pedidos con varias entregas necesitan los ítems (vínculo a la cotización).
        $withItems = Order::query()
            ->whereIn('id', $members->pluck('id')->all())
            ->with('items')
            ->orderByRaw('COALESCE(sequence_in_family, 1)')
            ->orderBy('id')
            ->get();
        $ctx = $this->planner->context($root, $withItems, $active);

        if (! $ctx->linkable) {
            // Pedido que solo admite completo: el listado genera una fila pendiente únicamente mientras
            // no tenga ninguna factura (con facturas activas solo muestra esas filas), así que el
            // contador debe coincidir con eso — no anuncia un pendiente que nadie puede accionar.
            return $active->isEmpty() ? 1 : 0;
        }
        if ($ctx->restante() <= InvoiceBillingPlanner::EPSILON) {
            return 0;
        }

        return $withItems->filter(fn (Order $o): bool => InvoiceBillingPlanner::etapaFacturable($o)
            && $ctx->parcialActivaDe($o->id) === null
            && $o->items->contains(fn ($item): bool => $item->qty_delivered > 0))->count();
    }

    /**
     * SCRUM-929 — una fila por ENTREGA facturable (antes, una por pedido): un pedido de entrega
     * única, o que solo admite pedido completo (productos sin vínculo a la cotización), conserva su
     * fila única anclada a la tarjeta raíz — misma forma y comportamiento que en SCRUM-876. Un pedido
     * dividido en entregas y facturable por entrega genera una fila por cada entrega ya facturable
     * (o ya facturada), más, a nivel de pedido y repetidos en cada fila, `puede_facturar_completo` y
     * `restante`. Ver contrato completo en ADR-SCRUM929 §4.
     *
     * @param array{tab?: string, view?: string, cliente?: string|null, proyecto?: string|null, vendedor_ids?: list<int>, estado?: string|null, cobro?: string|null, con_saldo_favor?: bool, desde?: string|null, hasta?: string|null, search?: string|null} $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $filters): array
    {
        $tab = $filters['tab'] ?? 'cobrable';

        // Una familia entra a la lista si CUALQUIER entrega llegó a una etapa facturable; se
        // representa por su tarjeta raíz (aunque la raíz misma siga en picking).
        $rootIds = InvoiceBillingPlanner::whereEtapaFacturable(Order::query())
            ->get(['id', 'family_id'])
            ->map(fn (Order $o): int => $o->familyRootId())
            ->unique()
            ->values();

        $roots = Order::query()
            ->whereIn('id', $rootIds)
            ->with('pipelineCard.masterClient', 'pipelineCard.subClient', 'pipelineCard.salesProject')
            ->get();

        // SCRUM-1175 — vendedores de todas las tarjetas del listado en una sola consulta.
        $vendedoresUsers = InvoiceSellers::usersFor($roots->map(fn (Order $o) => $o->pipelineCard));

        $membersByRoot = Order::query()
            ->where(fn ($q) => $q->whereIn('id', $rootIds)->orWhereIn('family_id', $rootIds))
            ->with('items')
            ->orderByRaw('COALESCE(sequence_in_family, 1)')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Order $o): int => $o->familyRootId());

        // Batch 12 — desde "Corrección de datos" puede haber más de una fila por entrega (la anulada
        // + su reemplazo); `orderBy('id')` deja la más reciente al final de cada grupo.
        $invoicesByRoot = AdminContInvoice::query()
            ->whereIn('family_root_id', $rootIds->all())
            ->with('lines')
            ->orderBy('id')
            ->get()
            ->groupBy('family_root_id');

        // Solo los pedidos con más de una entrega necesitan saber si sus productos están vinculados
        // a la cotización (1 consulta en lote para todos).
        /** @var Collection<int, Order> $multiMembers */
        $multiMembers    = $membersByRoot->filter(fn (Collection $m): bool => $m->count() > 1)->flatten(1);
        $quoteIdByItemId = $this->quoteIdByQuoteItem($multiMembers);

        $rows = [];
        foreach ($roots as $root) {
            /** @var Collection<int, Order> $members */
            $members = $membersByRoot->get($root->id, new Collection());
            /** @var Collection<int, AdminContInvoice> $invoices */
            $invoices = $invoicesByRoot->get($root->id, new Collection());
            $active   = $invoices->filter(fn (AdminContInvoice $i): bool => $i->anulada_at === null)->values();

            $ctx = $this->planner->lightContext($root, $members, $active, $root->linkedQuote(), $quoteIdByItemId);

            foreach ($this->pedidoRows($ctx, $invoices, $vendedoresUsers) as $row) {
                $esIncobrable = (bool) $row['es_incobrable'];
                if ($tab === 'incobrable' && ! $esIncobrable) {
                    continue;
                }
                if ($tab === 'cobrable' && $esIncobrable) {
                    continue;
                }

                if (($filters['cliente'] ?? null) !== null
                    && ! str_contains(mb_strtolower((string) $row['cliente']), mb_strtolower((string) $filters['cliente']))) {
                    continue;
                }
                // SCRUM-1175 — coincide si CUALQUIERA de los responsables del pedido está entre los ids elegidos.
                if (($filters['vendedor_ids'] ?? []) !== []
                    && array_intersect($filters['vendedor_ids'], $row['vendedor_ids']) === []) {
                    continue;
                }
                if (($filters['proyecto'] ?? null) !== null
                    && ! str_contains(mb_strtolower((string) $row['proyecto']), mb_strtolower((string) $filters['proyecto']))) {
                    continue;
                }
                if (($filters['estado'] ?? null) !== null && $row['estado'] !== $filters['estado']) {
                    continue;
                }
                // SCRUM-1147 — Cobradas / Por cobrar; combinable con el resto de filtros.
                if (($filters['cobro'] ?? null) !== null && $row['cobro_estado'] !== $filters['cobro']) {
                    continue;
                }
                // desde/hasta solo excluyen filas ya facturadas fuera del rango — una entrega
                // pendiente-facturar todavía no tiene fecha de factura, así que un filtro de fecha no
                // la descarta (default inclusivo, no hay "fecha" propia que comparar).
                /** @var \Illuminate\Support\Carbon|null $fechaFactura */
                $fechaFactura = $row['_fecha_factura'];
                if ($fechaFactura !== null && ($filters['desde'] ?? null) !== null && $fechaFactura->lt($filters['desde'])) {
                    continue;
                }
                if ($fechaFactura !== null && ($filters['hasta'] ?? null) !== null && $fechaFactura->gt($filters['hasta'])) {
                    continue;
                }
                if (($filters['search'] ?? null) !== null) {
                    $needle   = mb_strtolower((string) $filters['search']);
                    $haystack = mb_strtolower(implode(' ', [$row['cliente'], (string) $row['proyecto'], $row['order_number'], (string) $row['numero_factura']]));
                    if (! str_contains($haystack, $needle)) {
                        continue;
                    }
                }

                unset($row['_fecha_factura']);
                $rows[] = $row;
            }
        }

        // SCRUM-1147 — solo el listado de pantalla lo pide (no `countInTab()` ni el export).
        if (($filters['con_saldo_favor'] ?? false) === true) {
            $rows = $this->conSaldoFavor($rows);
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

    /**
     * SCRUM-1175 — opciones del filtro «Vendedor»: solo quienes son responsables de algún pedido facturable (dueño de la tarjeta o compartido),
     * ordenados por nombre. Una sola consulta de tarjetas y una de usuarios.
     *
     * @return list<array{id: int, name: string}>
     */
    public function vendedoresDisponibles(): array
    {
        $rootIds = InvoiceBillingPlanner::whereEtapaFacturable(Order::query())
            ->get(['id', 'family_id'])
            ->map(fn (Order $o): int => $o->familyRootId())
            ->unique()
            ->values();

        $cards = Order::query()->whereIn('id', $rootIds)->with('pipelineCard')->get()->map(fn (Order $o) => $o->pipelineCard);

        return array_values(InvoiceSellers::usersFor($cards)
            ->map(fn (\App\Models\User $u): array => ['id' => (int) $u->id, 'name' => trim("{$u->first_name} {$u->last_name}")])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->all());
    }

    /**
     * Filas de listado de UN pedido (ver `list()`). Cada fila lleva `_fecha_factura` (interno, para
     * el filtro de fechas), que `list()` quita antes de devolver.
     *
     * @param Collection<int, AdminContInvoice> $invoices todas las facturas del pedido (activas y anuladas), por id
     * @param Collection<int, \App\Models\User> $vendedoresUsers usuarios responsables de todas las tarjetas del listado (SCRUM-1175)
     * @return list<array<string, mixed>>
     */
    private function pedidoRows(BillingContext $ctx, Collection $invoices, Collection $vendedoresUsers): array
    {
        $root  = $ctx->root;
        $card  = $root->pipelineCard;
        $quote = $ctx->quote;

        $unica      = $ctx->esEntregaUnica();
        $porEntrega = ! $unica && $ctx->linkable;

        // Contexto completo (con los ítems de la cotización) solo cuando hace falta calcular un plan.
        /** @var BillingContext|null $full */
        $full        = null;
        $contextoFull = function () use (&$full, $ctx, $root): BillingContext {
            return $full ??= $this->planner->context($root, $ctx->members, $ctx->active);
        };

        $completo = $this->completoDisponible($ctx, $contextoFull);

        $pedido = [
            'pedido_id'                     => $root->id,
            'order_number'                  => $root->order_number,
            'sales_project_id'              => $card?->sales_project_id,
            'proyecto'                      => $card?->salesProject?->name,
            'master_client_id'              => $root->fiscalMasterClientId(),
            'cliente'                       => $card?->subClient->business_name ?? $card?->masterClient->name ?? $root->customer_name,
            'vendedores'                    => InvoiceSellers::namesFor($card, $vendedoresUsers),
            'vendedor_ids'                  => InvoiceSellers::idsOf($card),
            'cotizacion_folio'              => $quote?->folio,
            'entregas_del_pedido'           => $this->entregasResumen($ctx, $invoices),
            'es_entrega_unica'              => $unica,
            'facturacion_por_entrega'       => $porEntrega,
            // SCRUM-950 — cuántas entregas de la familia llegaron a una etapa facturable AHORA
            // MISMO, para el contador que ve el usuario en la fila de un pedido facturado
            // `completo` (ver `fila()`).
            'entregas_completadas'          => $this->entregasCompletadas($ctx),
            'monto_total_pedido'            => $ctx->total,
            'facturado_pedido'              => $ctx->facturado(),
            'restante'                      => $ctx->restante(),
            'puede_facturar_completo'       => $completo === null,
            'motivo_completo_no_disponible' => $completo,
        ];

        $motivoSinEntrega = $unica
            ? 'Pedido de entrega única: se factura como pedido completo.'
            : 'Este pedido solo admite facturación de pedido completo: sus productos no están vinculados a la cotización.';

        // Qué se puede hacer HOY con la entrega de una fila: con factura activa nada; sin ella (o con
        // una anulada) y con el pedido facturable por entrega corre el plan completo — incluidos los
        // topes por ítem — y su monto es lo que se facturaría. `linkable` decide SOLO esto: lo ya
        // emitido se lista siempre (F2).
        $disponibilidad = function (Order $entrega, ?AdminContInvoice $inv) use ($porEntrega, $card, $motivoSinEntrega, $contextoFull): array {
            if ($inv !== null && $inv->anulada_at === null) {
                return [$inv->monto, false, $inv->tipo === AdminContInvoice::TIPO_COMPLETO
                    ? 'Este pedido ya tiene una factura generada.'
                    : 'Esta entrega ya tiene una factura generada.'];
            }
            if (! $porEntrega) {
                return [$inv->monto ?? $card?->amount, false, $motivoSinEntrega];
            }
            try {
                return [$this->planner->planEntrega($contextoFull(), $entrega)->monto, true, null];
            } catch (InvoicePreviewException $e) {
                return [$inv->monto ?? 0.0, false, implode(' ', $e->missing())];
            }
        };

        // F2 — UNA FILA POR FACTURA EMITIDA (la última de cada par tarjeta+tipo: una anulada y su
        // reemplazo son una sola fila), con su propio `monto`/`invoice_id`/`tipo_factura`, sea cual sea
        // la forma del pedido. Se suman las filas PENDIENTES: una por entrega facturable sin factura
        // propia en los pedidos facturables por entrega; una sola anclada a la raíz si el pedido no
        // tiene ninguna factura y no admite entregas sueltas (SCRUM-876).
        /** @var array<string, AdminContInvoice> $slots */
        $slots = [];
        foreach ($invoices as $invoice) {
            $slots[((int) $invoice->order_id).'|'.$invoice->tipo] = $invoice; // por id ascendente: gana la más reciente
        }
        $completoActivo = $ctx->completoActivo();
        $miembroIds     = $ctx->members->pluck('id')->all();

        $rows = [];
        foreach ($ctx->members as $entrega) {
            $parcial       = $slots[$entrega->id.'|'.AdminContInvoice::TIPO_PARCIAL] ?? null;
            $completoSlot  = $slots[$entrega->id.'|'.AdminContInvoice::TIPO_COMPLETO] ?? null;
            $facturable    = InvoiceBillingPlanner::etapaFacturable($entrega);

            if ($parcial !== null) {
                [$monto, $puede, $motivo] = $disponibilidad($entrega, $parcial);
                $rows[] = $this->fila($pedido, $entrega, $parcial, $monto, $puede, $motivo);
            } elseif ($porEntrega && $facturable && $completoSlot === null && $completoActivo === null) {
                // Entrega facturable sin factura propia que no cubre ningún "pedido completo".
                [$monto, $puede, $motivo] = $disponibilidad($entrega, null);
                $rows[] = $this->fila($pedido, $entrega, null, $monto, $puede, $motivo);
            }

            // El `completo` cuelga de UNA tarjeta (la raíz): ésa es la única que puede tener a la vez
            // su propia parcial y el completo por el resto, y lleva una fila por cada una. Las demás
            // entregas que cubre no repiten fila (viajan en `entregas_del_pedido`).
            if ($completoSlot !== null) {
                [$monto, $puede, $motivo] = $disponibilidad($entrega, $completoSlot);
                $rows[] = $this->fila($pedido, $entrega, $completoSlot, $monto, $puede, $motivo);
            }
        }

        // Facturas colgadas de una tarjeta que ya no existe en la familia: se listan igual, ancladas a la raíz.
        foreach ($slots as $invoice) {
            if (! in_array((int) $invoice->order_id, $miembroIds, true)) {
                [$monto, $puede, $motivo] = $disponibilidad($root, $invoice);
                $rows[] = $this->fila($pedido, $root, $invoice, $monto, $puede, $motivo);
            }
        }

        // Pedido de entrega única, o que solo admite pedido completo, sin ninguna factura: la fila
        // pendiente histórica, anclada a la raíz.
        if ($rows === [] && ! $porEntrega) {
            [$monto, $puede, $motivo] = $disponibilidad($root, null);
            $rows[] = $this->fila($pedido, $root, null, $monto, $puede, $motivo);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $pedido campos a nivel pedido, repetidos en cada fila
     * @return array<string, mixed>
     */
    private function fila(array $pedido, Order $entrega, ?AdminContInvoice $invoice, mixed $monto, bool $puedeEntrega, ?string $motivoEntrega): array
    {
        return [
            'order_id'             => $entrega->id,
            'order_number'         => $pedido['order_number'],
            'sales_project_id'     => $pedido['sales_project_id'],
            'proyecto'             => $pedido['proyecto'],
            'master_client_id'     => $pedido['master_client_id'],
            'cliente'              => $pedido['cliente'],
            'vendedores'           => $pedido['vendedores'],
            'vendedor_ids'         => $pedido['vendedor_ids'],
            'cotizacion_folio'     => $pedido['cotizacion_folio'],
            'monto'                => $monto,
            'entregas_del_pedido'  => $pedido['entregas_del_pedido'],
            'entrega'              => [
                // SCRUM-950 — un pedido facturado `completo` solo tiene UNA fila visible (la
                // anclada a la entrega cuyo id coincide con `order_id` de esa factura; las demás
                // entregas de la familia nunca generan fila propia mientras el completo siga
                // activo, ver el `elseif` de arriba). Antes esa fila mostraba SIEMPRE la posición
                // fija de esa entrega ("Entrega 1 de 2"), sin importar cuántas entregas más
                // hiciera Bodega después de facturar — el pedido parecía "trabado en 1 de 2" para
                // siempre. Con un `completo` activo, el número que ve el usuario es cuántas
                // entregas de la familia llegaron a una etapa facturable AHORA (recalculado en
                // cada lectura, nunca cacheado) — no la posición fija de esta fila en particular.
                // Sin `completo` (facturación por entrega genuina), cada fila es su propia
                // entrega real y conserva su posición fija, sin cambios.
                'sequence_in_family' => $invoice !== null && $invoice->tipo === AdminContInvoice::TIPO_COMPLETO
                    ? $pedido['entregas_completadas']
                    : $entrega->sequence_in_family,
                'total_in_family'    => $entrega->total_in_family,
                'stage'              => $entrega->stage,
                'order_type'         => $entrega->order_type,
            ],
            // Batch 4 (REQ-448/449) — la fila considera `anulada_at` e `incobrable_pendiente`
            // (una propuesta pendiente NO se ve como "Facturada", RN3 de REQ-448).
            'estado'               => match (true) {
                $invoice === null              => 'pendiente-facturar',
                $invoice->anulada_at !== null   => 'anulada',
                default                          => 'facturada',
            },
            'numero_factura'       => $invoice?->numero,
            'invoice_id'           => $invoice?->id,
            'tipo_factura'         => $invoice?->tipo,
            'es_incobrable'        => $invoice->es_incobrable ?? false,
            'incobrable_pendiente' => $invoice->incobrable_pendiente ?? false,
            // SCRUM-1147 — estado de cobro de la factura: `cobrada` (saldo cobrado cubre el monto),
            // `por_cobrar` (activa, no incobrable aprobada, con saldo pendiente — incluye cobros
            // parciales) o null (pendiente de facturar, anulada o incobrable). Mismo criterio de
            // "pagada" que `puede_registrar_cobro` del detalle.
            'cobro_estado'         => self::cobroEstado($invoice),
            // SCRUM-929 — nivel pedido (repetido en cada fila del pedido) y nivel entrega.
            'pedido_id'                     => $pedido['pedido_id'],
            'es_entrega_unica'              => $pedido['es_entrega_unica'],
            'facturacion_por_entrega'       => $pedido['facturacion_por_entrega'],
            'monto_total_pedido'            => $pedido['monto_total_pedido'],
            'facturado_pedido'              => $pedido['facturado_pedido'],
            'restante'                      => $pedido['restante'],
            'puede_facturar_completo'       => $pedido['puede_facturar_completo'],
            'motivo_completo_no_disponible' => $pedido['motivo_completo_no_disponible'],
            'puede_facturar_entrega'        => $puedeEntrega,
            'motivo_entrega_no_disponible'  => $puedeEntrega ? null : $motivoEntrega,
            '_fecha_factura'                => $invoice?->created_at,
        ];
    }

    /**
     * SCRUM-1147 — agrega `saldo_a_favor` (float|null) a las filas por cobrar: informativo, junto al
     * total de la factura. Ver `AbonoService::saldoFavorPorFactura()` para la regla de nivel.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function conSaldoFavor(array $rows): array
    {
        $targets = [];
        foreach ($rows as $row) {
            if ($row['cobro_estado'] === 'por_cobrar' && $row['invoice_id'] !== null) {
                $targets[] = [
                    'invoice_id'       => (int) $row['invoice_id'],
                    'sales_project_id' => $row['sales_project_id'] !== null ? (int) $row['sales_project_id'] : null,
                    'master_client_id' => $row['master_client_id'] !== null ? (int) $row['master_client_id'] : null,
                ];
            }
        }
        $saldos = $this->abonos->saldoFavorPorFactura($targets);

        return array_map(function (array $row) use ($saldos): array {
            $row['saldo_a_favor'] = $row['invoice_id'] !== null ? ($saldos[(int) $row['invoice_id']] ?? null) : null;

            return $row;
        }, $rows);
    }

    /**
     * SCRUM-1147 — nombres de los vendedores responsables del pedido: el `owner` de la tarjeta del
     * Pipeline más los responsables adicionales (`compartido_con`), sin repetir y en ese orden.
     *
     * @return list<string>
     */
    private function vendedoresResponsables(?\App\Modules\VentasDiseno\Models\PipelineCard $card): array
    {
        return InvoiceSellers::namesFor($card, InvoiceSellers::usersFor([$card]));
    }

    /** SCRUM-1147 — ver `fila()`: cobrada | por_cobrar | null. */
    private static function cobroEstado(?AdminContInvoice $invoice): ?string
    {
        if ($invoice === null || $invoice->anulada_at !== null || $invoice->es_incobrable) {
            return null;
        }

        return round($invoice->monto - $invoice->saldo_aplicado, 2) <= 0 ? 'cobrada' : 'por_cobrar';
    }

    /**
     * ¿Se puede emitir HOY una factura de pedido completo? `null` si sí; si no, el motivo en
     * español (para mostrarlo en la UI). NO evalúa los datos faltantes (cotización/monto/cliente):
     * esos se informan en la vista previa, como siempre.
     */
    private function completoDisponible(BillingContext $ctx, \Closure $contextoFull): ?string
    {
        if ($ctx->completoActivo() !== null) {
            return 'Este pedido ya tiene una factura generada.';
        }
        if ($ctx->active->isNotEmpty() && $ctx->restante() <= InvoiceBillingPlanner::EPSILON) {
            return 'No queda saldo por facturar en este pedido.';
        }
        // Con facturas previas en un pedido de varias entregas, el saldo por líneas depende de la
        // cotización vigente: si cambió de versión, el completo se rechaza (F1) y no debe ofrecerse.
        if (! $ctx->esEntregaUnica() && $ctx->active->isNotEmpty()) {
            try {
                $this->planner->planCompleto($contextoFull());
            } catch (InvoicePreviewException $e) {
                return implode(' ', $e->missing());
            }
        }

        return null;
    }

    /**
     * SCRUM-950 — cuántos miembros de la familia llegaron a una etapa facturable (mismo criterio
     * que `facturable` en `entregasResumen()`), recalculado en cada lectura. Ver el comentario de
     * `fila()` para el porqué: es el contador que ve el usuario en la fila de un pedido facturado
     * `completo`, no la posición fija de ninguna entrega en particular.
     */
    private function entregasCompletadas(BillingContext $ctx): int
    {
        return $ctx->members->filter(fn (Order $o): bool => InvoiceBillingPlanner::etapaFacturable($o))->count();
    }

    /**
     * Resumen de cada entrega (miembro de familia) del pedido, por secuencia. Un pedido de entrega
     * única devuelve una sola fila. `factura_*` = la factura propia de la tarjeta, o la del "pedido
     * completo" que la cubre (`cubierta_por_completo`).
     *
     * @param Collection<int, AdminContInvoice>|null $invoices todas las facturas del pedido (por id); null = consultarlas
     * @return list<array<string, mixed>>
     */
    private function entregasResumen(BillingContext $ctx, ?Collection $invoices = null): array
    {
        $invoices ??= AdminContInvoice::forOrder($ctx->root)->orderBy('id')->get();

        return array_values($ctx->members->map(function (Order $o) use ($invoices): array {
            // Regla central (`pickInvoiceForDelivery`): la parcial vigente de la entrega, si no el
            // `completo` vigente que la cubre, y solo después las anuladas (histórico).
            $factura  = self::pickInvoiceForDelivery($invoices, $o->id, incluirAnuladas: true);
            $cubierta = $factura !== null && $factura->anulada_at === null
                && $factura->tipo === AdminContInvoice::TIPO_COMPLETO && (int) $factura->order_id !== $o->id;

            return [
                'order_id'              => $o->id,
                'sequence_in_family'    => $o->sequence_in_family,
                'total_in_family'       => $o->total_in_family,
                'stage'                 => $o->stage,
                'order_type'            => $o->order_type,
                'facturable'            => InvoiceBillingPlanner::etapaFacturable($o),
                'factura_numero'        => $factura?->numero,
                'factura_tipo'          => $factura?->tipo,
                'factura_anulada'       => $factura !== null && $factura->anulada_at !== null,
                'cubierta_por_completo' => $cubierta,
            ];
        })->all());
    }

    /**
     * REGLA CENTRAL — cuál es "la factura de una entrega" (SCRUM-929, B3a). Una entrega puede estar
     * facturada por su propia factura `parcial`, o cubierta por el `completo` del pedido; y la raíz
     * puede tener las dos a la vez. Orden: (1) la `parcial` ACTIVA anclada a esa entrega; (2) si no hay,
     * el `completo` ACTIVO de la familia (cubre el resto); (3) si no hay ninguna, `null`. Solo con
     * `$incluirAnuladas` (históricos), y recién después, las anuladas: (4) la anulada anclada a esa
     * entrega (parcial primero) y (5) el `completo` anulado de la familia. Todo consumidor que necesite
     * "la factura de esta entrega" pasa por acá — nunca `forOrder()->first()`, `keyBy('order_id')` ni
     * `inv.order_id = o.id` asumiendo una factura por tarjeta.
     *
     * @param Collection<int, AdminContInvoice> $invoices facturas de la FAMILIA (cualquier orden; se ordenan por id)
     */
    public static function pickInvoiceForDelivery(Collection $invoices, int $deliveryId, bool $incluirAnuladas = false): ?AdminContInvoice
    {
        $invoices = $invoices->sortBy('id')->values();
        $activas  = $invoices->filter(fn (AdminContInvoice $i): bool => $i->anulada_at === null);

        /** @var AdminContInvoice|null $pick */
        $pick = $activas->filter(fn (AdminContInvoice $i): bool => $i->tipo === AdminContInvoice::TIPO_PARCIAL && (int) $i->order_id === $deliveryId)->last()
            ?? $activas->filter(fn (AdminContInvoice $i): bool => $i->tipo === AdminContInvoice::TIPO_COMPLETO)->last();
        if ($pick !== null || ! $incluirAnuladas) {
            return $pick;
        }

        $anuladas = $invoices->filter(fn (AdminContInvoice $i): bool => $i->anulada_at !== null);
        $ancladas = $anuladas->filter(fn (AdminContInvoice $i): bool => (int) $i->order_id === $deliveryId);

        return $ancladas->filter(fn (AdminContInvoice $i): bool => $i->tipo === AdminContInvoice::TIPO_PARCIAL)->last()
            ?? $ancladas->last()
            ?? $anuladas->filter(fn (AdminContInvoice $i): bool => $i->tipo === AdminContInvoice::TIPO_COMPLETO)->last();
    }

    /** La factura de UNA entrega según la regla central (ver `pickInvoiceForDelivery()`). */
    public function invoiceForDelivery(Order $entrega, bool $incluirAnuladas = false): ?AdminContInvoice
    {
        return self::pickInvoiceForDelivery(AdminContInvoice::forOrder($entrega)->orderBy('id')->get(), $entrega->id, $incluirAnuladas);
    }

    /**
     * Ids de las entregas (tarjetas) de `$orders` que tienen una factura ACTIVA según la regla central,
     * en 1 sola consulta — para el tablero de Bodega.
     *
     * @param Collection<int, Order> $orders
     * @return list<int>
     */
    public function deliveryIdsWithActiveInvoice(Collection $orders): array
    {
        $rootIds = $orders->map(fn (Order $o): int => $o->familyRootId())->unique()->values();
        if ($rootIds->isEmpty()) {
            return [];
        }

        $activasPorRaiz = AdminContInvoice::query()
            ->whereNull('anulada_at')
            ->whereIn('family_root_id', $rootIds->all())
            ->get()
            ->groupBy('family_root_id');

        $ids = [];
        foreach ($orders as $order) {
            /** @var Collection<int, AdminContInvoice> $familia */
            $familia = $activasPorRaiz->get($order->familyRootId(), new Collection());
            if (self::pickInvoiceForDelivery($familia, $order->id) !== null) {
                $ids[] = $order->id;
            }
        }

        return $ids;
    }

    /**
     * Mapa `quote_item_id => quote_id` de los ítems vinculados de las tarjetas dadas, en UNA
     * consulta — insumo de `InvoiceBillingPlanner::lightContext()` para el listado.
     *
     * @param Collection<int, Order> $members con `items` cargados
     * @return array<int, int>
     */
    private function quoteIdByQuoteItem(Collection $members): array
    {
        $ids = $members->flatMap(fn (Order $o) => $o->items)->pluck('quote_item_id')->filter()->unique()->values()->all();
        if ($ids === []) {
            return [];
        }

        $map  = [];
        $rows = DB::table((new QuoteItem())->getTable().' as qi')
            ->join((new QuotePart())->getTable().' as qp', 'qp.id', '=', 'qi.quote_part_id')
            ->whereIn('qi.id', $ids)
            ->get(['qi.id as item_id', 'qp.quote_id']);
        foreach ($rows as $row) {
            $map[(int) $row->item_id] = (int) $row->quote_id;
        }

        return $map;
    }

    /**
     * Vista previa de lo que ofrece el wizard de "+ Crear Factura" para un proyecto: una entrada
     * por entrega facturable (pedidos facturables por entrega) o por pedido (el resto), todas en
     * "Por despachar" y sin factura activa.
     *
     * @return list<array<string, mixed>> entregas con cotización+monto+cliente completos, sin factura, del proyecto dado
     */
    public function pendingForProject(int $salesProjectId): array
    {
        $porDespacharRootIds = Order::query()
            ->where('stage', Order::STAGE_POR_DESPACHAR)
            ->whereHas('pipelineCard', fn ($q) => $q->where('sales_project_id', $salesProjectId))
            ->get(['id', 'family_id'])
            ->map(fn (Order $o): int => $o->familyRootId())
            ->unique();

        $payloads = [];
        $roots    = Order::query()->whereIn('id', $porDespacharRootIds->values())->with('pipelineCard.masterClient', 'pipelineCard.subClient')->get();
        foreach ($roots as $root) {
            $ctx = $this->planner->context($root);

            if ($ctx->completoActivo() !== null) {
                continue;
            }

            if (! $ctx->esEntregaUnica() && $ctx->linkable) {
                foreach ($ctx->members as $entrega) {
                    if ($entrega->stage !== Order::STAGE_POR_DESPACHAR) {
                        continue;
                    }
                    try {
                        $payloads[] = $this->previewPayload($ctx, $this->planner->planEntrega($ctx, $entrega), self::MODO_ENTREGA);
                    } catch (InvoicePreviewException) {
                        continue; // ya facturada, sin cantidades entregadas o sin saldo: nada que ofrecer
                    }
                }
                continue;
            }

            if ($ctx->active->isEmpty()) {
                $payloads[] = $this->previewPayload($ctx, null, self::MODO_COMPLETO);
            }
        }

        return $payloads;
    }

    /**
     * Vista previa de UNA factura. `modo = entrega` factura la entrega `$order` (solo en pedidos con
     * más de una entrega y productos vinculados a la cotización); `completo` factura el pedido
     * entero o lo que falta. En un pedido de entrega única ambos son la misma factura.
     *
     * @return array<string, mixed>
     * @throws InvoicePreviewException
     */
    public function preview(Order $order, string $modo = self::MODO_COMPLETO): array
    {
        [$ctx, $plan, $modoEfectivo] = $this->resolvePlan($order, $modo);

        return $this->previewPayload($ctx, $plan, $modoEfectivo);
    }

    /**
     * Batch 3 (REQ-441/443) — vista previa de 1..N facturas a la vez. SCRUM-902 retiró el saldo a
     * favor de este paso por completo: una factura se genera siempre por su monto íntegro (ver
     * `confirm()`), así que el preview ya no necesita mostrar ningún desglose de saldo.
     *
     * @param list<int> $orderIds en `completo`, ids de pedido/entrega (se normalizan a la raíz, sin
     *                            duplicados); en `entrega`, ids de la entrega a facturar
     * @return array{results: list<array<string, mixed>>}
     */
    public function previewBatch(array $orderIds, string $modo = self::MODO_COMPLETO): array
    {
        $results = [];
        $receptores = []; // sub_client_id => evaluación de la guarda, una consulta a la DGI por cliente

        foreach ($this->normalizeIds($orderIds, $modo) as $orderId) {
            $order = Order::find($orderId);
            if ($order === null) {
                $results[] = ['order_id' => $orderId, 'ok' => false, 'missing' => ['Pedido no encontrado']];
                continue;
            }

            try {
                $results[] = ['order_id' => $orderId, 'ok' => true]
                    + $this->preview($order, $modo)
                    + ['receptor_validacion' => $this->evaluarReceptor($order, $receptores)];
            } catch (InvoicePreviewException $e) {
                $results[] = ['order_id' => $orderId, 'ok' => false, 'missing' => $e->missing()];
            }
        }

        return ['results' => $results];
    }

    /**
     * SCRUM-929 — confirma UNA factura, con el guard anti-duplicado del ADR §3. Toda la decisión se
     * toma dentro de la transacción y con la fila RAÍZ del pedido bloqueada (`lockForUpdate`): dos
     * requests simultáneos sobre el mismo pedido (parcial + completo, o dos parciales) se serializan
     * ahí, y el segundo ve la factura del primero al re-leer el contexto. No se apoya en bloquear
     * filas que todavía no existen (un `lockForUpdate` no frena inserts fantasma).
     *
     * @param array<string, mixed>|null $auditoriaConsumidorFinal SCRUM-1140 — metadatos del evento de Consumidor final (causa, documento enmascarado); NULL = no aplica
     * @throws InvoicePreviewException
     */
    public function confirm(Order $order, User $actor, string $modo = self::MODO_COMPLETO, ?ReceptorFiscalSnapshot $receptor = null, ?array $auditoriaConsumidorFinal = null): AdminContInvoice
    {
        $rootId = $order->familyRootId();

        /** @var array{0: AdminContInvoice, 1: InvoicePlan} $creada */
        $creada = DB::transaction(function () use ($rootId, $order, $actor, $modo, $receptor, $auditoriaConsumidorFinal): array {
            /** @var Order $locked */
            $locked = Order::where('id', $rootId)->lockForUpdate()->firstOrFail();

            // Se relee TODO bajo el lock — nunca con lo que se vio antes de tomarlo.
            [$ctx, $plan] = $this->resolvePlan($order->id === $rootId ? $locked : $order, $modo, registrarAvisos: true);
            $card = $ctx->root->pipelineCard;

            // SCRUM-902 §4.3 — la factura SIEMPRE se genera por su monto completo, sin ninguna
            // resta visible. Un abono de nivel Proyecto confirmado sobre este proyecto se
            // re-asocia a la factura recién creada más abajo (fuera de esta transacción de
            // creación, pero antes del evento) — su aplicación real ocurre en Cobros.
            // SCRUM-1166 — una cotización exenta se factura a «0% — Exento»; sin cotización, la tasa por defecto de siempre.
            $rate    = $ctx->quote?->itbmsRate() ?? ItbmsRateResolver::defaultRate();
            $invoice = AdminContInvoice::create([
                'numero'            => $this->folio->next(),
                'order_id'          => $plan->orderId,
                'family_root_id'    => $plan->familyRootId,
                'tipo'              => $plan->tipo,
                'quote_id'          => $ctx->quote?->id,
                'itbms_rate_id'     => $rate->id,
                'sales_project_id'  => $card?->sales_project_id,
                'master_client_id'  => $ctx->root->fiscalMasterClientId(),
                'cliente'           => $card?->subClient->business_name ?? $card?->masterClient->name ?? $ctx->root->customer_name,
                'monto'             => $plan->monto,
                'saldo_aplicado'    => 0,
                'created_by'        => $actor->id,
                // SCRUM-1140 — receptor congelado (lo que viaja al PAC); NULL = el builder lee el cliente en vivo.
                ...($receptor?->comoAtributosDeFactura() ?? []),
            ]);

            foreach ($plan->lines as $line) {
                $invoice->lines()->create(Arr::except($line, ['bruto']) + ['itbms_rate_id' => $rate->id]);
            }

            // SCRUM-1140 (S1140-02) — el rastro de Consumidor final se escribe en la MISMA transacción que la factura: sin
            // factura no hay evento, y una factura a `02` por decisión de quien factura nunca queda sin su evento.
            if ($auditoriaConsumidorFinal !== null) {
                $this->audit->log(
                    action:     AuditService::ADMIN_CONT_INVOICE_RECEPTOR_CONSUMIDOR_FINAL,
                    entityType: 'invoice',
                    entityId:   $invoice->id,
                    metadata:   $auditoriaConsumidorFinal + ['numero' => $invoice->numero, 'monto' => (float) $invoice->monto],
                );
            }

            return [$invoice, $plan];
        });
        [$invoice, $plan] = $creada;

        if ($invoice->sales_project_id !== null) {
            $this->abonos->reasociarAlFacturar((int) $invoice->sales_project_id, $invoice);
        }

        $invoice->setAttribute('total_a_pagar', $invoice->monto);
        // F6 — avisos del cierre (vacío en el caso normal): la respuesta de confirmar los expone.
        $invoice->setAttribute('advertencias', $plan->advertencias);

        // `orderId` = `invoice.order_id`: la tarjeta RAÍZ en una factura `completo`, la ENTREGA en
        // una `parcial`. Único listener (`MarkOrderInvoiceReady`) es un no-op desde SCRUM-876.
        Event::dispatch(new InvoiceCreated(
            invoiceId: $invoice->id,
            orderId: $invoice->order_id,
            numero: $invoice->numero,
            tenant: Tenant::current()->slug ?? '',
        ));

        return $invoice;
    }

    /**
     * Batch 3 (REQ-441) — confirma 1..N facturas de una vez, cada una con su propia transacción.
     *
     * @param list<int> $orderIds ver `previewBatch()`
     * @param list<int> $consumidorFinalIds SCRUM-1140 — subconjunto de `$orderIds` que se factura a Consumidor Final por decisión de quien factura
     * @return array{invoices: list<AdminContInvoice>, errors: list<array<string, mixed>>}
     */
    public function confirmBatch(array $orderIds, User $actor, string $modo = self::MODO_COMPLETO, array $consumidorFinalIds = []): array
    {
        $created = [];
        $errors  = [];

        $ids = $this->normalizeIds($orderIds, $modo);

        // Guarda de RUC (docs/specs/guarda-validacion-ruc-al-facturar.md): ninguna factura del lote se crea (ni gasta su
        // número ante MSL) mientras algún receptor no esté validado contra la DGI — decisión 6, bloquea todas.
        // SCRUM-1140: además congela el receptor de cada factura (razón social de la DGI, o Consumidor Final si quien
        // factura lo decidió); el lote entero se resuelve ANTES de crear nada y la consulta a la DGI queda fuera de la
        // transacción de `confirm()`.
        ['bloqueo' => $bloqueo, 'receptores' => $receptores, 'consumidor_final' => $auditoriaCf] = $this->resolverReceptores(
            $ids,
            $this->normalizeIds($consumidorFinalIds, $modo),
        );
        if ($bloqueo !== []) {
            return ['invoices' => [], 'errors' => $bloqueo];
        }

        foreach ($ids as $orderId) {
            $order = Order::find($orderId);
            if ($order === null) {
                $errors[] = ['order_id' => $orderId, 'missing' => ['Pedido no encontrado']];
                continue;
            }

            try {
                $created[] = $this->confirm($order, $actor, $modo, $receptores[$orderId] ?? null, $auditoriaCf[$orderId] ?? null);
            } catch (InvoicePreviewException $e) {
                $errors[] = ['order_id' => $orderId, 'missing' => $e->missing()];
            }
        }

        return ['invoices' => $created, 'errors' => $errors];
    }

    /**
     * SubCliente fiscal del pedido: mismo camino que `FiscalDocumentBuilder::receptorParaInvoice()` (raíz de familia ->
     * tarjeta del pipeline -> SubCliente), para validar exactamente el receptor que viajará en el documento fiscal.
     */
    private function receptorDe(Order $order): ?SubClient
    {
        return Order::find($order->familyRootId())?->fiscalSubClient();
    }

    /**
     * @param array<int, array<string, mixed>> $cache evaluaciones ya hechas en este lote, por sub_client_id
     * @return array<string, mixed>
     */
    private function evaluarReceptor(Order $order, array &$cache): array
    {
        $subClient = $this->receptorDe($order);

        return $cache[(int) $subClient?->id] ??= $this->receptorGuard->evaluar($subClient);
    }

    /**
     * Evalúa el receptor de cada pedido del lote y decide qué se congela en su factura (SCRUM-1140).
     *  - Pedido en `$consumidorFinalIds`: Consumidor Final por decisión de quien factura. No se bloquea por la DGI; la
     *    evaluación (cacheada) solo sirve para registrar la causa en auditoría.
     *  - Resto: `ReceptorFiscalGuard::receptorResuelto()` (razón social de la DGI). Si bloquea, ninguna factura del lote se
     *    genera (decisión 6 de SCRUM-1117) y se informa por qué.
     *
     * @param list<int> $orderIds ya normalizados
     * @param list<int> $consumidorFinalIds ya normalizados
     * @return array{bloqueo: list<array<string, mixed>>, receptores: array<int, ReceptorFiscalSnapshot>, consumidor_final: array<int, array<string, mixed>>}
     */
    private function resolverReceptores(array $orderIds, array $consumidorFinalIds): array
    {
        $cache      = [];
        $evaluados  = [];
        $receptores = [];
        $auditoria  = [];
        $hayBloqueo = false;

        foreach ($orderIds as $orderId) {
            $order = Order::find($orderId);
            if ($order === null) {
                continue;
            }

            $evaluacion = $this->evaluarReceptor($order, $cache);

            if (in_array($orderId, $consumidorFinalIds, true)) {
                $receptores[$orderId] = ReceptorFiscalSnapshot::consumidorFinal();
                $auditoria[$orderId]  = [
                    'cliente'           => $this->receptorDe($order)?->business_name,
                    'sub_client_id'     => $evaluacion['sub_client_id'] ?? null,
                    'documento'         => $this->enmascarar((string) ($evaluacion['ruc'] ?? '')),
                    'causa'             => in_array($evaluacion['estado'], [ReceptorFiscalGuard::OK, ReceptorFiscalGuard::NOMBRE_DISTINTO, ReceptorFiscalGuard::NO_APLICA, ReceptorFiscalGuard::NO_VALIDADO], true)
                        ? 'decision_consciente'
                        : $evaluacion['estado'],
                    'order_id'          => $orderId,
                ];
                $evaluados[$orderId] = null; // no bloquea el lote

                continue;
            }

            $evaluados[$orderId] = $evaluacion;
            if ($evaluacion['bloquea'] === true) {
                $hayBloqueo = true;
                continue;
            }

            $snapshot = $this->receptorGuard->receptorResuelto($evaluacion);
            if ($snapshot !== null) {
                $receptores[$orderId] = $snapshot;
            }
        }

        if (! $hayBloqueo) {
            return ['bloqueo' => [], 'receptores' => $receptores, 'consumidor_final' => $auditoria];
        }

        $errores = [];
        foreach ($evaluados as $orderId => $evaluacion) {
            $errores[] = $evaluacion !== null && $evaluacion['bloquea'] === true
                ? ['order_id' => $orderId, 'missing' => [$evaluacion['mensaje']], 'receptor' => $evaluacion]
                : ['order_id' => $orderId, 'missing' => ['No se generó: otra factura de este lote tiene un receptor sin validar.']];
        }

        return ['bloqueo' => $errores, 'receptores' => [], 'consumidor_final' => []];
    }

    /** Mismo enmascarado que `TaxIdValidationService::enmascarar()` (primeros y últimos 2 caracteres). */
    private function enmascarar(string $documento): ?string
    {
        return $documento === '' ? null : \App\Modules\AdminCont\FacturacionElectronica\Services\TaxIdValidationService::enmascarar($documento);
    }

    /**
     * Resuelve el contexto del pedido de `$order`, valida los datos requeridos y calcula el plan.
     * Compartido por la vista previa y por `confirm()` (que lo llama dentro de la transacción y es el
     * ÚNICO que pide `$registrarAvisos`: el log de un cierre con advertencias se escribe al emitir, no al leer).
     *
     * @return array{0: BillingContext, 1: InvoicePlan, 2: string} contexto, plan y modo efectivo
     * @throws InvoicePreviewException
     */
    private function resolvePlan(Order $order, string $modo, bool $registrarAvisos = false): array
    {
        $ctx     = $this->planner->context($order);
        $missing = $this->missingRequirements($ctx);
        if ($missing !== []) {
            throw new InvoicePreviewException($missing);
        }

        // En un pedido de entrega única "la entrega" ES el pedido: `entrega` se trata como `completo`.
        if ($modo === self::MODO_ENTREGA && ! $ctx->esEntregaUnica()) {
            $entrega = $ctx->entrega($order->id) ?? throw new InvoicePreviewException(['La entrega no pertenece a este pedido.']);

            return [$ctx, $this->planner->planEntrega($ctx, $entrega, $registrarAvisos), self::MODO_ENTREGA];
        }

        return [$ctx, $this->planner->planCompleto($ctx, $registrarAvisos), self::MODO_COMPLETO];
    }

    /**
     * Normaliza la lista de ids del request, sin duplicados y en orden de aparición. En `completo`
     * cada id (entrega o pedido) se lleva a su tarjeta RAÍZ — un pedido, una factura de completo; en
     * `entrega` cada id es la entrega a facturar.
     *
     * @param list<int> $orderIds
     * @return list<int>
     */
    private function normalizeIds(array $orderIds, string $modo): array
    {
        $ids = [];
        foreach ($orderIds as $id) {
            $id = $modo === self::MODO_ENTREGA
                ? (int) $id
                : (Order::find((int) $id)?->familyRootId() ?? (int) $id);
            if (! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * SCRUM-943 — resuelve el modelo real de `AdminContInvoice` para un `order_id`/`invoice_id`
     * (mismo criterio de resolución que `detail()`: parcial vigente de la entrega → completo
     * vigente que la cubre → recién después las anuladas). Para consumidores que necesitan el
     * modelo en sí (ej. `FiscalDocumentPreviewService`), no el array ya aplanado de `detail()`.
     */
    public function resolveInvoice(int $orderId, ?int $invoiceId = null): ?AdminContInvoice
    {
        $entrega  = Order::findOrFail($orderId);
        $ctx      = $this->planner->context($entrega);
        $invoices = AdminContInvoice::forOrder($ctx->root)->with('lines')->orderBy('id')->get();

        return $invoiceId !== null
            ? $invoices->firstWhere('id', $invoiceId)
            : self::pickInvoiceForDelivery($invoices, $entrega->id, incluirAnuladas: true);
    }

    /**
     * Batch 4 (REQ-447) — payload completo del modal de detalle/trazabilidad de una entrega.
     * Trazabilidad en el orden correcto (RN1): cotización completa si tiene folio; factura
     * completa si existe `AdminContInvoice`; guía de entrega SOLO completa si además de existir
     * la factura hay un `OrderDocument` de tipo `guia_entrega` — nunca antes (RN2/Escenario 3),
     * incluso si por algún motivo se subiera "fuera de orden" (no debería poder pasar por el gate
     * ya existente de Bodega, pero se defiende igual, mismo criterio que `missingRequirements()`).
     *
     * SCRUM-929 — `$orderId` es la ENTREGA consultada. Su factura es la propia (parcial, o la
     * "completo" anclada a esa tarjeta) o, si no tiene, la de "pedido completo" que la cubre; una
     * entrega de un pedido con solo parciales ajenas figura como pendiente de facturar.
     *
     * @return array<string, mixed>
     */
    public function detail(int $orderId, ?int $invoiceId = null): array
    {
        $entrega  = Order::findOrFail($orderId);
        $ctx      = $this->planner->context($entrega);
        $order    = $ctx->root;
        $card     = $order->pipelineCard;
        $quote    = $ctx->quote;
        $invoices = AdminContInvoice::forOrder($order)->with('lines')->orderBy('id')->get();

        // Batch 12 — desde que una "Corrección de datos" puede dejar 2 filas para la misma entrega
        // (la original anulada + la nueva reemplazo), la más reciente es siempre la que refleja el
        // estado vigente — una anulada nunca es más nueva que su reemplazo.
        // `$invoiceId` desambigua la tarjeta que tiene a la vez su parcial y el `completo` (la raíz):
        // sin él se muestra la parcial de la entrega.
        // Sin `invoice_id`: regla central (parcial vigente de la entrega → completo vigente que la cubre →
        // recién después las anuladas), orden determinista.
        /** @var AdminContInvoice|null $invoice */
        $invoice = $invoiceId !== null
            ? ($invoices->firstWhere('id', $invoiceId) ?? throw (new ModelNotFoundException())->setModel(AdminContInvoice::class, [$invoiceId]))
            : self::pickInvoiceForDelivery($invoices, $entrega->id, incluirAnuladas: true);

        $estado = 'pendiente-facturar';
        if ($invoice !== null) {
            $estado = $invoice->anulada_at !== null ? 'anulada' : 'facturada';
        }

        // La guía de entrega es por entrega: una factura parcial "tiene guía" si SU entrega la
        // produjo; una de pedido completo, si CUALQUIER entrega de la familia produjo una.
        $guiaEntrega = ['existe' => false, 'fecha' => null];
        if ($invoice !== null) {
            $guia = OrderDocument::query()
                ->whereIn('order_id', $invoice->tipo === AdminContInvoice::TIPO_PARCIAL ? [(int) $invoice->order_id] : $order->familyMemberIds())
                ->where('tipo', OrderDocument::TIPO_GUIA_ENTREGA)
                ->latest('created_at')
                ->first();
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
        if ($invoice === null && ! $ctx->esEntregaUnica() && $ctx->linkable) {
            // Entrega todavía sin facturar de un pedido facturable por entrega: el monto es el de
            // ESA entrega, no el total del pedido.
            try {
                $monto = $this->planner->planEntrega($ctx, $ctx->entrega($entrega->id) ?? $entrega)->monto;
            } catch (InvoicePreviewException) {
                $monto = 0.0;
            }
        }

        if ($invoice !== null && ($invoice->tipo === AdminContInvoice::TIPO_PARCIAL || $invoice->lines->isNotEmpty())) {
            // SCRUM-929 — el monto de una factura por entrega (o de un completo que solo cubre lo que
            // faltaba) es el SUYO, no el total del pedido. Las legadas, sin líneas, siguen mostrando
            // el monto de la tarjeta como siempre.
            $monto = $invoice->monto;
        }

        if ($invoice !== null) {
            $saldoAplicado = $invoice->saldo_aplicado;
            $totalAPagar   = round($invoice->monto - $invoice->saldo_aplicado, 2);
        } else {
            $saldoAplicado = 0.0;
            $totalAPagar   = $monto;
        }

        $payload = [
            'order_id'              => $invoice !== null ? (int) $invoice->order_id : $entrega->id,
            'order_number'          => $order->order_number,
            'master_client_id'      => $order->fiscalMasterClientId(),
            'cliente'               => $card?->subClient->business_name ?? $card?->masterClient->name ?? $order->customer_name,
            // SCRUM-952 — misma fuente que InvoiceBookService::resolveRuc() (el RUC "oficial" del
            // libro de facturas), no Quote::ruc, para no introducir una segunda fuente de verdad.
            // SCRUM-1140 — con receptor congelado en la factura, el documento (RUC, tipo y razón social) es el que se envió
            // al PAC, no el actual del cliente.
            'cliente_ruc'           => $invoice?->receptor_tipo !== null ? $invoice->receptor_ruc : $order->fiscalSubClient()?->tax_id,
            // SCRUM-1003 — tipo de cliente ante la DGI (`02` = Consumidor Final: sin RUC, el PDF lleva una leyenda).
            'cliente_receptor_tipo' => $invoice->receptor_tipo ?? ($order->fiscalSubClient()?->receptor_tipo ?: ReceptorTipo::CONTRIBUYENTE),
            // SCRUM-1140 — razón social validada por la DGI que viaja en la factura (NULL sin receptor congelado o en `02`).
            'receptor_nombre'       => $invoice?->receptor_tipo === ReceptorTipo::CONTRIBUYENTE ? $invoice->receptor_nombre : null,
            'vendedor'              => $quote !== null ? trim("{$quote->owner->first_name} {$quote->owner->last_name}") : null,
            // SCRUM-1147 — responsables del pedido (owner + compartido_con de la tarjeta), para saber a quién contactar.
            'vendedores_responsables' => $this->vendedoresResponsables($card),
            // SCRUM-1166 — la factura de un cliente exento muestra «ITBMS (Exento)» (la tasa se fijó al facturar desde la cotización).
            'itbms_exento'          => $invoice !== null ? ($invoice->itbmsRate !== null && (float) $invoice->itbmsRate->porcentaje === 0.0) : ($quote?->isItbmsExempt() ?? false),
            'monto'                 => $monto,
            'saldo_aplicado'        => $saldoAplicado,
            'total_a_pagar'         => $totalAPagar,
            'cotizacion_folio'      => $quote?->folio,
            'fecha_cotizacion'      => $quote?->generated_at?->toDateString(),
            'numero_factura'        => $invoice?->numero,
            'fecha_factura'         => $invoice?->created_at?->toDateString(),
            // SCRUM-952 — 30 días calendario ya no está hardcodeado, viene de
            // AdminContFiscalSettings::dias_credito_factura (configurable por superadmin).
            'fecha_vencimiento'     => $invoice !== null
                ? $invoice->created_at->copy()->addDays(AdminContFiscalSettings::current()->dias_credito_factura)->toDateString()
                : null,
            'estado'                => $estado,
            'es_anulada'            => $invoice?->anulada_at !== null,
            'guia_entrega'          => $guiaEntrega,
            // SCRUM-929 — a qué pedido/entrega/factura corresponde este detalle.
            'pedido_id'             => $order->id,
            'entrega_id'            => $entrega->id,
            'invoice_id'            => $invoice?->id,
            'tipo_factura'          => $invoice?->tipo,
            // SCRUM-943 — estado de Facturación Electrónica: null (sin invoice, o certificación
            // nunca corrida) | no_aplica (pac_ambiente=apagado o tipo deshabilitado) | pendiente |
            // certificado | rechazado | error_configuracion (nunca mostrar como "rechazado por la
            // DGI" — es un problema de configuración nuestro, ver FiscalDocumentResult).
            'fe_estado'             => $invoice?->fe_estado,
            'cufe'                  => $invoice?->cufe,
            'qr_content'            => $invoice?->qr_content,
            'pac_provider_usado'    => $invoice?->pac_provider_usado,
            'concepto'              => $this->conceptoDeFactura($invoice, $ctx),
            // SCRUM-876 — avance de las entregas parciales del pedido (una sola fila si es entrega única).
            'entregas_del_pedido'   => $this->entregasResumen($ctx, $invoices),
            'cobrabilidad'          => $cobrabilidad,
            // SCRUM-864 — "Marcar como incobrable" no tiene sentido de negocio sobre una factura ya
            // cobrada (el pago ya se realizó). Mismo criterio de "no pagada" que `puede_registrar_cobro`
            // debajo (saldo_aplicado < monto), reusado acá para no duplicar la regla de "pagada".
            // Fase 13 (2.8): tampoco se ofrece sobre una factura que YA es incobrable o que ya tiene una propuesta pendiente (el servicio la rechazaba
            // con 422 después de mostrar la acción).
            'puede_marcar_incobrable' => $invoice !== null
                && $invoice->anulada_at === null
                && ! $invoice->es_incobrable
                && ! $invoice->incobrable_pendiente
                && $invoice->saldo_aplicado < $invoice->monto,
            // RN4 REQ-448 — el motivo original queda guardado como registro aunque se rechace,
            // pero solo se expone acá cuando hay algo vigente que mostrar (pendiente o aprobada).
            'motivo_incobrable'     => $cobrabilidad !== 'normal' ? $invoice?->motivo_incobrable : null,
            // REQ-451 RN1/RN2 — facturada y no incobrable APROBADA. Una propuesta pendiente sigue
            // contando (RN2: "sigue siendo técnicamente cobrable hasta que Gerencia decida").
            // SCRUM-858 — el botón quedaba visible aun con la factura ya cobrada porque esta
            // condición nunca miraba `saldo_aplicado` (que solo se incrementa con pagos
            // `estado=confirmado`, ver ClientCollectionReportService.php). Mismo criterio de
            // "pendiente" que ya usan PaymentMethodSalesReportService/ClientCollectionReportService.
            'puede_registrar_cobro' => $invoice !== null
                && $invoice->anulada_at === null
                && ! $invoice->es_incobrable
                && $invoice->saldo_aplicado < $invoice->monto,
        ];

        if ($invoice !== null) {
            $payload += $this->pagoBlock();
        }

        if ($invoice !== null && $invoice->lines->isNotEmpty()) {
            // SCRUM-929 — factura con líneas: el desglose sale de lo que REALMENTE se facturó, no de
            // la cotización completa. Las facturas legadas (sin líneas) siguen por el `else`.
            $payload += self::breakdownFromLines(self::lineArrays($invoice->lines), $ctx->quoteItems, $quote);
        } elseif ($invoice !== null && $quote !== null) {
            $payload += self::fiscalBreakdown($quote);
        }

        return $payload;
    }

    /**
     * Texto corto de qué cubre una factura con líneas ("Entrega 2 de 3" / "Pedido completo") para el
     * PDF. `null` en las facturas legadas (sin líneas): se renderizan exactamente como siempre.
     */
    private function conceptoDeFactura(?AdminContInvoice $invoice, BillingContext $ctx): ?string
    {
        if ($invoice === null || $invoice->lines->isEmpty()) {
            return null;
        }
        if ($invoice->tipo === AdminContInvoice::TIPO_COMPLETO) {
            return 'Pedido completo';
        }

        $entrega = $ctx->entrega((int) $invoice->order_id);

        return $entrega !== null && $entrega->sequence_in_family !== null && $entrega->total_in_family !== null
            ? "Entrega {$entrega->sequence_in_family} de {$entrega->total_in_family}"
            : 'Entrega parcial';
    }

    /**
     * @param Collection<int, \App\Modules\AdminCont\Models\AdminContInvoiceLine> $lines
     * @return list<array{quote_item_id: int|null, descripcion: string, cantidad: float, precio_unitario: float, subtotal: float, itbms: float, total: float}>
     */
    public static function lineArrays(Collection $lines): array
    {
        return array_values($lines->map(fn ($l): array => [
            'quote_item_id'   => $l->quote_item_id,
            'descripcion'     => $l->descripcion,
            'cantidad'        => $l->cantidad,
            'precio_unitario' => $l->precio_unitario,
            'subtotal'        => $l->subtotal,
            'itbms'           => $l->itbms,
            'total'           => $l->total,
        ])->all());
    }

    /**
     * Desglose fiscal (mismas claves que `fiscalBreakdown()`) de lo facturado en `$lines`. Las filas
     * muestran el importe BRUTO del ítem (antes del descuento de Totales de la cotización, igual que
     * `fiscalBreakdown()`); `descuentos` es la parte de ese descuento que le tocó a esta factura, de
     * modo que subtotal − descuentos + ITBMS = total = Σ de los totales de línea (= monto).
     *
     * @param list<array{quote_item_id: int|null, descripcion: string, cantidad: float, precio_unitario: float, subtotal: float, itbms: float, total: float}> $lines
     * @param Collection<int, QuoteItem> $quoteItems
     * @return array{subtotal: float, descuentos: float, itbms: float, total: float, items: list<array<string, mixed>>}
     */
    public static function breakdownFromLines(array $lines, Collection $quoteItems, ?Quote $quote): array
    {
        $settings = $quote !== null ? PricingSettings::current() : null;

        $items = [];
        $bruto = $neto = $itbms = 0.0;
        foreach ($lines as $line) {
            $gross = $line['subtotal'];
            $qi    = $line['quote_item_id'] !== null ? $quoteItems->get($line['quote_item_id']) : null;
            if ($quote !== null && $settings !== null && $qi !== null && $qi->quantity > 0) {
                $fraction = $line['cantidad'] >= $qi->quantity - InvoiceBillingPlanner::QTY_EPSILON ? 1.0 : $line['cantidad'] / $qi->quantity;
                $gross    = round($qi->lineTotal($quote->discount_mode, $quote->global_discount_percent, $quote->price_type, $settings) * $fraction, 2);
            }

            $items[] = [
                'descripcion'        => $line['descripcion'],
                // SCRUM-952 — misma fuente que el "código" que ya usa NucJsonMapper para el PAC
                // (QuoteItem::reference).
                'referencia_publica' => $qi?->reference,
                'cantidad'           => $line['cantidad'],
                'precio_unitario'    => $line['cantidad'] > 0 ? round($gross / $line['cantidad'], 2) : 0.0,
                'subtotal'           => $gross,
            ];
            $bruto += $gross;
            $neto  += $line['subtotal'];
            $itbms += $line['itbms'];
        }

        $descuentos = max(0.0, round($bruto - $neto, 2));

        return [
            'subtotal'   => round($neto + $descuentos, 2),
            'descuentos' => $descuentos,
            'itbms'      => round($itbms, 2),
            'total'      => round($neto + $itbms, 2),
            'items'      => $items,
        ];
    }

    /**
     * REQ-448 — Contabilidad/Aprobador/Gerencia proponen, solo Gerencia decide (gate en el middleware de la ruta).
     * RN1: solo sobre facturas emitidas vigentes (ni pendientes, ni ya anuladas). RN5: no se puede
     * proponer un segundo cambio mientras el primero sigue sin resolver.
     *
     * SCRUM-929 (B3a) — opera por FACTURA, no por pedido: `$invoiceId` elige la factura; sin él se usa la
     * única factura activa del pedido (compatibilidad con el frontend anterior) y, si hay varias,
     * se rechaza pidiendo el `invoice_id`. Un `$invoiceId` de otro pedido → `ModelNotFoundException` (404).
     *
     * @throws InvoiceStateException
     */
    public function proposeUncollectible(int $orderId, string $motivo, ?int $invoiceId = null): AdminContInvoice
    {
        return DB::transaction(function () use ($orderId, $motivo, $invoiceId): AdminContInvoice {
            $invoice = $this->invoiceForUncollectible($orderId, $invoiceId);

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
     *  anterior (el motivo queda guardado como registro, sin marca visible en la UI normal).
     *  SCRUM-929 (B3a) — mismo criterio de `$invoiceId` que `proposeUncollectible()`.
     *
     * @throws InvoiceStateException
     */
    public function decideUncollectible(int $orderId, bool $approve, ?int $invoiceId = null): AdminContInvoice
    {
        return DB::transaction(function () use ($orderId, $approve, $invoiceId): AdminContInvoice {
            $invoice = $this->invoiceForUncollectible($orderId, $invoiceId);

            if ($invoice === null || $invoice->anulada_at !== null || ! $invoice->incobrable_pendiente) {
                throw new InvoiceStateException('No hay ninguna propuesta de incobrable pendiente para esta factura.');
            }

            $invoice->update(['incobrable_pendiente' => false, 'es_incobrable' => $approve]);

            return $invoice->fresh() ?? $invoice;
        });
    }

    /**
     * La factura sobre la que opera una propuesta/decisión de incobrable (con lock de TODAS las del
     * pedido, dentro de la transacción). Con `$invoiceId`: esa, si es del pedido (si no, 404). Sin él: la
     * única factura activa; ninguna → `null`; varias → `InvoiceStateException` (422) pidiendo el `invoice_id`.
     *
     * @throws InvoiceStateException
     * @throws ModelNotFoundException
     */
    private function invoiceForUncollectible(int $orderId, ?int $invoiceId): ?AdminContInvoice
    {
        $familia = AdminContInvoice::forOrder($orderId)->orderBy('id')->lockForUpdate()->get();

        if ($invoiceId !== null) {
            /** @var AdminContInvoice $invoice */
            $invoice = $familia->firstWhere('id', $invoiceId)
                ?? throw (new ModelNotFoundException())->setModel(AdminContInvoice::class, [$invoiceId]);

            return $invoice;
        }

        $activas = $familia->filter(fn (AdminContInvoice $i): bool => $i->anulada_at === null);
        if ($activas->count() > 1) {
            throw new InvoiceStateException('Este pedido tiene varias facturas activas: indique sobre cuál operar con invoice_id.');
        }

        return $activas->first();
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

            $index = array_search(AgingBucketCalculator::bucketForDays($diasVencido), AgingBucketCalculator::BUCKETS, true);

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
     * tarjeta de Reportes (y la base de la comisión de Contabilidad, REQ-567). RN3 REQ-568/RN4 REQ-567:
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

        // SCRUM-1168 F2 — manda la fecha de aplicación del dinero a la factura (lo histórico, sin ella, usa la del cobro).
        $lines = AdminContPaymentInvoice::query()
            ->whereHas('payment', fn ($q) => $q->where('estado', AdminContPayment::ESTADO_CONFIRMADO))
            ->where(fn ($q) => $q
                ->whereBetween('fecha_aplicacion', [$monthStart, $monthEnd])
                ->orWhere(fn ($q2) => $q2->whereNull('fecha_aplicacion')
                    ->whereHas('payment', fn ($p) => $p->whereBetween('created_at', [$monthStart, $monthEnd]))))
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
            $diasVencidos = (int) $vencimiento->diffInDays(CarbonImmutable::parse($line->fecha_aplicacion ?? $payment->created_at), false);

            if ($diasVencidos > 90) {
                $cobradoMes += $line->monto_aplicado;
            }
        }

        // SCRUM-1168 F3 — compensaciones del mes cuyo dinero salió de un abono histórico o del saldo de una nota (sin cobro asociado): cuentan en
        // la fecha del cruce, igual que un cobro. Las que salen de un cobro ya entran arriba por su línea de aplicación.
        $compLines = AdminContCompensationLine::query()
            ->whereIn('source_type', [AdminContCompensationLine::SOURCE_ABONO, AdminContCompensationLine::SOURCE_NOTA_CREDITO])
            ->whereIn('compensation_id', AdminContCompensation::query()
                ->where('estado', AdminContCompensation::ESTADO_CONFIRMADO)->whereBetween('confirmed_at', [$monthStart, $monthEnd])->select('id'))
            ->get();
        $compensations = AdminContCompensation::query()->whereIn('id', $compLines->pluck('compensation_id')->unique())->get()->keyBy('id');
        $facturas      = AdminContInvoice::query()->whereIn('id', $compLines->pluck('invoice_id')->unique())->where('es_incobrable', false)->get()->keyBy('id');
        foreach ($compLines as $cl) {
            $invoice = $facturas->get($cl->invoice_id);
            $cmp     = $compensations->get($cl->compensation_id);
            if ($invoice === null || $cmp === null) {
                continue;
            }
            $vencimiento = CarbonImmutable::parse($invoice->created_at)->addDays($diasCredito);
            if ((int) $vencimiento->diffInDays(CarbonImmutable::parse($cmp->confirmed_at), false) > 90) {
                $cobradoMes += $cl->monto;
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
     * la entrega haya llegado a una etapa facturable de Bodega (`InvoiceBillingPlanner::etapaFacturable()`) — la UI nunca
     * ofrece "Generar factura" antes de eso, pero un `order_id` de un pedido todavía en picking no
     * debe poder facturarse vía API directa (hallazgo de Pre-QA, corregido en el mismo batch).
     *
     * @return list<string>
     */
    private function missingRequirements(BillingContext $ctx): array
    {
        // SCRUM-876 — se factura el PEDIDO, no la entrega: alcanza con que CUALQUIER entrega de la
        // familia haya llegado a una etapa facturable. El resto de requisitos
        // (cotización/monto/cliente) viven en la tarjeta. SCRUM-929 — además, `planEntrega()` exige
        // que la entrega puntual sea facturable.
        $card    = $ctx->root->pipelineCard;
        $quote   = $ctx->quote;
        $missing = [];

        $algunaFacturable = $ctx->members->contains(fn (Order $o): bool => InvoiceBillingPlanner::etapaFacturable($o));
        if (! $algunaFacturable) {
            $missing[] = 'Ninguna entrega del pedido llegó a la etapa "Por despachar" de Bodega';
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
     * Batch 3 (REQ-443 RN1) — payload de vista previa de una factura: cabecera + desglose fiscal
     * (subtotal/descuentos/ITBMS/total/items) de LO QUE SE FACTURARÍA (`$plan`), para que la pantalla
     * tenga el mismo contenido que el documento fiscal (Batch 4/REQ-449 reusa este desglose).
     * Sin `$plan` (pedidos que no llegan a calcularse por líneas) es la forma histórica: monto de la
     * tarjeta y desglose de la cotización completa.
     *
     * @return array<string, mixed>
     */
    private function previewPayload(BillingContext $ctx, ?InvoicePlan $plan, string $modo): array
    {
        $root  = $ctx->root;
        $card  = $root->pipelineCard;
        $quote = $ctx->quote;

        $payload = [
            'order_id'            => $plan->orderId ?? $root->id,
            'order_number'        => $root->order_number,
            'cotizacion_folio'    => $quote?->folio,
            'cliente'             => $card?->subClient->business_name ?? $card?->masterClient->name ?? $root->customer_name,
            'monto'               => $plan !== null ? $plan->monto : $card?->amount,
            // SCRUM-929 — qué se factura, y si esta factura deja el pedido sin saldo.
            'modo'                => $modo,
            'tipo'                => $plan->tipo ?? AdminContInvoice::TIPO_COMPLETO,
            'cierra_pedido'       => $plan->cierraPedido ?? true,
            'restante_despues'    => $plan->restanteDespues ?? 0.0,
            'advertencias'        => $plan->advertencias ?? [],
            // SCRUM-876 — entregas del pedido, para avisar en la vista previa cuántas hay y cuál se factura.
            'entregas_del_pedido' => $this->entregasResumen($ctx),
        ];

        if ($plan !== null && $plan->lines !== []) {
            $lines = [];
            foreach ($plan->lines as $l) {
                $lines[] = [
                    'quote_item_id'   => $l['quote_item_id'],
                    'descripcion'     => $l['descripcion'],
                    'cantidad'        => $l['cantidad'],
                    'precio_unitario' => $l['precio_unitario'],
                    'subtotal'        => $l['subtotal'],
                    'itbms'           => $l['itbms'],
                    'total'           => $l['total'],
                ];
            }
            $payload += self::breakdownFromLines($lines, $ctx->quoteItems, $quote);
        } elseif ($quote !== null) {
            $payload += self::fiscalBreakdown($quote);
        }

        return $payload;
    }

    /**
     * REQ-449 RN1 — bloque de datos de pago del documento fiscal (Pago a / Cuenta número /
     * Responsable). No hay todavía ningún concepto de "cuenta de cobro asignada a esta factura"
     * (eso depende de Cobros, batch futuro) — se muestra la primera cuenta bancaria activa como
     * cuenta de referencia. SCRUM-857 — el número ya no es forzosamente enmascarado, ver
     * docblock de `paymentBlock()`.
     *
     * "Responsable" — corregido en Batch 9 de Estado de Cuenta (REQ-458 RN3): antes usaba el
     * `created_by` de ESTA factura puntual, lo que hacía imposible que Estado de Cuenta (un
     * documento por CLIENTE, que agrega facturas de posiblemente distintos creadores) mostrara el
     * mismo valor "idéntico entre pantallas" que exige RN3. Ahora lee
     * `AdminContFiscalSettings::responsable_administracion_user_id` — un único responsable a nivel
     * empresa, no por factura — mismo campo que usa `AccountStatementService`.
     *
     * @return array{cuenta_pago: string|null, banco: string|null, tipo_cuenta: string|null, numero_cuenta: string|null, responsable: string|null}
     */
    private function pagoBlock(): array
    {
        return self::paymentBlock();
    }

    /**
     * Bloque de datos de pago, a nivel EMPRESA (no por factura ni por cliente) — compartido tal
     * cual por `AccountStatementService` (Batch 9, REQ-458 RN3: "idéntico entre pantallas").
     *
     * SCRUM-857 (REQ-458) — `cuenta_pago` ahora usa el número COMPLETO cuando la cuenta lo tiene
     * cargado (`numero_cuenta_completo`), en vez de siempre enmascarado: este bloque solo aparece
     * en documentos de cobro (Estado de Cuenta, Facturas), que el CLIENTE necesita para pagar por
     * transferencia — decisión de Luis 2026-09-08, ver docblock de la migración/modelo.
     *
     * SCRUM-928 (D-6, decisión de Luis 2026-09-18) — YA NO cae al enmascarado (`****XXXX`) cuando
     * la cuenta no tiene el número completo cargado: un documento al cliente nunca muestra
     * asteriscos. Sin número completo, `numero_cuenta` y `cuenta_pago` quedan `null` (las vistas
     * muestran "—") — el dato se carga desde Cuentas Bancarias, no se inventa ni se enmascara acá.
     * `banco`/`tipo_cuenta`/`numero_cuenta` se agregan sueltos (sin el
     * bloque compuesto) para pantallas que necesitan mostrarlos en filas separadas (Estado de
     * Cuenta) — `cuenta_pago` sigue igual para los consumidores que ya existían (factura impresa,
     * vista de detalle de factura) y nunca esperaron una fila de "Banco" aparte.
     *
     * @return array{cuenta_pago: string|null, banco: string|null, tipo_cuenta: string|null, numero_cuenta: string|null, responsable: string|null}
     */
    public static function paymentBlock(): array
    {
        $cuenta = AdminContBankAccount::where('activa', true)->oldest('id')->first();
        // `trim() === ''` además de null: un valor vacío/blanco cargado a mano cuenta como "sin número".
        $numeroCuenta = $cuenta !== null && trim((string) $cuenta->numero_cuenta_completo) !== ''
            ? trim((string) $cuenta->numero_cuenta_completo)
            : null;
        $cuentaPago = $numeroCuenta !== null ? "{$cuenta->banco} {$numeroCuenta}" : null;

        $userId = AdminContFiscalSettings::current()->responsable_administracion_user_id;
        $responsable = null;
        if ($userId !== null) {
            $user = User::find($userId);
            $responsable = $user !== null ? trim("{$user->first_name} {$user->last_name}") : null;
        }

        return [
            'cuenta_pago'   => $cuentaPago,
            'banco'         => $cuenta?->banco,
            'tipo_cuenta'   => $cuenta?->tipo_cuenta,
            'numero_cuenta' => $numeroCuenta,
            'responsable'   => $responsable,
        ];
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
                    'descripcion'        => $item->description,
                    'referencia_publica' => $item->reference,
                    'cantidad'           => $item->quantity,
                    'precio_unitario'    => $item->quantity > 0 ? round($lineTotal / $item->quantity, 2) : 0.0,
                    'subtotal'           => round($lineTotal, 2),
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
