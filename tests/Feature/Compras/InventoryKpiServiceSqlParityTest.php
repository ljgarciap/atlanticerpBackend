<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderLine;
use App\Modules\Compras\Models\PurchaseOrderReceipt;
use App\Modules\Compras\Models\PurchaseOrderReceiptLine;
use App\Modules\Compras\Services\InventoryKpiService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-993 — la regla de stock existe dos veces: en PHP (`disponibleParaVenta()`/`stockState()`/
 * `isInAttention()`/`isLowStockWithoutOpenOrder()` + los `*Map()`) y en SQL (`sql*()`, `withAggregates()`,
 * `applyChip()`), porque los chips/KPIs ya no pueden hidratar el catálogo completo. Este test es lo que
 * impide que diverjan: arma todos los bordes de la regla y compara, sobre TODOS los productos activos de
 * la BD, lo que dicen los dos lados. Si alguien toca la regla en un solo lado, falla acá.
 */
class InventoryKpiServiceSqlParityTest extends FeatureTestCase
{
    private InventoryKpiService $svc;
    private Provider $provider;
    private Warehouse $normalWarehouse;
    private Warehouse $excludedWarehouse;
    private SalesProject $salesProject;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->svc = app(InventoryKpiService::class);
        $this->provider = Provider::create(['name' => 'Parity Corp', 'currency' => 'USD']);
        $this->normalWarehouse = Warehouse::create(['name' => 'Parity normal', 'excluye_disponible' => false]);
        $this->excludedWarehouse = Warehouse::create(['name' => 'Parity excluida', 'excluye_disponible' => true]);
        $this->salesProject = SalesProject::create(['name' => 'Parity proyecto']);
    }

    /** @param array<string, mixed> $overrides */
    private function product(array $overrides = []): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => 'PAR-' . uniqid(), 'name' => 'Parity', 'description' => 'Parity',
            'price_full' => 10, 'cost' => 5, 'stock_quantity' => 10, 'reorder_point' => 3, 'is_active' => true,
            ...$overrides,
        ]);
    }

    private function order(string $status): PurchaseOrder
    {
        return PurchaseOrder::create([
            'provider_id' => $this->provider->id, 'currency' => 'USD',
            'status' => $status, 'status_changed_at' => now(),
        ]);
    }

    private function orderLine(PurchaseOrder $order, CatalogProduct $p, int $qty, ?int $salesProjectId = null): void
    {
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $p->id, 'quantity' => $qty,
            'unit_cost' => 10, 'subtotal' => $qty * 10, 'sales_project_id' => $salesProjectId,
        ]);
    }

    private function receiptLine(PurchaseOrder $order, CatalogProduct $p, int $qty, bool $confirmed): void
    {
        $receipt = PurchaseOrderReceipt::create(['purchase_order_id' => $order->id]);
        PurchaseOrderReceiptLine::create([
            'purchase_order_receipt_id' => $receipt->id, 'catalog_product_id' => $p->id, 'quantity' => $qty,
            'unit_cost' => 10, 'warehouse_id' => $this->normalWarehouse->id,
            'confirmed_at' => $confirmed ? now() : null,
        ]);
    }

    private function stockIn(Warehouse $w, CatalogProduct $p, int $qty): void
    {
        ProductWarehouseStock::create(['catalog_product_id' => $p->id, 'warehouse_id' => $w->id, 'quantity' => $qty]);
    }

    /** Todos los bordes de la regla, sobre productos activos (más uno inactivo que nunca debe contar). */
    private function seedEdgeCases(): void
    {
        // disponible = 0 exacto → sin_stock (por servir se come todo el stock)
        $p = $this->product(['stock_quantity' => 5, 'reorder_point' => 3]);
        $this->orderLine($this->order(PurchaseOrder::STATUS_ORDENADO), $p, 5, $this->salesProject->id);

        // disponible == reorder_point → bajo_stock (borde inclusivo)
        $this->product(['stock_quantity' => 10, 'reorder_point' => 10]);
        // disponible == reorder_point + 1 → disponible (no bajo)
        $this->product(['stock_quantity' => 11, 'reorder_point' => 10]);
        // rotación baja con stock bajo → bajo_stock pero NO en_atencion
        $this->product(['stock_quantity' => 2, 'reorder_point' => 5, 'rotation' => CatalogProduct::ROTATION_BAJA]);
        // rotación NULL con stock bajo → SÍ en_atencion
        $this->product(['stock_quantity' => 2, 'reorder_point' => 5, 'rotation' => null]);
        // rotación alta con stock bajo → en_atencion
        $this->product(['stock_quantity' => 2, 'reorder_point' => 5, 'rotation' => CatalogProduct::ROTATION_ALTA]);
        // reorder_point NULL y stock 0 → sin_stock; stock NULL → sin_stock
        $this->product(['stock_quantity' => 0, 'reorder_point' => null]);
        $this->product(['stock_quantity' => null, 'reorder_point' => null]);

        // bodega excluida: stock 20 - excluida 15 = 5 → bajo_stock (reorder 10); la normal NO descuenta
        $p = $this->product(['stock_quantity' => 20, 'reorder_point' => 10]);
        $this->stockIn($this->excludedWarehouse, $p, 15);
        $this->stockIn($this->normalWarehouse, $p, 5);

        // en_camino: orden abierta 50, registrado 30 → 20; producto sin stock (entra a "bajo sin ordenar" = NO)
        $p = $this->product(['stock_quantity' => 0, 'reorder_point' => 5]);
        $open = $this->order(PurchaseOrder::STATUS_EN_TRANSITO);
        $this->orderLine($open, $p, 50);
        $this->receiptLine($open, $p, 30, confirmed: false);

        // orden RECIBIDA → no cuenta como en_camino → bajo stock sin orden abierta ("sin ordenar")
        $p = $this->product(['stock_quantity' => 1, 'reorder_point' => 5]);
        $this->orderLine($this->order(PurchaseOrder::STATUS_RECIBIDO), $p, 40);

        // registrado > esperado → en_camino 0 (max(0, ...)), y por_ingresar 70 sin confirmar
        $p = $this->product(['stock_quantity' => 1, 'reorder_point' => 5]);
        $open2 = $this->order(PurchaseOrder::STATUS_EN_TRANSITO);
        $this->orderLine($open2, $p, 50);
        $this->receiptLine($open2, $p, 70, confirmed: false);

        // 2 órdenes abiertas del mismo producto: (50 - 20) + 30 = 60; ingreso confirmado no es por_ingresar
        $p = $this->product(['stock_quantity' => 0, 'reorder_point' => 5]);
        $a = $this->order(PurchaseOrder::STATUS_EN_TRANSITO);
        $b = $this->order(PurchaseOrder::STATUS_ORDENADO);
        $this->orderLine($a, $p, 50);
        $this->receiptLine($a, $p, 20, confirmed: true);
        $this->orderLine($b, $p, 30);

        // reserva de proyecto en una orden ya recibida: por_servir cuenta "sin importar el estado de la orden"
        $p = $this->product(['stock_quantity' => 8, 'reorder_point' => 3]);
        $this->orderLine($this->order(PurchaseOrder::STATUS_RECIBIDO), $p, 6, $this->salesProject->id);

        // inactivo, con stock bajo: jamás cuenta en KPIs ni en chips distintos de "inactivos"
        $this->product(['stock_quantity' => 0, 'reorder_point' => 5, 'is_active' => false]);
    }

    /** @return array{0: list<CatalogProduct>, 1: array<int,int>, 2: array<int,int>, 3: array<int,int>, 4: array<int,int>} */
    private function phpView(): array
    {
        /** @var list<CatalogProduct> $products */
        $products = CatalogProduct::where('is_active', true)->get()->all();
        $ids = array_map(fn (CatalogProduct $p): int => $p->id, $products);

        return [
            $products,
            $this->svc->porServirMap($ids),
            $this->svc->enBodegaExcluidaMap($ids),
            $this->svc->porIngresarMap($ids),
            $this->svc->enCaminoMap($ids),
        ];
    }

    /** @return list<int> */
    private function sqlChipIds(string $chip): array
    {
        $ids = $this->svc->applyChip(
            $this->svc->withAggregates($this->svc->baseQuery()->where('cp.is_active', true)),
            $chip,
        )->pluck('cp.id')->map(fn ($v): int => (int) $v)->all();
        sort($ids);

        return $ids;
    }

    public function test_los_agregados_por_producto_en_sql_coinciden_con_los_mapas_php(): void
    {
        $this->seedEdgeCases();
        [, $ps, $ex, $pi, $ec] = $this->phpView();

        $rows = $this->svc->withAggregates($this->svc->baseQuery()->where('cp.is_active', true))->get();

        $this->assertGreaterThanOrEqual(14, $rows->count());
        foreach ($rows as $row) {
            $id = $row->id;
            $this->assertSame($ps[$id] ?? 0, (int) $row->getAttribute('por_servir'), "por_servir de #{$id}");
            $this->assertSame($ex[$id] ?? 0, (int) $row->getAttribute('en_excluida'), "en_excluida de #{$id}");
            $this->assertSame($pi[$id] ?? 0, (int) $row->getAttribute('por_ingresar'), "por_ingresar de #{$id}");
            $this->assertSame($ec[$id] ?? 0, (int) $row->getAttribute('en_camino'), "en_camino de #{$id}");
        }
    }

    public function test_en_camino_valores_esperados_de_los_casos_borde(): void
    {
        // Ancla los números a mano (no solo SQL == PHP): si los dos lados se rompieran igual, este falla.
        $this->seedEdgeCases();
        $byCamino = $this->svc->withAggregates($this->svc->baseQuery()->where('cp.is_active', true))
            ->get()->pluck('en_camino')->map(fn ($v): int => (int) $v)->all();

        $this->assertContains(20, $byCamino);  // 50 esperado - 30 registrado
        $this->assertContains(60, $byCamino);  // (50 - 20) + 30 en dos órdenes abiertas
        $this->assertNotContains(40, $byCamino); // orden recibida no cuenta
        $this->assertNotContains(50, $byCamino);
        $this->assertNotContains(-20, $byCamino); // registrado > esperado → 0, nunca negativo
    }

    /** @return array<string, array{string}> */
    public static function chips(): array
    {
        return [
            'por_ingresar' => ['por_ingresar'],
            'en_atencion' => ['en_atencion'],
            'bajo_stock' => ['bajo_stock'],
            'sin_stock' => ['sin_stock'],
            'bajo_stock_sin_ordenar' => ['bajo_stock_sin_ordenar'],
        ];
    }

    #[DataProvider('chips')]
    public function test_cada_chip_devuelve_los_mismos_productos_que_la_regla_php(string $chip): void
    {
        $this->seedEdgeCases();
        [$products, $ps, $ex, $pi, $ec] = $this->phpView();

        $expected = [];
        foreach ($products as $p) {
            $porServir = $ps[$p->id] ?? 0;
            $enExcluida = $ex[$p->id] ?? 0;
            $match = match ($chip) {
                'por_ingresar' => ($pi[$p->id] ?? 0) > 0,
                'en_atencion' => $this->svc->isInAttention($p, $porServir, $enExcluida),
                'bajo_stock' => $this->svc->stockState($p, $porServir, $enExcluida) === InventoryKpiService::STOCK_BAJO_STOCK,
                'sin_stock' => $this->svc->stockState($p, $porServir, $enExcluida) === InventoryKpiService::STOCK_SIN_STOCK,
                'bajo_stock_sin_ordenar' => $this->svc->isLowStockWithoutOpenOrder($p, $porServir, $ec[$p->id] ?? 0, $enExcluida),
            };
            if ($match) {
                $expected[] = $p->id;
            }
        }
        sort($expected);

        $this->assertNotEmpty($expected, "el escenario debe ejercitar el chip {$chip}");
        $this->assertSame($expected, $this->sqlChipIds($chip));
    }

    public function test_kpis_y_conteo_de_gerencia_coinciden_con_el_calculo_php_original(): void
    {
        $this->seedEdgeCases();
        [$products, $ps, $ex, , $ec] = $this->phpView();

        $low = $out = $attention = $withoutOrder = 0;
        $value = 0.0;
        foreach ($products as $p) {
            $porServir = $ps[$p->id] ?? 0;
            $enExcluida = $ex[$p->id] ?? 0;
            $state = $this->svc->stockState($p, $porServir, $enExcluida);
            $out += $state === InventoryKpiService::STOCK_SIN_STOCK ? 1 : 0;
            $low += $state === InventoryKpiService::STOCK_BAJO_STOCK ? 1 : 0;
            $attention += $this->svc->isInAttention($p, $porServir, $enExcluida) ? 1 : 0;
            $withoutOrder += $this->svc->isLowStockWithoutOpenOrder($p, $porServir, $ec[$p->id] ?? 0, $enExcluida) ? 1 : 0;
            $value += (float) $p->price_full * (float) ($p->stock_quantity ?? 0);
        }

        $kpis = $this->svc->kpis();

        $this->assertSame(count($products), $kpis['total_products']);
        $this->assertSame($low, $kpis['low_stock']);
        $this->assertSame($out, $kpis['out_of_stock']);
        $this->assertSame($attention, $kpis['in_attention']);
        $this->assertEqualsWithDelta(round($value, 2), $kpis['total_value'], 0.001);
        $this->assertSame($withoutOrder, $this->svc->lowStockWithoutOpenOrderCount());
        $this->assertGreaterThan(0, $low);
        $this->assertGreaterThan(0, $out);
        $this->assertGreaterThan(0, $withoutOrder);
    }

    public function test_index_devuelve_la_pagina_en_sql_sin_hidratar_el_catalogo(): void
    {
        // Regresión de SCRUM-993: `index()` cargaba TODO el catálogo (`->get()->all()`) y agotaba la
        // memoria con ~30K productos. Con 5,000 productos, una página de 20 no puede hidratar más de
        // 20 `CatalogProduct` (contados por el evento `retrieved`) ni gastar decenas de MB.
        $this->bulkProducts(5000);
        DB::table('atlantic_auth.users')->where('email', 'designer@atlantic.test')
            ->update(['extra_permissions' => json_encode(['compras.read'])]);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        $hydrated = 0;
        CatalogProduct::retrieved(function () use (&$hydrated): void {
            $hydrated++;
        });

        memory_reset_peak_usage();
        $before = memory_get_peak_usage(false);
        $started = microtime(true);
        $response = $this->withHeaders($headers)->getJson('/api/compras/inventory?page=1&per_page=20')->assertStatus(200);
        $elapsedMs = (microtime(true) - $started) * 1000;
        $peakDeltaMb = (memory_get_peak_usage(false) - $before) / 1048576;

        fwrite(STDERR, sprintf("\nSCRUM-993 medición: %d modelos hidratados, +%.1f MB de pico, %.0f ms\n", $hydrated, $peakDeltaMb, $elapsedMs));

        $this->assertCount(20, $response->json('data'));
        $this->assertGreaterThanOrEqual(5000, $response->json('meta.total'));
        $this->assertLessThanOrEqual(20, $hydrated, 'index() no debe hidratar más modelos que la página pedida');
        $this->assertLessThan(64, $peakDeltaMb, 'pico de memoria de la request < 64 MB');
    }

    public function test_index_ordena_y_pagina_en_sql_con_desempate_estable(): void
    {
        $refs = ['b-2', 'A-1', 'c-3', 'B-1'];
        foreach ($refs as $r) {
            $this->product(['reference' => "ORD-{$r}", 'name' => "Nombre {$r}"]);
        }
        DB::table('atlantic_auth.users')->where('email', 'designer@atlantic.test')
            ->update(['extra_permissions' => json_encode(['compras.read'])]);
        $headers = $this->authHeaders($this->loginAs('designer@atlantic.test', 'Password123!', 'designer'));

        $asc = $this->withHeaders($headers)->getJson('/api/compras/inventory?per_page=100&sort_by=reference&sort_dir=asc&search=ORD-')->assertStatus(200);
        $desc = $this->withHeaders($headers)->getJson('/api/compras/inventory?per_page=100&sort_by=reference&sort_dir=desc&search=ORD-')->assertStatus(200);

        // strcasecmp: sin distinguir mayúsculas → a-1, b-1, b-2, c-3 (ORD-A-1 == ORD-a-1)
        $this->assertSame(['ORD-A-1', 'ORD-B-1', 'ORD-b-2', 'ORD-c-3'], $asc->json('data.*.reference'));
        $this->assertSame(array_reverse($asc->json('data.*.reference')), $desc->json('data.*.reference'));
        $this->assertSame(4, $asc->json('meta.total'));
    }

    private function bulkProducts(int $n): void
    {
        $table = (new CatalogProduct())->getTable();
        $now = now()->toDateTimeString();
        foreach (array_chunk(range(1, $n), 500) as $chunk) {
            DB::table($table)->insert(array_map(fn (int $i): array => [
                'reference' => 'BULK-' . uniqid() . "-{$i}", 'name' => "Bulk {$i}", 'description' => "Bulk {$i}",
                'price_full' => 10, 'cost' => 5, 'stock_quantity' => $i % 7, 'reorder_point' => 3,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }
    }
}
