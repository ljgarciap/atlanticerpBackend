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
    private const FELIX_EMAIL = 'contabilidad@test.com';

    private const YANETH_EMAIL = 'asistenteadministrativa@test.com';

    private const APPROVER_EMAIL = 'gerencia3@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function loginAsFelix(): string
    {
        return $this->loginAs(self::FELIX_EMAIL, self::FELIX_EMAIL, 'lider_admin_contab');
    }

    private function loginAsYaneth(): string
    {
        return $this->loginAs(self::YANETH_EMAIL, self::YANETH_EMAIL, 'asistente_administrativa');
    }

    private function loginAsMark(): string
    {
        return $this->loginAs(self::APPROVER_EMAIL, self::APPROVER_EMAIL, 'management');
    }

    public function test_index_devuelve_direccion_entrada_para_cobro_y_salida_para_comision_y_devolucion(): void
    {
        $account = AdminContBankAccount::create(['banco' => 'Banistmo', 'ultimos_4_digitos' => '9034']);
        AdminContBankMovement::create(['bank_account_id' => $account->id, 'fecha' => now(), 'tipo' => 'cobro', 'concepto' => 'Cobro F-100', 'monto' => 500]);
        AdminContBankMovement::create(['bank_account_id' => $account->id, 'fecha' => now(), 'tipo' => 'devolucion', 'concepto' => 'NC-1', 'monto' => 50]);
        AdminContBankMovement::create(['bank_account_id' => null, 'fecha' => now(), 'tipo' => 'comision', 'concepto' => 'Comisión Vendedor X', 'monto' => 270]);

        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/bank-movements')
            ->assertStatus(200);

        $byTipo = collect($response->json())->keyBy('tipo');
        $this->assertSame('entrada', $byTipo['cobro']['direccion']);
        $this->assertSame('salida', $byTipo['devolucion']['direccion']);
        $this->assertSame('salida', $byTipo['comision']['direccion']);
        $this->assertNotNull($byTipo['cobro']['bank_account_label']);
        $this->assertNull($byTipo['comision']['bank_account_label']);
    }

    public function test_filtro_por_bank_account_id(): void
    {
        $account1 = AdminContBankAccount::create(['banco' => 'Banistmo', 'ultimos_4_digitos' => '9034']);
        $account2 = AdminContBankAccount::create(['banco' => 'Banco General', 'ultimos_4_digitos' => '1234']);
        AdminContBankMovement::create(['bank_account_id' => $account1->id, 'fecha' => now(), 'tipo' => 'cobro', 'concepto' => 'A', 'monto' => 100]);
        AdminContBankMovement::create(['bank_account_id' => $account2->id, 'fecha' => now(), 'tipo' => 'cobro', 'concepto' => 'B', 'monto' => 200]);

        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/bank-movements?bank_account_id={$account1->id}")
            ->assertStatus(200);

        $this->assertCount(1, $response->json());
        $this->assertSame('A', $response->json()[0]['concepto']);
    }

    public function test_assign_account_yaneth_puede_asignar_cuenta_a_comision_sin_cuenta(): void
    {
        $account = AdminContBankAccount::create(['banco' => 'Banistmo', 'ultimos_4_digitos' => '9034']);
        $movement = AdminContBankMovement::create(['bank_account_id' => null, 'fecha' => now(), 'tipo' => 'comision', 'concepto' => 'Comisión', 'monto' => 270]);

        $token = $this->loginAsYaneth();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/bank-movements/{$movement->id}/assign-account", ['bank_account_id' => $account->id])
            ->assertStatus(200)
            ->assertJsonFragment(['bank_account_id' => $account->id]);
    }

    public function test_assign_account_rechaza_a_mark_gerencia_no_incluido_en_req534(): void
    {
        $account = AdminContBankAccount::create(['banco' => 'Banistmo', 'ultimos_4_digitos' => '9034']);
        $movement = AdminContBankMovement::create(['bank_account_id' => null, 'fecha' => now(), 'tipo' => 'comision', 'concepto' => 'Comisión', 'monto' => 270]);

        $token = $this->loginAsMark();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/bank-movements/{$movement->id}/assign-account", ['bank_account_id' => $account->id])
            ->assertStatus(403);
    }

    public function test_assign_account_rechaza_cuenta_inactiva(): void
    {
        $account = AdminContBankAccount::create(['banco' => 'Banistmo', 'ultimos_4_digitos' => '9034', 'activa' => false]);
        $movement = AdminContBankMovement::create(['bank_account_id' => null, 'fecha' => now(), 'tipo' => 'comision', 'concepto' => 'Comisión', 'monto' => 270]);

        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/bank-movements/{$movement->id}/assign-account", ['bank_account_id' => $account->id])
            ->assertStatus(422);
    }

    public function test_assign_account_rechaza_sobre_cobro_o_devolucion(): void
    {
        $account = AdminContBankAccount::create(['banco' => 'Banistmo', 'ultimos_4_digitos' => '9034']);
        $cobro = AdminContBankMovement::create(['bank_account_id' => null, 'fecha' => now(), 'tipo' => 'cobro', 'concepto' => 'Cobro', 'monto' => 100]);

        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/bank-movements/{$cobro->id}/assign-account", ['bank_account_id' => $account->id])
            ->assertStatus(422);
    }

    public function test_assign_account_rechaza_comision_que_ya_tiene_cuenta(): void
    {
        $account1 = AdminContBankAccount::create(['banco' => 'Banistmo', 'ultimos_4_digitos' => '9034']);
        $account2 = AdminContBankAccount::create(['banco' => 'Banco General', 'ultimos_4_digitos' => '1234']);
        $movement = AdminContBankMovement::create(['bank_account_id' => $account1->id, 'fecha' => now(), 'tipo' => 'comision', 'concepto' => 'Comisión', 'monto' => 270]);

        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/bank-movements/{$movement->id}/assign-account", ['bank_account_id' => $account2->id])
            ->assertStatus(422);
    }
}
