<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega\Concurrency;

use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\Bodega\Models\OrderStageHistory;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Bodega\Concerns\RunsConcurrentBodegaRequests;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-329 Oleada A / Batch A2 — Senior Review 2026-07-22, Blocker 1b: verificación de
 * concurrencia REAL (procesos/conexiones separados) para `PickingService::startPicking()`, que
 * `OrderPickingFlowTest::test_iniciar_picking_dos_veces_seguidas_la_segunda_falla()` NO cubre (esa
 * es secuencial, dentro del mismo proceso). Ver `RunsConcurrentBodegaRequests` para el mecanismo.
 */
class StartPickingConcurrencyTest extends FeatureTestCase
{
    use RunsConcurrentBodegaRequests;

    /** @var array<string, mixed> */
    protected $connectionsToTransact = [];

    private ?int $orderId = null;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    protected function tearDown(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        if ($this->orderId !== null) {
            OrderStageHistory::where('order_id', $this->orderId)->delete();
            OrderItem::where('order_id', $this->orderId)->delete();
            Order::where('id', $this->orderId)->delete();
        }

        parent::tearDown();
    }

    public function test_dos_inicios_de_picking_concurrentes_solo_uno_avanza_a_en_picking(): void
    {
        $pickerId = (int) DB::table('atlantic_auth.users')->where('email', 'auxiliarbodega@test.com')->value('id');

        $order = Order::create([
            'order_number' => 'PED-CONC-'.random_int(10000, 999999),
            'order_type' => Order::ORDER_TYPE_PEDIDO,
            'stage' => Order::STAGE_PICKING_PENDIENTE,
            'picker_id' => $pickerId,
            'customer_name' => 'Cliente concurrencia',
        ]);
        $this->orderId = $order->id;
        $order->items()->create(['qty_requested' => 5, 'reference_snapshot' => 'REF-CONC']);

        $snippet = <<<'PHP'
$order = \App\Modules\Bodega\Models\Order::find(__ORDER_ID__);
$actor = \App\Models\User::where('email', 'liderbodega@test.com')->first();
try {
    app(\App\Modules\Bodega\Services\PickingService::class)->startPicking($order, $actor);
    file_put_contents($outFile, 'OK');
} catch (\App\Modules\Bodega\Exceptions\OrderTransitionException $e) {
    file_put_contents($outFile, 'REJECTED:' . $e->getMessage());
}
PHP;
        $snippet = str_replace('__ORDER_ID__', (string) $order->id, $snippet);

        $results = $this->runConcurrently([$snippet, $snippet]);

        $okCount = count(array_filter($results, fn (string $r) => $r === 'OK'));
        $rejectedCount = count(array_filter($results, fn (string $r) => str_starts_with($r, 'REJECTED:')));

        $this->assertSame(1, $okCount, 'Exactamente 1 de los 2 inicios de picking concurrentes debe tener éxito. Resultados: '.implode(' | ', $results));
        $this->assertSame(1, $rejectedCount, 'El otro debe ver el pedido ya En picking y rechazar. Resultados: '.implode(' | ', $results));

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $this->assertSame(Order::STAGE_EN_PICKING, Order::find($order->id)->stage);
        $this->assertSame(
            1,
            OrderStageHistory::where('order_id', $order->id)->where('to_stage', Order::STAGE_EN_PICKING)->count(),
            'Blocker 1 — solo debe existir UNA fila de historial hacia En picking, no una por cada request concurrente.',
        );
    }
}
