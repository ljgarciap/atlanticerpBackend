<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Servicios\Models\Ticket;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 4 — Servicios, Grupo D parte 2 (REQ-240 RN3/RN4, REQ-291 RN3(c)/RN4, SCRUM-361) — el
 * Consumo real de Kardex al completar un Informe de Inspección con materiales vinculados a un
 * insumo real de la Reserva Servicios. Ver docblock de
 * `InspectionReportService::consumeMaterialsFromReserve()`.
 */
class InspectionReportInsumoConsumptionTest extends FeatureTestCase
{
    private const LIDER_SERVICIOS = 'servicio@atlantic.com.pa';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @param array<string, mixed> $overrides */
    private function makeTicket(array $overrides = []): Ticket
    {
        return Ticket::create([
            'numero'                   => 'INS-2026-'.static::uniqueTestNumber(),
            'tipo'                     => Ticket::TIPO_INSTALLATION,
            'subtipo'                  => Ticket::SUBTIPO_INSPECTION,
            'tipo_instalacion'         => Ticket::TIPO_INSTALACION_INTERNAL,
            'descripcion'              => 'Ticket de prueba — Grupo D parte 2',
            'cliente'                  => 'Cliente de prueba',
            'estado'                   => Ticket::ESTADO_ON_SITE,
            'quote_status'             => Ticket::QUOTE_STATUS_NOT_APPLICABLE,
            'inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING,
            ...$overrides,
        ]);
    }

    private function reservaWarehouseId(): int
    {
        return (int) Warehouse::where('name', 'Reserva Servicios')->value('id');
    }

    private function seedReservaStock(CatalogProduct $product, int $quantity): void
    {
        ProductWarehouseStock::create([
            'catalog_product_id' => $product->id,
            'warehouse_id'       => $this->reservaWarehouseId(),
            'quantity'           => $quantity,
        ]);
    }

    public function test_material_vinculado_a_insumo_genera_consumo_al_completar(): void
    {
        $product = CatalogProduct::create(['reference' => 'INS-CONS-'.uniqid(), 'name' => 'Cinta aislante', 'description' => '', 'price_full' => 5, 'cost' => 2, 'is_active' => true]);
        $this->seedReservaStock($product, 10);

        $ticket = $this->makeTicket();
        $token  = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/servicios/tickets/{$ticket->id}/inspection-report", [
                'conclusion' => 'Conclusión de prueba',
                'materiales' => [
                    ['nombre_material' => 'Cinta aislante', 'cantidad' => '3', 'catalog_product_id' => $product->id],
                    ['nombre_material' => 'Cable suelto (no trackeado)', 'cantidad' => '1'],
                ],
            ])
            ->assertStatus(200);

        $materiales = $response->json('materiales');
        $this->assertNotEmpty($materiales);
        foreach ($materiales as $m) {
            $this->assertArrayHasKey('catalog_product_id', $m);
        }

        $this->reMakeCurrent();

        $movimientos = InventoryMovement::where('catalog_product_id', $product->id)
            ->where('tipo', InventoryMovement::TIPO_CONSUMO)
            ->get();
        $this->assertCount(1, $movimientos);
        $this->assertSame(-3, $movimientos->first()->cantidad);
        $this->assertSame($this->reservaWarehouseId(), $movimientos->first()->warehouse_id);

        $stock = ProductWarehouseStock::where('catalog_product_id', $product->id)
            ->where('warehouse_id', $this->reservaWarehouseId())->first();
        $this->assertSame(7, $stock->quantity);
    }

    public function test_material_sin_catalog_product_id_nunca_afecta_inventario(): void
    {
        $ticket = $this->makeTicket();
        $token  = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/servicios/tickets/{$ticket->id}/inspection-report", [
                'conclusion' => 'Conclusión de prueba',
                'materiales' => [
                    ['nombre_material' => 'Silicón', 'cantidad' => '2'],
                ],
            ])
            ->assertStatus(200);

        $this->reMakeCurrent();
        $this->assertSame(0, InventoryMovement::where('tipo', InventoryMovement::TIPO_CONSUMO)->count());
    }

    public function test_editar_informe_despues_de_completado_no_duplica_el_consumo(): void
    {
        $product = CatalogProduct::create(['reference' => 'INS-CONS2-'.uniqid(), 'name' => 'Conectores Wago', 'description' => '', 'price_full' => 5, 'cost' => 2, 'is_active' => true]);
        $this->seedReservaStock($product, 20);

        $ticket = $this->makeTicket();
        $token  = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/servicios/tickets/{$ticket->id}/inspection-report", [
                'conclusion' => 'Primer guardado',
                'materiales' => [
                    ['nombre_material' => 'Conectores Wago', 'cantidad' => '5', 'catalog_product_id' => $product->id],
                ],
            ])
            ->assertStatus(200);

        // Segundo guardado -- corrige la conclusión, mismos materiales. RN: el Consumo real solo
        // se aplica la PRIMERA vez que el informe se completa (ver docblock de finishUpsert()).
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/servicios/tickets/{$ticket->id}/inspection-report", [
                'conclusion' => 'Segundo guardado',
                'materiales' => [
                    ['nombre_material' => 'Conectores Wago', 'cantidad' => '5', 'catalog_product_id' => $product->id],
                ],
            ])
            ->assertStatus(200);

        $this->reMakeCurrent();
        $movimientos = InventoryMovement::where('catalog_product_id', $product->id)
            ->where('tipo', InventoryMovement::TIPO_CONSUMO)
            ->count();
        $this->assertSame(1, $movimientos, 'El Consumo real debe aplicarse una única vez, no en cada edición.');

        $stock = ProductWarehouseStock::where('catalog_product_id', $product->id)
            ->where('warehouse_id', $this->reservaWarehouseId())->first();
        $this->assertSame(15, $stock->quantity);
    }

    public function test_cantidad_no_numerica_con_insumo_vinculado_no_bloquea_el_guardado(): void
    {
        $product = CatalogProduct::create(['reference' => 'INS-CONS3-'.uniqid(), 'name' => 'Producto raro', 'description' => '', 'price_full' => 5, 'cost' => 2, 'is_active' => true]);
        $this->seedReservaStock($product, 5);

        $ticket = $this->makeTicket();
        $token  = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/servicios/tickets/{$ticket->id}/inspection-report", [
                'conclusion' => 'Conclusión de prueba',
                'materiales' => [
                    ['nombre_material' => 'Producto raro', 'cantidad' => '2 rollos', 'catalog_product_id' => $product->id],
                ],
            ])
            ->assertStatus(200);

        $this->reMakeCurrent();
        $this->assertSame(0, InventoryMovement::where('tipo', InventoryMovement::TIPO_CONSUMO)->count());
        $stock = ProductWarehouseStock::where('catalog_product_id', $product->id)
            ->where('warehouse_id', $this->reservaWarehouseId())->first();
        $this->assertSame(5, $stock->quantity, 'El stock nunca debe tocarse cuando la cantidad no es numérica.');
    }
}
