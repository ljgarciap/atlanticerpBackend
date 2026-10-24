<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\FacturacionElectronica\Services\ReceptorFiscalGuard;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Services\ArchitectCommissionService;
use App\Modules\AdminCont\Services\CommissionOrderService;
use App\Modules\Bodega\Models\Order;
use App\Modules\Gerencia\Services\GerenciaHomeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\AdminCont\Concerns\BuildsIcgTwins;
use Tests\Feature\AdminCont\Concerns\BuildsPartiallyInvoicedOrders;
use Tests\Feature\FeatureTestCase;

/**
 * Aislamiento operativo de los pedidos gemelos de ICG (spec regla 3, tarea T2): un gemelo, aun facturado en AtlanticERP, no
 * entra en comisiones ni en los KPIs de Gerencia. Cada prueba falla si se quita el aislamiento correspondiente.
 */
final class IcgTwinIsolationTest extends FeatureTestCase
{
    use BuildsPartiallyInvoicedOrders;
    use BuildsIcgTwins;

    private const RUC = '1689571-1-684168';

    protected function setUp(): void
    {
        parent::setUp();
        $this->reTenant();
        config(['services.msl_fe.sandbox' => ['base_url' => 'https://sandbox.msl.test', 'api_key' => 'sandbox-key']]);
        AdminContFiscalSettings::current()->forceFill(['pac_provider' => 'msl', 'pac_ambiente' => 'testing'])->save();
        Cache::forget(ReceptorFiscalGuard::claveCache('testing', self::RUC));
        Http::fake(['*/v1/validar-ruc*' => Http::response(['ok' => true, 'valido' => true, 'nombre' => 'CONSTRUCTORA GRUPO V S A', 'dv' => '33', 'tipoRuc' => '2', 'estado' => 'I'])]);
    }

    protected function tearDown(): void
    {
        Cache::forget(ReceptorFiscalGuard::claveCache('testing', self::RUC));
        parent::tearDown();
    }

    public function test_un_gemelo_facturado_no_aparece_en_las_comisiones_de_vendedor_ni_de_arquitecto(): void
    {
        $gemelo = $this->gemelo();
        $normal = $this->makePedido([['desc' => 'Lámpara', 'qty' => 2, 'price' => 100.0]], [[2]])['root'];
        $this->api($this->token(), 'POST', '/api/admin-contab/invoices', ['order_ids' => [$gemelo->id]])->assertStatus(201);

        $pedidos = app(CommissionOrderService::class)->ordersForAllVendors();

        $this->assertNotContains($gemelo->order_number, $pedidos->pluck('numero_pedido')->all());
        $this->assertContains($normal->order_number, $pedidos->pluck('numero_pedido')->all(), 'Control: el pedido normal sí entra.');
        $this->assertNotContains($gemelo->order_number, app(ArchitectCommissionService::class)->allProjects()->pluck('numero_pedido')->all());
    }

    public function test_los_gemelos_entregados_no_inflan_los_kpis_de_bodega_de_gerencia(): void
    {
        $antes = app(GerenciaHomeService::class)->home([])['salud_modulos']['bodega']['completados_hoy'] ?? null;
        $this->assertNotNull($antes, 'La clave debe existir en el payload de Gerencia.');

        $this->gemelo();
        $this->gemelo();

        $despues = app(GerenciaHomeService::class)->home([])['salud_modulos']['bodega']['completados_hoy'];
        $this->assertSame($antes, $despues);
        $this->assertSame(2, Order::where('origin', 'icg_legacy')->where('stage', Order::STAGE_ENTREGADO)->count(), 'Control: los gemelos sí existen.');
    }
}
