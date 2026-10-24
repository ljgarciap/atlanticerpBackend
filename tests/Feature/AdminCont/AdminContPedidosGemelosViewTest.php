<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Http\Controllers\AdminContPedidosGemelosController;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Bodega\Models\Order;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\AdminCont\Concerns\BuildsIcgTwins;
use Tests\Feature\AdminCont\Concerns\BuildsPartiallyInvoicedOrders;
use Tests\Feature\FeatureTestCase;

/**
 * Vista de validación de los pedidos gemelos de ICG (spec `docs/specs/icg-pedidos-gemelos-vista-validacion.md`): solo lectura,
 * el pendiente sale de la cotización gemela (lo que luego factura el flujo de facturación) y los totales cubren el filtro completo.
 */
final class AdminContPedidosGemelosViewTest extends FeatureTestCase
{
    use BuildsPartiallyInvoicedOrders;
    use BuildsIcgTwins;

    private const URL = '/api/admin-contab/reports/pedidos-gemelos';

    protected function setUp(): void
    {
        parent::setUp();
        $this->reTenant();
        AdminContFiscalSettings::current()->forceFill(['pac_provider' => 'msl', 'pac_ambiente' => 'testing'])->save();
        config(['services.msl_fe.sandbox' => ['base_url' => 'https://sandbox.msl.test', 'api_key' => 'k'], 'services.msl_fe.production' => ['base_url' => 'https://prod.msl.test', 'api_key' => 'k']]);
        Cache::flush();
        Http::fake(['*/v1/validar-ruc*' => Http::response(['ok' => true, 'valido' => true, 'nombre' => 'CONSTRUCTORA GRUPO V S A', 'dv' => '33', 'tipoRuc' => '2', 'estado' => 'I'])]);
    }

    /** @param array<string, mixed> $icg */
    private function gemeloConHoja(string $hoja, string $vendedor, array $subAttrs = [], string $stage = Order::STAGE_ENTREGADO): Order
    {
        $o = $this->gemelo($subAttrs, $stage);
        $o->forceFill(['icg_hoja' => $hoja, 'icg_vendedor' => $vendedor])->save();

        return $o;
    }

    public function test_lista_los_gemelos_con_pendiente_saldo_y_totales_del_filtro_completo(): void
    {
        $a = $this->gemeloConHoja('PARCIAL 2026', 'ANA PEREZ');
        $this->gemeloConHoja('COMPLETO 2026', 'LUIS SOTO', [], Order::STAGE_ASIGNADO);

        $json = $this->api($this->token(), 'GET', self::URL)->assertOk()->json();

        $this->assertCount(2, $json['data']);
        $this->assertSame(2, $json['totales']['pedidos']);
        $this->assertEqualsWithDelta(856.0, $json['totales']['pendiente'], 0.01);
        $this->assertEqualsWithDelta(856.0, $json['totales']['saldo'], 0.01);
        $fila = collect($json['data'])->firstWhere('order_id', $a->id);
        $this->assertEqualsWithDelta(428.0, $fila['pendiente'], 0.01);
        $this->assertSame('PARCIAL 2026', $fila['hoja']);
        $this->assertTrue($fila['facturable']);

        $filtrado = $this->api($this->token(), 'GET', self::URL.'?hoja=COMPLETO%202026')->assertOk()->json();
        $this->assertSame(1, $filtrado['totales']['pedidos']);
        $this->assertEqualsWithDelta(428.0, $filtrado['totales']['pendiente'], 0.01);
    }

    public function test_el_listado_no_incluye_pedidos_normales(): void
    {
        $this->gemeloConHoja('PARCIAL 2026', 'ANA PEREZ');
        $this->makePedido([['desc' => 'A', 'qty' => 2, 'price' => 10.0]], [[2]]);

        $this->assertSame(1, $this->api($this->token(), 'GET', self::URL)->assertOk()->json('totales.pedidos'));
    }

