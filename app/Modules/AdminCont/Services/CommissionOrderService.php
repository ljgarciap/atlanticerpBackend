<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\VentasDiseno\Services\PipelineStages;
use App\Models\User;
use App\Modules\AdminCont\Models\AdminContBankMovement;
use App\Modules\AdminCont\Models\AdminContCommissionAdjustment;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\CommissionMonthSnapshot;
use App\Shared\Auth\JwtActor;
use App\Modules\AdminCont\Models\CommissionTier;
use App\Shared\Multitenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Batch 14 de Admin&Cont (SCRUM-575→579, REQ-498→502) + Batch 15 (SCRUM-580→584, REQ-503→507).
 * Ver docs/adr/ADR-SCRUM575-579-batch14-comisiones-internas.md y
 * docs/adr/ADR-SCRUM580-584-batch15-comisiones-internas.md — deliberadamente independiente de
 * `App\Modules\VentasDiseno\Services\CommissionService` (REQ-073, otro módulo, coexisten por
 * decisión de Luis).
 *
 * "Pedido comisionable" no es una tabla propia — se arma en vivo con un join de solo lectura
 * `ventas_diseno.pipeline_cards` (stage=approved) → `bodega.orders` (pipeline_card_id) →
 * `admin_contab.invoices` (order_id), sin FK cross-schema (Postgres no las permite entre módulos
 * independientes en este proyecto). `total_cobrado` es siempre `invoices.saldo_aplicado` — nunca
 * un dato capturado aparte (RN de REQ-501). `total_nota_credito` es siempre la suma de
 * `admin_contab.credit_notes` en `estado='aplicada'` contra esa factura (RN de REQ-503).
 *
 * Proyectos compartidos (REQ-506, Batch 15): si `pipeline_cards.compartido_con` no está vacío, un
 * mismo pedido genera UNA fila comisionable por cada responsable (owner_id + cada id de
 * compartido_con), cada una con `total_pedido` = `amount / cantidad_de_responsables` — por eso
 * `rawOrders()` ya no filtra por `owner_id` a nivel SQL (un responsable compartido no es el
 * owner_id de la tarjeta) y expande en PHP después de traer todas las tarjetas aprobadas.
 *
 * @phpstan-type CommissionOrderRow array{pedido_id: int, vendedor_id: int, numero_pedido: mixed,
 *     cliente: mixed, fecha_pedido: CarbonImmutable|null, factura_numero: mixed,
 *     fecha_factura: CarbonImmutable|null, fecha_cobro_completo: CarbonImmutable|null,
 *     mes_cierre: CarbonImmutable|null, total_pedido: float, total_pedido_completo: float|null,
 *     total_facturado: float|null, total_cobrado: float, total_nota_credito: float,
 *     nota_credito_numeros: mixed, compartido_con: array<int, string>, responsables_count: int,
 *     estado: string}
 */
final class CommissionOrderService
{
    public const ESTADO_PENDIENTE_COBRO = 'pendiente_cobro';
    public const ESTADO_POR_PAGAR       = 'por_pagar';
    public const ESTADO_PAGADO          = 'pagado';

    /** Cache en memoria de la corrida actual — `rawOrders()` es costosa (varias subqueries) y se
     *  pide muchas veces por request (una por vendedor visible).
     *  @var Collection<int, CommissionOrderRow>|null */
    private ?Collection $allOrdersCache = null;

    /**
     * Tabla escalonada paramétrica (REQ-498 RN1) — nunca hardcodeada, ver `commission_tiers`.
     */
    public function percentFor(float $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        $tier = CommissionTier::query()
            ->where('monto_minimo', '<=', $total)
            ->where(function ($q) use ($total): void {
                $q->whereNull('monto_maximo')->orWhere('monto_maximo', '>=', $total);
            })
            ->orderBy('orden')
            ->first();

        return $tier->porcentaje ?? 0.0;
    }

    /**
     * Todos los pedidos comisionables de UN vendedor (como owner_id o como co-responsable de un
     * proyecto compartido), en cualquier mes de cierre — hace falta el universo completo (no solo
     * el mes filtrado) porque REQ-499 RN2/REQ-504 exigen arrastre de pedidos pendientes de meses
     * ya cerrados.
     *
     * @return Collection<int, CommissionOrderRow>
     */
    public function ordersForVendor(int $vendorId): Collection
    {
        return $this->ordersForAllVendors()->filter(fn (array $o) => $o['vendedor_id'] === $vendorId)->values();
    }

    /** @return Collection<int, CommissionOrderRow> */
    public function ordersForAllVendors(): Collection
    {
        if ($this->allOrdersCache !== null) {
            return $this->allOrdersCache;
        }

        return $this->allOrdersCache = $this->rawOrders();
    }

