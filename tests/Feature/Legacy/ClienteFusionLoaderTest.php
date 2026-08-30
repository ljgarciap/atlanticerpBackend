<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\Cotizacion;
use App\Modules\Legacy\Models\Pedido;
use App\Modules\Legacy\Services\ClienteFusionLoader;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Épica SCRUM-749, decisión de Luis 2026-08-21 — promueve clientes recurrentes sin match real a
 * `sub_clients`/`master_clients` nuevos (ver docblock de `ClienteFusionLoader` para el diseño
 * completo: umbral ≥1 transacción, placeholder de CIF, estructura 1:1).
 */
final class ClienteFusionLoaderTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_promueve_cliente_unmatched_con_una_cotizacion(): void
    {
        $cliente = self::makeCliente(1001, cif: '8-123-456');
        self::makeCotizacion($cliente->id, numpresupuesto: 1);

        $result = (new ClienteFusionLoader())->fuse();

        $this->assertSame(1, $result['candidatos']);
        $this->assertSame(1, $result['promovidos']);
        $this->assertSame(0, $result['errores']);

        $cliente->refresh();
        $this->assertSame(Cliente::MATCH_PROMOTED, $cliente->match_status);
        $this->assertNotNull($cliente->sub_client_id);

        $subClient = SubClient::query()->findOrFail($cliente->sub_client_id);
        $this->assertSame('8-123-456', $subClient->tax_id);
        $this->assertNotNull($subClient->master_client_id);
    }

    public function test_promueve_cliente_con_un_pedido_en_vez_de_cotizacion(): void
    {
        $cliente = self::makeCliente(1002, cif: '8-999-999');
        self::makePedido($cliente->id, numpedido: 1);

        $result = (new ClienteFusionLoader())->fuse();

        $this->assertSame(1, $result['promovidos']);
        $cliente->refresh();
        $this->assertSame(Cliente::MATCH_PROMOTED, $cliente->match_status);
    }

    public function test_cliente_sin_cif_recibe_placeholder(): void
    {
        $cliente = self::makeCliente(1003, cif: null);
        self::makeCotizacion($cliente->id, numpresupuesto: 2);

        (new ClienteFusionLoader())->fuse();

        $cliente->refresh();
        $subClient = SubClient::query()->findOrFail($cliente->sub_client_id);
        $this->assertSame('SIN-CIF-1003', $subClient->tax_id);
    }

    public function test_cliente_sin_transacciones_no_se_promueve(): void
    {
        self::makeCliente(1004, cif: '8-111-111'); // sin cotización ni pedido

        $result = (new ClienteFusionLoader())->fuse();

        $this->assertSame(0, $result['candidatos']);
        $this->assertSame(0, $result['promovidos']);
        $cliente = Cliente::query()->where('icg_codcliente', 1004)->firstOrFail();
        $this->assertSame(Cliente::MATCH_UNMATCHED, $cliente->match_status);
        $this->assertNull($cliente->sub_client_id);
    }

    public function test_cliente_ya_matched_por_cif_email_nunca_se_toca(): void
    {
        $master = MasterClient::query()->create(['name' => 'Ya Existente SA']);
        $subClient = SubClient::query()->create([
            'master_client_id' => $master->id, 'business_name' => 'Ya Existente SA', 'tax_id' => '8-555-555',
        ]);
        $cliente = self::makeCliente(1005, cif: '8-555-555');
        $cliente->sub_client_id = $subClient->id;
        $cliente->match_status  = Cliente::MATCH_MATCHED;
        $cliente->save();
        self::makeCotizacion($cliente->id, numpresupuesto: 3);

        $result = (new ClienteFusionLoader())->fuse();

        $this->assertSame(0, $result['candidatos']); // ya tiene sub_client_id, no es candidato
        $cliente->refresh();
        $this->assertSame($subClient->id, $cliente->sub_client_id); // intacto
        $this->assertSame(Cliente::MATCH_MATCHED, $cliente->match_status); // nunca pisado a "promoted"
        $this->assertSame(1, SubClient::query()->count()); // no se creó uno nuevo de más
    }

    public function test_re_correr_es_idempotente_no_duplica_sub_clients(): void
    {
        $cliente = self::makeCliente(1006, cif: '8-222-222');
        self::makeCotizacion($cliente->id, numpresupuesto: 4);

        (new ClienteFusionLoader())->fuse();
        $result2 = (new ClienteFusionLoader())->fuse();

        $this->assertSame(0, $result2['candidatos']); // ya promovido, no vuelve a aparecer
        $this->assertSame(1, SubClient::query()->count());
        $this->assertSame(1, MasterClient::query()->count());
    }

    public function test_cliente_ambiguous_tambien_es_candidato(): void
    {
        $cliente = self::makeCliente(1007, cif: '8-333-333');
        $cliente->match_status = Cliente::MATCH_AMBIGUOUS;
        $cliente->save();
        self::makeCotizacion($cliente->id, numpresupuesto: 5);

        $result = (new ClienteFusionLoader())->fuse();

        $this->assertSame(1, $result['promovidos']);
    }

    private static function makeCliente(int $icgCodcliente, ?string $cif): Cliente
    {
        return Cliente::query()->create([
            'icg_codcliente' => $icgCodcliente,
            'razon_social'   => "Cliente {$icgCodcliente}",
            'cif'            => $cif,
            'bloqueado'      => false,
        ]);
    }

    private static function makeCotizacion(int $legacyClienteId, int $numpresupuesto): Cotizacion
    {
        return Cotizacion::query()->create([
            'icg_numserie' => 'A', 'icg_numpresupuesto' => $numpresupuesto, 'icg_n' => 1, 'icg_version' => 1,
            'legacy_cliente_id' => $legacyClienteId, 'orphan' => false,
        ]);
    }

    private static function makePedido(int $legacyClienteId, int $numpedido): Pedido
    {
        return Pedido::query()->create([
            'icg_numserie' => 'A', 'icg_numpedido' => $numpedido, 'icg_n' => 1,
            'legacy_cliente_id' => $legacyClienteId, 'orphan' => false,
        ]);
    }
}
