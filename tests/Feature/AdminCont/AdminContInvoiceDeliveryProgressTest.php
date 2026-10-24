<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\Bodega\Models\Order;
use Tests\Feature\AdminCont\Concerns\BuildsPartiallyInvoicedOrders;
use Tests\Feature\AdminCont\Concerns\StubsReceptorGuard;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-950 — un pedido con 2 entregas parciales, facturado `completo` cuando solo la primera
 * llegó a una etapa facturable, no debe quedar "trabado" mostrando "Entrega 1 de 2" para siempre
 * en Facturación cuando Bodega despacha la segunda. Causa raíz real: mientras existe un `completo`
 * activo, `InvoiceService::pedidoRows()` genera UNA sola fila (la anclada a la entrega cuyo id
 * coincide con `order_id` de esa factura) — las demás entregas de la familia NUNCA generan fila
 * propia, así que esa única fila es lo único que el usuario ve. Antes mostraba la posición fija de
 * esa fila (`sequence_in_family`, que nunca cambia); ahora muestra cuántas entregas de la familia
 * llegaron a una etapa facturable, recalculado en cada lectura.
 */
class AdminContInvoiceDeliveryProgressTest extends FeatureTestCase
{
    use BuildsPartiallyInvoicedOrders;
    use StubsReceptorGuard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reTenant();
        $this->stubReceptorGuard();
    }

    /** @return array{order_id: int, fila: array<string, mixed>} */
    private function filaDe(Order $root): array
    {
        $response = $this->withHeaders($this->authHeaders($this->token()))
            ->getJson('/api/admin-contab/invoices?view=plana&tab=cobrable')
            ->assertStatus(200);
        $this->reTenant();

        $filas = collect($response->json('rows'))->where('pedido_id', $root->id)->values();
        $this->assertCount(1, $filas, 'Con un completo activo debe haber una sola fila visible para el pedido.');

        return ['order_id' => (int) $filas[0]['order_id'], 'fila' => $filas[0]];
    }

    public function test_950_el_contador_de_entregas_avanza_cuando_bodega_despacha_la_segunda_entrega_tras_facturar_completo(): void
    {
        // Entrega 0 (raíz) ya en etapa facturable; entrega 1 todavía en picking — Bodega no la
        // despachó todavía. Mismo escenario del ticket: "Bodega realiza la primera entrega → 1 de 2".
        ['root' => $root, 'entregas' => $entregas] = $this->makePedido(
            [['desc' => 'Panel', 'qty' => 10, 'price' => 50.00]],
            [[6], [4]],
            stages: [Order::STAGE_POR_DESPACHAR, Order::STAGE_EN_PICKING],
        );
        $token = $this->token();

        // "Se decide facturar el pedido completo, aunque únicamente se haya realizado la primera entrega."
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);

        $antes = $this->filaDe($root)['fila'];
        $this->assertTrue($antes['facturacion_por_entrega'], 'Precondición: este pedido admite facturación por entrega (porEntrega=true), como en la captura del ticket.');
        $this->assertSame('facturada', $antes['estado']);
        $this->assertSame(1, $antes['entrega']['sequence_in_family'], 'Antes de la segunda entrega: 1 de 2.');
        $this->assertSame(2, $antes['entrega']['total_in_family']);

        // "Posteriormente, Bodega realiza la segunda entrega → 2 de 2."
        $entregas[1]->update(['stage' => Order::STAGE_ENTREGADO]);

        $despues = $this->filaDe($root)['fila'];
        $this->assertSame(2, $despues['entrega']['sequence_in_family'], 'Después de la segunda entrega: Facturación debe reflejar 2 de 2, sin volver a facturar.');
        $this->assertSame(2, $despues['entrega']['total_in_family']);
        // "El pedido debe conservar el estado de facturado completo."
        $this->assertSame('facturada', $despues['estado']);
        $this->assertSame($antes['numero_factura'], $despues['numero_factura'], 'No hizo falta una nueva factura.');
    }

    public function test_950_funciona_para_3_4_y_5_entregas_parciales(): void
    {
        foreach ([3, 4, 5] as $total) {
            $stages   = array_fill(0, $total, Order::STAGE_EN_PICKING);
            $stages[0] = Order::STAGE_POR_DESPACHAR;
            $cantidades = array_fill(0, $total, [2]);

            ['root' => $root, 'entregas' => $entregas] = $this->makePedido(
                [['desc' => 'Panel', 'qty' => $total * 2, 'price' => 50.00]],
                $cantidades,
                stages: $stages,
            );

            $this->facturar($this->token(), [$root->id], 'completo')->assertStatus(201);
            $this->assertSame(1, $this->filaDe($root)['fila']['entrega']['sequence_in_family'], "total={$total}: arranca en 1 de {$total}.");

            // Bodega despacha el resto, una por una — el contador sube de a 1, sin volver a facturar.
            for ($i = 1; $i < $total; $i++) {
                $entregas[$i]->update(['stage' => Order::STAGE_ENTREGADO]);
                $fila = $this->filaDe($root)['fila'];
                $this->assertSame($i + 1, $fila['entrega']['sequence_in_family'], "total={$total}: tras despachar la entrega #{$i}.");
                $this->assertSame($total, $fila['entrega']['total_in_family']);
            }
        }
    }

    public function test_950_sin_completo_activo_cada_fila_conserva_su_posicion_fija_de_familia(): void
    {
        // Facturación GENUINA por entrega (sin completo): cada fila es su propia entrega real, la
        // posición fija sigue siendo la posición fija — este caso no debe cambiar de comportamiento.
        ['root' => $root, 'entregas' => $entregas] = $this->makePedido(
            [['desc' => 'Panel', 'qty' => 10, 'price' => 50.00]],
            [[6], [4]],
        );
        $token = $this->token();

        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/invoices?view=plana&tab=cobrable')
            ->assertStatus(200);
        $this->reTenant();

        $fila = collect($response->json('rows'))->firstWhere('order_id', $entregas[1]->id);
        $this->assertNotNull($fila);
        $this->assertSame(2, $fila['entrega']['sequence_in_family'], 'Sin completo activo, sigue siendo la posición fija real de esta entrega.');
    }
}
