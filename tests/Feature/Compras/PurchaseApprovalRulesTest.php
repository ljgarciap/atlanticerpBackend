<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Modules\Auth\Models\AuditLog;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseApprovalRule;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 14a (docs/specs/fase14-parametrizacion.md) — reglas de aprobación de órdenes de compra como datos: la regla sembrada equivale al
 * código anterior (aéreo + paga la empresa), la primera regla activa que coincide decide, el monto es el total a pagar y solo el superadmin
 * las administra.
 */
final class PurchaseApprovalRulesTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->activarTenant();
        Storage::fake('s3-private');
    }

    private function activarTenant(): void
    {
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
    }

    /** @return array<string, string> */
    private function superadmin(): array
    {
        return $this->authHeaders($this->loginAs('superadmin@atlantic.test', 'Password123!'));
    }

    /** @return array<string, string> */
    private function compras(): array
    {
        DB::table('atlantic_auth.users')->where('email', 'vendedor@atlantic.test')
            ->update(['extra_permissions' => json_encode(['compras.read', 'compras.write', 'compras.edit'])]);

        return $this->authHeaders($this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer'));
    }

    /** @param array<string, mixed> $extra */
    private function crearOrden(array $headers, array $extra = [], int $cantidad = 10): TestResponse
    {
        $this->activarTenant();
        $provider = Provider::create(['name' => 'Proveedor '.uniqid(), 'currency' => 'USD']);
        $product = CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Producto', 'price_full' => 200, 'cost' => 100,
            'provider_id' => $provider->id, 'is_active' => true,
        ]);

        return $this->withHeaders($headers)->postJson('/api/compras/orders', [
            'provider_id' => $provider->id,
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => $cantidad]],
            ...$extra,
        ]);
    }

    public function test_la_regla_sembrada_equivale_al_codigo_anterior_para_toda_combinacion(): void
    {
        foreach ([null, 'aereo', 'maritimo', 'terrestre'] as $envio) {
            foreach ([null, 'atlantic', 'proveedor'] as $paga) {
                $esperado = $envio === 'aereo' && $paga === 'atlantic';
                $this->assertSame(
                    $esperado,
                    PurchaseOrder::computeRequiresPrimaryApproval($envio, $paga, 1234.5),
                    "envío={$envio} paga={$paga}",
                );
            }
        }
    }

    public function test_una_orden_aerea_pagada_por_la_empresa_nace_por_aprobar_y_las_demas_no(): void
    {
        $h = $this->compras();
        $aerea = $this->crearOrden($h, ['shipping_type' => 'aereo', 'who_pays_shipping' => 'atlantic', 'shipping_cost' => 50])->assertCreated();
        $maritima = $this->crearOrden($h, ['shipping_type' => 'maritimo'])->assertCreated();

        $this->assertTrue($aerea->json('requires_primary_approval'));
        $this->assertSame(PurchaseOrder::STATUS_POR_APROBAR, $aerea->json('status'));
        $this->assertFalse($maritima->json('requires_primary_approval'));
        $this->assertSame(PurchaseOrder::STATUS_PENDIENTE_LIQUIDAR, $maritima->json('status'));
    }

    public function test_una_regla_por_monto_usa_el_total_a_pagar_incluido_el_envio_de_la_empresa(): void
    {
        $this->activarTenant();
        PurchaseApprovalRule::query()->update(['is_active' => false]);
        PurchaseApprovalRule::create([
            'name' => 'Compras grandes', 'sort_order' => 1, 'is_active' => true, 'min_amount' => 1050, 'requires_approval' => true,
        ]);
        $h = $this->compras();

        // Productos 1000 (10 x 100): sin envío no llega a 1050; con envío aéreo de 100 pagado por la empresa, el total a pagar es 1100.
        $this->assertFalse($this->crearOrden($h)->assertCreated()->json('requires_primary_approval'));
        $con = $this->crearOrden($h, ['shipping_type' => 'aereo', 'who_pays_shipping' => 'atlantic', 'shipping_cost' => 100])->assertCreated();
        $this->assertTrue($con->json('requires_primary_approval'));
        $this->assertSame(PurchaseOrder::STATUS_POR_APROBAR, $con->json('status'));
    }

    public function test_gana_la_primera_regla_activa_que_coincide_y_una_inactiva_se_ignora(): void
    {
        $this->activarTenant();
        PurchaseApprovalRule::query()->update(['is_active' => false]);
        PurchaseApprovalRule::create(['name' => 'Excepción', 'sort_order' => 1, 'is_active' => true, 'shipping_type' => 'aereo', 'max_amount' => 500, 'requires_approval' => false]);
        PurchaseApprovalRule::create(['name' => 'Aéreo', 'sort_order' => 2, 'is_active' => true, 'shipping_type' => 'aereo', 'requires_approval' => true]);
        PurchaseApprovalRule::create(['name' => 'Apagada', 'sort_order' => 0, 'is_active' => false, 'requires_approval' => true]);

        $this->assertFalse(PurchaseOrder::computeRequiresPrimaryApproval('aereo', null, 500.0), 'límite superior inclusivo: gana la excepción');
        $this->assertTrue(PurchaseOrder::computeRequiresPrimaryApproval('aereo', null, 500.01));
        $this->assertFalse(PurchaseOrder::computeRequiresPrimaryApproval('maritimo', null, 9999.0), 'sin coincidencia: no requiere');
    }

    public function test_editar_la_orden_reevalua_con_el_nuevo_total(): void
    {
        $this->activarTenant();
        PurchaseApprovalRule::query()->update(['is_active' => false]);
        PurchaseApprovalRule::create(['name' => 'Grandes', 'sort_order' => 1, 'is_active' => true, 'min_amount' => 2000, 'requires_approval' => true]);
        $h = $this->compras();

        $creada = $this->crearOrden($h)->assertCreated();
        $this->assertFalse($creada->json('requires_primary_approval'));

        $this->activarTenant();
        $linea = PurchaseOrder::findOrFail($creada->json('id'))->lines()->firstOrFail();
        $r = $this->withHeaders($h)->putJson('/api/compras/orders/'.$creada->json('id'), [
            'provider_id' => $creada->json('provider_id'),
            'lines' => [['catalog_product_id' => $linea->catalog_product_id, 'quantity' => 30]],
        ])->assertOk();

        $this->assertTrue($r->json('requires_primary_approval'));
    }

    public function test_la_administracion_es_exclusiva_del_superadmin(): void
    {
        $this->withHeaders($this->compras())->getJson('/api/admin/purchase-approval-rules')->assertForbidden();
        $this->withHeaders($this->compras())->postJson('/api/admin/purchase-approval-rules', [])->assertForbidden();

        $lista = $this->withHeaders($this->superadmin())->getJson('/api/admin/purchase-approval-rules')->assertOk()->json('data');
        $this->assertSame('aereo', $lista[0]['shipping_type']);
        $this->assertSame('atlantic', $lista[0]['who_pays_shipping']);
    }

    public function test_crud_valida_y_audita(): void
    {
        $h = $this->superadmin();
        $this->withHeaders($h)->postJson('/api/admin/purchase-approval-rules', [
            'name' => 'Mala', 'sort_order' => 1, 'is_active' => true, 'min_amount' => 500, 'max_amount' => 100, 'requires_approval' => true,
        ])->assertStatus(422);
        $this->withHeaders($h)->postJson('/api/admin/purchase-approval-rules', [
            'name' => 'Mala', 'sort_order' => 1, 'is_active' => true, 'shipping_type' => 'maritimo', 'who_pays_shipping' => 'atlantic', 'requires_approval' => true,
        ])->assertStatus(422);

        $id = $this->withHeaders($h)->postJson('/api/admin/purchase-approval-rules', [
            'name' => 'Grandes', 'sort_order' => 5, 'is_active' => true, 'min_amount' => 5000, 'requires_approval' => true,
        ])->assertCreated()->json('id');
        $this->withHeaders($h)->putJson("/api/admin/purchase-approval-rules/{$id}", [
            'name' => 'Grandes', 'sort_order' => 5, 'is_active' => false, 'min_amount' => 7000, 'requires_approval' => true,
        ])->assertOk()->assertJsonPath('min_amount', 7000);
        $this->withHeaders($h)->deleteJson("/api/admin/purchase-approval-rules/{$id}")->assertOk();

        $this->activarTenant();
        $acciones = AuditLog::query()->where('entity_type', 'purchase_approval_rule')->where('entity_id', $id)->pluck('action')->all();
        $this->assertEqualsCanonicalizing(
            ['compras.purchase_approval_rule.created', 'compras.purchase_approval_rule.updated', 'compras.purchase_approval_rule.deleted'],
            $acciones,
        );
    }

    public function test_vista_previa_dice_si_requiere_y_que_regla_decide(): void
    {
        $h = $this->superadmin();
        $si = $this->withHeaders($h)->postJson('/api/admin/purchase-approval-rules/preview', [
            'shipping_type' => 'aereo', 'who_pays_shipping' => 'atlantic', 'amount' => 3000,
        ])->assertOk();
        $this->assertTrue($si->json('requires_approval'));
        $this->assertNotNull($si->json('rule.id'));

        $no = $this->withHeaders($h)->postJson('/api/admin/purchase-approval-rules/preview', ['shipping_type' => 'maritimo', 'amount' => 3000])->assertOk();
        $this->assertFalse($no->json('requires_approval'));
        $this->assertNull($no->json('rule'));
    }
}
