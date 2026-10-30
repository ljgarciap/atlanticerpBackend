<?php

declare(strict_types=1);

namespace App\Modules\Compras\Services;

use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderLine;
use App\Modules\Compras\Models\PurchaseOrderReceipt;
use App\Modules\Compras\Models\PurchaseOrderReceiptLine;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * REQ-169 (Inventario) — extraído de InventoryController para reusarlo byte-a-byte en
 * REQ-204 (Reportes: "Inventario: rotación y atención"), que exige los mismos números que ya
 * muestra Inventario, nunca un cálculo paralelo que pueda desincronizarse.
 */
final class InventoryKpiService
{
    public const STOCK_DISPONIBLE = 'disponible';

    public const STOCK_BAJO_STOCK = 'bajo_stock';

    public const STOCK_SIN_STOCK = 'sin_stock';

    /**
     * SCRUM-851 — fórmula única de "disponible", centralizada acá para que Compras/Bodega/
     * Catálogo/Cotizaciones/Servicios muestren siempre el mismo número (criterio explícito del
     * ticket). Resta lo reservado (`porServir`) y lo que está físicamente en una bodega marcada
     * `excluye_disponible` (Reserva Servicios, Merma, Reclamos y Devoluciones) — eso sigue
     * contando en `stock_quantity` (Inventario Total), solo se excluye acá.
     */
    public function disponibleParaVenta(CatalogProduct $product, int $porServir, int $enExcluida): float
    {
        return ($product->stock_quantity ?? 0) - $porServir - $enExcluida;
    }

    /**
     * SCRUM-265 (REQ-202) — mismo umbral `disponible <= reorder_point` que `format()`/`kpis()`,
     * para un solo producto. Usado al crear una orden (`PurchaseOrderController::replaceLines()`)
     * para capturar en qué estado estaba el producto EN ESE MOMENTO — el estado actual no sirve
     * retroactivamente, por eso esto se llama en el momento de creación, no después.
     *
     * `$porServir`/`$enExcluida` son opcionales: si el caller ya tiene los mapas precalculados
     * para varios productos (ver `porServirMap()`/`enBodegaExcluidaMap()`), los pasa para evitar
     * una query por línea en un loop — mismo patrón que `kpis()`. Si se omiten, se calculan acá
     * para un uso puntual de un solo producto.
     */
    public function stockState(CatalogProduct $product, ?int $porServir = null, ?int $enExcluida = null): string
    {
        $porServir ??= $this->porServirMap([$product->id])[$product->id] ?? 0;
        $enExcluida ??= $this->enBodegaExcluidaMap([$product->id])[$product->id] ?? 0;
        $disponible = $this->disponibleParaVenta($product, $porServir, $enExcluida);

        return match (true) {
            $disponible <= 0 => self::STOCK_SIN_STOCK,
            $disponible <= ($product->reorder_point ?? 0) => self::STOCK_BAJO_STOCK,
            default => self::STOCK_DISPONIBLE,
        };
    }

    /**
     * SCRUM-993 — un solo SELECT con agregados, sin hidratar modelos: antes cargaba todo el catálogo
     * activo con `get()` (~30K productos) en cada llamada, y `InventoryController::index()` lo llamaba
     * en cada respuesta. Las reglas viven en `sqlSinStock()`/`sqlBajoStock()`/`sqlEnAtencion()`, las
     * mismas que usan los chips del listado.
     *
     * @return array{total_products: int, with_stock: int, low_stock: int, out_of_stock: int, in_attention: int, total_value: float}
     */
    public function kpis(): array
    {
        $base = $this->withAggregates($this->baseQuery()->where('cp.is_active', true), ['por_servir', 'en_excluida'])->toBase();
        $base->columns = null; // solo los agregados de abajo, no `cp.*` ni las columnas por producto

        $row = $base
            ->selectRaw(
                'COUNT(*) AS total_products, '
                .'COUNT(*) FILTER (WHERE '.$this->sqlBajoStock().') AS low_stock, '
                .'COUNT(*) FILTER (WHERE '.$this->sqlSinStock().') AS out_of_stock, '
                .'COUNT(*) FILTER (WHERE '.$this->sqlEnAtencion().') AS in_attention, '
                .'COALESCE(SUM(COALESCE(cp.price_full, 0) * COALESCE(cp.stock_quantity, 0)), 0) AS total_value'
            )
            ->first();

        return [
            'total_products' => (int) ($row->total_products ?? 0),
            'with_stock' => $this->conStock(),
            'low_stock' => (int) ($row->low_stock ?? 0),
            'out_of_stock' => (int) ($row->out_of_stock ?? 0),
            'in_attention' => (int) ($row->in_attention ?? 0),
            'total_value' => round((float) ($row->total_value ?? 0), 2),
        ];
    }

