<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Compras\Models\BodegaZonaLibreRequest;
use App\Modules\Compras\Models\ComprasSettings;
use App\Modules\Compras\Models\Provider;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationSend;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Database\Seeders\ZonaLibreProviderSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-433→439, 442→445 (REQ-363→369, 371→375) — Bloque B3 "Zona Libre de Colón", lado Bodega.
 * `ZonaLibreProviderSeeder` (Tipo A, corre en testing) ya deja `compras_settings.
 * zona_libre_provider_id` seteado — no hace falta crearlo a mano en cada test.
 */
class BodegaZonaLibreRequestControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function grantBodega(string $email, array $perms = ['bodega.read', 'bodega.write']): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', $email)->update(['extra_permissions' => json_encode($perms)]);
    }

    private function grantCompras(string $email, array $perms): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', $email)->update(['extra_permissions' => json_encode($perms)]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeZonaLibreProduct(array $overrides = []): CatalogProduct
    {
        $providerId = (int) ComprasSettings::current()->zona_libre_provider_id;

        return CatalogProduct::create([
            'reference' => 'ZL-'.uniqid(), 'name' => 'Artículo XL Zona Libre', 'description' => 'Artículo XL Zona Libre',
            'price_full' => 50, 'cost' => 30, 'provider_id' => $providerId, 'is_active' => true,
            ...$overrides,
        ]);
    }

    public function test_zona_libre_provider_seeder_deja_el_proveedor_configurado(): void
    {
        $providerId = ComprasSettings::current()->zona_libre_provider_id;
        $this->assertNotNull($providerId);
        $this->assertSame('BK INTERPRISES S.A.', Provider::find($providerId)?->name);
    }

    public function test_zona_libre_provider_seeder_completa_categoria_vacia_sin_pisar_una_definida(): void
    {
        $provider = Provider::find(ComprasSettings::current()->zona_libre_provider_id);
        $this->assertSame(Provider::CATEGORY_ZONA_LIBRE, $provider?->category);

        $provider->update(['category' => null]);
        $this->seed(ZonaLibreProviderSeeder::class);
        $this->reMakeCurrent(); // el seeder cierra con Tenant::forgetCurrent()
        $this->assertSame(Provider::CATEGORY_ZONA_LIBRE, $provider->fresh()->category);

        $provider->update(['category' => Provider::CATEGORY_CHINA]);
        $this->seed(ZonaLibreProviderSeeder::class);
        $this->reMakeCurrent(); // el seeder cierra con Tenant::forgetCurrent()
        $this->assertSame(Provider::CATEGORY_CHINA, $provider->fresh()->category);
    }

    public function test_provider_devuelve_id_y_nombre_del_proveedor_configurado(): void
    {
        $providerId = ComprasSettings::current()->zona_libre_provider_id;
        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/zona-libre/provider');

        $response->assertStatus(200);
        $response->assertJsonPath('id', $providerId);
        $response->assertJsonPath('name', 'BK INTERPRISES S.A.');
    }

    public function test_provider_sin_permiso_bodega_read_retorna_403(): void
    {
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/bodega/zona-libre/provider')
            ->assertStatus(403);
    }

    public function test_store_crea_solicitud_con_folio_consecutivo_y_status_pendiente(): void
    {
        $product = $this->makeZonaLibreProduct();
        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/zona-libre/requests', [
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 3]],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', BodegaZonaLibreRequest::STATUS_PENDIENTE);
        $this->assertIsInt($response->json('order_number'));
        $response->assertJsonCount(1, 'lines');
        $response->assertJsonPath('lines.0.unit_cost_snapshot', 30);
    }

    public function test_store_con_cero_lineas_retorna_422(): void
    {
        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/bodega/zona-libre/requests', ['lines' => []])
            ->assertStatus(422);
    }

    public function test_store_con_cantidad_cero_retorna_422(): void
    {
        $product = $this->makeZonaLibreProduct();
        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/bodega/zona-libre/requests', [
                'lines' => [['catalog_product_id' => $product->id, 'quantity' => 0]],
            ])
            ->assertStatus(422);
    }

    /** Defensa en profundidad — un producto que NO es del proveedor Zona Libre nunca debe colarse. */
    public function test_store_con_producto_de_otro_proveedor_retorna_422(): void
    {
        $otherProvider = Provider::create(['name' => 'Otro Proveedor', 'currency' => 'USD']);
        $product = CatalogProduct::create([
            'reference' => 'OTRO-'.uniqid(), 'name' => 'Producto de otro proveedor', 'description' => 'Producto de otro proveedor',
            'price_full' => 50, 'cost' => 30, 'provider_id' => $otherProvider->id, 'is_active' => true,
        ]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/bodega/zona-libre/requests', [
                'lines' => [['catalog_product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Uno o más productos no pertenecen al proveedor BK INTERPRISES S.A.');
    }

    public function test_folio_es_consecutivo_entre_dos_solicitudes(): void
    {
        $product = $this->makeZonaLibreProduct();
        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        $first = $this->withHeaders($headers)->postJson('/api/bodega/zona-libre/requests', [
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $second = $this->withHeaders($headers)->postJson('/api/bodega/zona-libre/requests', [
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $this->assertSame($first->json('order_number') + 1, $second->json('order_number'));
    }

    public function test_index_filtra_por_status(): void
    {
        $product = $this->makeZonaLibreProduct();
        BodegaZonaLibreRequest::create(['order_number' => 1, 'status' => BodegaZonaLibreRequest::STATUS_PENDIENTE]);
        $rejected = BodegaZonaLibreRequest::create(['order_number' => 2, 'status' => BodegaZonaLibreRequest::STATUS_RECHAZADA, 'rejection_reason' => 'No aplica']);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/bodega/zona-libre/requests?status=rechazada');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $rejected->id);
    }

    public function test_show_incluye_lineas_y_motivo_rechazo(): void
    {
        $product = $this->makeZonaLibreProduct();
        $zonaLibreRequest = BodegaZonaLibreRequest::create([
            'order_number' => 1, 'status' => BodegaZonaLibreRequest::STATUS_RECHAZADA,
            'rejection_reason' => 'Precio fuera de rango',
        ]);
        $zonaLibreRequest->lines()->create(['catalog_product_id' => $product->id, 'quantity' => 2, 'unit_cost_snapshot' => 30]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/bodega/zona-libre/requests/{$zonaLibreRequest->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('rejection_reason', 'Precio fuera de rango');
        $response->assertJsonCount(1, 'lines');
    }

    public function test_remind_envia_notificacion_al_rol_lider_compras(): void
    {
        $zonaLibreRequest = BodegaZonaLibreRequest::create(['order_number' => 1, 'status' => BodegaZonaLibreRequest::STATUS_PENDIENTE]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/zona-libre/requests/{$zonaLibreRequest->id}/remind");

        $response->assertStatus(200);

        $this->reMakeCurrent();
        $send = NotificationSend::where('source', NotificationSend::SOURCE_BODEGA_ZONA_LIBRE_REMINDER)->first();
        $this->assertNotNull($send);
        $yirenaId = (int) DB::table('atlantic_auth.users')->where('email', 'lidercompras@test.com')->value('id');
        $this->assertContains($yirenaId, $send->audience);

        // SCRUM-1167/1170 — el aviso lleva a la pantalla donde Compras aprueba/rechaza Y a esa solicitud concreta (`?request={id}`), tanto en el contenido
        // como en la notificación in-app de Lider Compras.
        $esperado = "/bodega/ordenes-zona-libre?request={$zonaLibreRequest->id}";
        $this->assertSame($esperado, $send->content['cta_url']);
        $this->assertSame(
            $esperado,
            Notification::where('user_id', $yirenaId)->where('notification_send_id', $send->id)->value('link_url'),
        );
    }

    public function test_remind_sobre_solicitud_no_pendiente_retorna_422(): void
    {
        $zonaLibreRequest = BodegaZonaLibreRequest::create(['order_number' => 1, 'status' => BodegaZonaLibreRequest::STATUS_APROBADA]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/zona-libre/requests/{$zonaLibreRequest->id}/remind")
            ->assertStatus(422);
    }

    public function test_store_sin_permiso_write_retorna_403(): void
    {
        $product = $this->makeZonaLibreProduct();
        $this->grantBodega('vendedor@atlantic.test', ['bodega.read']);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/bodega/zona-libre/requests', [
                'lines' => [['catalog_product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertStatus(403);
    }

    /**
     * SCRUM-434 — Bodega necesita buscar productos del proveedor Zona Libre para armar el
     * carrito. Reusa `GET /compras/products` (CatalogProductSearchController), ahora gateado con
     * `permission:compras.read,bodega.read` (OR) — un usuario con SOLO `bodega.read` debe poder
     * llamarlo sin necesitar ningún permiso de Compras.
     */
    public function test_bodega_puede_buscar_productos_del_proveedor_zona_libre(): void
    {
        $product = $this->makeZonaLibreProduct();
        $this->grantBodega('vendedor@atlantic.test', ['bodega.read']);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/products?provider_id='.$product->provider_id);

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertContains($product->id, $ids);
    }

    /** Compras (sin permisos de Bodega) no puede crear una solicitud vía este endpoint. */
    public function test_compras_no_puede_crear_via_endpoint_de_bodega(): void
    {
        $product = $this->makeZonaLibreProduct();
        $this->grantCompras('vendedor@atlantic.test', ['compras.read', 'compras.write']);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/bodega/zona-libre/requests', [
                'lines' => [['catalog_product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertStatus(403);
    }

    // ── update() — SCRUM-797, revierte SCRUM-440 ────────────────────────────────

    public function test_lider_bodega_puede_editar_solicitud_pendiente(): void
    {
        $product = $this->makeZonaLibreProduct();
        $newProduct = $this->makeZonaLibreProduct();
        $zonaLibreRequest = BodegaZonaLibreRequest::create(['order_number' => 1, 'status' => BodegaZonaLibreRequest::STATUS_PENDIENTE]);
        $zonaLibreRequest->lines()->create(['catalog_product_id' => $product->id, 'quantity' => 2, 'unit_cost_snapshot' => 30]);

        // liderbodega@test.com = Lider Bodega Test, role_key lider_bodega real (BusinessRoleUserSeeder).
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/bodega/zona-libre/requests/{$zonaLibreRequest->id}", [
                'notes' => 'Actualizado por el Líder de Bodega',
                'lines' => [['catalog_product_id' => $newProduct->id, 'quantity' => 5]],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('notes', 'Actualizado por el Líder de Bodega');
        $response->assertJsonCount(1, 'lines');
        $response->assertJsonPath('lines.0.catalog_product_id', $newProduct->id);
        $response->assertJsonPath('lines.0.quantity', 5);
    }

    public function test_lider_compras_puede_editar_solicitud_pendiente(): void
    {
        $product = $this->makeZonaLibreProduct();
        $zonaLibreRequest = BodegaZonaLibreRequest::create(['order_number' => 1, 'status' => BodegaZonaLibreRequest::STATUS_PENDIENTE]);
        $zonaLibreRequest->lines()->create(['catalog_product_id' => $product->id, 'quantity' => 2, 'unit_cost_snapshot' => 30]);

        // lidercompras@test.com = Lider Compras Test, ya tiene compras.zona-libre.approve real
        // (SpecialPermissionSeeder) — sin bodega.write, así que el gate amplio de la ruta también
        // se ejercita acá (permission:bodega.write,compras.zona-libre.approve).
        $token = $this->loginAs('lidercompras@test.com', 'lidercompras@test.com', 'lider_compras');

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/bodega/zona-libre/requests/{$zonaLibreRequest->id}", [
                'lines' => [['catalog_product_id' => $product->id, 'quantity' => 9]],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('lines.0.quantity', 9);
    }

    public function test_usuario_con_bodega_write_sin_ser_lider_no_puede_editar(): void
    {
        $product = $this->makeZonaLibreProduct();
        $zonaLibreRequest = BodegaZonaLibreRequest::create(['order_number' => 1, 'status' => BodegaZonaLibreRequest::STATUS_PENDIENTE]);
        $zonaLibreRequest->lines()->create(['catalog_product_id' => $product->id, 'quantity' => 2, 'unit_cost_snapshot' => 30]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/bodega/zona-libre/requests/{$zonaLibreRequest->id}", [
                'lines' => [['catalog_product_id' => $product->id, 'quantity' => 3]],
            ])
            ->assertStatus(403);
    }

    public function test_editar_solicitud_aprobada_retorna_422(): void
    {
        $product = $this->makeZonaLibreProduct();
        $zonaLibreRequest = BodegaZonaLibreRequest::create(['order_number' => 1, 'status' => BodegaZonaLibreRequest::STATUS_APROBADA]);
        $zonaLibreRequest->lines()->create(['catalog_product_id' => $product->id, 'quantity' => 2, 'unit_cost_snapshot' => 30]);

        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/bodega/zona-libre/requests/{$zonaLibreRequest->id}", [
                'lines' => [['catalog_product_id' => $product->id, 'quantity' => 3]],
            ])
            ->assertStatus(422);
    }

    public function test_editar_solicitud_rechazada_retorna_422(): void
    {
        $product = $this->makeZonaLibreProduct();
        $zonaLibreRequest = BodegaZonaLibreRequest::create([
            'order_number' => 1, 'status' => BodegaZonaLibreRequest::STATUS_RECHAZADA, 'rejection_reason' => 'No aplica',
        ]);
        $zonaLibreRequest->lines()->create(['catalog_product_id' => $product->id, 'quantity' => 2, 'unit_cost_snapshot' => 30]);

        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/bodega/zona-libre/requests/{$zonaLibreRequest->id}", [
                'lines' => [['catalog_product_id' => $product->id, 'quantity' => 3]],
            ])
            ->assertStatus(422);
    }

    public function test_editar_con_producto_de_otro_proveedor_retorna_422(): void
    {
        $product = $this->makeZonaLibreProduct();
        $zonaLibreRequest = BodegaZonaLibreRequest::create(['order_number' => 1, 'status' => BodegaZonaLibreRequest::STATUS_PENDIENTE]);
        $zonaLibreRequest->lines()->create(['catalog_product_id' => $product->id, 'quantity' => 2, 'unit_cost_snapshot' => 30]);

        $otherProvider = Provider::create(['name' => 'Otro Proveedor Update', 'currency' => 'USD']);
        $otherProduct = CatalogProduct::create([
            'reference' => 'OTRO-'.uniqid(), 'name' => 'Producto de otro proveedor', 'description' => 'Producto de otro proveedor',
            'price_full' => 50, 'cost' => 30, 'provider_id' => $otherProvider->id, 'is_active' => true,
        ]);

        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/bodega/zona-libre/requests/{$zonaLibreRequest->id}", [
                'lines' => [['catalog_product_id' => $otherProduct->id, 'quantity' => 1]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Uno o más productos no pertenecen al proveedor BK INTERPRISES S.A.');
    }
}
