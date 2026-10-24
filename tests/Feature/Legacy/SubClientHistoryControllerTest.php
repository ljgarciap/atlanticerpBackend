<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Models\Albaran;
use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\Cotizacion;
use App\Modules\Legacy\Models\Factura;
use App\Modules\Legacy\Models\Pedido;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-762 (épica SCRUM-749) — pestaña "Historial ICG". Cubre las 4 entidades reales
 * (Cotizaciones/Pedidos/Albaranes/Facturas — nunca Pagos, ver docblock del controller), el estado
 * vacío para Subcliente sin match, y que nunca se filtre por un `legacy_cliente_id` de OTRO
 * Subcliente.
 */
final class SubClientHistoryControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function grantVentasDisenoRead(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read'])]);
    }

    private function makeSubClient(string $taxId): SubClient
    {
        $master = MasterClient::query()->create(['name' => "Master {$taxId}"]);

        return SubClient::query()->create([
            'master_client_id' => $master->id,
            'business_name'    => "Sub {$taxId}",
            'tax_id'           => $taxId,
        ]);
    }

    private function makeCliente(int $icgCodcliente, ?int $subClientId): Cliente
    {
        return Cliente::query()->create([
            'icg_codcliente' => $icgCodcliente,
            'razon_social'   => "Cliente {$icgCodcliente}",
            'sub_client_id'  => $subClientId,
            'match_status'   => $subClientId !== null ? Cliente::MATCH_MATCHED : Cliente::MATCH_UNMATCHED,
            'bloqueado'      => false,
        ]);
    }

    public function test_subcliente_sin_match_icg_retorna_estado_vacio_sin_error(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $subClient = $this->makeSubClient('9999999');
        $token     = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/sub-clients/{$subClient->id}/legacy-history?entidad=cotizaciones")
            ->assertStatus(200);

        $this->assertSame([], $response->json('data'));
        $this->assertSame(0, $response->json('total'));
    }

    public function test_lista_cotizaciones_del_cliente_matcheado(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $subClient = $this->makeSubClient('1111111');
        $cliente   = $this->makeCliente(2001, $subClient->id);
        Cotizacion::query()->create([
            'icg_numserie' => 'A', 'icg_numpresupuesto' => 500, 'icg_n' => 1, 'icg_version' => 1,
            'legacy_cliente_id' => $cliente->id, 'fecha' => '2026-08-01', 'total_neto' => '1000.00',
            'idestado' => 'A', 'orphan' => false,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/sub-clients/{$subClient->id}/legacy-history?entidad=cotizaciones")
            ->assertStatus(200);

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('A-500', $rows[0]['numero']);
        $this->assertSame('1000.00', $rows[0]['total']);
    }

    public function test_nunca_mezcla_historial_de_otro_subcliente(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $subClientA = $this->makeSubClient('1111111');
        $subClientB = $this->makeSubClient('2222222');
        $clienteA   = $this->makeCliente(2002, $subClientA->id);
        $clienteB   = $this->makeCliente(2003, $subClientB->id);

        Pedido::query()->create([
            'icg_numserie' => 'A', 'icg_numpedido' => 10, 'icg_n' => 1,
            'legacy_cliente_id' => $clienteA->id, 'fechapedido' => '2026-08-01', 'totneto' => '100.00',
            'orphan' => false,
        ]);
        Pedido::query()->create([
            'icg_numserie' => 'A', 'icg_numpedido' => 11, 'icg_n' => 1,
            'legacy_cliente_id' => $clienteB->id, 'fechapedido' => '2026-08-02', 'totneto' => '200.00',
            'orphan' => false,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/sub-clients/{$subClientA->id}/legacy-history?entidad=pedidos")
            ->assertStatus(200);

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('A-10', $rows[0]['numero']);
    }

    public function test_lista_albaranes_con_estado_derivado_de_facturado(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $subClient = $this->makeSubClient('3333333');
        $cliente   = $this->makeCliente(2004, $subClient->id);
        Albaran::query()->create([
            'icg_numserie' => 'A', 'icg_numalbaran' => 30, 'icg_n' => '1',
            'legacy_cliente_id' => $cliente->id, 'fecha' => '2026-08-01', 'totalneto' => '50.00',
            'facturado' => true, 'orphan' => false,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/sub-clients/{$subClient->id}/legacy-history?entidad=albaranes")
            ->assertStatus(200);

        $this->assertSame('facturado', $response->json('data.0.estado'));
    }

    public function test_lista_facturas_del_cliente_matcheado(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $subClient = $this->makeSubClient('4444444');
        $cliente   = $this->makeCliente(2005, $subClient->id);
        Factura::query()->create([
            'icg_numserie' => 'A', 'icg_numfactura' => 40, 'icg_n' => '1',
            'legacy_cliente_id' => $cliente->id, 'fecha' => '2026-08-01', 'total_neto' => '75.00',
            'orphan' => false,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/sub-clients/{$subClient->id}/legacy-history?entidad=facturas")
            ->assertStatus(200);

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('A-40', $rows[0]['numero']);
        $this->assertNull($rows[0]['estado']);
    }

    public function test_agrega_varios_clientes_icg_vinculados_al_mismo_subcliente(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $subClient = $this->makeSubClient('5555555');
        $clienteX  = $this->makeCliente(2006, $subClient->id);
        $clienteY  = $this->makeCliente(2007, $subClient->id); // mismo sub_client_id, sin UNIQUE en la FK

        Cotizacion::query()->create([
            'icg_numserie' => 'A', 'icg_numpresupuesto' => 60, 'icg_n' => 1, 'icg_version' => 1,
            'legacy_cliente_id' => $clienteX->id, 'fecha' => '2026-08-01', 'total_neto' => '10.00',
            'orphan' => false,
        ]);
        Cotizacion::query()->create([
            'icg_numserie' => 'A', 'icg_numpresupuesto' => 61, 'icg_n' => 1, 'icg_version' => 1,
            'legacy_cliente_id' => $clienteY->id, 'fecha' => '2026-08-02', 'total_neto' => '20.00',
            'orphan' => false,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/sub-clients/{$subClient->id}/legacy-history?entidad=cotizaciones")
            ->assertStatus(200);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_sin_permiso_ventas_diseno_read_retorna_403(): void
    {
        $subClient = $this->makeSubClient('6666666');
        $token     = $this->loginAs('electrician@atlantic.test', 'Password123!', 'electrician');

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/sub-clients/{$subClient->id}/legacy-history?entidad=cotizaciones")
            ->assertStatus(403);
    }

    // ---- show() — vista "gemela" tipo documento (cabecera + líneas), a pedido de Luis 2026-09-02 ----

    public function test_show_cotizacion_incluye_cabecera_y_lineas(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $subClient = $this->makeSubClient('7000001');
        $cliente   = $this->makeCliente(3001, $subClient->id);
        $cotizacion = Cotizacion::query()->create([
            'icg_numserie' => 'A', 'icg_numpresupuesto' => 700, 'icg_n' => 1, 'icg_version' => 1,
            'legacy_cliente_id' => $cliente->id, 'icg_codcliente' => 3001, 'fecha' => '2026-08-01',
            'total_neto' => '1000.00', 'total_bruto' => '1180.00', 'icg_codvendedor' => 45,
            'idestado' => 'A', 'orphan' => false,
        ]);
        DB::table('atlantic_legacy.cotizacion_items')->insert([
            'legacy_cotizacion_id' => $cotizacion->id, 'icg_codarticulo' => 9001,
            'referencia' => 'REF-1', 'descripcion' => 'Lámpara LED', 'cantidad' => 2,
            'precio' => 100, 'descuento' => 10, 'total' => 180, 'costo' => 60,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/sub-clients/{$subClient->id}/legacy-history/cotizaciones/{$cotizacion->id}")
            ->assertStatus(200);

        $response->assertJsonPath('entidad', 'cotizaciones');
        $response->assertJsonPath('header.folio', 'A-700-1-1');
        $response->assertJsonPath('header.cliente.id', $cliente->id);
        $response->assertJsonPath('header.icg_codvendedor', 45);
        $this->assertCount(1, $response->json('items'));
        $response->assertJsonPath('items.0.referencia', 'REF-1');
        $response->assertJsonPath('items.0.descripcion', 'Lámpara LED');
    }

    public function test_show_factura_nunca_trae_items(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $subClient = $this->makeSubClient('7000002');
        $cliente   = $this->makeCliente(3002, $subClient->id);
        $factura = Factura::query()->create([
            'icg_numserie' => 'A', 'icg_numfactura' => 40, 'icg_n' => '1',
            'legacy_cliente_id' => $cliente->id, 'fecha' => '2026-08-01', 'total_neto' => '75.00',
            'orphan' => false,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/sub-clients/{$subClient->id}/legacy-history/facturas/{$factura->id}")
            ->assertStatus(200);

        $this->assertNull($response->json('items'));
    }

    public function test_show_nunca_expone_documento_de_otro_subcliente(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $subClientA = $this->makeSubClient('7000003');
        $subClientB = $this->makeSubClient('7000004');
        $clienteB   = $this->makeCliente(3004, $subClientB->id);
        $pedidoDeB  = Pedido::query()->create([
            'icg_numserie' => 'A', 'icg_numpedido' => 10, 'icg_n' => 1,
            'legacy_cliente_id' => $clienteB->id, 'fechapedido' => '2026-08-01', 'totneto' => '100.00',
            'orphan' => false,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/sub-clients/{$subClientA->id}/legacy-history/pedidos/{$pedidoDeB->id}")
            ->assertStatus(404);
    }

    public function test_show_entidad_invalida_retorna_404(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $subClient = $this->makeSubClient('7000005');
        $token     = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/sub-clients/{$subClient->id}/legacy-history/pagos/1")
            ->assertStatus(404);
    }
}