    /**
     * SCRUM-991 — productos ACTIVOS con unidades (> 0) en al menos una bodega operativa de Atlantic: es la cifra que
     * coincide con lo que muestran las bodegas (Vista Bodegas, filtro por bodega). A diferencia de `total_products` (tamaño del
     * catálogo activo, incluye los de 0 unidades), esta cuenta solo inventario real. Una bodega de solo consulta no suma
     * (`enBodegaOperativa()`), y los inactivos tampoco — mismo criterio que las listas, que ocultan los inactivos por defecto.
     */
    public function conStock(): int
    {
        return ProductWarehouseStock::query()
            ->where('quantity', '>', 0)
            ->whereIn('catalog_product_id', CatalogProduct::query()->select('id')->where('is_active', true))
            ->distinct()
            ->count('catalog_product_id');
    }

    // ── SCRUM-993 — la MISMA regla de stock, expresada en SQL ─────────────────────────────────
    //
    // `disponibleParaVenta()`/`stockState()`/`isInAttention()`/`isLowStockWithoutOpenOrder()` (arriba)
    // siguen siendo la referencia por producto (`show()`, `PurchaseOrderController::replaceLines()`).
    // Las funciones `sql*()` de abajo son su traducción para filtrar/contar sobre TODO el catálogo
    // sin hidratarlo. Cualquier cambio de regla se hace en los dos lados a la vez; el test
    // `InventoryKpiServiceSqlParityTest` falla si divergen.

    /** Alias de la tabla base y de cada agregado — los `sql*()` de abajo dependen de ellos. */
    private const AGG_ALIASES = [
        'por_servir' => 'inv_ps',
        'en_excluida' => 'inv_ex',
        'por_ingresar' => 'inv_pi',
        'en_camino' => 'inv_ec',
    ];

    /** @return Builder<CatalogProduct> `catalog_products` con alias `cp`, listo para `withAggregates()`. */
    public function baseQuery(): Builder
    {
        return CatalogProduct::query()->from((new CatalogProduct)->getTable().' as cp')->select('cp.*');
    }

    /**
     * Une los agregados por producto como subconsultas agrupadas (LEFT JOIN + COALESCE en los `sql*()`),
     * en vez de los `*Map($productIds)` con un `whereIn` de decenas de miles de IDs. Cada agregado
     * también se selecciona como columna (`por_servir`, `en_excluida`, `por_ingresar`, `en_camino`).
     *
     * @param  Builder<CatalogProduct>  $query
     * @param  list<'por_servir'|'en_excluida'|'por_ingresar'|'en_camino'>  $only
     * @return Builder<CatalogProduct>
     */
    public function withAggregates(Builder $query, array $only = ['por_servir', 'en_excluida', 'por_ingresar', 'en_camino']): Builder
    {
        foreach ($only as $name) {
            $alias = self::AGG_ALIASES[$name];
            $sub = match ($name) {
                'por_servir' => $this->porServirSub(),
                'en_excluida' => $this->enExcluidaSub(),
                'por_ingresar' => $this->porIngresarSub(),
                'en_camino' => $this->enCaminoSub(),
            };
            $query->leftJoinSub($sub, $alias, "{$alias}.catalog_product_id", '=', 'cp.id')
                ->addSelect(DB::raw("COALESCE({$alias}.total, 0) AS {$name}"));
        }

        return $query;
    }

    /** Disponible = stock - por servir - en bodega excluida (SCRUM-851, ver `disponibleParaVenta()`). */
    public function sqlDisponible(): string
    {
        return '(COALESCE(cp.stock_quantity, 0) - COALESCE(inv_ps.total, 0) - COALESCE(inv_ex.total, 0))';
    }

