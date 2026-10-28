<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1145 — envío Aéreo (pagado por Atlantic) + costos adicionales (importación, flete,
 * manejo, otros) en el total a pagar al proveedor. `total_amount` sigue siendo "solo productos"
 * (el PDF de la orden no cambia).
 */
class PurchaseOrderAdditionalCostsTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Storage::fake('s3-private');
    }

    private function grant(array $perms): string
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', 'vendedor@atlantic.test')
            ->update(['extra_permissions' => json_encode($perms)]);

        return $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
    }

    /** @return array{provider: Provider, product: CatalogProduct} */
    private function fixture(): array
    {
        $provider = Provider::create(['name' => 'Proveedor Demo A', 'currency' => 'USD']);
        $product = CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Producto', 'price_full' => 20, 'cost' => 100,
            'provider_id' => $provider->id, 'is_active' => true,
        ]);

        return ['provider' => $provider, 'product' => $product];
    }

    /** @param array<string, mixed> $extra */
    private function create(string $token, array $fx, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->authHeaders($token))->postJson('/api/compras/orders', [
            'provider_id' => $fx['provider']->id,
            'lines'       => [['catalog_product_id' => $fx['product']->id, 'quantity' => 10]],
            ...$extra,
        ]);
    }

    public function test_envio_aereo_pagado_por_atlantic_se_suma_al_total_a_pagar(): void
    {
        $fx = $this->fixture();
        $token = $this->grant(['compras.read', 'compras.write']);

        $r = $this->create($token, $fx, [
            'shipping_type' => 'aereo', 'who_pays_shipping' => 'atlantic', 'shipping_cost' => 150,
        ])->assertStatus(201);

        $r->assertJsonPath('total_amount', 1000);          // solo productos: no cambia
        $r->assertJsonPath('additional_costs_total', 0);
        $r->assertJsonPath('payable_total', 1150);
    }

    public function test_envio_aereo_pagado_por_el_cliente_no_se_suma(): void
    {
        $fx = $this->fixture();
        $token = $this->grant(['compras.read', 'compras.write']);

        $this->create($token, $fx, [
            'shipping_type' => 'aereo', 'who_pays_shipping' => 'cliente', 'shipping_cost' => 150,
        ])->assertStatus(201)->assertJsonPath('payable_total', 1000);
    }

    public function test_costos_adicionales_son_opcionales_y_se_suman(): void
    {
        $fx = $this->fixture();
        $token = $this->grant(['compras.read', 'compras.write']);

        $this->create($token, $fx)->assertStatus(201)
            ->assertJsonPath('import_cost', null)
            ->assertJsonPath('payable_total', 1000);

        $this->create($token, $fx, [
            'import_cost' => 100, 'freight_cost' => 50.5, 'handling_cost' => 20, 'other_cost' => 10,
        ])->assertStatus(201)
            ->assertJsonPath('total_amount', 1000)
            ->assertJsonPath('additional_costs_total', 180.5)
            ->assertJsonPath('payable_total', 1180.5);
    }

    public function test_costo_adicional_negativo_retorna_422(): void
    {
        $fx = $this->fixture();
        $token = $this->grant(['compras.read', 'compras.write']);

        $r = $this->create($token, $fx, ['freight_cost' => -1])->assertStatus(422)->assertJsonValidationErrors(['freight_cost']);
        $this->assertStringContainsString('flete', implode(' ', $r->json('errors.freight_cost')), 'El mensaje usa el nombre en español del campo.');
    }

    public function test_editar_orden_actualiza_los_costos_adicionales(): void
    {
        $fx = $this->fixture();
        $token = $this->grant(['compras.read', 'compras.write', 'compras.edit']);
        $id = $this->create($token, $fx, ['import_cost' => 100])->assertStatus(201)->json('id');

        $this->withHeaders($this->authHeaders($token))->putJson("/api/compras/orders/{$id}", [
            'provider_id' => $fx['provider']->id,
            'lines'       => [['catalog_product_id' => $fx['product']->id, 'quantity' => 10]],
            'other_cost'  => 30,
        ])->assertStatus(200)
            ->assertJsonPath('import_cost', null)   // la edición reemplaza: vacío = sin costo
            ->assertJsonPath('other_cost', 30)
            ->assertJsonPath('payable_total', 1030);
    }

    public function test_pago_a_proveedor_usa_el_total_a_pagar(): void
    {
        $fx = $this->fixture();
        $token = $this->grant(['compras.read', 'compras.write', 'compras.edit']);
        $id = $this->create($token, $fx, [
            'shipping_type' => 'aereo', 'who_pays_shipping' => 'atlantic', 'shipping_cost' => 150,
            'import_cost' => 50,
        ])->assertStatus(201)->json('id');
        // total a pagar = 1000 + 150 + 50 = 1200

        $pay = fn (float $amount) => $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/orders/{$id}/payments", [
                'amount' => $amount,
                'proof'  => UploadedFile::fake()->create('c.pdf', 50, 'application/pdf'),
            ]);

        $pay(1300)->assertStatus(422);   // excede 1200
        $pay(1000)->assertStatus(201);   // antes de SCRUM-1145 esto ya dejaba la orden "completa"

        $this->withHeaders($this->authHeaders($token))->getJson("/api/compras/orders/{$id}")
            ->assertJsonPath('payment_status', PurchaseOrder::PAYMENT_STATUS_PARCIAL);

        $pay(200)->assertStatus(201);

        $this->withHeaders($this->authHeaders($token))->getJson("/api/compras/orders/{$id}")
            ->assertJsonPath('payment_status', PurchaseOrder::PAYMENT_STATUS_COMPLETO)
            ->assertJsonPath('paid_amount', 1200);
    }

    // Senior Review — centavos: pagos que suman exactamente el total a pagar dejan la orden "completa" (sin errores de float).
    public function test_pagos_en_centavos_que_suman_el_total_a_pagar_completan_la_orden(): void
    {
        $fx = $this->fixture();
        $token = $this->grant(['compras.read', 'compras.write', 'compras.edit']);
        $id = $this->create($token, $fx, ['import_cost' => 100.10, 'freight_cost' => 50.20])->assertStatus(201)->json('id');
        // total a pagar = 1000 + 100.10 + 50.20 = 1150.30

        $pay = fn (float $amount) => $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/orders/{$id}/payments", [
                'amount' => $amount,
                'proof'  => UploadedFile::fake()->create('c.pdf', 50, 'application/pdf'),
            ]);

        $pay(1000.10)->assertStatus(201);
        $pay(150.20)->assertStatus(201);   // el saldo exacto (150.20) nunca se rechaza por redondeo
        $pay(0.01)->assertStatus(422);     // ya no queda saldo

        $this->withHeaders($this->authHeaders($token))->getJson("/api/compras/orders/{$id}")
            ->assertJsonPath('payment_status', PurchaseOrder::PAYMENT_STATUS_COMPLETO);
    }

    public function test_el_pdf_de_la_orden_no_incluye_los_costos_adicionales(): void
    {
        $fx = $this->fixture();
        $token = $this->grant(['compras.read', 'compras.write']);
        $id = $this->create($token, $fx, [
            'shipping_type' => 'aereo', 'who_pays_shipping' => 'atlantic', 'shipping_cost' => 150,
            'import_cost' => 77.77,
        ])->assertStatus(201)->json('id');

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $order = PurchaseOrder::with(['provider', 'lines.catalogProduct'])->findOrFail($id);
        $html = view('compras.purchase-order-pdf', [
            'order' => $order, 'includeCost' => true,
            'statusLabel' => 'Ordenado', 'shippingTypeLabel' => 'Aéreo',
        ])->render();

        $this->assertStringContainsString('1,000.00', $html);
        $this->assertStringNotContainsString('1,227.77', $html);
        $this->assertStringNotContainsString('77.77', $html);
        $this->assertStringNotContainsString('150.00', $html);
    }
}
