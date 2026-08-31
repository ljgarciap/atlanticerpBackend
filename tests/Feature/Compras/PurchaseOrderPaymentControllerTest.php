<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Models\User;
use App\Modules\Compras\Models\ComprasSettings;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderPayment;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/** SCRUM-250→253 (REQ-187→190) — Pagos a Proveedores. */
class PurchaseOrderPaymentControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Storage::fake('s3-private');
    }

    private function grantCompras(string $email, array $perms): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode($perms)]);
    }

    private function makeProvider(array $overrides = []): Provider
    {
        return Provider::create(['name' => 'LightCorp', 'currency' => 'USD', ...$overrides]);
    }

    private function makeOrder(int $providerId, array $overrides = []): PurchaseOrder
    {
        return PurchaseOrder::create([
            'provider_id' => $providerId, 'currency' => 'USD', 'total_amount' => 1000,
            'status' => PurchaseOrder::STATUS_POR_APROBAR, 'status_changed_at' => now(),
            ...$overrides,
        ]);
    }

    public function test_registrar_pago_sin_comprobante_retorna_422(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $order = $this->makeOrder($this->makeProvider()->id);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/orders/{$order->id}/payments", ['amount' => 500])
            ->assertStatus(422);
    }

    public function test_registrar_pago_parcial_y_completo(): void
    {
        // REQ-189 Escenario 2 — saldo 1000: pago de 600 -> parcial ($400 pendiente); segundo
        // pago de 400 -> completo.
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read']);
        $order = $this->makeOrder($this->makeProvider()->id);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/orders/{$order->id}/payments", [
                'amount' => 600,
                'proof' => UploadedFile::fake()->create('comprobante1.pdf', 50, 'application/pdf'),
            ])
            ->assertStatus(201);

        $mid = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/orders/{$order->id}")
            ->assertStatus(200);
        $mid->assertJsonPath('payment_status', PurchaseOrder::PAYMENT_STATUS_PARCIAL);
        $mid->assertJsonPath('paid_amount', 600);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/orders/{$order->id}/payments", [
                'amount' => 400,
                'proof' => UploadedFile::fake()->create('comprobante2.pdf', 50, 'application/pdf'),
            ])
            ->assertStatus(201);

        $final = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/orders/{$order->id}")
            ->assertStatus(200);
        $final->assertJsonPath('payment_status', PurchaseOrder::PAYMENT_STATUS_COMPLETO);
        $final->assertJsonPath('paid_amount', 1000);
    }

    public function test_registrar_pago_que_excede_el_saldo_retorna_422(): void
    {
        // REQ-189 Escenario 3 — saldo 1000, intento de pagar 1500.
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $order = $this->makeOrder($this->makeProvider()->id);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/orders/{$order->id}/payments", [
                'amount' => 1500,
                'proof' => UploadedFile::fake()->create('comprobante.pdf', 50, 'application/pdf'),
            ])
            ->assertStatus(422);
    }

    public function test_historial_de_pagos_acumula_cada_registro_por_separado(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read']);
        $order = $this->makeOrder($this->makeProvider()->id);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/orders/{$order->id}/payments", [
                'amount' => 300, 'proof' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
            ])->assertStatus(201);
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/orders/{$order->id}/payments", [
                'amount' => 200, 'proof' => UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'),
            ])->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/orders/{$order->id}/payments")
            ->assertStatus(200);

        $amounts = collect($response->json('data'))->pluck('amount');
        $this->assertEquals([300.0, 200.0], $amounts->all());
    }

    /**
     * REQ-189 Escenario 4. Usa `management@atlantic.test` como "Mark" configurado (mismo
     * criterio que PurchaseOrderControllerTest::test_aprobar_orden_siendo_el_mark_configurado_es_exitosa
     * — la cuenta real de Mark no tiene password fijo en testing).
     */
    public function test_cambio_de_monto_antes_del_primer_pago_requiere_aprobacion_de_mark(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $this->grantCompras('management@atlantic.test', ['compras.edit']);
        $markId = (int) User::where('email', 'management@atlantic.test')->value('id');
        ComprasSettings::current()->update(['primary_approver_user_id' => $markId]);

        $order = $this->makeOrder($this->makeProvider()->id);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/request-amount-change", ['new_amount' => 1200])
            ->assertStatus(200)
            ->assertJsonPath('pending_amount_change', 1200);

        // El monto real todavía no cambió — queda pendiente de Mark.
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertEquals(1000.0, PurchaseOrder::find($order->id)?->total_amount);

        // Un usuario que no es Mark no puede aprobarlo.
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/approve-amount-change")
            ->assertStatus(403);

        $markToken = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $this->withHeaders($this->authHeaders($markToken))
            ->patchJson("/api/compras/orders/{$order->id}/approve-amount-change")
            ->assertStatus(200)
            ->assertJsonPath('total_amount', 1200);
    }

    public function test_no_se_puede_cambiar_el_monto_si_ya_hay_pagos_registrados(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $order = $this->makeOrder($this->makeProvider()->id);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/orders/{$order->id}/payments", [
                'amount' => 100, 'proof' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
            ])->assertStatus(201);

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/request-amount-change", ['new_amount' => 1200])
            ->assertStatus(422);
    }

    /**
     * SCRUM-252 (hallazgo de Gerencia Test 2026-08-09) — "caso crítico a corregir": antes, pedir
     * un cambio de monto no bloqueaba nada, así que se podía registrar el pago con el monto viejo
     * mientras el cambio seguía "pendiente de aprobación", dejando una inconsistencia visible.
     * Ahora, mientras exista `pending_amount_change`, registrar un pago está bloqueado (422) — el
     * escenario descrito en el ticket ya no es alcanzable por esta vía.
     */
    public function test_registrar_pago_bloqueado_mientras_hay_cambio_de_monto_pendiente(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $order = $this->makeOrder($this->makeProvider()->id);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/request-amount-change", ['new_amount' => 1200])
            ->assertStatus(200);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/orders/{$order->id}/payments", [
                'amount' => 100, 'proof' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
            ])
            ->assertStatus(422);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame(0, PurchaseOrder::find($order->id)?->payments()->count());
    }

    /** SCRUM-252 — mismo bloqueo real para "Enviar a pago". */
    public function test_enviar_a_pago_bloqueado_mientras_hay_cambio_de_monto_pendiente(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $order = $this->makeOrder($this->makeProvider()->id);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/request-amount-change", ['new_amount' => 1200])
            ->assertStatus(200);

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/request-payment")
            ->assertStatus(422);
    }

    /** SCRUM-252 — "Rechazar cambio": el monto anterior se conserva, la orden queda desbloqueada. */
    public function test_rechazar_cambio_de_monto_conserva_el_monto_anterior_y_desbloquea(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $this->grantCompras('management@atlantic.test', ['compras.edit']);
        $markId = (int) User::where('email', 'management@atlantic.test')->value('id');
        ComprasSettings::current()->update(['primary_approver_user_id' => $markId]);

        $order = $this->makeOrder($this->makeProvider()->id);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/request-amount-change", ['new_amount' => 1200])
            ->assertStatus(200);

        // Yirena (no es Mark) no puede rechazar su propio pedido.
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/reject-amount-change")
            ->assertStatus(403);

        $markToken = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $this->withHeaders($this->authHeaders($markToken))
            ->patchJson("/api/compras/orders/{$order->id}/reject-amount-change")
            ->assertStatus(200)
            ->assertJsonPath('total_amount', 1000);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $fresh = PurchaseOrder::find($order->id);
        $this->assertEquals(1000.0, $fresh->total_amount);
        $this->assertNull($fresh->pending_amount_change);

        // Desbloqueado: ahora sí se puede registrar el pago con el monto vigente.
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/orders/{$order->id}/payments", [
                'amount' => 100, 'proof' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
            ])
            ->assertStatus(201);
    }

    /**
     * Hallazgo de Pre-QA (2026-07-18), sigue vigente como defensa aparte: la vía normal para
     * llegar a "pago registrado + cambio pendiente" ya está bloqueada (ver test de arriba), pero
     * approveAmountChange() se queda con su propio guard defensivo por si un pago entra por otra
     * vía (ej. una condición de carrera real entre 2 requests). Se simula sembrando el pago
     * directo por Eloquent, sin pasar por el controller, para ejercitar ese guard.
     */
    public function test_aprobar_cambio_de_monto_obsoleto_por_pago_registrado_en_el_medio_se_descarta(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $this->grantCompras('management@atlantic.test', ['compras.edit']);
        $markId = (int) User::where('email', 'management@atlantic.test')->value('id');
        ComprasSettings::current()->update(['primary_approver_user_id' => $markId]);

        $order = $this->makeOrder($this->makeProvider()->id);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/request-amount-change", ['new_amount' => 1200])
            ->assertStatus(200);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        PurchaseOrderPayment::create([
            'purchase_order_id' => $order->id, 'amount' => 100,
            'payment_proof_storage_key' => 'compras/test/fake.pdf',
        ]);

        $markToken = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $this->withHeaders($this->authHeaders($markToken))
            ->patchJson("/api/compras/orders/{$order->id}/approve-amount-change")
            ->assertStatus(422);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $fresh = PurchaseOrder::find($order->id);
        $this->assertEquals(1000.0, $fresh->total_amount);
        $this->assertNull($fresh->pending_amount_change);
    }

    public function test_enviar_a_pago_no_bloquea_el_registro_del_pago(): void
    {
        // RN de REQ-189 — puramente informativo (sin cambio de monto pendiente de por medio).
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $order = $this->makeOrder($this->makeProvider()->id);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/request-payment")
            ->assertStatus(200);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/orders/{$order->id}/payments", [
                'amount' => 100, 'proof' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
            ])
            ->assertStatus(201);
    }

    public function test_listado_de_ordenes_con_payment_pending_excluye_ordenadas(): void
    {
        // REQ-187 — "todavía no llegan a Ordenado".
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_POR_APROBAR]);
        $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_ORDENADO]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/orders?payment_pending=1')
            ->assertStatus(200);

        $statuses = collect($response->json('data'))->pluck('status');
        $this->assertTrue($statuses->every(fn ($s) => $s === PurchaseOrder::STATUS_POR_APROBAR));
    }

    public function test_filtro_por_estado_de_pago(): void
    {
        // Hallazgo de QA (2026-07-18): REQ-187 pide "filtros por estado de pago" en UBICACIÓN,
        // no solo los "5 indicadores" (esos sí quedaron fuera, sin RN documentadas). payment_status()
        // no es una columna — se calcula de paid_amount vs. total_amount.
        //
        // SCRUM-250 (hallazgo de Gerencia Test 2026-08-09) — el chip "pendiente" se dividió en
        // "sin_enviar" (nunca se usó "Enviar a pago") y "por_pagar" (ya enviado, sigue sin pagos).
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read']);
        $provider = $this->makeProvider();
        $pending = $this->makeOrder($provider->id, ['total_amount' => 500]);
        $paid = $this->makeOrder($provider->id, ['total_amount' => 500]);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/orders/{$paid->id}/payments", [
                'amount' => 500,
                'proof' => UploadedFile::fake()->create('comprobante.pdf', 50, 'application/pdf'),
            ])
            ->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/orders?payment_status=sin_enviar')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($pending->id));
        $this->assertFalse($ids->contains($paid->id));
    }

    /** SCRUM-250 (hallazgo de Gerencia Test) — "por_pagar" es un estado DISTINTO de "sin_enviar". */
    public function test_filtro_por_pagar_distinto_de_sin_enviar(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read']);
        $provider = $this->makeProvider();
        $sinEnviar = $this->makeOrder($provider->id, ['total_amount' => 500]);
        $porPagar = $this->makeOrder($provider->id, ['total_amount' => 500]);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$porPagar->id}/request-payment")
            ->assertStatus(200);

        $sinEnviarResponse = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/orders?payment_status=sin_enviar')
            ->assertStatus(200);
        $sinEnviarIds = collect($sinEnviarResponse->json('data'))->pluck('id');
        $this->assertTrue($sinEnviarIds->contains($sinEnviar->id));
        $this->assertFalse($sinEnviarIds->contains($porPagar->id));

        $porPagarResponse = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/orders?payment_status=por_pagar')
            ->assertStatus(200);
        $porPagarIds = collect($porPagarResponse->json('data'))->pluck('id');
        $this->assertTrue($porPagarIds->contains($porPagar->id));
        $this->assertFalse($porPagarIds->contains($sinEnviar->id));
    }

    /**
     * SCRUM-250 (hallazgo de Gerencia Test) — los 5 KPIs se calculan sobre el universo COMPLETO
     * de "payment_pending" (con los demás filtros combinables aplicados, pero SIN el chip de
     * estado de pago activo), no sobre la página ya paginada ni sobre el chip actual.
     */
    public function test_kpis_de_pagos_a_proveedores(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read']);
        $provider = $this->makeProvider();
        $sinEnviar = $this->makeOrder($provider->id, ['total_amount' => 100]);
        $porPagar = $this->makeOrder($provider->id, ['total_amount' => 200]);
        $parcial = $this->makeOrder($provider->id, ['total_amount' => 300]);
        $completo = $this->makeOrder($provider->id, ['total_amount' => 400]);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$porPagar->id}/request-payment")
            ->assertStatus(200);
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/orders/{$parcial->id}/payments", [
                'amount' => 100, 'proof' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
            ])->assertStatus(201);
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/orders/{$completo->id}/payments", [
                'amount' => 400, 'proof' => UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'),
            ])->assertStatus(201);

        // Pide un chip activo (por_pagar) a propósito -- los KPIs no deben quedar acotados a él.
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/orders?payment_pending=1&payment_status=por_pagar')
            ->assertStatus(200);

        $this->assertSame(1, $response->json('payment_kpis.sin_enviar'));
        $this->assertSame(1, $response->json('payment_kpis.por_pagar'));
        $this->assertSame(1, $response->json('payment_kpis.parcial'));
        $this->assertSame(1, $response->json('payment_kpis.completo'));
        // (100-0) + (200-0) + (300-100) + (400-400) = 100+200+200+0 = 500
        $this->assertEquals(500.0, $response->json('payment_kpis.total_pending_balance'));

        // El chip SÍ acota "data" (solo por_pagar).
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertSame([$porPagar->id], $ids->all());
    }
}
