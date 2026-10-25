<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega\Concerns;

use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-329 Oleada A / Batch A2 — helpers compartidos entre los tests del tablero de Pedidos.
 * Los usuarios reales de Bodega (Lider Bodega/lider_bodega, Asistente Bodega+Asistente Bodega/asistente_bodega, Ayudante General Bodega/
 * auxiliar_bodega, Transporte/transporte) ya existen vía `BusinessRoleUserSeeder` en TODOS los
 * entornos incl. testing — password = el email (cast `hashed`, ver `User::casts()`). Igual que
 * `OrderCreationFromApprovedQuoteTest::grantVentasDisenoEdit()`, `bodega.read`/`bodega.write`/
 * `bodega.approve` todavía no están wireados end-to-end vía RoleModuleVisibility (Fase D pendiente
 * de Senior Review) — se otorgan acá vía `extra_permissions`, el mismo puente que ya usa el resto
 * de la suite de Bodega (`AdjustmentRequestControllerTest::grantBodega()`).
 */
trait BodegaOrderTestHelpers
{
    protected function makeCurrentTenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @param list<string> $perms */
    protected function grantBodegaPermissions(string $email, array $perms = ['bodega.read', 'bodega.write']): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', $email)->update(['extra_permissions' => json_encode($perms)]);
    }

    protected function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    /** @param array<string, mixed> $overrides */
    protected function makeCatalogProduct(array $overrides = []): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Lámpara de prueba',
            'price_full' => 200, 'cost' => 120, 'stock_quantity' => 0, 'is_active' => true,
            ...$overrides,
        ]);
    }

    protected function setStock(CatalogProduct $product, int $quantity, ?string $warehouseName = 'Bodega Central'): ProductWarehouseStock
    {
        $warehouse = Warehouse::where('name', $warehouseName)->firstOrFail();

        return ProductWarehouseStock::updateOrCreate(
            ['catalog_product_id' => $product->id, 'warehouse_id' => $warehouse->id],
            ['quantity' => $quantity],
        );
    }

    /**
     * Crea un `Order` (+ 1 `OrderItem`) directamente en la etapa que pida el test, sin pasar por
     * todo el flujo de aprobación de cotización (ya cubierto por `OrderCreationFromApprovedQuoteTest`,
     * Batch A1) — acá el foco es el flujo de Pedidos en sí.
     *
     * @param array<string, mixed> $orderOverrides
     * @param array<string, mixed> $itemOverrides
     */
    protected function makeOrder(array $orderOverrides = [], array $itemOverrides = []): Order
    {
        $order = Order::create([
            'order_number' => 'PED-2026-'.random_int(1000, 999999),
            'order_type'   => Order::ORDER_TYPE_PEDIDO,
            'stage'        => Order::STAGE_ASIGNADO,
            'customer_name' => 'Cliente de prueba',
            ...$orderOverrides,
        ]);

        $order->items()->create([
            'qty_requested' => 10,
            'reference_snapshot' => 'REF-TEST',
            'description_snapshot' => 'Ítem de prueba',
            ...$itemOverrides,
        ]);

        return $order->fresh('items') ?? $order;
    }

    /**
     * SCRUM-329 Oleada A / Batch A4 — variante de `makeOrder()` con una `PipelineCard` real
     * detrás (proyecto + vendedor/dueño), necesaria para los tests de "Status de Pedidos"
     * (REQ-338/339/342): el filtro "propio" y el N° de cotización dependen de
     * `pipeline_card_id`, que `makeOrder()` deja en null a propósito (no lo necesitaba ningún
     * test del tablero Kanban).
     *
     * @param array<string, mixed> $orderOverrides
     * @param array<string, mixed> $itemOverrides
     */
    protected function makeOrderWithPipelineCard(int $ownerId, array $orderOverrides = [], array $itemOverrides = []): Order
    {
        $master = MasterClient::create(['name' => 'Cliente Status Test '.uniqid()]);
        $sub    = SubClient::create([
            'master_client_id' => $master->id, 'business_name' => 'Subcliente Status Test '.uniqid(),
            'tax_id' => '155-0000-1-'.random_int(1000, 9999),
        ]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Status Test '.uniqid()]);
        $card = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved', 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $ownerId, 'stage_changed_at' => Carbon::now(),
        ]);
        $card->contacts()->create(['name' => 'Contacto Status Test', 'role' => 'client', 'phone' => '6000-9999']);

        $order = Order::create([
            'order_number'     => 'PED-2026-'.random_int(1000, 999999),
            'pipeline_card_id' => $card->id,
            'order_type'       => Order::ORDER_TYPE_PEDIDO,
            'stage'            => Order::STAGE_ASIGNADO,
            'customer_name'    => $sub->business_name,
            ...$orderOverrides,
        ]);

        $order->items()->create([
            'qty_requested' => 10,
            'reference_snapshot' => 'REF-TEST',
            'description_snapshot' => 'Ítem de prueba',
            ...$itemOverrides,
        ]);

        return $order->fresh('items') ?? $order;
    }
}