    public function test_tras_facturar_la_fila_muestra_facturado_y_saldo_cero(): void
    {
        $o = $this->gemeloConHoja('PARCIAL 2026', 'ANA PEREZ');
        $this->api($this->token(), 'POST', '/api/admin-contab/invoices', ['order_ids' => [$o->id]])->assertStatus(201);

        $fila = $this->api($this->token(), 'GET', self::URL)->assertOk()->json('data.0');

        $this->assertEqualsWithDelta(428.0, $fila['facturado_atlanticerp'], 0.01);
        $this->assertEqualsWithDelta(0.0, $fila['saldo'], 0.01);
        $this->assertFalse($fila['facturable']);
        $this->assertSame('No queda saldo por facturar', $fila['motivo_no_facturable']);
    }

    public function test_un_gemelo_sin_dv_trae_advertencia_y_no_es_facturable(): void
    {
        $this->gemeloConHoja('PARCIAL 2026', 'ANA PEREZ', ['tax_id_dv' => null]);

        $json = $this->api($this->token(), 'GET', self::URL.'?solo_advertencias=1')->assertOk()->json();

        $this->assertSame(1, $json['totales']['pedidos']);
        $this->assertSame(['Sin DV del cliente'], $json['data'][0]['advertencias']);
        $this->assertFalse($json['data'][0]['facturable']);
    }

    public function test_el_resumen_trae_lo_esperado_y_los_omitidos_del_informe_de_la_carga(): void
    {
        $this->gemeloConHoja('PARCIAL 2026', 'ANA PEREZ');
        AuditLog::query()->create([
            'user_id' => null, 'action' => AuditService::LEGACY_CARGAR_PEDIDOS_GEMELOS, 'entity_type' => 'order',
            'metadata' => ['filas' => 3, 'por_hoja' => ['PARCIAL 2026' => ['excel' => 900.5]], 'omitidos' => [['clave' => 'V11P-9', 'hoja' => 'PARCIAL 2026', 'motivo' => 'sin SubCliente', 'pendiente_excel' => 472.5]]],
        ]);

        $resumen = $this->api($this->token(), 'GET', self::URL)->assertOk()->json('resumen');

        $this->assertSame(3, $resumen['filas_excel']);
        $this->assertEqualsWithDelta(900.5, $resumen['excel_total'], 0.01);
        $this->assertSame('sin SubCliente', $resumen['omitidos'][0]['motivo']);
        $this->assertEqualsWithDelta(428.0, $resumen['por_hoja']['PARCIAL 2026']['pendiente'], 0.01);
    }

    public function test_un_rol_fuera_del_roster_recibe_403_y_un_filtro_invalido_422(): void
    {
        $diseno = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($diseno))->getJson(self::URL)->assertStatus(403);
        $this->withHeaders($this->authHeaders($diseno))->getJson(self::URL.'/excel')->assertStatus(403);
        $this->reTenant();

        $this->api($this->token(), 'GET', self::URL.'?desde=2026-02-31')->assertStatus(422);
    }

    public function test_la_exportacion_respeta_el_filtro_y_deja_auditoria_y_la_vista_no_cambia_datos(): void
    {
        $this->gemeloConHoja('PARCIAL 2026', 'ANA PEREZ');
        $this->gemeloConHoja('COMPLETO 2026', 'LUIS SOTO', [], Order::STAGE_ASIGNADO);
        $antes = $this->conteos();

        $this->api($this->token(), 'GET', self::URL.'/excel?hoja=COMPLETO%202026')->assertOk();
        $this->api($this->token(), 'GET', self::URL)->assertOk();

        $evento = AuditLog::query()->where('action', AdminContPedidosGemelosController::AUDIT_EXPORT)->firstOrFail();
        $this->assertSame(1, $evento->metadata['filas']);
        $this->assertSame($antes, $this->conteos(), 'La vista no cambia pedidos, facturas, pagos ni abonos.');
        $this->api($this->token(), 'GET', self::URL.'/excel?hoja=NO%20EXISTE')->assertStatus(422);
    }

    /** @return array<string, int> */
    private function conteos(): array
    {
        $slug = 'atlantic';

        return [
            'orders'   => DB::table("{$slug}_bodega.orders")->count(),
            'invoices' => DB::table("{$slug}_admin_contab.invoices")->count(),
        ];
    }
}
