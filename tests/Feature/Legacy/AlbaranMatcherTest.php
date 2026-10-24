<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Models\Albaran;
use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Services\AlbaranMatcher;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-759 — `AlbaranMatcher` es un LOOKUP simple contra `legacy.clientes.icg_codcliente` (ya
 * matcheada por CIF/email en SCRUM-755) — nunca re-matchea por CIF/email acá. Mismo patrón que
 * `PedidoMatcherTest`.
 */
final class AlbaranMatcherTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_albaran_con_codcliente_conocido_matchea(): void
    {
        $cliente = Cliente::query()->create(['icg_codcliente' => 555, 'razon_social' => 'Cliente Legal SA']);
        $albaran = self::makeAlbaran(icgCodcliente: 555);

        $result = (new AlbaranMatcher())->match([$albaran->id]);

        $this->assertSame(1, $result['matched']);
        $this->assertSame(0, $result['orphan']);
        $this->assertSame(0, $result['errores']);
        $this->assertSame([], $result['details']);
        $albaran->refresh();
        $this->assertSame($cliente->id, $albaran->legacy_cliente_id);
        $this->assertFalse($albaran->orphan);
    }

    public function test_albaran_con_codcliente_desconocido_queda_orphan(): void
    {
        $albaran = self::makeAlbaran(icgCodcliente: 9999);

        $result = (new AlbaranMatcher())->match([$albaran->id]);

        $this->assertSame(0, $result['matched']);
        $this->assertSame(1, $result['orphan']);
        $this->assertSame(0, $result['errores']);
        $this->assertCount(1, $result['details']);
        $this->assertSame('orphan', $result['details'][0]['status']);
        $albaran->refresh();
        $this->assertNull($albaran->legacy_cliente_id);
        $this->assertTrue($albaran->orphan);
    }

    public function test_albaran_sin_codcliente_queda_orphan_sin_reventar(): void
    {
        $albaran = self::makeAlbaran(icgCodcliente: null);

        $result = (new AlbaranMatcher())->match([$albaran->id]);

        $this->assertSame(0, $result['matched']);
        $this->assertSame(1, $result['orphan']);
        $this->assertSame(0, $result['errores']);
        $this->assertCount(1, $result['details']);
        $this->assertSame('orphan', $result['details'][0]['status']);
        $albaran->refresh();
        $this->assertTrue($albaran->orphan);
    }

    public function test_lista_vacia_no_hace_nada(): void
    {
        $result = (new AlbaranMatcher())->match([]);

        $this->assertSame(['matched' => 0, 'orphan' => 0, 'errores' => 0, 'details' => []], $result);
    }

    public function test_matchea_multiples_albaranes_del_mismo_cliente(): void
    {
        Cliente::query()->create(['icg_codcliente' => 555, 'razon_social' => 'Cliente Legal SA']);
        $a1 = self::makeAlbaran(icgNumalbaran: 1, icgCodcliente: 555);
        $a2 = self::makeAlbaran(icgNumalbaran: 2, icgCodcliente: 555);

        $result = (new AlbaranMatcher())->match([$a1->id, $a2->id]);

        $this->assertSame(2, $result['matched']);
        $this->assertSame(0, $result['orphan']);
        $this->assertSame([], $result['details']);
    }

    private static function makeAlbaran(
        int $icgNumalbaran = 5001,
        ?int $icgCodcliente = 555,
    ): Albaran {
        return Albaran::query()->create([
            'icg_numserie' => 'A', 'icg_numalbaran' => $icgNumalbaran, 'icg_n' => 'B',
            'icg_codcliente' => $icgCodcliente, 'facturado' => false, 'orphan' => false,
            'created_at' => now(),
        ]);
    }
}
