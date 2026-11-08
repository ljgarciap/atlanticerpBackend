<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContBankMovement;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/** SCRUM-610→611 (REQ-533→534) — Movimientos. */
class AdminContBankMovementControllerTest extends FeatureTestCase
{
    private const CONTABILIDAD_EMAIL = 'contabilidad@test.com';

    private const ASISTENTE_EMAIL = 'asistenteadministrativa@test.com';

    private const APPROVER_EMAIL = 'gerencia3@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function loginAsContabilidad(): string
    {
        return $this->loginAs(self::CONTABILIDAD_EMAIL, self::CONTABILIDAD_EMAIL, 'lider_admin_contab');
    }

    private function loginAsAsistente(): string
    {
        return $this->loginAs(self::ASISTENTE_EMAIL, self::ASISTENTE_EMAIL, 'asistente_administrativa');
    }

    private function loginAsApprover(): string
    {
        return $this->loginAs(self::APPROVER_EMAIL, self::APPROVER_EMAIL, 'management');
    }

    public function test_index_devuelve_direccion_entrada_para_cobro_y_salida_para_comision_y_devolucion(): void
    {
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo B', 'ultimos_4_digitos' => '9034']);
        AdminContBankMovement::create(['bank_account_id' => $account->id, 'fecha' => now(), 'tipo' => 'cobro', 'concepto' => 'Cobro F-100', 'monto' => 500]);
        AdminContBankMovement::create(['bank_account_id' => $account->id, 'fecha' => now(), 'tipo' => 'devolucion', 'concepto' => 'NC-1', 'monto' => 50]);
        AdminContBankMovement::create(['bank_account_id' => null, 'fecha' => now(), 'tipo' => 'comision', 'concepto' => 'Comisión Vendedor X', 'monto' => 270]);

        $token = $this->loginAsContabilidad();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/bank-movements')
            ->assertStatus(200);

        $byTipo = collect($response->json('data'))->keyBy('tipo');
        $this->assertSame('entrada', $byTipo['cobro']['direccion']);
        $this->assertSame('salida', $byTipo['devolucion']['direccion']);
        $this->assertSame('salida', $byTipo['comision']['direccion']);
        $this->assertNotNull($byTipo['cobro']['bank_account_label']);
        $this->assertNull($byTipo['comision']['bank_account_label']);
    }

