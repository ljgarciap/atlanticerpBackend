<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\Cotizacion;
use App\Modules\Legacy\Services\CotizacionMatcher;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-757 — `CotizacionMatcher` es un LOOKUP simple contra `legacy.clientes.icg_codcliente`
 * (ya matcheada por CIF/email en SCRUM-755) — nunca re-matchea por CIF/email acá.
 */
final class CotizacionMatcherTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    public function test_cotizacion_con_codcliente_conocido_matchea(): void
    {
        $cliente = Cliente::query()->create(['icg_codcliente' => 555, 'razon_social' => 'Cliente Legal SA']);
        $cotizacion = self::makeCotizacion(icgCodcliente: 555);

        $result = (new CotizacionMatcher())->match([$cotizacion->id]);

        $this->assertSame(['matched' => 1, 'orphan' => 0, 'errores' => 0], $result);
        $cotizacion->refresh();
        $this->assertSame($cliente->id, $cotizacion->legacy_cliente_id);
        $this->assertFalse($cotizacion->orphan);
    }

    public function test_cotizacion_con_codcliente_desconocido_queda_orphan(): void
    {
        $cotizacion = self::makeCotizacion(icgCodcliente: 9999);

        $result = (new CotizacionMatcher())->match([$cotizacion->id]);

        $this->assertSame(['matched' => 0, 'orphan' => 1, 'errores' => 0], $result);
        $cotizacion->refresh();
        $this->assertNull($cotizacion->legacy_cliente_id);
        $this->assertTrue($cotizacion->orphan);
    }

    public function test_cotizacion_sin_codcliente_queda_orphan_sin_reventar(): void
    {
        $cotizacion = self::makeCotizacion(icgCodcliente: null);

        $result = (new CotizacionMatcher())->match([$cotizacion->id]);

        $this->assertSame(['matched' => 0, 'orphan' => 1, 'errores' => 0], $result);
        $cotizacion->refresh();
        $this->assertTrue($cotizacion->orphan);
    }

    public function test_lista_vacia_no_hace_nada(): void
    {
        $result = (new CotizacionMatcher())->match([]);

        $this->assertSame(['matched' => 0, 'orphan' => 0, 'errores' => 0], $result);
    }

    public function test_matchea_multiples_cotizaciones_del_mismo_cliente(): void
    {
        Cliente::query()->create(['icg_codcliente' => 555, 'razon_social' => 'Cliente Legal SA']);
        $c1 = self::makeCotizacion(icgNumpresupuesto: 1, icgCodcliente: 555);
        $c2 = self::makeCotizacion(icgNumpresupuesto: 2, icgCodcliente: 555);

        $result = (new CotizacionMatcher())->match([$c1->id, $c2->id]);

        $this->assertSame(['matched' => 2, 'orphan' => 0, 'errores' => 0], $result);
    }

    private static function makeCotizacion(
        int $icgNumpresupuesto = 1001,
        ?int $icgCodcliente = 555,
    ): Cotizacion {
        return Cotizacion::query()->create([
            'icg_numserie' => 'A', 'icg_numpresupuesto' => $icgNumpresupuesto, 'icg_n' => 1, 'icg_version' => 1,
            'icg_codcliente' => $icgCodcliente, 'orphan' => false, 'created_at' => now(),
        ]);
    }
}
