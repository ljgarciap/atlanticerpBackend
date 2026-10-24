<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Bodega\Services\BodegaInventoryKpiService;
use App\Modules\Bodega\Services\BodegaReportsService;
use App\Modules\Bodega\Services\OrderCommitmentService;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderReceipt;
use App\Modules\Compras\Models\PurchaseOrderReceiptLine;
use App\Modules\Compras\Services\InventoryKpiService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\CatalogProductFamily;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-994 — la regla de stock de Bodega existe dos veces: en PHP (`stockState()`/`isInAttentionBodega()` +
 * `committedMap()`/`enBodegaExcluidaMap()`/`porIngresarMap()`, la implementación ORIGINAL de la pantalla) y en
 * SQL (`BodegaInventoryKpiService::apply*()`/`kpis()`), porque los chips, el filtro `estado` y los KPIs ya no
 * pueden hidratar el catálogo completo (`chip=criticos` y `estado=sin_stock` daban 500 por agotar memoria con
 * ~30K productos). Este test es lo que impide que diverjan: arma todos los bordes de la regla y compara, sobre
 * TODOS los productos de la BD y recorriendo TODAS las páginas de la API, lo que dicen los dos lados.
 * `oracleIds()` y `oracleKpis()` son la lógica PHP de antes del cambio, copiada tal cual.
 */
class BodegaInventorySqlParityTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    private BodegaInventoryKpiService $bodega;
    private InventoryKpiService $compras;
    private OrderCommitmentService $commitments;
    private Warehouse $normalWarehouse;
    private Warehouse $excludedWarehouse;
    private Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
        $this->bodega = app(BodegaInventoryKpiService::class);
        $this->compras = app(InventoryKpiService::class);
        $this->commitments = app(OrderCommitmentService::class);
        $this->provider = Provider::create(['name' => 'Paridad Bodega Corp', 'currency' => 'USD']);
        $this->normalWarehouse = Warehouse::create(['name' => 'Paridad normal', 'excluye_disponible' => false]);
        $this->excludedWarehouse = Warehouse::create(['name' => 'Paridad excluida', 'excluye_disponible' => true]);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $this->grantBodegaPermissions('liderbodega@test.com', ['bodega.read']);

        return $this->authHeaders($this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega'));
    }

    /** @param array<string, mixed> $overrides */
    private function product(array $overrides = []): CatalogProduct
    {
        return $this->makeCatalogProduct([
            'reference' => 'PB-' . uniqid(), 'name' => 'Paridad Bodega', 'stock_quantity' => 10, 'reorder_point' => 3,
            ...$overrides,
        ]);
    }

    private function commit(CatalogProduct $p, int $requested, int $delivered = 0, string $stage = Order::STAGE_ASIGNADO): void
    {
        $this->makeOrder(['stage' => $stage], [
            'catalog_product_id' => $p->id, 'qty_requested' => $requested, 'qty_delivered' => $delivered,
        ]);
    }

    private function stockIn(Warehouse $w, CatalogProduct $p, int $qty): void
    {
        ProductWarehouseStock::create(['catalog_product_id' => $p->id, 'warehouse_id' => $w->id, 'quantity' => $qty]);
    }

    private function receiptLine(CatalogProduct $p, int $qty, bool $confirmed): void
    {
        $po = PurchaseOrder::create([
            'provider_id' => $this->provider->id, 'currency' => 'USD',
            'status' => PurchaseOrder::STATUS_EN_TRANSITO, 'status_changed_at' => now(),
        ]);
        $receipt = PurchaseOrderReceipt::create(['purchase_order_id' => $po->id]);
        PurchaseOrderReceiptLine::create([
            'purchase_order_receipt_id' => $receipt->id, 'catalog_product_id' => $p->id, 'quantity' => $qty,
            'unit_cost' => 10, 'warehouse_id' => $this->normalWarehouse->id,
            'confirmed_at' => $confirmed ? now() : null,
        ]);
    }

    /** Todos los bordes de la regla de Bodega (compromiso a pedidos, allowlist de rotación, bodegas excluidas). */
    private function seedEdgeCases(): void
    {
        $alta = CatalogProduct::ROTATION_ALTA;
        $media = CatalogProduct::ROTATION_MEDIA;
        $baja = CatalogProduct::ROTATION_BAJA;

        // disponible = 0 exacto (el pedido se come todo el stock) → sin_stock; rotación alta → en atención
        $p = $this->product(['stock_quantity' => 5, 'reorder_point' => 3, 'rotation' => $alta]);
        $this->commit($p, 5);

        // disponible == reorder_point → bajo_stock (borde inclusivo); media → en atención
        $this->product(['stock_quantity' => 10, 'reorder_point' => 10, 'rotation' => $media]);
        // disponible == reorder_point + 1 → disponible, no bajo, no en atención
        $this->product(['stock_quantity' => 11, 'reorder_point' => 10, 'rotation' => $alta]);
        // baja rotación con stock bajo → bajo_stock pero NUNCA en atención (allowlist de Bodega)
        $this->product(['stock_quantity' => 2, 'reorder_point' => 5, 'rotation' => $baja]);
        // rotación NULL con stock bajo → bajo_stock pero NO en atención (a diferencia de Compras, REQ-346 RN1)
        $this->product(['stock_quantity' => 2, 'reorder_point' => 5, 'rotation' => null]);
        // rotación alta con stock bajo → en atención
        $this->product(['stock_quantity' => 2, 'reorder_point' => 5, 'rotation' => $alta]);
        // reorder_point NULL y stock 0 → sin_stock (NULL se trata como 0); stock NULL → sin_stock
        $this->product(['stock_quantity' => 0, 'reorder_point' => null, 'rotation' => $alta]);
        $this->product(['stock_quantity' => null, 'reorder_point' => null, 'rotation' => $media]);
        // reorder_point NULL con stock positivo → disponible (disponible > 0 = reorder_point efectivo)
        $this->product(['stock_quantity' => 4, 'reorder_point' => null, 'rotation' => $alta]);

        // bodega excluida: stock 20 - excluida 15 = 5 → bajo_stock (reorder 10); la bodega normal NO descuenta
        $p = $this->product(['stock_quantity' => 20, 'reorder_point' => 10, 'rotation' => $alta]);
        $this->stockIn($this->excludedWarehouse, $p, 15);
        $this->stockIn($this->normalWarehouse, $p, 5);

        // pedidos en etapas activas (cuenta lo pendiente: 3 - 1 = 2) e inactivas (despachado/entregado no cuentan)
        $p = $this->product(['stock_quantity' => 4, 'reorder_point' => 3, 'rotation' => $alta]);
        $this->commit($p, 3, 1, Order::STAGE_EN_PICKING);
        $this->commit($p, 9, 0, Order::STAGE_DESPACHADO);
        $this->commit($p, 9, 0, Order::STAGE_ENTREGADO);

        // qty_delivered > qty_requested → pendiente max(0, ...) = 0: no descuenta nada
        $p = $this->product(['stock_quantity' => 6, 'reorder_point' => 3, 'rotation' => $media]);
        $this->commit($p, 3, 5, Order::STAGE_PACKING);

        // 2 pedidos activos del mismo producto: 4 + 3 = 7 → disponible 3 > reorder 1
        $p = $this->product(['stock_quantity' => 10, 'reorder_point' => 1, 'rotation' => $alta]);
        $this->commit($p, 4, 0, Order::STAGE_PICKING_PENDIENTE);
        $this->commit($p, 3, 0, Order::STAGE_POR_DESPACHAR);

        // por ingresar: línea de ingreso sin confirmar (cuenta) vs confirmada (no cuenta)
        $p = $this->product(['stock_quantity' => 50, 'reorder_point' => 3]);
        $this->receiptLine($p, 6, confirmed: false);
        $p = $this->product(['stock_quantity' => 50, 'reorder_point' => 3]);
        $this->receiptLine($p, 6, confirmed: true);

        // inactivo con pedido activo y stock bajo: jamás cuenta en KPIs ni en chips distintos de "inactivos"
        $p = $this->product(['stock_quantity' => 0, 'reorder_point' => 5, 'rotation' => $alta, 'is_active' => false]);
        $this->commit($p, 50);
        $this->receiptLine($p, 2, confirmed: false);
    }

    // ── Oráculo: la lógica PHP original de la pantalla (antes de SCRUM-994) ─────────────────────

    /** @return list<int> */
    private function oracleIds(string $chip, ?string $estado = null): array
    {
        /** @var list<CatalogProduct> $products */
        $products = CatalogProduct::query()
            ->when($chip !== 'inactivos', fn ($q) => $q->where('is_active', true))
            ->when($chip === 'inactivos', fn ($q) => $q->where('is_active', false))
            ->get()->all();
        if ($chip === 'rotacion') {
            $products = array_values(array_filter($products, fn (CatalogProduct $p): bool => $p->rotation === CatalogProduct::ROTATION_ALTA));
        }

        $ids = array_map(fn (CatalogProduct $p): int => $p->id, $products);
        $committed = $this->commitments->committedMap($ids);
        $excl = $this->compras->enBodegaExcluidaMap($ids);
        $porIngresar = $this->compras->porIngresarMap($ids);

        if ($chip === 'por_ingresar') {
            $products = array_values(array_filter($products, fn (CatalogProduct $p): bool => ($porIngresar[$p->id] ?? 0) > 0));
        } elseif ($chip === 'en_atencion') {
            $products = array_values(array_filter($products, fn (CatalogProduct $p): bool => $this->bodega->isInAttentionBodega($p, $committed[$p->id] ?? 0, $excl[$p->id] ?? 0)));
        } elseif ($chip === 'criticos') {
            $products = array_values(array_filter($products, fn (CatalogProduct $p): bool => in_array(
                $this->compras->stockState($p, $committed[$p->id] ?? 0, $excl[$p->id] ?? 0),
                [InventoryKpiService::STOCK_BAJO_STOCK, InventoryKpiService::STOCK_SIN_STOCK],
                true,
            )));
        }

        if ($estado !== null && $estado !== '' && $estado !== 'todos') {
            $products = array_values(array_filter($products, fn (CatalogProduct $p): bool => $this->compras->stockState($p, $committed[$p->id] ?? 0, $excl[$p->id] ?? 0) === $estado));
        }

        $out = array_map(fn (CatalogProduct $p): int => $p->id, $products);
        sort($out);

        return $out;
    }

    /** @return array{total_products: int, low_stock: int, out_of_stock: int, in_attention: int, total_por_servir: int} */
    private function oracleKpis(): array
    {
        $active = CatalogProduct::where('is_active', true)->get();
        $ids = array_values(array_map('intval', $active->pluck('id')->all()));
        $committedMap = $this->commitments->committedMap($ids);
        $excl = $this->compras->enBodegaExcluidaMap($ids);

        $low = $out = $att = $porServir = 0;
        foreach ($active as $p) {
            $c = $committedMap[$p->id] ?? 0;
            $e = $excl[$p->id] ?? 0;
            $porServir += $c;
            $state = $this->compras->stockState($p, $c, $e);
            if ($state === InventoryKpiService::STOCK_SIN_STOCK) {
                $out++;
            } elseif ($state === InventoryKpiService::STOCK_BAJO_STOCK) {
                $low++;
            }
            if ($this->bodega->isInAttentionBodega($p, $c, $e)) {
                $att++;
            }
        }

        return ['total_products' => $active->count(), 'low_stock' => $low, 'out_of_stock' => $out, 'in_attention' => $att, 'total_por_servir' => $porServir];
    }

    /**
     * Recorre TODAS las páginas de la API: sin repetidos y devuelve los ids ordenados.
     *
     * @return list<int>
     */
    private function apiIds(string $query, int $perPage = 10): array
    {
        $headers = $this->headers();
        $all = [];
        $page = 1;
        do {
            $res = $this->withHeaders($headers)->getJson("/api/bodega/inventory?page={$page}&per_page={$perPage}" . ($query !== '' ? "&{$query}" : ''))->assertStatus(200);
            foreach ($res->json('data') as $row) {
                $all[] = (int) $row['id'];
            }
            $last = (int) $res->json('meta.last_page');
            $page++;
        } while ($page <= $last);

        $this->assertSame($all, array_values(array_unique($all)), "Repetidos entre páginas con {$query}");
        sort($all);

        return $all;
    }

    /** @return array<string, array{0: string}> */
    public static function chips(): array
    {
        return array_map(fn (string $c): array => [$c], array_combine(
            ['todos', 'inactivos', 'rotacion', 'por_ingresar', 'en_atencion', 'criticos'],
            ['todos', 'inactivos', 'rotacion', 'por_ingresar', 'en_atencion', 'criticos'],
        ));
    }

    #[DataProvider('chips')]
    public function test_cada_chip_devuelve_los_mismos_productos_que_la_regla_php(string $chip): void
    {
        $this->seedEdgeCases();

        $expected = $this->oracleIds($chip);
        $this->assertNotEmpty($expected, "El dataset debe tener productos para el chip {$chip}");
        $this->assertSame($expected, $this->apiIds("chip={$chip}"));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function chipsYEstados(): array
    {
        $out = [];
        foreach (['todos', 'disponible', 'bajo_stock', 'sin_stock'] as $estado) {
            foreach (['todos', 'criticos', 'en_atencion', 'por_ingresar'] as $chip) {
                $out["{$chip} + estado={$estado}"] = [$chip, $estado];
            }
        }

        return $out;
    }

    #[DataProvider('chipsYEstados')]
    public function test_cada_estado_solo_y_combinado_con_un_chip_coincide_con_la_regla_php(string $chip, string $estado): void
    {
        $this->seedEdgeCases();

        $this->assertSame($this->oracleIds($chip, $estado), $this->apiIds("chip={$chip}&estado={$estado}"));
    }

    public function test_los_estados_no_estan_vacios_en_el_dataset_de_bordes(): void
    {
        $this->seedEdgeCases();

        foreach (['disponible', 'bajo_stock', 'sin_stock'] as $estado) {
            $this->assertNotEmpty($this->oracleIds('todos', $estado), "estado {$estado} sin productos: el dataset no cubre el borde");
        }
    }

    public function test_un_estado_desconocido_no_devuelve_productos_como_antes(): void
    {
        $this->seedEdgeCases();

        $this->assertSame([], $this->oracleIds('todos', 'inventado'));
        $this->assertSame([], $this->apiIds('estado=inventado'));
    }

    public function test_los_kpis_coinciden_con_el_calculo_php_original_y_total_por_servir_solo_cuenta_activos(): void
    {
        $this->seedEdgeCases();

        $expected = $this->oracleKpis();
        $kpis = $this->bodega->kpis();
        // SCRUM-991 — `with_stock` es un KPI nuevo, sin equivalente en el cálculo PHP original que sirve de oráculo:
        // se verifica aparte, contra su propia definición (productos ACTIVOS distintos con unidades en bodega operativa).
        $conStockEsperado = ProductWarehouseStock::query()->enBodegaOperativa()->where('quantity', '>', 0)
            ->whereHas('catalogProduct', fn ($q) => $q->where('is_active', true))
            ->pluck('catalog_product_id')->unique()->count();
        $this->assertSame($conStockEsperado, $kpis['with_stock']);
        unset($kpis['with_stock']);
        $this->assertSame($expected, $kpis);
        $this->assertGreaterThan(0, $expected['low_stock']);
        $this->assertGreaterThan(0, $expected['out_of_stock']);
        $this->assertGreaterThan(0, $expected['in_attention']);
        // el pedido de 50 del producto INACTIVO no entra en el total: 5 + 2 + 7 (los pendientes de los activos)
        $this->assertSame(14, $expected['total_por_servir']);

        // y es lo que devuelve la respuesta de la pantalla
        $res = $this->withHeaders($this->headers())->getJson('/api/bodega/inventory?per_page=5')->assertStatus(200);
        $kpisRespuesta = $res->json('kpis');
        $this->assertSame($conStockEsperado, $kpisRespuesta['with_stock']);
        unset($kpisRespuesta['with_stock']);
        $this->assertSame($expected, $kpisRespuesta);
    }

    public function test_el_resumen_de_reportes_sigue_coincidiendo_exacto_con_los_kpis_de_la_pantalla(): void
    {
        $this->seedEdgeCases();

        $kpis = $this->bodega->kpis();
        $summary = app(BodegaReportsService::class)->inventorySummary();

        $this->assertSame([
            'total_products'  => $kpis['total_products'],
            'in_attention'    => $kpis['in_attention'],
            'committed_units' => $kpis['total_por_servir'],
        ], $summary);
    }

    public function test_kpis_no_hidrata_ningun_modelo_del_catalogo(): void
    {
        $this->seedEdgeCases();
        $hydrated = $this->countRetrieved(fn () => $this->bodega->kpis());

        $this->assertSame(0, $hydrated, 'kpis() debe ser un solo SELECT agregado, sin hidratar CatalogProduct');
    }

    // ── Orden: 4 campos x asc/desc, con NULLs y empates ─────────────────────────────────────────

    /** @return array<string, array{0: string, 1: string}> */
    public static function ordenes(): array
    {
        $out = [];
        foreach (['reference', 'name', 'stock_quantity', 'created_at'] as $field) {
            foreach (['asc', 'desc'] as $dir) {
                $out["{$field} {$dir}"] = [$field, $dir];
            }
        }

        return $out;
    }

    #[DataProvider('ordenes')]
    public function test_el_orden_es_estable_por_id_en_empates_y_respeta_los_nulls_en_cada_campo(string $field, string $dir): void
    {
        $specs = [
            ['Zsorta', 'Zsortref' . 'a', null, '2026-01-05 10:00:00'],
            ['zsortb', 'zsortrefb', 0, '2026-01-01 10:00:00'],
            ['Zsortc', 'Zsortrefc', 5, '2026-01-03 10:00:00'],
            ['zsortd', 'zsortrefd', 5, '2026-01-03 10:00:00'],   // empata con el anterior en stock y created_at
            ['Zsortdup', 'zsortrefe', 9, '2026-01-02 10:00:00'],
            ['zsortdup', 'zsortreff', null, '2026-01-04 10:00:00'], // mismo nombre (sin distinguir mayúsculas) que el anterior
        ];
        $mine = [];
        foreach ($specs as [$name, $ref, $stock, $created]) {
            $p = $this->product(['name' => $name, 'reference' => $ref, 'stock_quantity' => $stock, 'reorder_point' => 3]);
            DB::table((new CatalogProduct())->getTable())->where('id', $p->id)->update(['created_at' => $created]);
            $mine[$p->id] = ['name' => strtolower($name), 'reference' => strtolower($ref), 'stock_quantity' => $stock, 'created_at' => $created];
        }

        // Toda la lista en el orden de la API (todas las páginas), quedándonos con NUESTROS productos.
        $headers = $this->headers();
        $ordered = [];
        $page = 1;
        do {
            $res = $this->withHeaders($headers)->getJson("/api/bodega/inventory?page={$page}&per_page=25&sort_by={$field}&sort_dir={$dir}")->assertStatus(200);
            foreach ($res->json('data') as $row) {
                if (isset($mine[(int) $row['id']])) {
                    $ordered[] = (int) $row['id'];
                }
            }
            $last = (int) $res->json('meta.last_page');
            $page++;
        } while ($page <= $last);

        $this->assertCount(count($specs), $ordered, 'La API debe devolver todos los productos de prueba, sin repetir ni perder');

        // Postgres (ORDER BY por defecto): ASC → NULLS LAST, DESC → NULLS FIRST; los empates, por id ascendente.
        $sign = $dir === 'asc' ? 1 : -1;
        $key = fn (int $id) => $mine[$id][$field];
        for ($i = 1; $i < count($ordered); $i++) {
            [$a, $b] = [$ordered[$i - 1], $ordered[$i]];
            [$ka, $kb] = [$key($a), $key($b)];
            if ($ka === null || $kb === null) {
                if ($ka === $kb) {
                    $this->assertLessThan($b, $a, "empate de NULLs: id ascendente ({$field} {$dir})");
                } else {
                    // asc: el NULL va al final; desc: al principio
                    $this->assertSame($dir === 'asc' ? $kb === null : $ka === null, true, "NULL mal ubicado ({$field} {$dir})");
                }
                continue;
            }
            $cmp = $ka <=> $kb;
            $this->assertTrue($cmp * $sign <= 0, "Orden roto ({$field} {$dir}): {$ka} antes de {$kb}");
            if ($cmp === 0) {
                $this->assertLessThan($b, $a, "empate de {$field}: el desempate es por id ascendente");
            }
        }
    }

    #[DataProvider('ordenes')]
    public function test_el_order_by_de_la_consulta_principal_termina_con_el_desempate_por_id(string $field, string $dir): void
    {
        $this->seedEdgeCases();
        $headers = $this->headers();

        $queries = [];
        DB::listen(function ($q) use (&$queries): void {
            $queries[] = $q->sql;
        });
        $this->withHeaders($headers)->getJson("/api/bodega/inventory?per_page=10&sort_by={$field}&sort_dir={$dir}&chip=criticos")->assertStatus(200);

        $main = array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'as "cp"') && str_contains($sql, ' limit ') && str_contains($sql, 'order by')));
        $this->assertNotEmpty($main, 'no se encontró la consulta principal paginada');
        foreach ($main as $sql) {
            $this->assertMatchesRegularExpression('/order by .*"cp"\."id" asc limit /i', $sql, 'el ORDER BY debe terminar con el desempate por id');
        }
    }

    // ── Combinaciones de filtros ────────────────────────────────────────────────────────────────

    public function test_las_combinaciones_de_filtros_coinciden_con_el_calculo_php(): void
    {
        $alta = CatalogProduct::ROTATION_ALTA;
        $media = CatalogProduct::ROTATION_MEDIA;
        $otroProveedor = Provider::create(['name' => 'Otro proveedor SCRUM994', 'currency' => 'USD']);
        $family = CatalogProductFamily::create(['name' => 'Familia SCRUM994', 'is_active' => true]);

        $hit = $this->product(['stock_quantity' => 0, 'reorder_point' => 5, 'rotation' => $alta, 'provider_id' => $this->provider->id, 'brand' => 'MarcaX994', 'description' => 'Combo hit']);
        $family->products()->attach($hit->id);
        $this->stockIn($this->normalWarehouse, $hit, 3);

        $wrongWarehouse = $this->product(['stock_quantity' => 0, 'reorder_point' => 5, 'rotation' => $alta, 'provider_id' => $this->provider->id, 'brand' => 'MarcaX994']);
        $family->products()->attach($wrongWarehouse->id);

        $wrongFamily = $this->product(['stock_quantity' => 0, 'reorder_point' => 5, 'rotation' => $alta, 'provider_id' => $this->provider->id, 'brand' => 'MarcaX994']);
        $this->stockIn($this->normalWarehouse, $wrongFamily, 3);

        $wrongProvider = $this->product(['stock_quantity' => 0, 'reorder_point' => 5, 'rotation' => $alta, 'provider_id' => $otroProveedor->id, 'brand' => 'MarcaX994']);
        $family->products()->attach($wrongProvider->id);
        $this->stockIn($this->normalWarehouse, $wrongProvider, 3);

        $wrongRotation = $this->product(['stock_quantity' => 0, 'reorder_point' => 5, 'rotation' => $media, 'provider_id' => $this->provider->id, 'brand' => 'MarcaX994']);
        $family->products()->attach($wrongRotation->id);
        $this->stockIn($this->normalWarehouse, $wrongRotation, 3);

        $wrongBrand = $this->product(['stock_quantity' => 0, 'reorder_point' => 5, 'rotation' => $alta, 'provider_id' => $this->provider->id, 'brand' => 'OtraMarca']);
        $family->products()->attach($wrongBrand->id);
        $this->stockIn($this->normalWarehouse, $wrongBrand, 3);

        // fila de stock en 0 en ESA bodega: REQ-349 RN2 exige stock > 0, así que NO debe aparecer al filtrar por bodega
        $zeroQty = $this->product(['stock_quantity' => 0, 'reorder_point' => 5, 'rotation' => $alta, 'provider_id' => $this->provider->id, 'brand' => 'MarcaX994']);
        $family->products()->attach($zeroQty->id);
        $this->stockIn($this->normalWarehouse, $zeroQty, 0);

        $q = "chip=criticos&estado=sin_stock&rotation={$alta}&provider_id={$this->provider->id}&brand=marcax994"
            . "&family_id={$family->id}&warehouse_id={$this->normalWarehouse->id}";
        $this->assertSame([$hit->id], $this->apiIds($q));

        // Quitando UN filtro cada vez, entra exactamente el producto que ese filtro dejaba fuera.
        $base = "chip=criticos&estado=sin_stock&rotation={$alta}&provider_id={$this->provider->id}&brand=MarcaX994"
            . "&family_id={$family->id}&warehouse_id={$this->normalWarehouse->id}";
        $sinFiltro = fn (string $param): string => implode('&', array_filter(
            explode('&', $base),
            fn (string $kv): bool => ! str_starts_with($kv, $param . '='),
        ));
        $this->assertSame($this->sortedIds([$hit->id, $wrongWarehouse->id, $zeroQty->id]), $this->apiIds($sinFiltro('warehouse_id')));
        $this->assertSame($this->sortedIds([$hit->id, $wrongFamily->id]), $this->apiIds($sinFiltro('family_id')));
        $this->assertSame($this->sortedIds([$hit->id, $wrongProvider->id]), $this->apiIds($sinFiltro('provider_id')));
        $this->assertSame($this->sortedIds([$hit->id, $wrongRotation->id]), $this->apiIds($sinFiltro('rotation')));
        $this->assertSame($this->sortedIds([$hit->id, $wrongBrand->id]), $this->apiIds($sinFiltro('brand')));
    }

    public function test_la_cantidad_por_bodega_se_calcula_solo_para_la_pagina_y_disponible_la_usa(): void
    {
        $family = CatalogProductFamily::create(['name' => 'Familia bodega SCRUM994', 'is_active' => true]);
        $ids = [];
        for ($i = 1; $i <= 12; $i++) {
            $p = $this->product(['stock_quantity' => 100, 'reorder_point' => 3, 'reference' => sprintf('QTY-%02d', $i)]);
            $family->products()->attach($p->id);
            $this->stockIn($this->normalWarehouse, $p, $i);
            $ids[$i] = $p->id;
        }

        $headers = $this->headers();
        $res = $this->withHeaders($headers)
            ->getJson("/api/bodega/inventory?warehouse_id={$this->normalWarehouse->id}&family_id={$family->id}&per_page=10&page=2&sort_by=reference&sort_dir=asc")
            ->assertStatus(200);

        $this->assertSame(12, $res->json('meta.total'));
        $this->assertCount(2, $res->json('data'));
        $this->assertSame([$ids[11], $ids[12]], array_column($res->json('data'), 'id'));
        // `disponible` de una vista filtrada por bodega es la cantidad en ESA bodega (SCRUM-361), solo de la página
        $this->assertSame([11, 12], array_column($res->json('data'), 'disponible'));
    }

    /** @param list<int> $ids @return list<int> */
    private function sortedIds(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    // ── Regresión de hidratación y de memoria (el 500 de `chip=criticos`/`estado=sin_stock`) ────

    private function countRetrieved(callable $fn): int
    {
        $hydrated = 0;
        $active = true;
        CatalogProduct::retrieved(function () use (&$hydrated, &$active): void {
            if ($active) {
                $hydrated++;
            }
        });
        try {
            $fn();
        } finally {
            $active = false;
        }

        return $hydrated;
    }

    private function bulkProducts(int $n): void
    {
        $table = (new CatalogProduct())->getTable();
        $now = now()->toDateTimeString();
        $rot = ['alta', 'media', 'baja', null];
        foreach (array_chunk(range(1, $n), 500) as $chunk) {
            DB::table($table)->insert(array_map(fn (int $i): array => [
                'reference' => 'BULKB-' . uniqid() . "-{$i}", 'name' => "Bulk bodega {$i}", 'description' => "Bulk {$i}",
                'price_full' => 10, 'cost' => 5, 'stock_quantity' => $i % 4 === 0 ? 0 : $i % 7, 'reorder_point' => 3,
                'rotation' => $rot[$i % 4], 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }
    }

    /** @return array<string, array{0: string}> */
    public static function consultasPesadas(): array
    {
        return [
            'sin filtros'         => [''],
            'chip=criticos'       => ['chip=criticos'],
            'chip=en_atencion'    => ['chip=en_atencion'],
            'chip=por_ingresar'   => ['chip=por_ingresar'],
            'estado=sin_stock'    => ['estado=sin_stock'],
            'estado=disponible'   => ['estado=disponible'],
            'estado=bajo_stock'   => ['estado=bajo_stock'],
            'criticos + orden'    => ['chip=criticos&sort_by=stock_quantity&sort_dir=desc'],
        ];
    }

    #[DataProvider('consultasPesadas')]
    public function test_index_no_hidrata_mas_modelos_que_la_pagina_ni_gasta_decenas_de_mb(string $query): void
    {
        // Regresión de SCRUM-994: con ~30K productos `chip=criticos` y `estado=sin_stock` cargaban el conjunto
        // candidato completo en PHP (más `kpis()` hidratando el catálogo otra vez) y agotaban los 256 MB de PHP-FPM.
        // Con 5,000 productos, una página de 20 no puede hidratar más de 20 `CatalogProduct` ni gastar decenas de MB.
        $this->bulkProducts(5000);
        $headers = $this->headers();

        memory_reset_peak_usage();
        $before = memory_get_peak_usage(false);
        $response = null;
        $hydrated = $this->countRetrieved(function () use (&$response, $headers, $query): void {
            $response = $this->withHeaders($headers)->getJson('/api/bodega/inventory?page=1&per_page=20' . ($query !== '' ? "&{$query}" : ''));
        });
        $peakDeltaMb = (memory_get_peak_usage(false) - $before) / 1048576;

        $response->assertStatus(200);
        $this->assertLessThanOrEqual(20, $hydrated, "index() no debe hidratar más modelos que la página pedida ({$query})");
        $this->assertLessThan(64, $peakDeltaMb, "pico de memoria de la request < 64 MB ({$query})");
        if ($query !== 'chip=por_ingresar') { // el dataset masivo no trae ingresos sin confirmar (los cubre la paridad)
            $this->assertGreaterThan(20, (int) $response->json('meta.total'), 'el dataset grande debe devolver más de una página');
        }
    }

    public function test_chip_criticos_y_estado_sin_stock_con_un_catalogo_grande_dan_200_y_paginan_bien(): void
    {
        $this->bulkProducts(5000);
        $headers = $this->headers();

        foreach (['chip=criticos', 'estado=sin_stock'] as $query) {
            $page1 = $this->withHeaders($headers)->getJson("/api/bodega/inventory?page=1&per_page=20&{$query}")->assertStatus(200);
            $lastPage = (int) $page1->json('meta.last_page');
            $this->assertGreaterThan(50, $lastPage, "{$query}: el filtro debe dejar miles de productos");

            $last = $this->withHeaders($headers)->getJson("/api/bodega/inventory?page={$lastPage}&per_page=20&{$query}")->assertStatus(200);
            $this->assertNotEmpty($last->json('data'));
            $this->assertSame((int) $page1->json('meta.total'), (int) $last->json('meta.total'));
        }
    }
}