    public function test_filtro_por_bank_account_id(): void
    {
        $account1 = AdminContBankAccount::create(['banco' => 'Banco Demo B', 'ultimos_4_digitos' => '9034']);
        $account2 = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'ultimos_4_digitos' => '1234']);
        AdminContBankMovement::create(['bank_account_id' => $account1->id, 'fecha' => now(), 'tipo' => 'cobro', 'concepto' => 'A', 'monto' => 100]);
        AdminContBankMovement::create(['bank_account_id' => $account2->id, 'fecha' => now(), 'tipo' => 'cobro', 'concepto' => 'B', 'monto' => 200]);

        $token = $this->loginAsContabilidad();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/bank-movements?bank_account_id={$account1->id}")
            ->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('A', $response->json('data')[0]['concepto']);
    }

    // ── SCRUM-947 — paginación estándar ─────────────────────────────────────────────────────

    public function test_index_pagina_20_por_defecto_y_expone_meta(): void
    {
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo B', 'ultimos_4_digitos' => '9034']);
        for ($i = 0; $i < 25; $i++) {
            AdminContBankMovement::create(['bank_account_id' => $account->id, 'fecha' => now(), 'tipo' => 'cobro', 'concepto' => "Mov {$i}", 'monto' => 10]);
        }
        $token = $this->loginAsContabilidad();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/bank-movements')
            ->assertStatus(200)->json();

        $this->assertCount(20, $response['data']);
        $this->assertSame(['total' => 25, 'per_page' => 20, 'current_page' => 1, 'last_page' => 2], $response['meta']);
    }

    public function test_assign_account_asistente_puede_asignar_cuenta_a_comision_sin_cuenta(): void
    {
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo B', 'ultimos_4_digitos' => '9034']);
        $movement = AdminContBankMovement::create(['bank_account_id' => null, 'fecha' => now(), 'tipo' => 'comision', 'concepto' => 'Comisión', 'monto' => 270]);

        $token = $this->loginAsAsistente();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/bank-movements/{$movement->id}/assign-account", ['bank_account_id' => $account->id])
            ->assertStatus(200)
            ->assertJsonFragment(['bank_account_id' => $account->id]);
    }

    public function test_fase28_el_aprobador_primario_es_segundo_asignador(): void
    {
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo B', 'ultimos_4_digitos' => '9034']);
        $movement = AdminContBankMovement::create(['bank_account_id' => null, 'fecha' => now(), 'tipo' => 'comision', 'concepto' => 'Comisión', 'monto' => 270]);

        $token = $this->loginAsApprover();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/bank-movements/{$movement->id}/assign-account", ['bank_account_id' => $account->id])
            ->assertStatus(200);
    }

    public function test_fase28_un_rol_sin_permiso_ni_aprobador_recibe_403(): void
    {
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo B', 'ultimos_4_digitos' => '9034']);
        $movement = AdminContBankMovement::create(['bank_account_id' => null, 'fecha' => now(), 'tipo' => 'comision', 'concepto' => 'Comisión', 'monto' => 270]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/bank-movements/{$movement->id}/assign-account", ['bank_account_id' => $account->id])
            ->assertStatus(403);
    }

    public function test_fase28_reasignar_pide_motivo_audita_y_respeta_el_arqueo_cerrado(): void
    {
        $a = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'ultimos_4_digitos' => '1111']);
        $b = AdminContBankAccount::create(['banco' => 'Banco Demo B', 'ultimos_4_digitos' => '2222']);
        $movement = AdminContBankMovement::create(['bank_account_id' => $a->id, 'fecha' => now(), 'tipo' => 'comision', 'concepto' => 'Comisión', 'monto' => 270]);
        $token = $this->loginAsContabilidad();
        $url = "/api/admin-contab/bank-movements/{$movement->id}/reassign-account";

        $this->withHeaders($this->authHeaders($token))->putJson($url, ['bank_account_id' => $b->id])->assertStatus(422);
        $this->withHeaders($this->authHeaders($token))->putJson($url, ['bank_account_id' => $a->id, 'motivo' => 'Era la cuenta equivocada'])->assertStatus(422);

        $this->withHeaders($this->authHeaders($token))
            ->putJson($url, ['bank_account_id' => $b->id, 'motivo' => 'La comisión salió por la otra cuenta'])
            ->assertStatus(200)->assertJsonFragment(['bank_account_id' => $b->id]);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent(); // tras una petición HTTP el tenant queda olvidado
        $this->assertSame($b->id, $movement->fresh()?->bank_account_id);
    }

    public function test_fase28_no_se_reasigna_la_cuenta_de_un_cobro_desde_el_movimiento(): void
    {
        $a = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'ultimos_4_digitos' => '1111']);
        $b = AdminContBankAccount::create(['banco' => 'Banco Demo B', 'ultimos_4_digitos' => '2222']);
        $cobro = AdminContBankMovement::create(['bank_account_id' => $a->id, 'fecha' => now(), 'tipo' => 'cobro', 'concepto' => 'Cobro', 'monto' => 500]);
        $token = $this->loginAsContabilidad();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/bank-movements/{$cobro->id}/reassign-account", ['bank_account_id' => $b->id, 'motivo' => 'Era la cuenta equivocada'])
            ->assertStatus(422);
    }

    public function test_fase28_filtros_y_pendientes_sin_cuenta(): void
    {
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo B', 'ultimos_4_digitos' => '9034']);
        AdminContBankMovement::create(['bank_account_id' => $account->id, 'fecha' => '2026-03-01', 'tipo' => 'cobro', 'concepto' => 'Cobro', 'monto' => 500]);
        AdminContBankMovement::create(['bank_account_id' => null, 'fecha' => '2026-03-02', 'tipo' => 'comision', 'concepto' => 'Sin cuenta', 'monto' => 270]);
        AdminContBankMovement::create(['bank_account_id' => $account->id, 'fecha' => '2026-04-02', 'tipo' => 'devolucion', 'concepto' => 'Dev', 'monto' => 50]);
        $token = $this->loginAsContabilidad();
        $base = '/api/admin-contab/bank-movements';

        $this->assertCount(1, $this->withHeaders($this->authHeaders($token))->getJson("{$base}?tipo=devolucion")->json('data'));
        $this->assertCount(2, $this->withHeaders($this->authHeaders($token))->getJson("{$base}?desde=2026-03-01&hasta=2026-03-31")->json('data'));
        $sinCuenta = $this->withHeaders($this->authHeaders($token))->getJson("{$base}?sin_cuenta=1&tipo=cobro")->assertStatus(200);
        $this->assertCount(0, $sinCuenta->json('data'));
        $this->assertSame(1, $sinCuenta->json('pendientes_sin_cuenta.cantidad'), 'el pendiente no depende de los filtros');
        $this->withHeaders($this->authHeaders($token))->getJson("{$base}?tipo=otro")->assertStatus(422);
    }

    public function test_fase28_desactivar_una_cuenta_con_movimientos_avisa(): void
    {
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo B', 'ultimos_4_digitos' => '9034']);
        AdminContBankMovement::create(['bank_account_id' => $account->id, 'fecha' => now(), 'tipo' => 'cobro', 'concepto' => 'Cobro', 'monto' => 500]);
        $token = $this->loginAsContabilidad();

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/bank-accounts/{$account->id}/deactivate")->assertStatus(200);
        $this->assertNotEmpty($response->json('avisos'));
    }

    public function test_assign_account_rechaza_cuenta_inactiva(): void
    {
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo B', 'ultimos_4_digitos' => '9034', 'activa' => false]);
        $movement = AdminContBankMovement::create(['bank_account_id' => null, 'fecha' => now(), 'tipo' => 'comision', 'concepto' => 'Comisión', 'monto' => 270]);

        $token = $this->loginAsContabilidad();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/bank-movements/{$movement->id}/assign-account", ['bank_account_id' => $account->id])
            ->assertStatus(422);
    }

    public function test_assign_account_rechaza_sobre_cobro_o_devolucion(): void
    {
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo B', 'ultimos_4_digitos' => '9034']);
        $cobro = AdminContBankMovement::create(['bank_account_id' => null, 'fecha' => now(), 'tipo' => 'cobro', 'concepto' => 'Cobro', 'monto' => 100]);

        $token = $this->loginAsContabilidad();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/bank-movements/{$cobro->id}/assign-account", ['bank_account_id' => $account->id])
            ->assertStatus(422);
    }

    public function test_assign_account_rechaza_comision_que_ya_tiene_cuenta(): void
    {
        $account1 = AdminContBankAccount::create(['banco' => 'Banco Demo B', 'ultimos_4_digitos' => '9034']);
        $account2 = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'ultimos_4_digitos' => '1234']);
        $movement = AdminContBankMovement::create(['bank_account_id' => $account1->id, 'fecha' => now(), 'tipo' => 'comision', 'concepto' => 'Comisión', 'monto' => 270]);

        $token = $this->loginAsContabilidad();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/bank-movements/{$movement->id}/assign-account", ['bank_account_id' => $account2->id])
            ->assertStatus(422);
    }
}
