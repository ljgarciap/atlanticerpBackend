<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\FacturacionElectronica\Services\ReceptorFiscalGuard;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Support\ReceptorTipo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\AdminCont\Concerns\BuildsIcgTwins;
use Tests\Feature\AdminCont\Concerns\BuildsPartiallyInvoicedOrders;
use Tests\Feature\FeatureTestCase;

/**
 * Pedidos gemelos de ICG (diseño `docs/architecture/icg-pedidos-gemelos-diseno.md` §9, T11–T13): un gemelo no tiene tarjeta
 * del pipeline, así que su total sale de la cotización gemela, su receptor del SubCliente propio y la guarda de receptor
 * es más dura que la de un pedido normal (nunca Consumidor Final por omisión).
 */
final class IcgTwinInvoicingTest extends FeatureTestCase
{
    use BuildsPartiallyInvoicedOrders;
    use BuildsIcgTwins;

    private const URL_FACTURAS = '/api/admin-contab/invoices';
    private const RUC          = '1689571-1-684168';

    /** @var array<string, mixed> respuesta de `validar-ruc` que devuelve el stub de `setUp()`; cada prueba puede cambiarla. */
    private array $respuestaDgi = ['ok' => true, 'valido' => true, 'nombre' => 'CONSTRUCTORA GRUPO V S A', 'dv' => '33', 'tipoRuc' => '2', 'estado' => 'I'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->reTenant();
        config([
            'services.msl_fe.sandbox'    => ['base_url' => 'https://sandbox.msl.test', 'api_key' => 'sandbox-key'],
            'services.msl_fe.production' => ['base_url' => 'https://prod.msl.test', 'api_key' => 'sandbox-key'],
        ]);
        AdminContFiscalSettings::current()->forceFill(['pac_provider' => 'msl', 'pac_ambiente' => 'testing'])->save();
        Cache::forget(ReceptorFiscalGuard::claveCache('testing', self::RUC));
        Http::fake(['*/v1/validar-ruc*' => fn () => Http::response($this->respuestaDgi)]);
    }

    protected function tearDown(): void
    {
        Cache::forget(ReceptorFiscalGuard::claveCache('testing', self::RUC));
        parent::tearDown();
    }

    public function test_el_total_de_la_factura_de_un_gemelo_sale_de_su_cotizacion_gemela(): void
    {
        $order = $this->gemelo();
        $esperado = (float) $order->linkedQuote()?->loadMissing('parts.items')->grandTotal();
        $this->assertGreaterThan(0, $esperado);

        $this->api($this->token(), 'POST', self::URL_FACTURAS, ['order_ids' => [$order->id]])->assertStatus(201);

        $facturas = $this->activas($order);
        $this->assertCount(1, $facturas);
        $this->assertSame($esperado, $this->sumaActivas($order));
        $this->assertSame(self::RUC, $facturas[0]->receptor_ruc, 'El receptor viene del SubCliente del gemelo, no de Consumidor Final.');
        $this->assertSame(ReceptorTipo::CONTRIBUYENTE, $facturas[0]->receptor_tipo);
        $this->assertSame($order->subClient?->master_client_id, $facturas[0]->master_client_id, 'La factura cuelga del cliente principal del gemelo: sin él no aparecería en el estado de cuenta del cliente.');
        $this->assertNotNull($facturas[0]->master_client_id);
    }

    public function test_un_gemelo_se_factura_aunque_bodega_aun_no_lo_despache(): void
    {
        $order = $this->gemelo([], Order::STAGE_ASIGNADO);

        $this->api($this->token(), 'POST', self::URL_FACTURAS, ['order_ids' => [$order->id]])->assertStatus(201);

        $this->assertCount(1, $this->activas($order));
    }

