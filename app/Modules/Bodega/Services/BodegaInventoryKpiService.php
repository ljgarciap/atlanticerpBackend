<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Modules\Compras\Services\InventoryKpiService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-329 Bloque B2 (REQ-346/351, Ver Inventario de Bodega) — KPIs y estado de stock para la
 * pantalla de Bodega, con el mismo criterio de umbral que Compras (`InventoryKpiService::
 * stockState()`, función pura parametrizada por un int de "por servir" — SIEMPRE reusada tal
 * cual, nunca reimplementada) pero alimentado por `OrderCommitmentService::committedMap()`
 * (compromiso a PEDIDOS de Bodega en curso) en vez de `InventoryKpiService::porServirMap()`
 * (reservas de Ventas & Diseño al momento de comprar — un concepto distinto, ver docblock de
 * `OrderCommitmentService`).
 *
 * REQ-351 RN1 confirma que este es el "por servir" correcto para esta pantalla: "Dado que un
 * producto tiene 2 unidades disponibles y 1 comprometida A UN PEDIDO, Stock Total indica 3" — "un
 * pedido" es `Bodega\Models\Order`, no una línea de compra.
 *
 * NOTA — "En atención" (REQ-346 RN1 / SCRUM-416, chip REQ-350 / SCRUM-420) NO reusa
 * `InventoryKpiService::isInAttention()`. Pre-QA (2026-07-28,
 * docs/pre-qa/scrum416-420-en-atencion-rotacion-20260728.md) encontró que ese método implementa
 * el criterio BLOCKLIST de REQ-169 (Compras > Inventario, en producción: cuenta todo excepto
 * `rotation=baja`, `NULL` incluido — conservador, a propósito). REQ-346 RN1 define un criterio
 * ALLOWLIST distinto para Bodega: "productos de rotación ALTA o MEDIA que además están en Bajo
 * stock o Sin stock" — `NULL`/sin clasificar NO cuenta. Decisión de Luis: separar la lógica por
 * pantalla en vez de unificar, para no regresionar REQ-169 (ya en producción, depende del
 * blocklist). Ver `isInAttentionBodega()` abajo.
 */
final class BodegaInventoryKpiService
{
    public function __construct(
        private readonly InventoryKpiService $inventoryKpis,
        private readonly OrderCommitmentService $commitments,
    ) {}

    /**
     * REQ-346 RN1 / SCRUM-416 (KPI) y REQ-350 / SCRUM-420 (chip "Atención") — criterio propio de
     * Bodega, ALLOWLIST: solo rotación `alta` o `media`, Y ADEMÁS en "Bajo stock" o "Sin stock"
     * (`disponible <= reorder_point`, mismo umbral que `InventoryKpiService::stockState()`).
     * `rotation === null` ("Compra única" en el frontend, ver REQ-419) NUNCA cuenta acá — a
     * diferencia de `InventoryKpiService::isInAttention()` (Compras, REQ-169), que sí lo cuenta.
     * `reorder_point === null` se trata como 0 (mismo fallback que el resto del servicio) — no
     * rompe el cálculo, simplemente exige `disponible <= 0` (Sin stock) para calificar.
     */
    public function isInAttentionBodega(CatalogProduct $product, int $committed, int $enExcluida = 0): bool
    {
        if (! in_array($product->rotation, [CatalogProduct::ROTATION_ALTA, CatalogProduct::ROTATION_MEDIA], true)) {
            return false;
        }

        $disponible = $this->inventoryKpis->disponibleParaVenta($product, $committed, $enExcluida);

        return $disponible <= ($product->reorder_point ?? 0);
    }

    /**
     * SCRUM-994 — un solo SELECT con agregados, sin hidratar modelos: antes cargaba todo el catálogo activo
     * con `get()` (~30K productos, 1,6 s y +163 MB en dev) en CADA respuesta de `index()` y de
     * `BodegaReportsService::inventorySummary()` (REQ-424 RN1, que reutiliza este método para coincidir exacto
     * con la pantalla). Las reglas viven en `sqlSinStock()`/`sqlBajoStock()` (de `InventoryKpiService`, la misma
     * regla que Compras) y en `sqlEnAtencionBodega()` (allowlist propia de Bodega, abajo). `total_por_servir`
     * suma el compromiso SOLO de productos activos, igual que antes (`committedMap()` sobre los activos).
     *
     * @return array{total_products: int, with_stock: int, low_stock: int, out_of_stock: int, in_attention: int, total_por_servir: int}
     */
    public function kpis(): array
    {
        $base = $this->withStockAggregates($this->baseQuery()->where('cp.is_active', true))->toBase();
        $base->columns = null; // solo los agregados de abajo, no `cp.*` ni las columnas por producto

        $row = $base
            ->selectRaw(
                'COUNT(*) AS total_products, '
                . 'COUNT(*) FILTER (WHERE ' . $this->inventoryKpis->sqlBajoStock() . ') AS low_stock, '
                . 'COUNT(*) FILTER (WHERE ' . $this->inventoryKpis->sqlSinStock() . ') AS out_of_stock, '
                . 'COUNT(*) FILTER (WHERE ' . $this->sqlEnAtencionBodega() . ') AS in_attention, '
                . 'COALESCE(SUM(COALESCE(inv_ps.total, 0)), 0) AS total_por_servir'
            )
            ->first();

        return [
            'total_products'   => (int) ($row->total_products ?? 0),
            // SCRUM-991 — misma cifra y definición que el KPI de Compras (`InventoryKpiService::conStock()`).
            'with_stock'       => $this->inventoryKpis->conStock(),
            'low_stock'        => (int) ($row->low_stock ?? 0),
            'out_of_stock'     => (int) ($row->out_of_stock ?? 0),
            'in_attention'     => (int) ($row->in_attention ?? 0),
            'total_por_servir' => (int) ($row->total_por_servir ?? 0),
        ];
    }

