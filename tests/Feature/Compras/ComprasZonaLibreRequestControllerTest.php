<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Modules\Compras\Models\BodegaZonaLibreRequest;
use App\Modules\Compras\Models\ComprasSettings;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-440 (REQ-370, Bloque B3 Zona Libre de Colón) — decisión de Yirena (Compras) sobre una
 * solicitud generada por Bodega. `gerencia2@illuminations.com.pa` (Yirena, role_key
 * `lider_compras`) ya sale de `SpecialPermissionSeeder` con `compras.zona-libre.approve`
 * concedido — no hace falta otorgarlo a mano.
 */
class ComprasZonaLibreRequestControllerTest extends FeatureTestCase
{
    private const YIRENA_EMAIL = 'gerencia2@illuminations.com.pa';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function grantBodega(string $email, array $perms = ['bodega.read', 'bodega.write']): void
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', $email)->update(['extra_permissions' => json_encode($perms)]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeZonaLibreProduct(array $overrides = []): CatalogProduct
    {
        $providerId = (int) ComprasSettings::current()->zona_libre_provider_id;

        return CatalogProduct::create([
            'reference' => 'ZL-'.uniqid(), 'name' => 'Bombillo LED Zona Libre', 'description' => 'Bombillo LED Zona Libre',
            'price_full' => 50, 'cost' => 30, 'provider_id' => $providerId, 'is_active' => true,
            ...$overrides,
        ]);
    }

    private function makeRequestWithLine(int $quantity = 2, float $unitCost = 30): BodegaZonaLibreRequest
    {
        $product = $this->makeZonaLibreProduct();
        $zonaLibreRequest = BodegaZonaLibreRequest::create([
            'order_number' => random_int(1, 999999), 'status' => BodegaZonaLibreRequest::STATUS_PENDIENTE,
        ]);
        $zonaLibreRequest->lines()->create([
            'catalog_product_id' => $product->id, 'quantity' => $quantity, 'unit_cost_snapshot' => $unitCost,
        ]);

        return $zonaLibreRequest;
    }

    public function test_approve_genera_purchase_order_con_modality_zona_libre(): void
    {
        $zonaLibreRequest = $this->makeRequestWithLine(quantity: 3, unitCost: 20);

        $token = $this->loginAs(self::YIRENA_EMAIL, self::YIRENA_EMAIL);
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/zona-libre/requests/{$zonaLibreRequest->id}/approve");

        $response->assertStatus(200);
        $orderId = $response->json('order_id');
        $this->assertIsInt($orderId);

        $this->reMakeCurrent();
        $order = PurchaseOrder::with('lines')->find($orderId);
        $this->assertNotNull($order);
        $this->assertSame(PurchaseOrder::MODALITY_ZONA_LIBRE, $order->modality);
        $this->assertSame(PurchaseOrder::STATUS_POR_APROBAR, $order->status);
        $this->assertSame(60.0, $order->total_amount);
        $this->assertSame(3, $order->lines->first()->quantity);
        $this->assertSame(20.0, (float) $order->lines->first()->unit_cost);

        $fresh = BodegaZonaLibreRequest::find($zonaLibreRequest->id);
        $this->assertSame(BodegaZonaLibreRequest::STATUS_APROBADA, $fresh->status);
        $this->assertSame($orderId, $fresh->generated_order_id);
    }

    public function test_reject_requiere_motivo(): void
    {
        $zonaLibreRequest = $this->makeRequestWithLine();

        $token = $this->loginAs(self::YIRENA_EMAIL, self::YIRENA_EMAIL);
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/zona-libre/requests/{$zonaLibreRequest->id}/reject", [])
            ->assertStatus(422);
    }

    public function test_reject_marca_rechazada_sin_generar_orden(): void
    {
        $zonaLibreRequest = $this->makeRequestWithLine();

        $token = $this->loginAs(self::YIRENA_EMAIL, self::YIRENA_EMAIL);
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/zona-libre/requests/{$zonaLibreRequest->id}/reject", [
                'rejection_reason' => 'Precio no competitivo',
            ]);

        $response->assertStatus(200);

        $this->reMakeCurrent();
        $fresh = BodegaZonaLibreRequest::find($zonaLibreRequest->id);
        $this->assertSame(BodegaZonaLibreRequest::STATUS_RECHAZADA, $fresh->status);
        $this->assertSame('Precio no competitivo', $fresh->rejection_reason);
        $this->assertNull($fresh->generated_order_id);
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_approve_sobre_solicitud_ya_resuelta_retorna_422(): void
    {
        $zonaLibreRequest = $this->makeRequestWithLine();
        $token = $this->loginAs(self::YIRENA_EMAIL, self::YIRENA_EMAIL);
        $headers = $this->authHeaders($token);

        $this->withHeaders($headers)->postJson("/api/compras/zona-libre/requests/{$zonaLibreRequest->id}/approve")->assertStatus(200);
        $this->withHeaders($headers)->postJson("/api/compras/zona-libre/requests/{$zonaLibreRequest->id}/approve")->assertStatus(422);

        $this->reMakeCurrent();
        $this->assertSame(1, PurchaseOrder::count());
    }

    public function test_reject_sobre_solicitud_ya_resuelta_retorna_422(): void
    {
        $zonaLibreRequest = $this->makeRequestWithLine();
        $token = $this->loginAs(self::YIRENA_EMAIL, self::YIRENA_EMAIL);
        $headers = $this->authHeaders($token);

        $this->withHeaders($headers)->postJson("/api/compras/zona-libre/requests/{$zonaLibreRequest->id}/reject", ['rejection_reason' => 'A'])->assertStatus(200);
        $this->withHeaders($headers)->postJson("/api/compras/zona-libre/requests/{$zonaLibreRequest->id}/reject", ['rejection_reason' => 'B'])->assertStatus(422);
    }

    /** Bodega (bodega.write, sin el permiso angosto de Compras) no puede aprobar ni rechazar. */
    public function test_bodega_no_puede_aprobar_ni_rechazar(): void
    {
        $zonaLibreRequest = $this->makeRequestWithLine();
        $this->grantBodega('designer@illuminations.test');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/zona-libre/requests/{$zonaLibreRequest->id}/approve")
            ->assertStatus(403);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/zona-libre/requests/{$zonaLibreRequest->id}/reject", ['rejection_reason' => 'x'])
            ->assertStatus(403);

        $this->reMakeCurrent();
        $this->assertSame(BodegaZonaLibreRequest::STATUS_PENDIENTE, BodegaZonaLibreRequest::find($zonaLibreRequest->id)->status);
    }

    public function test_approve_sin_lineas_retorna_422(): void
    {
        $zonaLibreRequest = BodegaZonaLibreRequest::create(['order_number' => 1, 'status' => BodegaZonaLibreRequest::STATUS_PENDIENTE]);

        $token = $this->loginAs(self::YIRENA_EMAIL, self::YIRENA_EMAIL);
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/zona-libre/requests/{$zonaLibreRequest->id}/approve")
            ->assertStatus(422);

        $this->reMakeCurrent();
        $this->assertSame(0, PurchaseOrder::count());
    }
}
