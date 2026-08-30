<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega\Concurrency;

use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderDocument;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\Bodega\Models\OrderStageHistory;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Bodega\Concerns\RunsConcurrentBodegaRequests;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-329 Oleada A / Batch A2 — Senior Review 2026-07-22, Blocker 1: dos `POST .../dispatch`
 * casi simultáneos sobre el MISMO pedido no debían poder decrementar el Kardex dos veces
 * ("double-spend" de inventario). Antes del fix no había NINGÚN lock en `dispatch()`/
 * `dispatchOne()` — este test corre 2 despachos en procesos y conexiones REALES separadas
 * (`RunsConcurrentBodegaRequests`) para probarlo de verdad, no una simulación secuencial.
 *
 * `$connectionsToTransact = []` — sin esto, el fixture creado acá vive en una transacción sin
 * commitear (RefreshDatabase), invisible para los 2 procesos hijos reales. Como consecuencia los
 * datos SÍ quedan commiteados de verdad — `tearDown()` limpia todo lo que este test crea.
 */
class DispatchConcurrencyTest extends FeatureTestCase
{
    use RunsConcurrentBodegaRequests;

    /** @var array<string, mixed> */
    protected $connectionsToTransact = [];

    private ?int $orderId = null;

    private ?int $productId = null;

    private ?int $warehouseId = null;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        Storage::fake('s3-private');
    }

    protected function tearDown(): void
    {
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();

        if ($this->orderId !== null) {
            OrderStageHistory::where('order_id', $this->orderId)->delete();
            OrderDocument::where('order_id', $this->orderId)->delete();
            OrderItem::where('order_id', $this->orderId)->delete();
            InventoryMovement::where('referencia_entidad_id', $this->orderId)
                ->where('referencia_entidad_tipo', 'PEDIDO')->delete();
            Order::where('id', $this->orderId)->delete();
        }
        if ($this->productId !== null) {
            ProductWarehouseStock::where('catalog_product_id', $this->productId)->delete();
            CatalogProduct::where('id', $this->productId)->delete();
        }

        parent::tearDown();
    }

    public function test_dos_despachos_concurrentes_sobre_el_mismo_pedido_solo_decrementan_el_kardex_una_vez(): void
    {
        $product = CatalogProduct::create([
            'reference' => 'REF-CONC-'.uniqid(), 'name' => 'Producto de prueba de concurrencia', 'description' => 'Producto de prueba de concurrencia',
            'price_full' => 100, 'cost' => 60, 'stock_quantity' => 0, 'is_active' => true,
        ]);
        $this->productId = $product->id;

        $warehouse = Warehouse::where('name', 'Bodega Central')->firstOrFail();
        $this->warehouseId = $warehouse->id;
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 20]);

        $courierId = (int) DB::table('illuminations_auth.users')->where('email', 'arrochagary@gmail.com')->value('id');

        $order = Order::create([
            'order_number' => 'PED-CONC-'.random_int(10000, 999999),
            'order_type'   => Order::ORDER_TYPE_PEDIDO,
            'stage'        => Order::STAGE_POR_DESPACHAR,
            'courier_id'   => $courierId,
            'invoice_ready' => true,
            'customer_name' => 'Cliente concurrencia',
        ]);
        $this->orderId = $order->id;
        $order->items()->create([
            'catalog_product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'qty_requested' => 5, 'qty_picked' => 5, 'qty_delivered' => 5,
            'reference_snapshot' => $product->reference,
        ]);

        $snippet = <<<'PHP'
$order = \App\Modules\Bodega\Models\Order::find(__ORDER_ID__);
$actor = \App\Models\User::where('email', 'almacen@illuminations.com.pa')->first();
try {
    app(\App\Modules\Bodega\Services\DeliveryService::class)->dispatch($order, $actor);
    file_put_contents($outFile, 'OK');
} catch (\App\Modules\Bodega\Exceptions\OrderTransitionException $e) {
    file_put_contents($outFile, 'REJECTED:' . $e->getMessage());
}
PHP;
        $snippet = str_replace('__ORDER_ID__', (string) $order->id, $snippet);

        $results = $this->runConcurrently([$snippet, $snippet]);

        $okCount       = count(array_filter($results, fn (string $r) => $r === 'OK'));
        $rejectedCount = count(array_filter($results, fn (string $r) => str_starts_with($r, 'REJECTED:')));

        $this->assertSame(2, count($results), 'Se esperaban 2 resultados (uno por proceso).');
        $this->assertSame(1, $okCount, "Exactamente 1 de los 2 despachos concurrentes debe tener éxito. Resultados: ".implode(' | ', $results));
        $this->assertSame(1, $rejectedCount, "El otro debe ver el pedido ya despachado y rechazar. Resultados: ".implode(' | ', $results));

        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();

        $this->assertSame(
            Order::STAGE_DESPACHADO,
            Order::find($order->id)->stage,
            'El pedido debe quedar Despachado (una sola vez), no revertido ni duplicado.',
        );

        $movements = InventoryMovement::where('referencia_entidad_tipo', 'PEDIDO')
            ->where('referencia_entidad_id', $order->id)
            ->get();
        $this->assertCount(1, $movements, 'Blocker 1 — el Kardex NO debe decrementarse dos veces por un doble despacho concurrente.');
        $this->assertSame(-5, $movements->first()->cantidad);

        $this->assertSame(
            15,
            ProductWarehouseStock::where('catalog_product_id', $product->id)->where('warehouse_id', $warehouse->id)->value('quantity'),
            'El stock físico debe reflejar UN solo decremento (20 - 5 = 15), no un doble decremento (20 - 10 = 10).',
        );
    }
}