    /**
     * SCRUM-996 (auditoría SCRUM-995, sitio C) — tarjeta "Artículos críticos" del Home de Bodega: el total y los
     * `$limit` más urgentes, en SQL, con la MISMA regla del chip `criticos` del Inventario (`applyChip()`:
     * activo y `sin_stock OR bajo_stock` con el compromiso a pedidos de Bodega). Antes el Home hidrataba todo el
     * catálogo activo (2,2 s y 93 MB en cada visita) y calculaba el "por servir" con la definición de Compras
     * (`porServirMap()`), por eso decía 26.282 mientras el Inventario al que enlaza listaba 26.285 (O1 del Pre-QA
     * de SCRUM-994). Orden REQ-304 "de mayor a menor importancia": `sin_stock` primero y, dentro de cada grupo,
     * el de menor disponible; desempate por id (antes: el orden arbitrario de la consulta).
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function criticalArticles(int $limit): array
    {
        $query = $this->withStockAggregates($this->baseQuery()->where('cp.is_active', true));
        $this->applyChip($query, 'criticos');

        $count = (clone $query)->toBase();
        $count->columns = null; // solo el conteo, no `cp.*` ni las columnas por producto
        $total = (int) ($count->selectRaw('COUNT(*) AS total')->first()->total ?? 0);

        if ($total === 0 || $limit <= 0) {
            return ['items' => [], 'total' => $total];
        }

        $disponible = $this->inventoryKpis->sqlDisponible();

        /** @var list<CatalogProduct> $rows */
        $rows = $query
            ->addSelect(DB::raw($disponible . ' AS disponible_calc'))
            ->orderByRaw('CASE WHEN ' . $this->inventoryKpis->sqlSinStock() . ' THEN 0 ELSE 1 END')
            ->orderByRaw($disponible . ' ASC')
            ->orderBy('cp.id')
            ->limit($limit)
            ->get()
            ->all();