    /** Ver `stockState()` — `disponible <= 0`. */
    public function sqlSinStock(): string
    {
        return $this->sqlDisponible().' <= 0';
    }

    /** Ver `stockState()` — `0 < disponible <= reorder_point`. */
    public function sqlBajoStock(): string
    {
        return '('.$this->sqlDisponible().' > 0 AND '.$this->sqlDisponible().' <= COALESCE(cp.reorder_point, 0))';
    }

    /** Ver `isInAttention()` — rotación "baja" nunca cuenta; sin clasificar (NULL) sí. */
    public function sqlEnAtencion(): string
    {
        return "(cp.rotation IS DISTINCT FROM '".CatalogProduct::ROTATION_BAJA."' AND "
            .$this->sqlDisponible().' <= COALESCE(cp.reorder_point, 0))';
    }

    /** Ver `isLowStockWithoutOpenOrder()` — (sin stock o bajo stock) y `en_camino <= 0`. */
    public function sqlLowStockWithoutOpenOrder(): string
    {
        return '(('.$this->sqlSinStock().' OR '.$this->sqlBajoStock().') AND COALESCE(inv_ec.total, 0) <= 0)';
    }

    /**
     * Filtro de los chips de Inventario (REQ-172/REQ-112/SCRUM-168). `todos`/`inactivos` (y cualquier
     * valor desconocido) no filtran acá — `is_active` lo resuelve el caller.
     *
     * @param  Builder<CatalogProduct>  $query
     * @return Builder<CatalogProduct>
     */
    public function applyChip(Builder $query, string $chip): Builder
    {
        return match ($chip) {
            'por_ingresar' => $query->whereRaw('COALESCE(inv_pi.total, 0) > 0'),
            'en_atencion' => $query->whereRaw($this->sqlEnAtencion()),
            'bajo_stock' => $query->whereRaw($this->sqlBajoStock()),
            'sin_stock' => $query->whereRaw($this->sqlSinStock()),
            'bajo_stock_sin_ordenar' => $query->whereRaw($this->sqlLowStockWithoutOpenOrder()),
            default => $query,
        };
    }

    /** @return QueryBuilder `SUM(quantity)` de reservas de proyecto por producto (ver `porServirMap()`). */
    private function porServirSub(): QueryBuilder
    {
        return (new PurchaseOrderLine)->newQuery()->toBase()
            ->whereNotNull('sales_project_id')
            ->selectRaw('catalog_product_id, SUM(quantity) AS total')
            ->groupBy('catalog_product_id');
    }

    /** @return QueryBuilder stock en bodegas con `excluye_disponible` por producto (ver `enBodegaExcluidaMap()`). */
    private function enExcluidaSub(): QueryBuilder
    {
        $warehouses = (new Warehouse)->getTable();

        return (new ProductWarehouseStock)->newQuery()->toBase()
            ->whereIn('warehouse_id', fn ($q) => $q->select('id')->from($warehouses)->where('excluye_disponible', true))
            ->selectRaw('catalog_product_id, SUM(quantity) AS total')
            ->groupBy('catalog_product_id');
    }

    /** @return QueryBuilder líneas de ingreso sin confirmar por producto (ver `porIngresarMap()`). */
    private function porIngresarSub(): QueryBuilder
    {
        return (new PurchaseOrderReceiptLine)->newQuery()->toBase()
            ->whereNull('confirmed_at')
            ->selectRaw('catalog_product_id, SUM(quantity) AS total')
            ->groupBy('catalog_product_id');
    }

