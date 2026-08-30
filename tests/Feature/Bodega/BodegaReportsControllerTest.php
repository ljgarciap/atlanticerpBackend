<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\AdjustmentRequest;
use App\Modules\Bodega\Models\AdjustmentRequestLine;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Services\BodegaInventoryKpiService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-490→495 (REQ-420→425, "Reportes de Bodega"). Mariano Sandoval (almacen2@illuminations.com.pa)
 * y Osvaldo Santos (logistica@illuminations.com.pa) son los 2 Asistentes de Bodega reales
 * sembrados por `BusinessRoleUserSeeder` en todos los entornos (ver `BodegaOrderTestHelpers`).
 */
class BodegaReportsControllerTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
    }

    private function authHeadersForBodegaReader(): array
    {
        $this->grantBodegaPermissions('almacen@illuminations.com.pa', ['bodega.read']);
        $token = $this->loginAs('almacen@illuminations.com.pa', 'almacen@illuminations.com.pa', 'lider_bodega');

        return $this->authHeaders($token);
    }

    // ── REQ-421 — Productividad por operativo ───────────────────────────────

    public function test_productividad_rankea_por_pedidos_gestionados_en_el_periodo(): void
    {
        $marianoId = $this->userIdByEmail('almacen2@illuminations.com.pa');
        $osvaldoId = $this->userIdByEmail('logistica@illuminations.com.pa');

        $this->makeOrder(['assistant_id' => $marianoId]);
        $this->makeOrder(['assistant_id' => $marianoId]);
        $this->makeOrder(['assistant_id' => $marianoId]);
        $this->makeOrder(['assistant_id' => $osvaldoId]);

        // Fuera del período actual (mes pasado) — no debe contar en "month".
        $old = $this->makeOrder(['assistant_id' => $marianoId]);
        $old->created_at = Carbon::now()->subMonths(2);
        $old->save();

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/reports/productivity?period=month')
            ->assertStatus(200);

        $response->assertJsonPath('has_data', true);
        $rows = collect($response->json('data'));
        $marianoRow = $rows->firstWhere('assistant_id', $marianoId);
        $osvaldoRow = $rows->firstWhere('assistant_id', $osvaldoId);

        $this->assertSame(3, $marianoRow['orders_count']);
        // json_encode() de un float entero (100.0) serializa como 100 (int), no 100.0.
        $this->assertEqualsWithDelta(100.0, $marianoRow['percent_of_top'], 0.01);
        $this->assertSame(1, $osvaldoRow['orders_count']);
        $this->assertEqualsWithDelta(33.3, $osvaldoRow['percent_of_top'], 0.1);
    }

    public function test_productividad_incluye_asistentes_activos_sin_pedidos(): void
    {
        $marianoId = $this->userIdByEmail('almacen2@illuminations.com.pa');
        $osvaldoId = $this->userIdByEmail('logistica@illuminations.com.pa');

        $this->makeOrder(['assistant_id' => $marianoId]);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/reports/productivity?period=month')
            ->assertStatus(200);

        // RN1 — "todos los Asistentes de Bodega activos", dinámico: Osvaldo aparece con 0 aunque
        // no haya gestionado ningún pedido este período, en vez de desaparecer del ranking.
        $rows = collect($response->json('data'));
        $osvaldoRow = $rows->firstWhere('assistant_id', $osvaldoId);
        $this->assertNotNull($osvaldoRow);
        $this->assertSame(0, $osvaldoRow['orders_count']);
    }

    public function test_productividad_sin_pedidos_en_el_periodo_indica_sin_datos(): void
    {
        // Ningún pedido creado en absoluto — Escenario 2 (REQ-421).
        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/reports/productivity?period=month')
            ->assertStatus(200);

        $response->assertJsonPath('has_data', false);
        $this->assertSame([], $response->json('data'));
    }

    public function test_productividad_excluye_asistente_inactivo(): void
    {
        $marianoId = $this->userIdByEmail('almacen2@illuminations.com.pa');
        $osvaldoId = $this->userIdByEmail('logistica@illuminations.com.pa');
        $slug = (string) \App\Shared\Multitenancy\Tenant::where('slug', 'illuminations')->value('slug');
        DB::table("{$slug}_auth.users")->where('id', $osvaldoId)->update(['is_active' => false]);

        $this->makeOrder(['assistant_id' => $marianoId]);
        $this->makeOrder(['assistant_id' => $osvaldoId]);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/reports/productivity?period=month')
            ->assertStatus(200);

        $rows = collect($response->json('data'));
        $this->assertNull($rows->firstWhere('assistant_id', $osvaldoId));
        $this->assertNotNull($rows->firstWhere('assistant_id', $marianoId));
    }

    // ── REQ-422 — Precisión de inventario ────────────────────────────────────

    private function makeAdjustmentLine(string $estado, string $motivo, ?Carbon $createdAt = null): AdjustmentRequestLine
    {
        $warehouse = \App\Modules\Bodega\Models\Warehouse::query()->firstOrFail();
        $product = $this->makeCatalogProduct();

        $line = AdjustmentRequestLine::create([
            'adjustment_request_id' => AdjustmentRequest::create([
                'catalog_product_id' => $product->id,
                'solicitado_por'     => $this->userIdByEmail('almacen@illuminations.com.pa'),
            ])->id,
            'warehouse_id' => $warehouse->id,
            'tipo'         => AdjustmentRequestLine::TIPO_SUMAR,
            'cantidad'     => 5,
            'motivo'       => $motivo,
            'estado'       => $estado,
        ]);

        if ($createdAt !== null) {
            $line->created_at = $createdAt;
            $line->save();
        }

        return $line;
    }

    public function test_precision_inventario_los_3_kpis_suman_el_total(): void
    {
        $this->makeAdjustmentLine(AdjustmentRequestLine::ESTADO_APROBADA, 'Producto dañado / roto');
        $this->makeAdjustmentLine(AdjustmentRequestLine::ESTADO_APROBADA, 'Producto dañado / roto');
        $this->makeAdjustmentLine(AdjustmentRequestLine::ESTADO_RECHAZADA, 'Robo o pérdida');
        $this->makeAdjustmentLine(AdjustmentRequestLine::ESTADO_PENDIENTE, 'Error de registro');

        // Fuera de período.
        $old = $this->makeAdjustmentLine(AdjustmentRequestLine::ESTADO_APROBADA, 'Producto dañado / roto');
        $old->created_at = Carbon::now()->subMonths(3);
        $old->save();

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/reports/adjustment-accuracy?period=month')
            ->assertStatus(200);

        $response->assertJsonPath('total', 4);
        $response->assertJsonPath('aprobadas', 2);
        $response->assertJsonPath('rechazadas', 1);
        $response->assertJsonPath('pendientes', 1);
        $this->assertSame(
            $response->json('total'),
            $response->json('aprobadas') + $response->json('rechazadas') + $response->json('pendientes'),
        );
    }

    public function test_precision_inventario_agrupa_motivos_reales(): void
    {
        $this->makeAdjustmentLine(AdjustmentRequestLine::ESTADO_APROBADA, 'Producto dañado / roto');
        $this->makeAdjustmentLine(AdjustmentRequestLine::ESTADO_APROBADA, 'Producto dañado / roto');
        $this->makeAdjustmentLine(AdjustmentRequestLine::ESTADO_RECHAZADA, 'Conteo físico no coincide');

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/reports/adjustment-accuracy?period=month')
            ->assertStatus(200);

        $motivos = collect($response->json('motivos'))->keyBy('motivo');
        $this->assertSame(2, $motivos['Producto dañado / roto']['count']);
        $this->assertSame(1, $motivos['Conteo físico no coincide']['count']);
    }

    // ── REQ-423 — Capacidad por bodega ───────────────────────────────────────

    public function test_capacidad_por_bodega_dispara_aviso_de_concentracion(): void
    {
        $concentrated = $this->makeCatalogProduct();
        $this->setStock($concentrated, 90, 'Bodega Central');
        $other = $this->makeCatalogProduct();
        $this->setStock($other, 10, 'Showroom Obarrio');

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/reports/warehouse-capacity')
            ->assertStatus(200);

        $response->assertJsonPath('total_units', 100);
        $response->assertJsonPath('concentration_alert.warehouse_name', 'Bodega Central');
        // json_encode() de un float entero (90.0) serializa como 90 (int), no 90.0.
        $response->assertJsonPath('concentration_alert.percent', 90);
        $response->assertJsonPath('concentration_alert.threshold_pct', 75);
    }

    public function test_capacidad_por_bodega_sin_concentracion_no_muestra_aviso(): void
    {
        $a = $this->makeCatalogProduct();
        $this->setStock($a, 40, 'Bodega Central');
        $b = $this->makeCatalogProduct();
        $this->setStock($b, 60, 'Showroom Obarrio');

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/reports/warehouse-capacity')
            ->assertStatus(200);

        $response->assertJsonPath('concentration_alert', null);
    }

    public function test_capacidad_por_bodega_umbral_configurable(): void
    {
        $this->grantBodegaPermissions('almacen@illuminations.com.pa', ['bodega.read', 'bodega.settings.configure']);
        $token = $this->loginAs('almacen@illuminations.com.pa', 'almacen@illuminations.com.pa', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/bodega/settings', ['capacidad_concentracion_pct' => 50])
            ->assertStatus(200)
            ->assertJsonPath('capacidad_concentracion_pct', 50);

        // loginAs()/putJson() son requests HTTP reales — `InitializeTenancy` deja el tenant sin
        // "current" al volver (mismo gotcha documentado en KardexDispatchIntegrationTest), hay
        // que reestablecerlo antes de crear fixtures vía Eloquent directo.
        $this->makeCurrentTenant();

        $a = $this->makeCatalogProduct();
        $this->setStock($a, 60, 'Bodega Central');
        $b = $this->makeCatalogProduct();
        $this->setStock($b, 40, 'Showroom Obarrio');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/bodega/reports/warehouse-capacity')
            ->assertStatus(200);

        $response->assertJsonPath('concentration_alert.warehouse_name', 'Bodega Central');
        $response->assertJsonPath('concentration_alert.threshold_pct', 50);
    }

    // ── REQ-424 — Inventario: rotación y atención ────────────────────────────

    public function test_resumen_inventario_coincide_con_ver_inventario(): void
    {
        $this->makeCatalogProduct([
            'stock_quantity' => 0,
            'reorder_point'  => 5,
            'rotation'       => CatalogProduct::ROTATION_ALTA,
            'is_active'      => true,
        ]);
        $this->makeCatalogProduct([
            'stock_quantity' => 100,
            'reorder_point'  => 5,
            'rotation'       => CatalogProduct::ROTATION_BAJA,
            'is_active'      => true,
        ]);

        $expected = app(BodegaInventoryKpiService::class)->kpis();

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/reports/inventory-summary')
            ->assertStatus(200);

        $response->assertJsonPath('total_products', $expected['total_products']);
        $response->assertJsonPath('in_attention', $expected['in_attention']);
        $response->assertJsonPath('committed_units', $expected['total_por_servir']);
        // Al menos el producto de rotación alta+sin stock debe quedar "en atención".
        $this->assertGreaterThanOrEqual(1, $expected['in_attention']);
    }

    public function test_resumen_inventario_sin_productos_en_atencion_da_cero(): void
    {
        $this->makeCatalogProduct([
            'stock_quantity' => 100,
            'reorder_point'  => 5,
            'rotation'       => CatalogProduct::ROTATION_BAJA,
            'is_active'      => true,
        ]);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/reports/inventory-summary')
            ->assertStatus(200);

        $response->assertJsonPath('in_attention', 0);
    }

    // ── Gate de permisos ──────────────────────────────────────────────────────

    public function test_reportes_requieren_bodega_read(): void
    {
        // designer@ (Ventas & Diseño) no tiene ningún permiso bodega.* — mismo usuario "sin
        // permiso" que ya usan WarehouseControllerTest/BodegaInventoryControllerTest.
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/bodega/reports/productivity')
            ->assertStatus(403);
    }
}
