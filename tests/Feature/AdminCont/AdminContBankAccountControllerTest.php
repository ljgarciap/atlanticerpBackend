<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/** SCRUM-607→609 (REQ-530→532) — Cuentas Bancarias. */
class AdminContBankAccountControllerTest extends FeatureTestCase
{
    private const CONTABILIDAD_EMAIL = 'contabilidad@test.com';

    private const ASISTENTE_EMAIL = 'asistenteadministrativa@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function loginAsFelix(): string
    {
        return $this->loginAs(self::CONTABILIDAD_EMAIL, self::CONTABILIDAD_EMAIL, 'lider_admin_contab');
    }

    private function loginAsYaneth(): string
    {
        return $this->loginAs(self::ASISTENTE_EMAIL, self::ASISTENTE_EMAIL, 'asistente_administrativa');
    }

    public function test_index_visible_para_contabilidad(): void
    {
        AdminContBankAccount::create([
            'banco' => 'Banco Demo B', 'ultimos_4_digitos' => '9034',
        ]);

        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/bank-accounts')
            ->assertStatus(200)
            ->assertJsonFragment(['banco' => 'Banco Demo B', 'ultimos_4_digitos' => '9034', 'activa' => true]);
    }

    public function test_index_rechaza_a_un_rol_sin_visibilidad_del_modulo(): void
    {
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/bank-accounts')
            ->assertStatus(403);
    }

    public function test_store_crea_una_cuenta_activa_con_defaults_de_tipo_y_moneda(): void
    {
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/bank-accounts', [
                'banco' => 'Banco Demo A', 'ultimos_4_digitos' => '1234',
            ])
            ->assertStatus(201);

        $response->assertJsonFragment([
            'banco' => 'Banco Demo A', 'ultimos_4_digitos' => '1234',
            'tipo_cuenta' => 'corriente', 'moneda' => 'USD', 'activa' => true,
        ]);
    }

    public function test_store_asistente_tambien_puede_crear_cuentas(): void
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
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/bank-accounts', [
                'banco' => 'Banco Demo B', 'ultimos_4_digitos' => '1111',
            ])
            ->assertStatus(403);
    }

    public function test_store_exige_ultimos_4_digitos_exactos(): void
    {
        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/bank-accounts', ['banco' => 'Banco Demo B', 'ultimos_4_digitos' => '123'])
            ->assertStatus(422);

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/bank-accounts', ['banco' => 'Banco Demo B'])
            ->assertStatus(422);
    }

    public function test_store_ignora_un_campo_con_nombre_distinto_al_real(): void
    {
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/bank-accounts', [
                'banco' => 'Banco Demo B', 'ultimos_4_digitos' => '9034',
                'numero_completo' => '0000111122229034',
            ])
            ->assertStatus(201);

        // `numero_completo` (nombre distinto al campo real, ver test siguiente) no está en las
        // reglas de StoreBankAccountRequest ni en $fillable — estructuralmente no puede
        // persistirse por este endpoint.
        $this->assertArrayNotHasKey('numero_completo', $response->json());
    }

    // SCRUM-857 (REQ-458) — excepción deliberada a RN4 de REQ-530 ("nunca se captura el número
    // completo"): `numero_cuenta_completo` SÍ se acepta ahora, a propósito, para que el Estado de
    // Cuenta pueda mostrarle al cliente el dato real que necesita para pagar por transferencia.
    public function test_store_acepta_numero_cuenta_completo(): void
    {
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/bank-accounts', [
                'banco' => 'Banco Demo A', 'ultimos_4_digitos' => '4455',
                'numero_cuenta_completo' => '00-00-00-000000-1',
            ])
            ->assertStatus(201);

        $response->assertJsonFragment(['numero_cuenta_completo' => '00-00-00-000000-1']);
    }

    // ── update (nueva — antes solo existía deactivate/reactivate) ───────────────

    public function test_update_completa_el_numero_de_cuenta_completo_de_una_cuenta_ya_creada(): void
    {
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'ultimos_4_digitos' => '4455']);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/bank-accounts/{$account->id}", [
                'numero_cuenta_completo' => '00-00-00-000000-1',
            ])
            ->assertStatus(200);

        $response->assertJsonFragment(['numero_cuenta_completo' => '00-00-00-000000-1', 'banco' => 'Banco Demo A']);
    }

    public function test_update_parcial_no_pisa_el_resto_de_los_campos(): void
    {
        $account = AdminContBankAccount::create([
            'banco' => 'Banco Demo A', 'ultimos_4_digitos' => '4455', 'moneda' => 'USD',
        ]);
        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/bank-accounts/{$account->id}", ['numero_cuenta_completo' => '00-00-00-000000-1'])
            ->assertStatus(200);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $fresh = $account->fresh();
        $this->assertSame('Banco Demo A', $fresh->banco);
        $this->assertSame('4455', $fresh->ultimos_4_digitos);
        $this->assertSame('USD', $fresh->moneda);
    }

    public function test_update_rechaza_a_un_rol_no_autorizado(): void
    {
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo B', 'ultimos_4_digitos' => '9034']);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/bank-accounts/{$account->id}", ['numero_cuenta_completo' => '1234567890'])
            ->assertStatus(403);
    }

    public function test_update_cuenta_inexistente_retorna_404(): void
    {
        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/bank-accounts/999999', ['numero_cuenta_completo' => '1234567890'])
            ->assertStatus(404);
    }

    public function test_deactivate_y_reactivate(): void
    {
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo B', 'ultimos_4_digitos' => '9034']);
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