    /**
     * Ver `enCaminoMap()`: por cada (orden abierta, producto) = `max(0, esperado - registrado)`, sumado
     * por producto. NO es un SUM simple — el `GREATEST(0, ...)` es por par orden/producto.
     */
    private function enCaminoSub(): QueryBuilder
    {
        $g = DB::connection()->getQueryGrammar();
        $lines = $g->wrapTable((new PurchaseOrderLine)->getTable());
        $orders = $g->wrapTable((new PurchaseOrder)->getTable());
        $rLines = $g->wrapTable((new PurchaseOrderReceiptLine)->getTable());
        $receipts = $g->wrapTable((new PurchaseOrderReceipt)->getTable());

        $sql = 'SELECT e.catalog_product_id, SUM(GREATEST(0, e.qty - COALESCE(r.qty, 0))) AS total '
            .'FROM (SELECT l.purchase_order_id, l.catalog_product_id, SUM(l.quantity) AS qty '
            ."      FROM {$lines} l JOIN {$orders} o ON o.id = l.purchase_order_id "
            .'      WHERE o.status <> ? AND l.catalog_product_id IS NOT NULL '
            .'      GROUP BY l.purchase_order_id, l.catalog_product_id) e '
            .'LEFT JOIN (SELECT rc.purchase_order_id, rl.catalog_product_id, SUM(rl.quantity) AS qty '
            ."           FROM {$rLines} rl JOIN {$receipts} rc ON rc.id = rl.purchase_order_receipt_id "
            ."           JOIN {$orders} o2 ON o2.id = rc.purchase_order_id "
            .'           WHERE o2.status <> ? GROUP BY rc.purchase_order_id, rl.catalog_product_id) r '
            .'ON r.purchase_order_id = e.purchase_order_id AND r.catalog_product_id = e.catalog_product_id '
            .'GROUP BY e.catalog_product_id';

        return DB::query()->fromRaw("({$sql}) as agg_ec", [PurchaseOrder::STATUS_RECIBIDO, PurchaseOrder::STATUS_RECIBIDO])
            ->select('agg_ec.catalog_product_id', 'agg_ec.total');
    }

    /**
     * REQ-169 RN/Escenario 1: rotación "baja" nunca cuenta en "En atención", aunque sí en
     * "Sin stock"/"Bajo stock". Sin clasificar (`rotation === null`) SÍ cuenta — conservador.
     */
    public function isInAttention(CatalogProduct $product, int $porServir, int $enExcluida = 0): bool
    {
        if ($product->rotation === CatalogProduct::ROTATION_BAJA) {
            return false;
        }

        $disponible = $this->disponibleParaVenta($product, $porServir, $enExcluida);

        return $disponible <= ($product->reorder_point ?? 0);
    }

    /**
     * SCRUM-168 RN2 ("Bajo stock sin ordenar", tarjeta "Salud · Compras" de Gerencia,
     * 2026-08-26) — subconjunto de bajo_stock/sin_stock que además NO tiene ninguna unidad "en
     * camino" (`enCaminoMap()` — ninguna `PurchaseOrderLine` pendiente de recibir en una orden
     * abierta). Reusa `stockState()`/`enCaminoMap()`, nunca una clasificación paralela.
     */
    public function isLowStockWithoutOpenOrder(CatalogProduct $product, int $porServir, int $enCamino, int $enExcluida = 0): bool
    {
        $state = $this->stockState($product, $porServir, $enExcluida);

        return ($state === self::STOCK_BAJO_STOCK || $state === self::STOCK_SIN_STOCK) && $enCamino <= 0;
    }

    /** Conteo para la tarjeta "Salud · Compras" de Gerencia (SCRUM-168 RN2). SCRUM-993 — en SQL, sin hidratar el catálogo. */
    public function lowStockWithoutOpenOrderCount(): int
    {
        return (int) $this->withAggregates($this->baseQuery()->where('cp.is_active', true), ['por_servir', 'en_excluida', 'en_camino'])
            ->toBase()
            ->whereRaw($this->sqlLowStockWithoutOpenOrder())
            ->count();
    }