    public function test_un_gemelo_aparece_como_pendiente_de_facturar_en_cualquier_etapa(): void
    {
        $this->gemelo([], Order::STAGE_ASIGNADO);

        $resumen = $this->api($this->token(), 'GET', self::URL_FACTURAS.'/summary')->assertOk()->json();

        $this->assertSame(1, $resumen['pendientes_facturar'], 'El gemelo en Asignado cuenta como pendiente de facturar.');
        $this->assertSame(0, $resumen['pendientes_entrega'], 'Y no como pendiente de entrega: la etapa de Bodega no lo condiciona.');
    }

    public function test_el_listado_de_facturacion_incluye_al_gemelo_en_cualquier_etapa_sin_romperse(): void
    {
        $asignado  = $this->gemelo([], Order::STAGE_ASIGNADO);
        $entregado = $this->gemelo();

        foreach (['cobrable', 'facturado'] as $tab) {
            $this->api($this->token(), 'GET', self::URL_FACTURAS.'?view=plana&tab='.$tab)->assertOk();
        }
        $json = $this->api($this->token(), 'GET', self::URL_FACTURAS.'?view=plana&tab=cobrable&per_page=100')->assertOk()->json();
        $ids  = array_map('intval', array_column($json['rows'] ?? [], 'order_id'));

        $this->assertContains($asignado->id, $ids);
        $this->assertContains($entregado->id, $ids);
    }

    public function test_un_pedido_normal_en_asignado_sigue_sin_poder_facturarse(): void
    {
        ['root' => $normal] = $this->makePedido([['desc' => 'A', 'qty' => 2, 'price' => 10.0]], [[2]], [], [Order::STAGE_ASIGNADO]);

        $this->facturar($this->token(), [$normal->id]);

        $this->assertSame([], $this->activas($normal), 'La excepción de etapa es solo de los gemelos: un pedido normal en Asignado no se factura.');
    }

    public function test_un_gemelo_sin_dv_no_se_factura(): void
    {
        $order = $this->gemelo(['tax_id_dv' => null]);

        $r = $this->api($this->token(), 'POST', self::URL_FACTURAS, ['order_ids' => [$order->id]])->assertStatus(422);

        $this->assertSame('dv_distinto', $r->json('errors.0.receptor.estado'));
        $this->assertSame(0, AdminContInvoice::forOrder($order)->count());
    }

    public function test_un_gemelo_de_consumidor_final_no_se_factura(): void
    {
        $order = $this->gemelo(['receptor_tipo' => ReceptorTipo::CONSUMIDOR_FINAL]);

        $this->api($this->token(), 'POST', self::URL_FACTURAS, ['order_ids' => [$order->id]])->assertStatus(422);

        $this->assertSame(0, AdminContInvoice::forOrder($order)->count());
    }

    public function test_un_gemelo_sin_ruc_no_se_factura_ni_con_el_pac_apagado(): void
    {
        AdminContFiscalSettings::current()->forceFill(['pac_ambiente' => 'apagado'])->save();
        $order = $this->gemelo(['tax_id' => '']);

        $this->api($this->token(), 'POST', self::URL_FACTURAS, ['order_ids' => [$order->id]])->assertStatus(422);

        $this->assertSame(0, AdminContInvoice::forOrder($order)->count());
    }

    public function test_un_gemelo_de_persona_natural_sin_dv_se_factura_si_la_dgi_no_informa_dv(): void
    {
        $cedula = '8-767-2449';
        Cache::forget(ReceptorFiscalGuard::claveCache('testing', $cedula));
        $this->respuestaDgi = ['ok' => true, 'valido' => true, 'nombre' => 'PAOLA CASTREJON', 'tipoRuc' => '1', 'estado' => 'I'];
        $order = $this->gemelo(['business_name' => 'PAOLA CASTREJON', 'tax_id' => $cedula, 'tax_id_dv' => null]);

        $this->api($this->token(), 'POST', self::URL_FACTURAS, ['order_ids' => [$order->id]])->assertStatus(201);

        $this->assertCount(1, $this->activas($order));
        Cache::forget(ReceptorFiscalGuard::claveCache('testing', $cedula));
    }
}
