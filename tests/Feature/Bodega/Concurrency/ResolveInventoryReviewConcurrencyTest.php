<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega\Concurrency;

use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\Bodega\Models\OrderStageHistory;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\Bodega\Concerns\RunsConcurrentBodegaRequests;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-329 Oleada A / Batch A2 — Senior Review 2026-07-22, Blocker 1b: verificación de
 * concurrencia REAL (procesos/conexiones separados) para `InventoryReviewService::resolve()`, que
 * `InventoryReviewFlowTest::test_resolver_dos_veces_la_segunda_falla()` NO cubre (esa es
 * secuencial, dentro del mismo proceso). Ver `RunsConcurrentBodegaRequests` para el mecanismo.
 */
class ResolveInventoryReviewConcurrencyTest extends FeatureTestCase
{
    use RunsConcurrentBodegaRequests;

    /** @var array<string, mixed> */
    protected $connectionsToTransact = [];

    private ?int $orderId = null;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    protected function tearDown(): void
    {
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();

        if ($this->orderId !== null) {
            OrderStageHistory::where('order_id', $this->orderId)->delete();
            OrderItem::where('order_id', $this->orderId)->delete();
            Order::where('id', $this->orderId)->delete();
        }

        parent::tearDown();
    }

    public function test_dos_resoluciones_concurrentes_de_la_misma_revision_solo_una_tiene_exito(): void
    {
        $review = Order::create([
            'order_number' => 'PED-CONC-'.random_int(10000, 999999),
            'order_type'   => Order::ORDER_TYPE_REVISION_INVENTARIO,
            'stage'        => Order::STAGE_PICKING_PENDIENTE,
            'customer_name' => 'Cliente concurrencia',
        ]);
        $this->orderId = $review->id;
        $item = $review->items()->create(['qty_requested' => 4, 'reference_snapshot' => 'REF-CONC']);

        $snippet = <<<'PHP'
$review = \App\Modules\Bodega\Models\Order::find(__ORDER_ID__);
$actor = \App\Models\User::where('email', 'almacen@illuminations.com.pa')->first();
$decisions = [['order_item_id' => __ITEM_ID__, 'found' => true, 'location' => 'A-01']];
try {
    app(\App\Modules\Bodega\Services\InventoryReviewService::class)->resolve($review, $decisions, $actor);
    file_put_contents($outFile, 'OK');
} catch (\App\Modules\Bodega\Exceptions\OrderTransitionException $e) {
    file_put_contents($outFile, 'REJECTED:' . $e->getMessage());
}
PHP;
        $snippet = str_replace(['__ORDER_ID__', '__ITEM_ID__'], [(string) $review->id, (string) $item->id], $snippet);

        $results = $this->runConcurrently([$snippet, $snippet]);

        $okCount       = count(array_filter($results, fn (string $r) => $r === 'OK'));
        $rejectedCount = count(array_filter($results, fn (string $r) => str_starts_with($r, 'REJECTED:')));

        $this->assertSame(1, $okCount, "Exactamente 1 de las 2 resoluciones concurrentes debe tener éxito. Resultados: ".implode(' | ', $results));
        $this->assertSame(1, $rejectedCount, "La otra debe ver la revisión ya resuelta y rechazar. Resultados: ".implode(' | ', $results));

        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();

        $this->assertSame(Order::STAGE_EN_PICKING, Order::find($review->id)->stage);
        $this->assertSame(
            1,
            OrderStageHistory::where('order_id', $review->id)->count(),
            'Blocker 1 — solo debe existir UNA fila de historial de transición, no una por cada request concurrente.',
        );
    }
}