    /**
     * REQ-171 RN3: "Por servir" = unidades con proyecto asignado en su línea de origen, sin
     * importar el estado de la orden.
     *
     * @param  list<int>  $productIds
     * @return array<int, int>
     */
    public function porServirMap(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return PurchaseOrderLine::query()
            ->whereIn('catalog_product_id', $productIds)
            ->whereNotNull('sales_project_id')
            ->selectRaw('catalog_product_id, SUM(quantity) as total')
            ->groupBy('catalog_product_id')
            ->pluck('total', 'catalog_product_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * SCRUM-851 — unidades físicas ubicadas en una bodega marcada `excluye_disponible` (Bodega de
     * Servicios, Merma, Reclamos y Devoluciones). Siguen contando en `stock_quantity` (Inventario
     * Total), se excluyen solo del cálculo de "Disponible" vía `disponibleParaVenta()`.
     *
     * @param  list<int>  $productIds
     * @return array<int, int>
     */
    public function enBodegaExcluidaMap(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return ProductWarehouseStock::query()
            ->whereIn('catalog_product_id', $productIds)
            ->whereHas('warehouse', fn ($q) => $q->where('excluye_disponible', true))
            ->selectRaw('catalog_product_id, SUM(quantity) as total')
            ->groupBy('catalog_product_id')
            ->pluck('total', 'catalog_product_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * SCRUM-1196 — unidades en bodegas que SÍ se pueden vender pero NO cuentan en el «Disponible» (hoy Sala de exhibición 1: `permite_venta` y
     * `excluye_disponible`). Es el dato aparte que ven los vendedores («en Sala de exhibición 1: N») para mostrar a clientes o vender una última pieza,
     * sin sumarlo al disponible. Una sola consulta agregada para todos los productos pedidos; nunca negativo.
     *
     * @param  list<int>  $productIds
     * @return array<int, int>
     */
    public function enOtrasBodegasDeVentaMap(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return ProductWarehouseStock::query()
            ->whereIn('catalog_product_id', $productIds)
            ->whereHas('warehouse', fn ($q) => $q->where('permite_venta', true)->where('excluye_disponible', true))
            ->selectRaw('catalog_product_id, SUM(quantity) as total')
            ->groupBy('catalog_product_id')
            ->pluck('total', 'catalog_product_id')
            ->map(fn ($v) => max(0, (int) $v))
            ->all();
    }

    /**
     * REQ-172 RN1-RN4 (Ingreso de Mercancía) — unidades que YA llegaron físicamente pero faltan
     * confirmar en Inventario: línea de un ingreso (`purchase_order_receipt_lines`) con
     * `confirmed_at` NULL. No cuenta como "Disponible" hasta que se confirme
     * (`GoodsReceiptController::confirm()`).
     *
     * @param  list<int>  $productIds
     * @return array<int, int>
     */
    public function porIngresarMap(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return PurchaseOrderReceiptLine::query()
            ->whereIn('catalog_product_id', $productIds)
            ->whereNull('confirmed_at')
            ->selectRaw('catalog_product_id, SUM(quantity) as total')
            ->groupBy('catalog_product_id')
            ->pluck('total', 'catalog_product_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * REQ-172 RN1-RN4 — unidades que TODAVÍA no llegaron de ninguna forma: para cada orden
     * abierta (no-Recibida), lo esperado menos TODO lo ya registrado para esa orden y producto
     * (confirmado o no — mismo cálculo que `GoodsReceiptController::receivedByProduct()`, acá
     * agregado a través de TODAS las órdenes abiertas a la vez en vez de una sola). También usado
     * por `InventoryController::orderPrefill()` (REQ-179), que antes de este método sumaba
     * directamente lo esperado sin restar lo ya registrado.
     *
     * @param  list<int>  $productIds
     * @return array<int, int>
     */
    public function enCaminoMap(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        /** @var Collection<string, int> $expected */
        $expected = PurchaseOrderLine::query()
            ->whereIn('catalog_product_id', $productIds)
            ->whereHas('purchaseOrder', fn ($q) => $q->where('status', '!=', PurchaseOrder::STATUS_RECIBIDO))
            ->get()
            ->groupBy(fn (PurchaseOrderLine $l) => "{$l->purchase_order_id}:{$l->catalog_product_id}")
            ->map(fn (Collection $lines) => (int) $lines->sum('quantity'));

        /** @var Collection<string, int> $registered */
        $registered = PurchaseOrderReceiptLine::query()
            ->whereIn('catalog_product_id', $productIds)
            ->whereHas('receipt.purchaseOrder', fn ($q) => $q->where('status', '!=', PurchaseOrder::STATUS_RECIBIDO))
            ->with('receipt:id,purchase_order_id')
            ->get()
            ->groupBy(fn (PurchaseOrderReceiptLine $l) => "{$l->receipt?->purchase_order_id}:{$l->catalog_product_id}")
            ->map(fn (Collection $lines) => (int) $lines->sum('quantity'));

        $enCamino = [];
        foreach ($expected as $key => $qty) {
            $productId = (int) explode(':', $key, 2)[1];
            $remaining = max(0, $qty - ($registered[$key] ?? 0));
            $enCamino[$productId] = ($enCamino[$productId] ?? 0) + $remaining;
        }

        return $enCamino;
    }

    /**
     * SCRUM-234/238 (hallazgo de Gerencia Test 2026-08-09) — columna "Bodega(s)" de la tabla de
     * Inventario: para cada producto, la lista de bodegas donde tiene unidades físicas > 0 (nunca
     * las bodegas en 0 — mismo criterio que `InventoryController::warehouseBreakdown()`, que ya
     * resolvía esto para UN producto a la vez desde el modal de detalle). Este método agrega el
     * mismo dato para una PÁGINA completa de productos en una sola query, para no hacer N+1 al
     * pintar la tabla. Fuente única de verdad — el modal de detalle sigue usando el endpoint
     * `/warehouse-stock` existente, no se duplicó la query.
     *
     * @param  list<int>  $productIds
     * @return array<int, list<array{warehouse_id: int, warehouse_name: string|null, quantity: int}>>
     */
    public function warehousesMap(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $rows = ProductWarehouseStock::with('warehouse')
            ->whereIn('catalog_product_id', $productIds)
            ->where('quantity', '<>', 0) // SCRUM-1196: también los saldos negativos, para que la suma de «Ver bodegas» coincida con el Stock total
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->catalog_product_id][] = [
                'warehouse_id' => $row->warehouse_id,
                'warehouse_name' => $row->warehouse?->name,
                'quantity' => $row->quantity,
            ];
        }

        return $map;
    }

    /**
     * SCRUM-329 Bloque B2 (REQ-354/424, Ver Inventario de Bodega) — mismo criterio que
     * `enCaminoMap()` (orden no-Recibida, remanente esperado menos ya registrado), pero exponiendo
     * el detalle por orden (proveedor + fecha estimada + cantidad) en vez de solo el agregado, para
     * el drill-down de la columna "En camino". `[]` si no hay nada en camino — el caller (endpoint)
     * lo traduce en "no hay órdenes en camino" en vez de abrir un modal vacío sin explicación
     * (REQ-354 Escenario 2).
     *
     * @return list<array{purchase_order_id: int, provider_name: string|null, quantity: int, estimated_arrival_date: string|null}>
     */
    public function enCaminoDetail(int $productId): array
    {
        /** @var Collection<int, PurchaseOrder> $purchaseOrders */
        $purchaseOrders = PurchaseOrder::query()
            ->where('status', '!=', PurchaseOrder::STATUS_RECIBIDO)
            ->whereHas('lines', fn ($q) => $q->where('catalog_product_id', $productId))
            ->with(['provider', 'lines' => fn ($q) => $q->where('catalog_product_id', $productId)])
            ->orderBy('estimated_arrival_date')
            ->get();

        // Mismo patrón que `enCaminoMap()` — agrupar por un string interpolado (en vez de un int
        // devuelto directo) evita que Larastan se queje del tipo nullable de `$l->receipt`.
        /** @var Collection<string, int> $registeredByOrder */
        $registeredByOrder = PurchaseOrderReceiptLine::query()
            ->where('catalog_product_id', $productId)
            ->whereHas('receipt.purchaseOrder', fn ($q) => $q->where('status', '!=', PurchaseOrder::STATUS_RECIBIDO))
            ->with('receipt:id,purchase_order_id')
            ->get()
            ->groupBy(fn (PurchaseOrderReceiptLine $l) => "{$l->receipt?->purchase_order_id}")
            ->map(fn (Collection $lines) => (int) $lines->sum('quantity'));

        $rows = [];
        foreach ($purchaseOrders as $purchaseOrder) {
            $expected = (int) $purchaseOrder->lines->sum('quantity');
            $registered = (int) ($registeredByOrder["{$purchaseOrder->id}"] ?? 0);
            $remaining = max(0, $expected - $registered);

            if ($remaining <= 0) {
                continue;
            }

            $rows[] = [
                'purchase_order_id' => $purchaseOrder->id,
                'provider_name' => $purchaseOrder->provider?->name,
                'quantity' => $remaining,
                'estimated_arrival_date' => $purchaseOrder->estimated_arrival_date?->toDateString(),
            ];
        }

        return $rows;
    }
}
