<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\Pedido;
use App\Modules\Legacy\Services\PedidoMatcher;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-758 — `PedidoMatcher` es un LOOKUP simple contra `legacy.clientes.icg_codcliente` (ya
 * matcheada por CIF/email en SCRUM-755) — nunca re-matchea por CIF/email acá. Mismo patrón que
 * `CotizacionMatcherTest`.
 */
final class PedidoMatcherTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_pedido_con_codcliente_conocido_matchea(): void
    {
        $cliente = Cliente::query()->create(['icg_codcliente' => 555, 'razon_social' => 'Cliente Legal SA']);
        $pedido = self::makePedido(icgCodcliente: 555);

        $result = (new PedidoMatcher())->match([$pedido->id]);

        $this->assertSame(1, $result['matched']);
        $this->assertSame(0, $result['orphan']);
        $this->assertSame(0, $result['errores']);
        $this->assertSame([], $result['details']);
        $pedido->refresh();
        $this->assertSame($cliente->id, $pedido->legacy_cliente_id);
        $this->assertFalse($pedido->orphan);
    }

    public function test_pedido_con_codcliente_desconocido_queda_orphan(): void
    {
        $pedido = self::makePedido(icgCodcliente: 9999);

        $result = (new PedidoMatcher())->match([$pedido->id]);

        $this->assertSame(0, $result['matched']);
        $this->assertSame(1, $result['orphan']);
        $this->assertSame(0, $result['errores']);
        $this->assertCount(1, $result['details']);
        $this->assertSame('orphan', $result['details'][0]['status']);
        $pedido->refresh();
        $this->assertNull($pedido->legacy_cliente_id);
        $this->assertTrue($pedido->orphan);
    }

    public function test_pedido_sin_codcliente_queda_orphan_sin_reventar(): void
    {
        $pedido = self::makePedido(icgCodcliente: null);

        $result = (new PedidoMatcher())->match([$pedido->id]);

        $this->assertSame(0, $result['matched']);
        $this->assertSame(1, $result['orphan']);
        $this->assertSame(0, $result['errores']);
        $this->assertCount(1, $result['details']);
        $this->assertSame('orphan', $result['details'][0]['status']);
        $pedido->refresh();
        $this->assertTrue($pedido->orphan);
    }

    public function test_lista_vacia_no_hace_nada(): void
    {
        $result = (new PedidoMatcher())->match([]);

        $this->assertSame(['matched' => 0, 'orphan' => 0, 'errores' => 0, 'details' => []], $result);
    }

    public function test_matchea_multiples_pedidos_del_mismo_cliente(): void
    {
        Cliente::query()->create(['icg_codcliente' => 555, 'razon_social' => 'Cliente Legal SA']);
        $p1 = self::makePedido(icgNumpedido: 1, icgCodcliente: 555);
        $p2 = self::makePedido(icgNumpedido: 2, icgCodcliente: 555);

        $result = (new PedidoMatcher())->match([$p1->id, $p2->id]);

        $this->assertSame(2, $result['matched']);
        $this->assertSame(0, $result['orphan']);
        $this->assertSame([], $result['details']);
    }

    private static function makePedido(
        int $icgNumpedido = 2001,
        ?int $icgCodcliente = 555,
    ): Pedido {
        return Pedido::query()->create([
            'icg_numserie' => 'A', 'icg_numpedido' => $icgNumpedido, 'icg_n' => 1,
            'icg_codcliente' => $icgCodcliente, 'orphan' => false, 'created_at' => now(),
        ]);
    }
}
