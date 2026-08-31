<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/** SCRUM-607→609 (REQ-530→532) — Cuentas Bancarias. */
class AdminContBankAccountControllerTest extends FeatureTestCase
{
    private const FELIX_EMAIL = 'contabilidad@test.com';

    private const YANETH_EMAIL = 'asistenteadministrativa@test.com';

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

    public function test_index_visible_para_felix(): void
    {
        AdminContBankAccount::create([
            'banco' => 'Banistmo', 'ultimos_4_digitos' => '9034',
        ]);

        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/bank-accounts')
            ->assertStatus(200)
            ->assertJsonFragment(['banco' => 'Banistmo', 'ultimos_4_digitos' => '9034', 'activa' => true]);
    }

    public function test_index_rechaza_a_un_rol_sin_visibilidad_del_modulo(): void
    {
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/bank-accounts')
            ->assertStatus(403);
    }

    public function test_store_crea_una_cuenta_activa_con_defaults_de_tipo_y_moneda(): void
    {
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/bank-accounts', [
                'banco' => 'Banco General', 'ultimos_4_digitos' => '1234',
            ])
            ->assertStatus(201);

        $response->assertJsonFragment([
            'banco' => 'Banco General', 'ultimos_4_digitos' => '1234',
            'tipo_cuenta' => 'corriente', 'moneda' => 'USD', 'activa' => true,
        ]);
    }

    public function test_store_yaneth_tambien_puede_crear_cuentas(): void
    {
        $token = $this->loginAsYaneth();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/bank-accounts', [
                'banco' => 'Banco Nacional', 'ultimos_4_digitos' => '5566',
            ])
            ->assertStatus(201);
    }

    public function test_store_rechaza_a_un_rol_no_autorizado(): void
    {
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/bank-accounts', [
                'banco' => 'Banistmo', 'ultimos_4_digitos' => '1111',
            ])
            ->assertStatus(403);
    }

    public function test_store_exige_ultimos_4_digitos_exactos(): void
    {
        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/bank-accounts', ['banco' => 'Banistmo', 'ultimos_4_digitos' => '123'])
            ->assertStatus(422);

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/bank-accounts', ['banco' => 'Banistmo'])
            ->assertStatus(422);
    }

    public function test_store_nunca_acepta_un_numero_de_cuenta_completo(): void
    {
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/bank-accounts', [
                'banco' => 'Banistmo', 'ultimos_4_digitos' => '9034',
                'numero_completo' => '0000111122229034',
            ])
            ->assertStatus(201);

        // `numero_completo` no está en las reglas de StoreBankAccountRequest ni en $fillable —
        // estructuralmente no puede persistirse por este endpoint. No se usa assertDatabaseMissing
        // acá porque requeriría calificar el schema `admin_contab` a mano (el search_path del
        // tenant en tests solo resuelve `auth`, no todos los schemas — mismo gotcha de
        // Rule::unique documentado en CLAUDE.md, pero a la inversa).
        $this->assertArrayNotHasKey('numero_completo', $response->json());
    }

    public function test_deactivate_y_reactivate(): void
    {
        $account = AdminContBankAccount::create(['banco' => 'Banistmo', 'ultimos_4_digitos' => '9034']);
        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/bank-accounts/{$account->id}/deactivate")
            ->assertStatus(200)
            ->assertJsonFragment(['activa' => false]);

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/bank-accounts/{$account->id}/reactivate")
            ->assertStatus(200)
            ->assertJsonFragment(['activa' => true]);
    }

    public function test_filtro_activa_true_excluye_cuentas_desactivadas(): void
    {
        AdminContBankAccount::create(['banco' => 'Activa', 'ultimos_4_digitos' => '1111', 'activa' => true]);
        AdminContBankAccount::create(['banco' => 'Inactiva', 'ultimos_4_digitos' => '2222', 'activa' => false]);

        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/bank-accounts?activa=true')
            ->assertStatus(200);

        $bancos = collect($response->json())->pluck('banco')->all();
        $this->assertContains('Activa', $bancos);
        $this->assertNotContains('Inactiva', $bancos);
    }
}
