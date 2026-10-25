<?php

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\SystemSetting;
use Tests\Feature\FeatureTestCase;

class RegisterTest extends FeatureTestCase
{
    // ── GET /api/auth/departments ─────────────────────────────────────────────

    public function test_departments_publico_retorna_lista_de_areas(): void
    {
        $this->getJson('/api/auth/departments')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => [['id', 'name']]])
            ->assertJsonCount(6, 'data'); // 6 departments seeded
    }

    // ── POST /api/auth/register — cerrado (SCRUM-1177) ───────────────────────

    public function test_el_registro_publico_ya_no_existe(): void
    {
        $deptId = $this->firstDepartmentId();

        $this->postJson('/api/auth/register', [
            'first_name' => 'Juan', 'last_name' => 'Pérez', 'email' => 'nuevo@atlantic.test', 'department_id' => $deptId,
        ])->assertStatus(404);

        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('atlantic_auth.users')->where('email', 'nuevo@atlantic.test')->count());
    }

    // ── PATCH /api/settings/system — mfa_mode ────────────────────────────────

    public function test_system_settings_incluye_mfa_mode(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/settings/system')
            ->assertStatus(200)
            ->assertJsonStructure(['mfa_mode'])
            ->assertJsonPath('mfa_mode', 'optional');
    }

    public function test_system_settings_actualiza_mfa_mode(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->patchJson('/api/settings/system', ['mfa_mode' => 'required'])
            ->assertStatus(200)
            ->assertJsonPath('mfa_mode', 'required');

        // Verify it persisted
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/settings/system')
            ->assertJsonPath('mfa_mode', 'required');
    }

    public function test_system_settings_mfa_mode_invalido_retorna_422(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->patchJson('/api/settings/system', ['mfa_mode' => 'soloparami'])
            ->assertStatus(422);
    }

    // ── PATCH /api/settings/system — share_links_expiration_days ────────────────

    public function test_system_settings_incluye_share_links_expiration_days(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/settings/system')
            ->assertStatus(200)
            ->assertJsonStructure(['share_links_expiration_days'])
            ->assertJsonPath('share_links_expiration_days', 30);
    }

    public function test_system_settings_actualiza_share_links_expiration_days(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->patchJson('/api/settings/system', ['share_links_expiration_days' => 7])
            ->assertStatus(200)
            ->assertJsonPath('share_links_expiration_days', 7);

        // Verify it persisted
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/settings/system')
            ->assertJsonPath('share_links_expiration_days', 7);
    }

    public function test_system_settings_share_links_expiration_days_fuera_de_rango_retorna_422(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->patchJson('/api/settings/system', ['share_links_expiration_days' => 400])
            ->assertStatus(422);

        $this->withHeaders($this->authHeaders($token))
            ->patchJson('/api/settings/system', ['share_links_expiration_days' => 0])
            ->assertStatus(422);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function firstDepartmentId(): int
    {
        $response = $this->getJson('/api/auth/departments');
        return (int) $response->json('data.0.id');
    }

}