    /** @return Collection<int, CommissionOrderRow> */
    private function rawOrders(): Collection
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return collect();
        }

        $pipelineTable   = "{$tenant->slug}_ventas_diseno.pipeline_cards";
        $orderTable      = "{$tenant->slug}_bodega.orders";
        $invoiceTable    = "{$tenant->slug}_admin_contab.invoices";
        $paymentTable    = "{$tenant->slug}_admin_contab.payments";
        $payInvTable     = "{$tenant->slug}_admin_contab.payment_invoices";
        $compTable       = "{$tenant->slug}_admin_contab.compensations";
        $compLineTable   = "{$tenant->slug}_admin_contab.compensation_lines";
        $creditNoteTable = "{$tenant->slug}_admin_contab.credit_notes";

        // SCRUM-929 (B3b, decisión de Luis 2026-09-19) — igual que en `ArchitectCommissionService`:
        // una `parcial` cuelga de SU entrega (`order_id` ≠ raíz), así que `inv.order_id = o.id` (a)
        // no veía las parciales de entregas hijas y (b) con una parcial Y un completo en la raíz
        // hacía fan-out (dos filas por pedido, duplicando `total_pedido` en el Σ del mes — infla el
        // % de escalón). Se agrega por `family_root_id`. `factura_numero`/`fecha_factura` (solo
        // display) pasan a ser los de la factura MÁS RECIENTE de la familia — con varias activas ya
        // no hay una candidata única, y la más reciente es la que normalmente cierra el pedido.
        $rows = DB::table("{$pipelineTable} as pc")
            ->leftJoin("{$orderTable} as o", function ($join): void {
                $join->on('o.pipeline_card_id', '=', 'pc.id')->whereNull('o.family_id');
            })
            ->leftJoinSub(
                DB::table($invoiceTable)
                    ->whereNull('anulada_at')
                    ->selectRaw('family_root_id, sum(monto) as total_facturado, sum(saldo_aplicado) as total_cobrado')
                    ->groupBy('family_root_id'),
                'invagg',
                'invagg.family_root_id',
                '=',
                'o.id',
            )
            ->whereIn('pc.stage', PipelineStages::won())
            ->select([
                'pc.id as pedido_id',
                'pc.owner_id as owner_id',
                'pc.compartido_con',
                'pc.amount as total_pedido_completo',
                'pc.stage_changed_at',
                'o.order_number as numero_pedido',
                'o.customer_name as cliente',
                'invagg.total_facturado',
                'invagg.total_cobrado',
                DB::raw("(
                    select inv3.numero from {$invoiceTable} inv3
                    where inv3.family_root_id = o.id and inv3.anulada_at is null
                    order by inv3.created_at desc limit 1
                ) as factura_numero"),
                DB::raw("(
                    select inv3.created_at from {$invoiceTable} inv3
                    where inv3.family_root_id = o.id and inv3.anulada_at is null
                    order by inv3.created_at desc limit 1
                ) as fecha_factura"),
                // Fecha del cobro más reciente CONFIRMADO de CUALQUIER factura activa de la familia —
                // proxy razonable de "cuándo llegó al 100%" (no se trackea ese instante exacto en
                // ningún lado; solo es dato de display en la tabla de pedidos, RN5 de REQ-501 usa
                // el mes de CIERRE DEL PEDIDO, no esta fecha, para decidir el estado).
                DB::raw("(
                    select max(f) from (
                        select coalesce(pi.fecha_aplicacion, p.created_at) as f from {$paymentTable} p
                        inner join {$payInvTable} pi on pi.payment_id = p.id
                        inner join {$invoiceTable} inv2 on inv2.id = pi.invoice_id
                        where inv2.family_root_id = o.id and inv2.anulada_at is null and p.estado = 'confirmado'
                        union all
                        select cmp.confirmed_at as f from {$compLineTable} cl
                        inner join {$compTable} cmp on cmp.id = cl.compensation_id and cmp.estado = 'confirmado'
                        inner join {$invoiceTable} inv5 on inv5.id = cl.invoice_id
                        where cl.source_type in ('abono', 'nota_credito') and inv5.family_root_id = o.id and inv5.anulada_at is null
                    ) fechas
                ) as fecha_cobro_completo"),
                // REQ-503 — solo notas YA aplicadas (pendientes/rechazadas no han reducido nada),
                // contra cualquier factura ACTIVA de la familia (antes: solo la factura única).
                DB::raw("(
                    select coalesce(sum(cn.monto), 0) from {$creditNoteTable} cn
                    inner join {$invoiceTable} inv4 on inv4.id = cn.factura_origen_id
                    where inv4.family_root_id = o.id and inv4.anulada_at is null and cn.estado = 'aplicada'
                ) as total_nota_credito"),
                DB::raw("(
                    select string_agg(cn.numero, ', ') from {$creditNoteTable} cn
                    inner join {$invoiceTable} inv5 on inv5.id = cn.factura_origen_id
                    where inv5.family_root_id = o.id and inv5.anulada_at is null and cn.estado = 'aplicada'
                ) as nota_credito_numeros"),
            ])
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        // Nombres de todos los responsables (owner + compartido_con de cualquier fila) para el
        // badge "↔ Compartido con [nombres]" (RN3 REQ-506) — una sola query, no N+1.
        $allInvolvedIds = $rows->flatMap(function (object $row) {
            $shared = $row->compartido_con !== null ? (json_decode((string) $row->compartido_con, true) ?? []) : [];
            return [(int) $row->owner_id, ...array_map('intval', $shared)];
        })->unique()->values();
        $namesById = User::whereIn('id', $allInvolvedIds)->get()
            ->mapWithKeys(fn (User $u) => [$u->id => trim("{$u->first_name} {$u->last_name}")]);

        return $rows->flatMap(function (object $row) use ($namesById): array {
            $stageChangedAt = $row->stage_changed_at !== null ? CarbonImmutable::parse($row->stage_changed_at) : null;
            $mesCierre      = $stageChangedAt?->startOfMonth();
            $totalCompleto  = (float) $row->total_pedido_completo;
            $totalFacturado = $row->total_facturado !== null ? (float) $row->total_facturado : null;
            $totalCobrado   = $row->total_cobrado !== null ? (float) $row->total_cobrado : 0.0;
            $totalNC        = (float) $row->total_nota_credito;
            $estado         = $this->estadoFor($mesCierre, $totalFacturado, $totalCobrado, $totalCompleto);

            /** @var list<int> $sharedIds */
            $sharedIds = $row->compartido_con !== null ? array_map('intval', json_decode((string) $row->compartido_con, true) ?? []) : [];
            $responsableIds = array_values(array_unique([(int) $row->owner_id, ...$sharedIds]));
            $count = count($responsableIds);
            $share = $count > 1 ? round($totalCompleto / $count, 2) : $totalCompleto;

            return array_map(function (int $vendedorId) use (
                $row, $stageChangedAt, $mesCierre, $totalFacturado, $totalCobrado, $totalNC,
                $estado, $totalCompleto, $share, $count, $responsableIds, $namesById,
            ): array {
                $others = $count > 1
                    ? collect($responsableIds)->reject(fn (int $id) => $id === $vendedorId)
                        ->map(fn (int $id) => $namesById->get($id, '—'))->values()->all()
                    : [];

                return [
                    'pedido_id'             => (int) $row->pedido_id,
                    'vendedor_id'           => $vendedorId,
                    'numero_pedido'         => $row->numero_pedido,
                    'cliente'               => $row->cliente,
                    'fecha_pedido'          => $stageChangedAt,
                    'factura_numero'        => $row->factura_numero,
                    'fecha_factura'         => $row->fecha_factura !== null ? CarbonImmutable::parse($row->fecha_factura) : null,
                    'fecha_cobro_completo'  => $row->fecha_cobro_completo !== null ? CarbonImmutable::parse($row->fecha_cobro_completo) : null,
                    'mes_cierre'            => $mesCierre,
                    'total_pedido'          => $share,
                    'total_pedido_completo' => $count > 1 ? $totalCompleto : null,
                    'total_facturado'       => $totalFacturado,
                    'total_cobrado'         => $totalCobrado,
                    'total_nota_credito'    => $totalNC,
                    'nota_credito_numeros'  => $row->nota_credito_numeros,
                    'compartido_con'        => $others,
                    'responsables_count'    => $count,
                    'estado'                => $estado,
                ];
            }, $responsableIds);
        })->values();
    }

    /**
     * REQ-501, RN1-RN5. La comisión es todo o nada (RN1) — nunca proporcional a un abono parcial.
     *
     * SCRUM-929 (B3b, decisión de Luis 2026-09-19) — `$totalPedidoCompleto` es el monto TOTAL del
     * pedido (`pipeline_cards.amount`), no la parte ya facturada: con entregas parciales
     * `$totalFacturado` puede ser < `$totalPedidoCompleto` mientras falten entregas por facturar, y
     * "cobrado ≥ facturado" por sí solo ya no alcanza (una parcial cobrada al 100% no es el pedido
     * cobrado al 100%). Ahora exige ADEMÁS que el pedido esté COMPLETAMENTE facturado (tolerancia
     * 0.005 = medio centavo, épsilon de redondeo, no un umbral de negocio).
     */
    public function estadoFor(?CarbonImmutable $mesCierre, ?float $totalFacturado, float $totalCobrado, float $totalPedidoCompleto): string
    {
        if ($mesCierre === null || $totalFacturado === null || $totalFacturado <= 0) {
            return self::ESTADO_PENDIENTE_COBRO;
        }

        if ($totalFacturado + 0.005 < $totalPedidoCompleto || $totalCobrado < $totalFacturado) {
            return self::ESTADO_PENDIENTE_COBRO;
        }

        $currentMonth = CarbonImmutable::now()->startOfMonth();

        // RN5: el estado se determina por el mes de CIERRE DEL PEDIDO, no por la fecha del cobro.
        // Si ese mes ya pasó, la comisión ya está liquidada (día 1-5) — "pagado". Si es el mes en
        // curso, ya se ganó pero todavía no se liquida — "por_pagar" (RN3/RN4).
        return $mesCierre->lt($currentMonth) ? self::ESTADO_PAGADO : self::ESTADO_POR_PAGAR;
    }

    /**
     * REQ-504 — pedidos "relevantes" para la vista de un `viewMonth`: los del propio mes (sin
     * importar estado) más los arrastrados de meses anteriores que TODAVÍA no llegaron a
     * "pagado" (RN1/RN2). Un pedido ya liquidado dejar de arrastrarse una vez que el mes en que
     * se liquidó queda atrás — no se repite en meses posteriores.
     *
     * @param Collection<int, CommissionOrderRow> $orders
     * @return Collection<int, CommissionOrderRow>
     */
    private function relevantOrders(Collection $orders, CarbonImmutable $viewMonth): Collection
    {
        return $orders->filter(function (array $o) use ($viewMonth) {
            if ($o['mes_cierre'] === null) {
                return false;
            }
            if ($o['mes_cierre']->equalTo($viewMonth)) {
                return true;
            }

            return $o['mes_cierre']->lt($viewMonth) && $o['estado'] !== self::ESTADO_PAGADO;
        })->values();
    }

    /**
     * % y total de un vendedor para un mes de cierre puntual (REQ-502). Si el mes ya cerró, se
     * congela en `commission_month_snapshots` la primera vez que se pide (mismo patrón que
     * `CommissionCohort::cohortFor()` en VentasDiseno, REQ-073, replicado a propósito en vez de
     * reusado — ver ADR §1). El mes en curso siempre se calcula en vivo ("provisional"). RN1
     * REQ-502: se calcula sobre "Total pedido" (ya dividido si es compartido, REQ-506 RN4), nunca
     * sobre lo cobrado ni sobre lo facturado.
     *
     * @param Collection<int, CommissionOrderRow>|null $ordersForVendor
     * @return array{total: float, porcentaje: float, fijo: bool}
     */
    public function percentForVendorMonth(int $vendorId, CarbonImmutable $monthStart, ?Collection $ordersForVendor = null): array
    {
        $currentMonth = CarbonImmutable::now()->startOfMonth();
        $isClosed     = $monthStart->lt($currentMonth);

        if ($isClosed) {
            $snapshot = CommissionMonthSnapshot::where('vendedor_id', $vendorId)
                ->whereDate('mes_cierre', $monthStart->toDateString())
                ->first();
            if ($snapshot !== null) {
                return ['total' => $snapshot->total_pedidos, 'porcentaje' => $snapshot->porcentaje, 'fijo' => true];
            }
        }

        $orders = $ordersForVendor ?? $this->ordersForVendor($vendorId);
        $total  = (float) $orders
            ->filter(fn (array $o) => $o['mes_cierre'] !== null && $o['mes_cierre']->equalTo($monthStart))
            ->sum('total_pedido');
        $percent = $this->percentFor($total);

        if ($isClosed && $total > 0) {
            // SCRUM-1158 (Pre-QA MEDIO-2) — «Ver como usuario» es de solo lectura: el GET de inicio del observado calcula y devuelve lo mismo (mes
            // cerrado = `fijo: true`) pero NO congela el snapshot; lo congelará la primera consulta real de la persona. Sin `act` queda idéntico.
            if (JwtActor::currentRequestIsImpersonating()) {
                return ['total' => $total, 'porcentaje' => $percent, 'fijo' => true];
            }

            $snapshot = CommissionMonthSnapshot::create([
                'vendedor_id'     => $vendorId,
                'mes_cierre'      => $monthStart->toDateString(),
                'total_pedidos'   => $total,
                'porcentaje'      => $percent,
                'monto_comision'  => round($total * $percent / 100, 2),
            ]);

            return ['total' => $snapshot->total_pedidos, 'porcentaje' => $snapshot->porcentaje, 'fijo' => true];
        }

        return ['total' => $total, 'porcentaje' => $percent, 'fijo' => false];
    }

    /**
     * SCRUM-1168 F4 — monto de la comisión de UN pedido para su vendedor (mismo cálculo que el resumen: base × % del mes de cierre). Lo usa el descuento por
     * reverso de un cobro para saber cuánto se había pagado de un pedido que vuelve a quedar sin cobrar.
     *
     * @param CommissionOrderRow $order
     * @param Collection<int, CommissionOrderRow> $ordersForVendor
     */
    public function montoComisionDe(array $order, Collection $ordersForVendor): float
    {
        if ($order['mes_cierre'] === null) {
            return 0.0;
        }
        $mesData = $this->percentForVendorMonth($order['vendedor_id'], $order['mes_cierre'], $ordersForVendor);

        return round($this->comisionBase($order) * $mesData['porcentaje'] / 100, 2);
    }

    /**
     * Batch 23 (SCRUM-648, REQ-571) — comisiones internas ya REALIZADAS (pedido cobrado por
     * completo), una fila por pedido con su fecha real de cobro. Mismo `monto_comision` que
     * `enrichOrder()`/`buildGroups()` (nunca una fórmula paralela) — la diferencia acá es la fecha
     * usada: `mes_cierre` siempre es 1° de mes (sin granularidad diaria, no sirve para un total
     * "de hoy"), así que se agrupa por `fecha_cobro_completo` — la fecha real en que el pedido
     * quedó cobrado, mismo evento que hace que `estado` valga `pagado`. Pedidos `pendiente_cobro`
     * (comisión todavía estimada, sin `fecha_cobro_completo`) quedan fuera a propósito — "generada"
     * en el sentido de esta tarjeta es "ya real", no una proyección.
     *
     * @return Collection<int, array{fecha: string, monto: float}>
     */
    public function commissionsCollectedByDate(): Collection
    {
        $orders = $this->ordersForAllVendors()
            ->filter(fn (array $o) => $o['fecha_cobro_completo'] !== null && $o['mes_cierre'] !== null);

        $rows = collect();
        foreach ($orders->groupBy('vendedor_id') as $vendorId => $vendorOrders) {
            $allForVendor = $this->ordersForVendor((int) $vendorId);
            foreach ($vendorOrders->groupBy(fn (array $o) => $o['mes_cierre']->toDateString()) as $mesOrders) {
                /** @var CommissionOrderRow $first */
                $first    = $mesOrders->first();
                $mesCierre = $first['mes_cierre'];
                if ($mesCierre === null) {
                    continue;
                }
                $mesData = $this->percentForVendorMonth((int) $vendorId, $mesCierre, $allForVendor);
                foreach ($mesOrders as $o) {
                    if ($o['fecha_cobro_completo'] === null) {
                        continue;
                    }
                    $rows->push([
                        'fecha' => $o['fecha_cobro_completo']->toDateString(),
                        'monto' => round($this->comisionBase($o) * $mesData['porcentaje'] / 100, 2),
                    ]);
                }
            }
        }

        return $rows;
    }

    /** Base de comisión de un pedido (REQ-503 RN1): total_cobrado (o el estimado, si aún no
     *  cobró) MENOS notas de crédito aplicadas — nunca negativa.
     *
     * REQ-506 RN4 (SCRUM-906, corrección) — en un pedido compartido, `total_facturado`/
     * `total_cobrado`/`total_nota_credito` del row son siempre el monto COMPLETO del pedido
     * (iguales para las N filas del mismo pedido, uno por responsable — ver `enrichOrder()`, que
     * los muestra sin dividir a propósito para que el usuario vea el pedido completo). La base de
     * comisión de cada responsable, en cambio, debe prorratearse por `responsables_count` — nunca
     * calcularse ni pagarse sobre el monto completo repetido para cada uno. El fallback a
     * `total_pedido` (cuando el pedido aún no tiene factura) YA viene dividido — dividirlo de
     * nuevo sería un doble prorrateo.
     *
     * @param CommissionOrderRow $order
     */
    private function comisionBase(array $order): float
    {
        $count = max(1, $order['responsables_count']);

        if ($order['estado'] === self::ESTADO_PENDIENTE_COBRO) {
            $bruta = $order['total_facturado'] !== null
                ? $order['total_facturado'] / $count
                : $order['total_pedido']; // ya viene dividido — no dividir de nuevo
        } else {
            $bruta = $order['total_cobrado'] / $count;
        }

        return max(0.0, $bruta - ($order['total_nota_credito'] / $count));
    }

    /**
     * @param CommissionOrderRow $order
     * @return array<string, mixed>
     */
    private function enrichOrder(array $order, float $porcentaje): array
    {
        $monto = round($this->comisionBase($order) * $porcentaje / 100, 2);

        return [
            'id'                   => $order['pedido_id'],
            'cliente'              => $order['cliente'],
            'numero_pedido'        => $order['numero_pedido'],
            'fecha_pedido'         => $order['fecha_pedido']?->toDateString(),
            'numero_factura'       => $order['factura_numero'],
            'fecha_factura'        => $order['fecha_factura']?->toDateString(),
            'fecha_cobro_completo' => $order['fecha_cobro_completo']?->toDateString(),
            'total_pedido'         => $order['total_pedido'],
            'total_pedido_completo' => $order['total_pedido_completo'],
            'compartido_con'       => $order['compartido_con'],
            'total_facturado'      => $order['total_facturado'],
            'total_cobrado'        => $order['total_cobrado'],
            'total_nota_credito'   => $order['total_nota_credito'],
            'nota_credito_ref'     => $order['nota_credito_numeros'],
            'es_abono_parcial'     => $order['total_facturado'] !== null
                && $order['total_cobrado'] > 0 && $order['total_cobrado'] < $order['total_facturado'],
            'estado'               => $order['estado'],
            'monto_comision'       => $monto,
            'es_estimado'          => $order['estado'] === self::ESTADO_PENDIENTE_COBRO,
        ];
    }

    /**
     * Arma `groups[]` (REQ-505) — un grupo por cada `mes_cierre` presente en `$relevant`, más
     * reciente primero, cada uno con su propio %/fijo (RN3 REQ-505) y `arrastrado` si no es el
     * mes que se está viendo.
     *
     * @param Collection<int, CommissionOrderRow> $relevant
     * @param Collection<int, CommissionOrderRow> $allOrdersForVendor mes % se calcula sobre el
     *     universo completo del vendedor para ESE mes, no solo lo ya filtrado a "relevante".
     * @return array<int, array<string, mixed>>
     */
    private function buildGroups(int $vendorId, Collection $relevant, Collection $allOrdersForVendor, CarbonImmutable $viewMonth): array
    {
        // relevantOrders() ya excluye mes_cierre null, pero PHPStan no lo sabe cruzando métodos —
        // filtramos de nuevo acá para que el resto del método trabaje con CarbonImmutable no-nulo.
        $withMes = $relevant->filter(fn (array $o) => $o['mes_cierre'] !== null);

        $mesesCierre = $withMes->pluck('mes_cierre')->unique(fn (CarbonImmutable $m) => $m->toDateString())
            ->sortByDesc(fn (CarbonImmutable $m) => $m->toDateString())->values();

        return $mesesCierre->map(function (CarbonImmutable $mes) use ($vendorId, $withMes, $allOrdersForVendor, $viewMonth) {
            $mesData = $this->percentForVendorMonth($vendorId, $mes, $allOrdersForVendor);
            $pedidos = $withMes->filter(fn (array $o) => $o['mes_cierre'] !== null && $o['mes_cierre']->equalTo($mes))
                ->map(fn (array $o) => $this->enrichOrder($o, $mesData['porcentaje']))->values();

            return [
                'mes'             => $mes->format('Y-m'),
                'porcentaje'      => $mesData['porcentaje'],
                'porcentaje_fijo' => $mesData['fijo'],
                'arrastrado'      => ! $mes->equalTo($viewMonth),
                'pedidos'         => $pedidos,
            ];
        })->values()->all();
    }

    /**
     * Resumen completo para un vendedor en el mes filtrado (REQ-499→502, REQ-503→505): total del
     * mes filtrado + montos de comisión pagada/por_pagar/pendiente agregados de los pedidos
     * RELEVANTES a este mes (REQ-504 — el propio mes + arrastrados aún no liquidados, nunca todo
     * el histórico sin filtrar).
     *
     * @return array<string, mixed>
     */
    public function summaryForVendor(User $vendor, CarbonImmutable $viewMonth): array
    {
        $orders   = $this->ordersForVendor($vendor->id);
        $view     = $this->percentForVendorMonth($vendor->id, $viewMonth, $orders);
        $relevant = $this->relevantOrders($orders, $viewMonth);

        $pagada = 0.0;
        $porPagar = 0.0;
        $pendiente = 0.0;
        $totalNC = 0.0;

        foreach ($relevant as $order) {
            if ($order['mes_cierre'] === null) {
                continue; // relevantOrders() ya excluye esto, defensivo para PHPStan
            }

            $mesData = $this->percentForVendorMonth($vendor->id, $order['mes_cierre'], $orders);
            $monto   = round($this->comisionBase($order) * $mesData['porcentaje'] / 100, 2);
            $totalNC += $order['total_nota_credito'];

            match ($order['estado']) {
                self::ESTADO_PAGADO          => $pagada += $monto,
                self::ESTADO_POR_PAGAR       => $porPagar += $monto,
                self::ESTADO_PENDIENTE_COBRO => $pendiente += $monto,
                default                       => null,
            };
        }

        return [
            'vendedor_id'        => $vendor->id,
            'vendedor_nombre'    => trim("{$vendor->first_name} {$vendor->last_name}"),
            'total_pedidos_mes'  => $view['total'],
            'porcentaje'         => $view['porcentaje'],
            'porcentaje_fijo'    => $view['fijo'],
            'pagada'             => round($pagada, 2),
            'por_pagar'          => round($porPagar, 2),
            'pendiente_cobro'    => round($pendiente, 2),
            'total_nota_credito' => round($totalNC, 2),
            // SCRUM-1168 F4 — descuentos por cobros reversados que se restarán de la siguiente liquidación de este vendedor.
            'ajustes_pendientes' => round((float) AdminContCommissionAdjustment::query()->where('vendedor_id', $vendor->id)->whereColumn('monto_aplicado', '<', 'monto')
                ->get()->sum(fn (AdminContCommissionAdjustment $a): float => $a->pendiente()), 2),
            'groups'             => $this->buildGroups($vendor->id, $relevant, $orders, $viewMonth),
        ];
    }

    /**
     * REQ-507 — documento "Estado de cuenta de comisiones" de un vendedor para un mes puntual.
     *
     * RN1 (mes cerrado): SOLO los pedidos que quedaron `pagado` en ESE mes — lo que sigue
     * pendiente de cobro de ese mes ya se ve en el estado de cuenta del mes en curso, no se
     * repite acá. RN2 (mes en curso): todos los grupos relevantes (§REQ-504) + los 3 totales.
     *
     * @return array<string, mixed>
     */
    public function accountStatementForVendor(User $vendor, CarbonImmutable $viewMonth): array
    {
        $orders   = $this->ordersForVendor($vendor->id);
        $view     = $this->percentForVendorMonth($vendor->id, $viewMonth, $orders);
        $currentMonth = CarbonImmutable::now()->startOfMonth();
        $mesCerrado = $viewMonth->lt($currentMonth);

        if ($mesCerrado) {
            // RN1 — solo lo que quedó efectivamente pagado en ESE mes de cierre puntual, nunca
            // arrastre (un pedido pendiente no "pertenece" todavía a ningún cierre formal).
            /** @var Collection<int, CommissionOrderRow> $relevant */
            $relevant = $orders->filter(fn (array $o) => $o['mes_cierre'] !== null
                && $o['mes_cierre']->equalTo($viewMonth) && $o['estado'] === self::ESTADO_PAGADO)->values();
        } else {
            // RN2 — mismo universo que la pantalla principal (mes en curso + arrastrados).
            $relevant = $this->relevantOrders($orders, $viewMonth);
        }

        $pagada = 0.0;
        $porPagar = 0.0;
        $pendiente = 0.0;
        $totalNC = 0.0;
        foreach ($relevant as $order) {
            if ($order['mes_cierre'] === null) {
                continue; // defensivo — mes_cerrado filtra explícito arriba, relevantOrders() también excluye null
            }

            $mesData = $this->percentForVendorMonth($vendor->id, $order['mes_cierre'], $orders);
            $monto   = round($this->comisionBase($order) * $mesData['porcentaje'] / 100, 2);
            $totalNC += $order['total_nota_credito'];

            match ($order['estado']) {
                self::ESTADO_PAGADO          => $pagada += $monto,
                self::ESTADO_POR_PAGAR       => $porPagar += $monto,
                self::ESTADO_PENDIENTE_COBRO => $pendiente += $monto,
                default                       => null,
            };
        }

        return [
            'vendedor_id'        => $vendor->id,
            'vendedor_nombre'    => trim("{$vendor->first_name} {$vendor->last_name}"),
            'mes'                => $viewMonth->format('Y-m'),
            'mes_cerrado'        => $mesCerrado,
            'total_pedidos_mes'  => $view['total'],
            'porcentaje'         => $view['porcentaje'],
            'porcentaje_fijo'    => $view['fijo'],
            'pagada'             => round($pagada, 2),
            'por_pagar'          => round($porPagar, 2),
            'pendiente_cobro'    => round($pendiente, 2),
            'total_nota_credito' => round($totalNC, 2),
            // RN3: descuento total por NC agregado al % del mes filtrado, mismo cálculo que el
            // mockup (openEstadoCuenta) — no confundir con el total_nota_credito bruto de arriba.
            'descuento_nota_credito' => round($totalNC * $view['porcentaje'] / 100, 2),
            'groups'             => $this->buildGroups($vendor->id, $relevant, $orders, $viewMonth),
        ];
    }

    /**
     * SCRUM-879 (decisión E-1/E-5) — fecha de corte para generar movimientos de caja de comisiones:
     * el arranque del módulo Flujo de Caja (Batch 18). Antes de esa fecha no se genera nada, ni el
     * job ni el backfill.
     */
    public const CASH_LEDGER_CUTOFF_FALLBACK = '2026-08-25';

    /**
     * SCRUM-879 — refleja como salida de caja (`bank_movements`, tipo comisión, sin cuenta
     * asignada) cada `CommissionMonthSnapshot` de un mes YA CERRADO cuyo monto de comisión sea > 0.
     * Fuerza la creación del snapshot si nadie abrió la pantalla de ese vendedor/mes (mismo gotcha
     * que SCRUM-867). Idempotente por `(source_type, source_id=snapshot.id)`. Fecha del movimiento:
     * día 1 del mes siguiente al de cierre (decisión E-5). Lo usan el job `commissions:settle-internal`
     * y el backfill.
     *
     * @return int cantidad de movimientos creados
     */
    public function settleInternalCommissionsToCashLedger(): int
    {
        $cutoff = AdminContFiscalSettings::current()->arqueo_tracking_start_date?->startOfMonth()
            ?? CarbonImmutable::parse(self::CASH_LEDGER_CUTOFF_FALLBACK)->startOfMonth();
        $currentMonth = CarbonImmutable::now()->startOfMonth();

        $allOrders = $this->ordersForAllVendors();
        $vendorIds = $allOrders->pluck('vendedor_id')->unique();

        $names = User::query()->whereIn('id', $vendorIds)->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn (User $u): array => [$u->id => trim("{$u->first_name} {$u->last_name}")]);

        $created = 0;
        foreach ($vendorIds as $vendorId) {
            $vendorOrders = $this->ordersForVendor((int) $vendorId);

            /** @var array<string, CarbonImmutable> $mesesCierre */
            $mesesCierre = [];
            foreach ($vendorOrders->pluck('mes_cierre')->filter() as $raw) {
                $mes = CarbonImmutable::parse($raw)->startOfMonth();
                if ($mes->lt($currentMonth) && $mes->gte($cutoff)) {
                    $mesesCierre[$mes->toDateString()] = $mes;
                }
            }

            foreach ($mesesCierre as $mesCierre) {
                // Fuerza el snapshot (create-if-closed) y luego lo lee para el monto exacto.
                $this->percentForVendorMonth((int) $vendorId, $mesCierre, $vendorOrders);
                $snapshot = CommissionMonthSnapshot::where('vendedor_id', $vendorId)
                    ->whereDate('mes_cierre', $mesCierre->toDateString())
                    ->first();
                if ($snapshot === null || $snapshot->monto_comision <= 0) {
                    continue;
                }

                $yaRegistrado = AdminContBankMovement::query()
                    ->where('source_type', AdminContBankMovement::SOURCE_INTERNAL_COMMISSION)
                    ->where('source_id', $snapshot->id)
                    ->exists()
                    // SCRUM-1168 F4 — una liquidación que los descuentos dejaron en cero no genera movimiento: su marca es el descuento aplicado.
                    || AdminContCommissionAdjustment::query()->where('applied_snapshot_id', $snapshot->id)->exists();
                if ($yaRegistrado) {
                    continue;
                }

                // SCRUM-1168 F4 — descuentos pendientes del vendedor (reversos de cobros de pedidos ya liquidados) registrados hasta el cierre de este
                // mes: se restan de esta liquidación; si no alcanza, el resto queda para la siguiente.
                $descuento = 0.0;
                $disponible = (float) $snapshot->monto_comision;
                $pendientes = AdminContCommissionAdjustment::query()
                    ->where('vendedor_id', $vendorId)->whereColumn('monto_aplicado', '<', 'monto')
                    ->where('created_at', '<', $mesCierre->addMonth()->startOfMonth())->orderBy('id')->lockForUpdate()->get();
                foreach ($pendientes as $ajuste) {
                    $aplica = round(min($ajuste->pendiente(), max(0.0, $disponible - $descuento)), 2);
                    if ($aplica <= 0.004) {
                        break;
                    }
                    $ajuste->update(['monto_aplicado' => round($ajuste->monto_aplicado + $aplica, 2), 'applied_snapshot_id' => $snapshot->id]);
                    $descuento = round($descuento + $aplica, 2);
                }
                $neto = round($snapshot->monto_comision - $descuento, 2);
                if ($neto <= 0.004) {
                    continue;
                }

                $vendorName = $names->get((int) $vendorId) ?? "vendedor #{$vendorId}";
                AdminContBankMovement::create([
                    'bank_account_id' => null,
                    'fecha'           => $mesCierre->addMonth()->startOfMonth()->toDateString(),
                    'tipo'            => AdminContBankMovement::TIPO_COMISION,
                    'concepto'        => "Comisión interna pagada — {$vendorName} (".self::mesEtiqueta($mesCierre).')'
                        .($descuento > 0 ? ' — descuento por cobros reversados $'.number_format($descuento, 2) : ''),
                    'monto'           => $neto,
                    'source_type'     => AdminContBankMovement::SOURCE_INTERNAL_COMMISSION,
                    'source_id'       => $snapshot->id,
                ]);
                $created++;
            }
        }

        return $created;
    }

    /** "jun 2026" — etiqueta corta de mes en español, sin depender de la config de locale de Carbon. */
    private static function mesEtiqueta(CarbonImmutable $mes): string
    {
        $nombres = [
            '01' => 'ene', '02' => 'feb', '03' => 'mar', '04' => 'abr', '05' => 'may', '06' => 'jun',
            '07' => 'jul', '08' => 'ago', '09' => 'sep', '10' => 'oct', '11' => 'nov', '12' => 'dic',
        ];

        return $nombres[$mes->format('m')].' '.$mes->format('Y');
    }

    /**
     * Aviso global de pago automático (REQ-499 RN3) — independiente del mes filtrado, cuenta
     * TODAS las comisiones en "por_pagar" de toda la operación, listas para liquidarse en el
     * cierre del mes en curso.
     *
     * SCRUM-904 — `$vendorId` angosta el banner a un único vendedor (actor sin team-view,
     * auto-scope a sí mismo). `null` (default) mantiene el comportamiento histórico: total
     * agregado de todos los vendedores, para roles con visibilidad total. Nunca se angosta por un
     * filtro manual de vendedor_id (ese es un filtro de tabla, no de este banner/tarjetas — mismo
     * criterio que `$cardRows` en `AdminContInternalCommissionController::buildSummary()`).
     *
     * @return array{count: int, total: float}
     */
    public function pendingAutoPayBanner(?int $vendorId = null): array
    {
        $allOrders = $this->ordersForAllVendors();
        $vendorIds = $vendorId !== null
            ? collect([$vendorId])
            : $allOrders->pluck('vendedor_id')->unique();

        $count = 0;
        $total = 0.0;
        foreach ($vendorIds as $vendorId) {
            // Fetch del set COMPLETO del vendedor (no el ya filtrado a por_pagar) — el % de un
            // mes se calcula sobre el total de TODOS sus pedidos de ese mes de cierre (RN1
            // REQ-502), pasar un subconjunto ya filtrado daría un total, y por lo tanto un %,
            // incorrecto.
            $vendorOrders = $this->ordersForVendor((int) $vendorId);
            foreach ($vendorOrders->where('estado', self::ESTADO_POR_PAGAR) as $order) {
                $mesCierre = $order['mes_cierre'];
                if ($mesCierre === null) {
                    continue; // estadoFor() nunca devuelve por_pagar con mes_cierre null — defensivo
                }

                $mesData = $this->percentForVendorMonth((int) $vendorId, $mesCierre, $vendorOrders);
                $total  += round($this->comisionBase($order) * $mesData['porcentaje'] / 100, 2);
                $count++;
            }
        }

        return ['count' => $count, 'total' => round($total, 2)];
    }
}