        return [
            'items' => array_map(fn (CatalogProduct $p): array => [
                'catalog_product_id' => $p->id,
                'factory_reference'  => $p->factory_reference,
                'reference'          => $p->reference,
                'description'        => $p->name,
                'disponible'         => (int) $p->getAttribute('disponible_calc'),
                'reorder_point'      => $p->reorder_point,
                'estado'             => (int) $p->getAttribute('disponible_calc') <= 0
                    ? InventoryKpiService::STOCK_SIN_STOCK
                    : InventoryKpiService::STOCK_BAJO_STOCK,
            ], $rows),
            'total' => $total,
        ];
    }

    // ── SCRUM-994 — la MISMA regla de stock de Bodega, expresada en SQL ───────────────────────────
    //
    // `stockState()`/`isInAttentionBodega()` (PHP, por producto, alimentados por `committedMap()` y
    // `enBodegaExcluidaMap()`) siguen siendo la referencia para un solo producto. Las funciones de abajo son su
    // traducción para filtrar/contar sobre TODO el catálogo sin hidratarlo. Reusan las expresiones de
    // `InventoryKpiService` (`sqlDisponible()`, `sqlSinStock()`, `sqlBajoStock()`) tal cual: el "por servir" de
    // Bodega (compromiso a pedidos, `OrderCommitmentService::committedSub()`) se une con el alias `inv_ps` que
    // esas expresiones esperan, así la fórmula `disponible = stock - por servir - en bodega excluida` existe
    // UNA sola vez. `BodegaInventorySqlParityTest` falla si SQL y PHP divergen.

    /** @return Builder<CatalogProduct> `catalog_products` con alias `cp` (mismo `baseQuery()` de Compras). */
    public function baseQuery(): Builder
    {
        return $this->inventoryKpis->baseQuery();
    }

    /**
     * Une el compromiso de Bodega (`inv_ps`) y el stock en bodegas excluidas (`inv_ex`) como subconsultas
     * agrupadas: lo que necesita `sqlDisponible()`. También se seleccionan como columnas (`committed`,
     * `en_excluida`).
     *
     * @param Builder<CatalogProduct> $query
     * @return Builder<CatalogProduct>
     */
    public function withStockAggregates(Builder $query): Builder
    {
        $query->leftJoinSub($this->commitments->committedSub(), 'inv_ps', 'inv_ps.catalog_product_id', '=', 'cp.id')
            ->addSelect(DB::raw('COALESCE(inv_ps.total, 0) AS committed'));

        return $this->inventoryKpis->withAggregates($query, ['en_excluida']);
    }

    /**
     * Une las líneas de ingreso sin confirmar por producto (`inv_pi`), para el chip "Por ingresar".
     *
     * @param Builder<CatalogProduct> $query
     * @return Builder<CatalogProduct>
     */
    public function withPorIngresar(Builder $query): Builder
    {
        return $this->inventoryKpis->withAggregates($query, ['por_ingresar']);
    }

    /**
     * Une las órdenes en camino por producto (`inv_ec`), para el chip `bajo_stock_sin_ordenar`.
     *
     * @param Builder<CatalogProduct> $query
     * @return Builder<CatalogProduct>
     */
    public function withEnCamino(Builder $query): Builder
    {
        return $this->inventoryKpis->withAggregates($query, ['en_camino']);
    }

    /** Ver `isInAttentionBodega()` — allowlist: rotación alta o media; `NULL`/baja nunca cuentan. */
    public function sqlEnAtencionBodega(): string
    {
        return "(cp.rotation IN ('" . CatalogProduct::ROTATION_ALTA . "', '" . CatalogProduct::ROTATION_MEDIA . "') AND "
            . $this->inventoryKpis->sqlDisponible() . ' <= COALESCE(cp.reorder_point, 0))';
    }

    /** Ver `stockState()` — `disponible > 0 AND disponible > reorder_point` (ni sin stock ni bajo stock). */
    public function sqlDisponibleEstado(): string
    {
        return '(' . $this->inventoryKpis->sqlDisponible() . ' > 0 AND '
            . $this->inventoryKpis->sqlDisponible() . ' > COALESCE(cp.reorder_point, 0))';
    }

    /** Chips REQ-350 que dependen de agregados: `por_ingresar`, `en_atencion` y `criticos`. */
    public function isComputedChip(string $chip): bool
    {
        return in_array($chip, ['por_ingresar', 'en_atencion', 'criticos'], true);
    }

    /**
     * REQ-350 — chips calculados. `criticos` (REQ-362 RN1) = bajo stock O sin stock. Cualquier otro valor no
     * filtra acá (`todos`/`inactivos`/`rotacion` los resuelve el caller sobre columnas).
     *
     * @param Builder<CatalogProduct> $query
     * @return Builder<CatalogProduct>
     */
    public function applyChip(Builder $query, string $chip): Builder
    {
        return match ($chip) {
            'por_ingresar'           => $query->whereRaw('COALESCE(inv_pi.total, 0) > 0'),
            'en_atencion'            => $query->whereRaw($this->sqlEnAtencionBodega()),
            'criticos'               => $query->whereRaw('(' . $this->inventoryKpis->sqlSinStock() . ' OR ' . $this->inventoryKpis->sqlBajoStock() . ')'),
            'bajo_stock_sin_ordenar' => $query->whereRaw($this->inventoryKpis->sqlLowStockWithoutOpenOrder()),
            default                  => $query,
        };
    }

    /**
     * Filtro `estado` (`disponible`/`bajo_stock`/`sin_stock`). Un valor desconocido no coincide con ningún
     * producto, igual que antes (`stockState() === $estado` nunca era verdadero).
     *
     * @param Builder<CatalogProduct> $query
     * @return Builder<CatalogProduct>
     */
    public function applyEstado(Builder $query, string $estado): Builder
    {
        return match ($estado) {
            InventoryKpiService::STOCK_SIN_STOCK   => $query->whereRaw($this->inventoryKpis->sqlSinStock()),
            InventoryKpiService::STOCK_BAJO_STOCK  => $query->whereRaw($this->inventoryKpis->sqlBajoStock()),
            InventoryKpiService::STOCK_DISPONIBLE  => $query->whereRaw($this->sqlDisponibleEstado()),
            default                                => $query->whereRaw('1 = 0'),
        };
    }

    /** ¿Este `estado`/`chip` necesita los agregados de stock (`inv_ps`/`inv_ex`) para poder filtrar? */
    public function needsStockAggregates(string $chip, ?string $estado): bool
    {
        return in_array($chip, ['en_atencion', 'criticos', 'bajo_stock_sin_ordenar'], true) || ($estado !== null && $estado !== '' && $estado !== 'todos');
    }
}
