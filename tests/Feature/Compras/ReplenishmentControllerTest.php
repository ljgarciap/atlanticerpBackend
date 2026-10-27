<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderLine;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Modules\VentasDiseno\Models\Quote;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/** SCRUM-1184 — «Pendientes de reposición»: pedidos sin stock consolidados por producto y agrupados por proveedor. */
class ReplenishmentControllerTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function grantCompras(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', $email)->update(['extra_permissions' => json_encode(['compras.read', 'compras.write'])]);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $this->grantCompras('designer@atlantic.test');

        $headers = $this->authHeaders($this->loginAs('designer@atlantic.test', 'Password123!', 'designer'));
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent(); // el login resetea el tenant: sin esto no se pueden crear proveedores/productos después

        return $headers;
    }

    private function makeProvider(string $name): Provider
    {
        return Provider::create(['name' => $name.' '.uniqid(), 'currency' => 'USD']);
    }

    /** @param array<string, mixed> $overrides */
    private function makeProduct(array $overrides = []): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => 'S1184-'.uniqid(), 'name' => 'Producto '.uniqid(), 'description' => 'Producto de prueba',
            'price_full' => 20, 'cost' => 12, 'stock_quantity' => 0, 'is_active' => true,
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeOrder(array $overrides = []): Order
    {
        return Order::create([
            'order_number' => 'PED-S1184-'.uniqid(),
            'order_type'   => Order::ORDER_TYPE_FALTANTE,
            'stage'        => Order::STAGE_ASIGNADO,
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function addItem(Order $order, ?int $productId, int $qty, array $overrides = []): OrderItem
    {
        return $order->items()->create([
            'catalog_product_id'   => $productId,
            'reference_snapshot'   => $productId === null ? null : "REF-{$productId}",
            'description_snapshot' => 'Ítem de prueba',
            'qty_requested'        => $qty,
            'found_at_location'    => false,
            'shortage_reason'      => OrderItem::SHORTAGE_SIN_STOCK,
            ...$overrides,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function productRow(array $json, int $productId): ?array
    {
        foreach ($json['groups'] as $group) {
            foreach ($group['products'] as $row) {
                if ($row['catalog_product_id'] === $productId) {
                    return $row + ['_provider' => $group['provider']];
                }
            }
        }

        return null;
    }

    public function test_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/compras/replenishment')->assertStatus(401);
    }

    public function test_con_solo_compras_read_retorna_403_porque_no_podria_generar_la_orden(): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', 'designer@atlantic.test')->update(['extra_permissions' => json_encode(['compras.read'])]);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))->getJson('/api/compras/replenishment')
            ->assertStatus(403)->assertJsonMissingPath('groups');
    }

    public function test_sin_permisos_de_compras_retorna_403_y_no_filtra_datos(): void
    {
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))->getJson('/api/compras/replenishment')
            ->assertStatus(403)->assertJsonMissingPath('groups');
    }

    public function test_consolida_un_producto_que_esperan_varios_pedidos_con_cantidad_sumada_y_pedidos_afectados(): void
    {
        $provider = $this->makeProvider('Proveedor Demo A');
        $product  = $this->makeProduct(['provider_id' => $provider->id, 'stock_quantity' => 2, 'cost' => 12, 'freight_cost' => 1.5]);
        $first    = $this->makeOrder();
        $second   = $this->makeOrder();
        $this->addItem($first, $product->id, 5);
        $this->addItem($second, $product->id, 3);
        $this->addItem($second, $product->id, 4); // mismo pedido, dos ítems del mismo producto: una sola fila de pedido con la suma

        $json = $this->withHeaders($this->headers())->getJson('/api/compras/replenishment')->assertStatus(200)->json();
        $row  = $this->productRow($json, $product->id);

        $this->assertNotNull($row, 'El producto sin stock debe aparecer.');
        $this->assertSame(12, $row['faltante']);
        $this->assertSame(2, $row['stock']);
        $this->assertSame(13.5, $row['unit_cost']);
        $this->assertSame($provider->id, $row['_provider']['id']);
        $this->assertCount(2, $row['orders']);
        $porPedido = collect($row['orders'])->pluck('qty', 'order_id')->all();
        $this->assertSame([$first->id => 5, $second->id => 7], $porPedido);
    }

    public function test_cada_pedido_trae_el_folio_de_su_cotizacion_y_null_si_no_tiene(): void
    {
        $product = $this->makeProduct();
        $sinTarjeta = $this->makeOrder();
        $this->addItem($sinTarjeta, $product->id, 1);

        $row = $this->productRow($this->withHeaders($this->headers())->getJson('/api/compras/replenishment')->assertStatus(200)->json(), $product->id);

        $this->assertArrayHasKey('quote_folio', $row['orders'][0]);
        $this->assertNull($row['orders'][0]['quote_folio'], 'Un pedido sin tarjeta/proyecto no tiene cotización: null, no un error.');
    }

    public function test_el_folio_de_la_cotizacion_del_proyecto_viaja_con_el_pedido_y_el_nombre_del_proyecto(): void
    {
        $product = $this->makeProduct();
        $ownerId = (int) User::where('email', 'designer@atlantic.test')->value('id');
        $order   = $this->makeOrderWithPipelineCard($ownerId, ['order_type' => Order::ORDER_TYPE_FALTANTE], ['catalog_product_id' => $product->id, 'qty_requested' => 3, 'found_at_location' => false, 'shortage_reason' => OrderItem::SHORTAGE_SIN_STOCK]);
        $card    = $order->pipelineCard;
        foreach (['COT-2026-0001', 'COT-2026-0002'] as $folio) { // la última con folio del proyecto es la vigente (misma regla que Order::linkedQuote())
            Quote::create([
                'master_client_id' => $card->master_client_id, 'sub_client_id' => $card->sub_client_id, 'sales_project_id' => $card->sales_project_id,
                'ruc' => '155-0000-1-2026', 'name' => $folio, 'description' => $folio, 'owner_id' => $ownerId,
                'status' => 'approved', 'folio' => $folio, 'generated_at' => now(),
            ]);
        }

        $row = $this->productRow($this->withHeaders($this->headers())->getJson('/api/compras/replenishment')->assertStatus(200)->json(), $product->id);

        $this->assertSame('COT-2026-0002', $row['orders'][0]['quote_folio']);
        $this->assertSame($order->order_number, $row['orders'][0]['order_number']);
        $this->assertNotNull($row['orders'][0]['project']);
    }

    public function test_agrupa_por_proveedor_y_deja_sin_proveedor_al_final(): void
    {
        $alfa = $this->makeProvider('Alfa');
        $beta = $this->makeProvider('Beta');
        $pAlfa = $this->makeProduct(['provider_id' => $alfa->id]);
        $pBeta = $this->makeProduct(['provider_id' => $beta->id]);
        $pNone = $this->makeProduct(['provider_id' => null]);
        $order = $this->makeOrder();
        foreach ([$pNone, $pBeta, $pAlfa] as $p) {
            $this->addItem($order, $p->id, 1);
        }

        $json   = $this->withHeaders($this->headers())->getJson('/api/compras/replenishment')->assertStatus(200)->json();
        $groups = collect($json['groups']);

        $ours = $groups->filter(fn (array $g): bool => collect($g['products'])->pluck('catalog_product_id')->intersect([$pAlfa->id, $pBeta->id, $pNone->id])->isNotEmpty())->values();
        $this->assertCount(3, $ours);
        $this->assertNull($groups->last()['provider'], '«Sin proveedor» va siempre al final.');
        $this->assertSame([$alfa->id, $beta->id], $ours->pluck('provider.id')->filter()->values()->all());
        $this->assertSame([$pNone->id], collect($groups->last()['products'])->pluck('catalog_product_id')->intersect([$pNone->id])->values()->all());
    }

    public function test_excluye_lo_que_no_es_faltante_pendiente_y_cuenta_los_items_personalizados(): void
    {
        $product = $this->makeProduct();
        $otherReason = $this->makeProduct();
        $found       = $this->makeProduct();
        $normal      = $this->makeProduct();
        $delivered   = $this->makeProduct();
        $otherStage  = $this->makeProduct();

        $sinStock = $this->makeOrder();
        $this->addItem($sinStock, $product->id, 2);
        $this->addItem($sinStock, $otherReason->id, 2, ['shortage_reason' => OrderItem::SHORTAGE_DANADO]);
        $this->addItem($sinStock, $found->id, 2, ['found_at_location' => true]);
        $this->addItem($sinStock, $delivered->id, 2, ['qty_delivered' => 2]);
        $this->addItem($sinStock, null, 3); // ítem personalizado: no se puede ordenar por catálogo

        $this->addItem($this->makeOrder(['order_type' => Order::ORDER_TYPE_PEDIDO]), $normal->id, 2);
        $this->addItem($this->makeOrder(['stage' => Order::STAGE_EN_PICKING]), $otherStage->id, 2);

        $before = $this->withHeaders($this->headers())->getJson('/api/compras/replenishment')->assertStatus(200)->json();

        $this->assertNotNull($this->productRow($before, $product->id));
        foreach ([$otherReason, $found, $normal, $delivered, $otherStage] as $excluded) {
            $this->assertNull($this->productRow($before, $excluded->id), "El producto {$excluded->id} no debe aparecer.");
        }
        $this->assertGreaterThanOrEqual(1, $before['custom_items_count']);
    }

    public function test_muestra_lo_que_ya_esta_en_camino_en_ordenes_de_compra_abiertas(): void
    {
        $provider = $this->makeProvider('Transito');
        $product  = $this->makeProduct(['provider_id' => $provider->id]);
        $this->addItem($this->makeOrder(), $product->id, 6);
        $po = PurchaseOrder::create(['provider_id' => $provider->id, 'currency' => 'USD', 'status' => PurchaseOrder::STATUS_ORDENADO, 'status_changed_at' => now()]);
        PurchaseOrderLine::create(['purchase_order_id' => $po->id, 'catalog_product_id' => $product->id, 'quantity' => 4, 'unit_cost' => 10, 'subtotal' => 40]);

        $row = $this->productRow($this->withHeaders($this->headers())->getJson('/api/compras/replenishment')->json(), $product->id);

        $this->assertSame(6, $row['faltante']);
        $this->assertSame(4, $row['en_camino']);
    }

    public function test_incluye_al_proveedor_principal_entre_los_alternativos(): void
    {
        $main = $this->makeProvider('Principal');
        $alt  = $this->makeProvider('Alterno');
        $product = $this->makeProduct(['provider_id' => $main->id]);
        $product->providers()->attach($alt->id);
        $this->addItem($this->makeOrder(), $product->id, 1);

        $row = $this->productRow($this->withHeaders($this->headers())->getJson('/api/compras/replenishment')->json(), $product->id);

        $this->assertEqualsCanonicalizing([$main->id, $alt->id], array_column($row['providers'], 'id'));
    }

    public function test_el_producto_desaparece_cuando_el_pedido_se_libera(): void
    {
        $product = $this->makeProduct();
        $order   = $this->makeOrder();
        $this->addItem($order, $product->id, 2);
        $headers = $this->headers();

        $this->assertNotNull($this->productRow($this->withHeaders($headers)->getJson('/api/compras/replenishment')->json(), $product->id));

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent(); // el cliente HTTP de test resetea el tenant al final de cada request
        $order->update(['order_type' => Order::ORDER_TYPE_PEDIDO]); // lo que hace ReleaseOrdersAwaitingStock al recibirse la orden de compra

        $this->assertNull($this->productRow($this->withHeaders($headers)->getJson('/api/compras/replenishment')->json(), $product->id));
    }

    public function test_sin_pedidos_sin_stock_devuelve_estructura_vacia(): void
    {
        Order::query()->where('order_type', Order::ORDER_TYPE_FALTANTE)->update(['order_type' => Order::ORDER_TYPE_PEDIDO]);

        $this->withHeaders($this->headers())->getJson('/api/compras/replenishment')
            ->assertStatus(200)
            ->assertJson(['groups' => [], 'custom_items_count' => 0, 'total_products' => 0]);
    }

    // ── Guardar el proveedor elegido (rebote de SCRUM-1184) ───────────────────────────────────────────────────────────

    /** Producto que hoy espera reposición (pedido de faltante asignado con un ítem sin stock). @param array<string, mixed> $overrides */
    private function pendingProduct(array $overrides = []): CatalogProduct
    {
        $product = $this->makeProduct($overrides);
        $this->addItem($this->makeOrder(), $product->id, 2);

        return $product;
    }

    private function assign(array $headers, int $productId, int $providerId, ?int $expected): \Illuminate\Testing\TestResponse
    {
        $response = $this->withHeaders($headers)->putJson("/api/compras/replenishment/products/{$productId}/provider", [
            'provider_id' => $providerId, 'expected_provider_id' => $expected,
        ]);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent(); // el cliente HTTP de test resetea el tenant al final de cada request

        return $response;
    }

    public function test_asignar_proveedor_a_un_producto_sin_proveedor_lo_guarda_como_principal_y_lo_audita(): void
    {
        $headers = $this->headers();
        $provider = $this->makeProvider('Nuevo');
        $product = $this->pendingProduct(['provider_id' => null]);

        $this->assign($headers, $product->id, $provider->id, null)->assertStatus(200)
            ->assertJson(['catalog_product_id' => $product->id, 'provider_id' => $provider->id, 'providers' => [['id' => $provider->id, 'name' => $provider->name]]]);

        $product->refresh();
        $this->assertSame($provider->id, $product->provider_id);
        $this->assertSame([$provider->id], $product->providers()->pluck('provider_id')->map(fn ($v) => (int) $v)->all());
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $this->assertSame(1, DB::table("{$slug}_auth.audit_logs")->where('action', 'compras.replenishment.provider_assigned')->where('entity_id', $product->id)->count());
    }

    public function test_asignar_no_quita_los_proveedores_que_el_producto_ya_tenia(): void
    {
        $headers = $this->headers();
        $existing = $this->makeProvider('Existente');
        $new = $this->makeProvider('Nuevo');
        $product = $this->pendingProduct(['provider_id' => null]);
        $product->providers()->attach([$existing->id]);

        $this->assign($headers, $product->id, $new->id, null)->assertStatus(200);

        $product->refresh();
        $ids = $product->providers()->pluck('provider_id')->map(fn ($v) => (int) $v)->all();
        sort($ids);
        $expected = [$existing->id, $new->id];
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertSame($new->id, $product->provider_id);
    }

    public function test_corregir_la_eleccion_reemplaza_solo_el_proveedor_esperado(): void
    {
        $headers = $this->headers();
        $other = $this->makeProvider('Otro');
        $first = $this->makeProvider('Primero');
        $second = $this->makeProvider('Segundo');
        $product = $this->pendingProduct(['provider_id' => null]);
        $product->providers()->attach([$other->id]);

        $this->assign($headers, $product->id, $first->id, null)->assertStatus(200);
        $this->assign($headers, $product->id, $second->id, $first->id)->assertStatus(200)->assertJson(['provider_id' => $second->id]);

        $product->refresh();
        $ids = $product->providers()->pluck('provider_id')->map(fn ($v) => (int) $v)->all();
        sort($ids);
        $expected = [$other->id, $second->id];
        sort($expected);
        $this->assertSame($expected, $ids, 'el primero se reemplazó, el que ya tenía el producto se conserva');
        $this->assertSame($second->id, $product->provider_id);
    }

    public function test_si_otra_persona_cambio_el_proveedor_responde_409_y_no_pisa_nada(): void
    {
        $headers = $this->headers();
        $mine = $this->makeProvider('Mio');
        $theirs = $this->makeProvider('Ajeno');
        $product = $this->pendingProduct(['provider_id' => $theirs->id]);
        $product->providers()->attach([$theirs->id]);

        $this->assign($headers, $product->id, $mine->id, null)->assertStatus(409)
            ->assertJson(['code' => 'provider_changed', 'current_provider_id' => $theirs->id]);

        $this->assertSame($theirs->id, $product->fresh()->provider_id);
        $this->assertSame([$theirs->id], $product->providers()->pluck('provider_id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_repetir_la_misma_asignacion_es_idempotente(): void
    {
        $headers = $this->headers();
        $provider = $this->makeProvider('Mismo');
        $product = $this->pendingProduct(['provider_id' => $provider->id]);
        $product->providers()->attach([$provider->id]);

        $this->assign($headers, $product->id, $provider->id, $provider->id)->assertStatus(200)->assertJson(['provider_id' => $provider->id]);
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $this->assertSame(0, DB::table("{$slug}_auth.audit_logs")->where('action', 'compras.replenishment.provider_assigned')->count());
    }

    public function test_asignar_valida_proveedor_inexistente_o_inactivo_y_producto_inexistente(): void
    {
        $headers = $this->headers();
        $inactive = $this->makeProvider('Inactivo');
        $inactive->update(['is_active' => false]);
        $product = $this->pendingProduct(['provider_id' => null]);

        $this->assign($headers, $product->id, $inactive->id, null)->assertStatus(422);
        $this->assign($headers, $product->id, 99999999, null)->assertStatus(422);
        $this->assign($headers, 99999999, $this->makeProvider('Valido')->id, null)->assertStatus(404);
        $this->assertNull($product->fresh()->provider_id);
        $this->withHeaders($headers)->putJson("/api/compras/replenishment/products/{$product->id}/provider", [])->assertStatus(422);
    }

    public function test_asignar_proveedor_exige_compras_write(): void
    {
        $provider = $this->makeProvider('X');
        $product = $this->pendingProduct(['provider_id' => null]);
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', 'designer@atlantic.test')->update(['extra_permissions' => json_encode(['compras.read'])]);
        $readOnly = $this->authHeaders($this->loginAs('designer@atlantic.test', 'Password123!', 'designer'));

        $this->assign($readOnly, $product->id, $provider->id, null)->assertStatus(403);
        $this->assertNull($product->fresh()->provider_id);
    }

    public function test_no_permite_cambiar_el_proveedor_de_un_producto_que_no_espera_reposicion(): void
    {
        $headers = $this->headers();
        $provider = $this->makeProvider('X');
        $product = $this->makeProduct(['provider_id' => null]); // en el catálogo, pero ningún pedido sin stock lo espera

        $this->assign($headers, $product->id, $provider->id, null)->assertStatus(403)->assertJson(['code' => 'replenishment_scope']);
        $this->assertNull($product->fresh()->provider_id);
    }

    public function test_no_permite_cambiar_un_proveedor_establecido_fuera_de_esta_pantalla(): void
    {
        $headers = $this->headers();
        $established = $this->makeProvider('Establecido');
        $other = $this->makeProvider('Otro');
        $product = $this->pendingProduct(['provider_id' => $established->id]);
        $product->providers()->attach([$established->id]);

        // «corregir» diciendo que el principal esperado es el establecido: el optimista coincide, pero nunca lo asignó esta pantalla.
        $this->assign($headers, $product->id, $other->id, $established->id)->assertStatus(403)->assertJson(['code' => 'replenishment_scope']);
        $this->assertSame($established->id, $product->fresh()->provider_id);
    }
}

