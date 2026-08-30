<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Modules\Compras\Mail\PurchaseOrderMail;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderLine;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\FeatureTestCase;

/**
 * REQ-136/149 — PDF real (dompdf) + envío por correo. Instalado por pedido explícito de Luis
 * (daily sprint2, 2026-07-14): el patrón window.print() de Cotizaciones no alcanza acá porque
 * el PDF también se adjunta a un correo real generado en el servidor.
 */
class PurchaseOrderPdfTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function grantCompras(string $email, array $perms): void
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode($perms)]);
    }

    private function makeOrderWithLine(array $providerOverrides = []): PurchaseOrder
    {
        $provider = Provider::create(['name' => 'LightCorp', 'currency' => 'USD', ...$providerOverrides]);
        $order = PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD',
            'status' => PurchaseOrder::STATUS_ORDENADO, 'status_changed_at' => now(),
            'total_amount' => 60,
        ]);
        $product = CatalogProduct::create([
            'reference' => 'REF-1', 'name' => 'Lámpara colgante', 'description' => 'Lámpara colgante', 'price_full' => 100, 'cost' => 30,
            'provider_id' => $provider->id, 'is_active' => true,
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $product->id,
            'quantity' => 2, 'unit_cost' => 30, 'subtotal' => 60,
        ]);

        return $order;
    }

    public function test_pdf_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/compras/orders/1/pdf')->assertStatus(401);
    }

    public function test_pdf_con_costo_es_un_documento_pdf_valido(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $order = $this->makeOrderWithLine();

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->get("/api/compras/orders/{$order->id}/pdf")
            ->assertStatus(200);

        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_vista_sin_costo_no_incluye_precios(): void
    {
        // REQ-136 Escenario 1 — se prueba sobre el HTML fuente (antes de convertir a PDF), más
        // confiable que inspeccionar el binario del PDF generado.
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $order = $this->makeOrderWithLine();
        $order->load(['provider', 'lines.catalogProduct']);

        $html = view('compras.purchase-order-pdf', [
            'order' => $order, 'includeCost' => false,
            'statusLabel' => 'Ordenado', 'shippingTypeLabel' => 'Sin especificar',
        ])->render();

        $this->assertStringNotContainsString('$', $html);
        $this->assertStringContainsString('Lámpara colgante', $html);
    }

    public function test_vista_con_costo_incluye_total(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $order = $this->makeOrderWithLine();
        $order->load(['provider', 'lines.catalogProduct']);

        $html = view('compras.purchase-order-pdf', [
            'order' => $order, 'includeCost' => true,
            'statusLabel' => 'Ordenado', 'shippingTypeLabel' => 'Sin especificar',
        ])->render();

        $this->assertStringContainsString('60.00', $html);
    }

    public function test_enviar_correo_sin_email_de_proveedor_retorna_422(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write']);
        $order = $this->makeOrderWithLine();

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/orders/{$order->id}/send-email")
            ->assertStatus(422);

        Mail::assertNothingSent();
    }

    public function test_enviar_correo_con_email_de_proveedor_encola_y_envia(): void
    {
        // QUEUE_CONNECTION=sync en testing (.env.testing) — el job corre en el mismo request.
        $this->grantCompras('designer@illuminations.test', ['compras.write']);
        $order = $this->makeOrderWithLine(['email' => 'proveedor@lightcorp.test']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/orders/{$order->id}/send-email", ['include_cost' => true])
            ->assertStatus(202);

        Mail::assertSent(PurchaseOrderMail::class, function (PurchaseOrderMail $mail) use ($order) {
            return $mail->orderId === $order->id
                && $mail->hasTo('proveedor@lightcorp.test');
        });
    }

    public function test_enviar_correo_sin_permiso_retorna_403(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $order = $this->makeOrderWithLine(['email' => 'proveedor@lightcorp.test']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/orders/{$order->id}/send-email")
            ->assertStatus(403);
    }
}
