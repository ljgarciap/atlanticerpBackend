<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Modules\Compras\Models\LiquidationAgency;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/** SCRUM-210 (REQ-147, alcance reducido) — buscador + alta de agencias de liquidación. */
class LiquidationAgencyControllerTest extends FeatureTestCase
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

    public function test_listado_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/compras/liquidation-agencies')->assertStatus(401);
    }

    public function test_busqueda_por_nombre(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        LiquidationAgency::create(['name' => 'Agencia Aduanal Istmo']);
        LiquidationAgency::create(['name' => 'Otra Agencia']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/liquidation-agencies?search=Istmo')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertEquals(['Agencia Aduanal Istmo'], $names->all());
    }

    public function test_crear_agencia_solo_con_nombre(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/liquidation-agencies', ['name' => 'Agencia Nueva'])
            ->assertStatus(201);

        $response->assertJsonPath('name', 'Agencia Nueva');
        $this->assertDatabaseHas('illuminations_compras.liquidation_agencies', ['name' => 'Agencia Nueva']);
    }

    public function test_crear_agencia_sin_nombre_retorna_422(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/liquidation-agencies', [])
            ->assertStatus(422);
    }

    public function test_crear_agencia_con_telefono_invalido_retorna_422(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/liquidation-agencies', ['name' => 'X', 'phone' => 'abcdef'])
            ->assertStatus(422);
    }

    public function test_crear_agencia_sin_permiso_write_retorna_403(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/liquidation-agencies', ['name' => 'X'])
            ->assertStatus(403);
    }

    public function test_resumen_cuenta_liquidadas_y_por_liquidar(): void
    {
        // "Liquidada" = llegó a "recibido" (criterio real del escenario de aceptación del
        // ticket) — una orden solo EN "en_aduana" (sin llegar a recibido todavía) sigue
        // por_liquidar, ver test_orden_de_proveedor_local_cuenta_liquidada_al_llegar_a_recibido.
        $this->grantCompras('designer@illuminations.test', ['compras.read', 'compras.write', 'compras.edit', 'compras.approve']);
        $provider = \App\Modules\Compras\Models\Provider::create(['name' => 'LightCorp', 'origin' => 'internacional']);
        $agency = LiquidationAgency::create(['name' => 'Agencia Aduanal Istmo']);

        \App\Modules\Compras\Models\PurchaseOrder::create([
            'provider_id' => $provider->id, 'status' => 'recibido', 'liquidation_agency_id' => $agency->id,
            'currency' => 'USD', 'total_amount' => 100,
        ]);
        \App\Modules\Compras\Models\PurchaseOrder::create([
            'provider_id' => $provider->id, 'status' => 'en_aduana', 'liquidation_agency_id' => $agency->id,
            'currency' => 'USD', 'total_amount' => 200,
        ]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/liquidation-agencies/summary')
            ->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('id', $agency->id);
        $this->assertSame(1, $row['liquidated_count']);
        $this->assertSame(1, $row['pending_count']);
    }

    public function test_orden_de_proveedor_local_cuenta_liquidada_al_llegar_a_recibido(): void
    {
        // Regresión de hallazgo de QA (2026-07-18): un proveedor local SALTA "en_aduana" por
        // completo (PurchaseOrder::statusSequence()) — la definición vieja de "liquidada"
        // (hasReachedStage(EN_ADUANA)) dejaba estas órdenes "por_liquidar" para siempre, sin
        // importar que llegaran a Recibido. El criterio real del ticket es llegar a "Recibido".
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $localProvider = \App\Modules\Compras\Models\Provider::create(['name' => 'Local SA', 'origin' => 'local']);
        $agency = LiquidationAgency::create(['name' => 'Agencia Aduanal Istmo']);

        \App\Modules\Compras\Models\PurchaseOrder::create([
            'provider_id' => $localProvider->id, 'status' => 'recibido', 'liquidation_agency_id' => $agency->id,
            'currency' => 'USD', 'total_amount' => 150,
        ]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/liquidation-agencies/summary')
            ->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('id', $agency->id);
        $this->assertSame(1, $row['liquidated_count']);
        $this->assertSame(0, $row['pending_count']);
    }

    public function test_detalle_incluye_contactos_y_pedidos(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $agency = LiquidationAgency::create(['name' => 'Agencia Aduanal Istmo']);
        $agency->contacts()->create(['name' => 'Juan Pérez', 'phone' => '+507 6000-0000']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/liquidation-agencies/{$agency->id}")
            ->assertStatus(200);

        $response->assertJsonPath('contacts.0.name', 'Juan Pérez');
        $response->assertJsonPath('purchase_orders', []);
    }

    public function test_agregar_contacto(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read', 'compras.edit']);
        $agency = LiquidationAgency::create(['name' => 'Agencia Aduanal Istmo']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/liquidation-agencies/{$agency->id}/contacts", ['name' => 'Ana López'])
            ->assertStatus(201)
            ->assertJsonPath('name', 'Ana López');

        $this->assertDatabaseHas('illuminations_compras.liquidation_agency_contacts', ['name' => 'Ana López']);
    }

    public function test_no_se_puede_quitar_el_unico_contacto(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read', 'compras.edit']);
        $agency = LiquidationAgency::create(['name' => 'Agencia Aduanal Istmo']);
        $contact = $agency->contacts()->create(['name' => 'Único Contacto']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/compras/liquidation-agencies/{$agency->id}/contacts/{$contact->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('illuminations_compras.liquidation_agency_contacts', ['id' => $contact->id]);
    }

    public function test_quitar_contacto_cuando_hay_mas_de_uno(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read', 'compras.edit']);
        $agency = LiquidationAgency::create(['name' => 'Agencia Aduanal Istmo']);
        $contact = $agency->contacts()->create(['name' => 'Contacto A']);
        $agency->contacts()->create(['name' => 'Contacto B']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/compras/liquidation-agencies/{$agency->id}/contacts/{$contact->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('illuminations_compras.liquidation_agency_contacts', ['id' => $contact->id]);
    }

    // ── SCRUM-254 (REQ-191) — "situación de pago" mockeada, carga manual de Compras ─────────

    public function test_actualizar_situacion_de_pago(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read', 'compras.edit']);
        $agency = LiquidationAgency::create(['name' => 'Agencia Aduanal Istmo']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/liquidation-agencies/{$agency->id}", [
                'pending_payment_amount' => 460,
                'paid_amount'            => 0,
                'last_payment_date'      => null,
                'next_payment_date'      => '2026-07-10',
            ])
            ->assertStatus(200);

        $response->assertJsonPath('pending_payment_amount', 460);
        $response->assertJsonPath('next_payment_date', '2026-07-10');
        $this->assertDatabaseHas('illuminations_compras.liquidation_agencies', [
            'id' => $agency->id, 'pending_payment_amount' => 460, 'next_payment_date' => '2026-07-10',
        ]);
    }

    public function test_actualizar_situacion_de_pago_con_monto_negativo_retorna_422(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read', 'compras.edit']);
        $agency = LiquidationAgency::create(['name' => 'Agencia Aduanal Istmo']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/liquidation-agencies/{$agency->id}", [
                'pending_payment_amount' => -10,
                'paid_amount'            => 0,
            ])
            ->assertStatus(422);
    }

    public function test_actualizar_situacion_de_pago_sin_permiso_edit_retorna_403(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $agency = LiquidationAgency::create(['name' => 'Agencia Aduanal Istmo']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/liquidation-agencies/{$agency->id}", [
                'pending_payment_amount' => 100, 'paid_amount' => 0,
            ])
            ->assertStatus(403);
    }

    public function test_resumen_incluye_kpis_de_situacion_de_pago(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $agency = LiquidationAgency::create([
            'name' => 'Agencia Aduanal Istmo', 'pending_payment_amount' => 460, 'paid_amount' => 0,
        ]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/liquidation-agencies/summary')
            ->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('id', $agency->id);
        $this->assertEquals(460, $row['pending_payment_amount']);
        $this->assertSame(1, $response->json('kpis.agencies_with_pending_balance'));
        $this->assertEquals(460, $response->json('kpis.total_pending_amount'));
    }
}
