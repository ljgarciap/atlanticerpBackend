<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\Factura;
use App\Modules\Legacy\Services\FacturaMatcher;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-760 — `FacturaMatcher` es un LOOKUP simple contra `legacy.clientes.icg_codcliente` (ya
 * matcheada por CIF/email en SCRUM-755) — nunca re-matchea por CIF/email acá. Mismo patrón que
 * `AlbaranMatcherTest`.
 */
final class FacturaMatcherTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_factura_con_codcliente_conocido_matchea(): void
    {
        $cliente = Cliente::query()->create(['icg_codcliente' => 555, 'razon_social' => 'Cliente Legal SA']);
        $factura = self::makeFactura(icgCodcliente: 555);

        $result = (new FacturaMatcher())->match([$factura->id]);

        $this->assertSame(1, $result['matched']);
        $this->assertSame(0, $result['orphan']);
        $this->assertSame(0, $result['errores']);
        $this->assertSame([], $result['details']);
        $factura->refresh();
        $this->assertSame($cliente->id, $factura->legacy_cliente_id);
        $this->assertFalse($factura->orphan);
    }

    public function test_factura_con_codcliente_desconocido_queda_orphan(): void
    {
        $factura = self::makeFactura(icgCodcliente: 9999);

        $result = (new FacturaMatcher())->match([$factura->id]);

        $this->assertSame(0, $result['matched']);
        $this->assertSame(1, $result['orphan']);
        $this->assertSame(0, $result['errores']);
        $this->assertCount(1, $result['details']);
        $this->assertSame('orphan', $result['details'][0]['status']);
        $factura->refresh();
        $this->assertNull($factura->legacy_cliente_id);
        $this->assertTrue($factura->orphan);
    }

    public function test_factura_sin_codcliente_queda_orphan_sin_reventar(): void
    {
        $factura = self::makeFactura(icgCodcliente: null);

        $result = (new FacturaMatcher())->match([$factura->id]);

        $this->assertSame(0, $result['matched']);
        $this->assertSame(1, $result['orphan']);
        $this->assertSame(0, $result['errores']);
        $this->assertCount(1, $result['details']);
        $this->assertSame('orphan', $result['details'][0]['status']);
        $factura->refresh();
        $this->assertTrue($factura->orphan);
    }

    public function test_lista_vacia_no_hace_nada(): void
    {
        $result = (new FacturaMatcher())->match([]);

        $this->assertSame(['matched' => 0, 'orphan' => 0, 'errores' => 0, 'details' => []], $result);
    }

    public function test_matchea_multiples_facturas_del_mismo_cliente(): void
    {
        Cliente::query()->create(['icg_codcliente' => 555, 'razon_social' => 'Cliente Legal SA']);
        $f1 = self::makeFactura(icgNumfactura: 1, icgCodcliente: 555);
        $f2 = self::makeFactura(icgNumfactura: 2, icgCodcliente: 555);

        $result = (new FacturaMatcher())->match([$f1->id, $f2->id]);

        $this->assertSame(2, $result['matched']);
        $this->assertSame(0, $result['orphan']);
        $this->assertSame([], $result['details']);
    }

    private static function makeFactura(
        int $icgNumfactura = 9001,
        ?int $icgCodcliente = 555,
    ): Factura {
        return Factura::query()->create([
            'icg_numserie' => 'A', 'icg_numfactura' => $icgNumfactura, 'icg_n' => 'B',
            'icg_codcliente' => $icgCodcliente, 'orphan' => false, 'created_at' => now(),
        ]);
    }
}
