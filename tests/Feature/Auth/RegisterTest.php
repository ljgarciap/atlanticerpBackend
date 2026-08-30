<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\SystemSetting;
use App\Shared\Multitenancy\Tenant;
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

    // ── POST /api/auth/register ───────────────────────────────────────────────

    public function test_register_con_datos_validos_retorna_201(): void
    {
        $deptId = $this->firstDepartmentId();

        $this->postJson('/api/auth/register', [
            'first_name'    => 'Juan',
            'last_name'     => 'Pérez',
            'email'         => 'nuevo@atlantic.test',
            'department_id' => $deptId,
        ])
        ->assertStatus(201)
        ->assertJsonStructure(['message']);
    }

    public function test_register_crea_usuario_con_rol_guest_inactivo(): void
    {
        $deptId = $this->firstDepartmentId();

        $this->postJson('/api/auth/register', [
            'first_name'    => 'Ana',
            'last_name'     => 'López',
            'email'         => 'nueva@atlantic.test',
            'department_id' => $deptId,
        ])->assertStatus(201);

        /** @var Tenant $tenant */
        $tenant = Tenant::where('slug', 'atlantic')->firstOrFail();
        $tenant->makeCurrent();

        try {
            $user = User::where('email', 'nueva@atlantic.test')->firstOrFail();
            $this->assertFalse($user->is_active);
            $this->assertTrue($user->hasRole(User::ROLE_GUEST));
        } finally {
            Tenant::forgetCurrent();
        }
    }

    public function test_register_usuario_pendiente_no_puede_loguearse(): void
    {
        $deptId = $this->firstDepartmentId();

        $this->postJson('/api/auth/register', [
            'first_name'    => 'Roberto',
            'last_name'     => 'Méndez',
            'email'         => 'pendiente@atlantic.test',
            'department_id' => $deptId,
        ])->assertStatus(201);

        // Any login attempt is blocked — is_active=false is checked before password
        $this->postJson('/api/auth/login', [
            'email'    => 'pendiente@atlantic.test',
            'password' => 'AnyPassword123!',
        ])->assertStatus(401);
    }

    public function test_register_email_duplicado_retorna_422(): void
    {
        $deptId = $this->firstDepartmentId();
        $payload = [
            'first_name'    => 'Duplicate',
            'last_name'     => 'User',
            'email'         => 'nuevo@atlantic.test',
            'department_id' => $deptId,
        ];

        $this->postJson('/api/auth/register', $payload)->assertStatus(201);
        $this->postJson('/api/auth/register', $payload)->assertStatus(422)
            ->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_register_email_de_usuario_existente_retorna_422(): void
    {
        $deptId = $this->firstDepartmentId();

        // designer@atlantic.test is seeded as an active user
        $this->postJson('/api/auth/register', [
            'first_name'    => 'Otro',
            'last_name'     => 'Usuario',
            'email'         => 'designer@atlantic.test',
            'department_id' => $deptId,
        ])->assertStatus(422)
            ->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_register_sin_campos_retorna_422(): void
    {
        $this->postJson('/api/auth/register', [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors']);
    }

    public function test_register_email_invalido_retorna_422(): void
    {
        $this->postJson('/api/auth/register', [
            'first_name'    => 'Test',
            'last_name'     => 'User',
            'email'         => 'no-es-un-email',
            'department_id' => 1,
        ])->assertStatus(422)
            ->assertJsonStructure(['errors' => ['email']]);
    }

    // ── GET /api/users/pending ────────────────────────────────────────────────

    public function test_pending_lista_registros_en_espera(): void
    {
        $deptId = $this->firstDepartmentId();
        $token  = $this->loginAs();

        $this->postJson('/api/auth/register', [
            'first_name'    => 'Pending',
            'last_name'     => 'User',
            'email'         => 'pending@atlantic.test',
            'department_id' => $deptId,
        ])->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/users/pending');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [['id', 'first_name', 'email', 'roles', 'is_active']]])
            ->assertJsonPath('data.0.is_active', false)
            ->assertJsonPath('data.0.roles', ['guest']);
    }

    public function test_pending_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/users/pending')->assertStatus(401);
    }

    // ── POST /api/users/{id}/approve ──────────────────────────────────────────

    public function test_approve_activa_usuario_con_nuevo_rol(): void
    {
        $deptId = $this->firstDepartmentId();
        $token  = $this->loginAs();

        $this->postJson('/api/auth/register', [
            'first_name'    => 'Approve',
            'last_name'     => 'Me',
            'email'         => 'approve@atlantic.test',
            'department_id' => $deptId,
        ])->assertStatus(201);

        $userId = $this->getPendingUserId('approve@atlantic.test');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/users/{$userId}/approve", [
                'role'              => 'designer',
                'security_level_id' => 3,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('is_active', true)
            ->assertJsonPath('roles', ['designer']);
    }

    public function test_approve_usuario_activo_retorna_422(): void
    {
        $token = $this->loginAs();

        // Get an already-active user from the seeded data
        $activeId = $this->getActiveUserId('designer@atlantic.test');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/users/{$activeId}/approve", [
                'role'              => 'designer',
                'security_level_id' => 3,
            ])->assertStatus(422);
    }

    public function test_approve_rol_invalido_retorna_422(): void
    {
        $deptId = $this->firstDepartmentId();
        $token  = $this->loginAs();

        $this->postJson('/api/auth/register', [
            'first_name'    => 'BadRole',
            'last_name'     => 'User',
            'email'         => 'badrole@atlantic.test',
            'department_id' => $deptId,
        ])->assertStatus(201);

        $userId = $this->getPendingUserId('badrole@atlantic.test');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/users/{$userId}/approve", [
                'role'              => 'guest', // guest is not a valid approval role
                'security_level_id' => 1,
            ])->assertStatus(422);
    }

    // ── DELETE /api/users/{id}/reject ─────────────────────────────────────────

    public function test_reject_elimina_usuario_pendiente(): void
    {
        $deptId = $this->firstDepartmentId();
        $token  = $this->loginAs();

        $this->postJson('/api/auth/register', [
            'first_name'    => 'Reject',
            'last_name'     => 'Me',
            'email'         => 'reject@atlantic.test',
            'department_id' => $deptId,
        ])->assertStatus(201);

        $userId = $this->getPendingUserId('reject@atlantic.test');

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/users/{$userId}/reject")
            ->assertStatus(204);

        // Verify the user no longer appears in pending list
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/users/pending')
            ->assertJsonPath('data', []);
    }

    public function test_reject_usuario_activo_retorna_422(): void
    {
        $token    = $this->loginAs();
        $activeId = $this->getActiveUserId('designer@atlantic.test');

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/users/{$activeId}/reject")
            ->assertStatus(422);
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

    private function getPendingUserId(string $email): int
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::where('slug', 'atlantic')->firstOrFail();
        $tenant->makeCurrent();

        try {
            return (int) User::where('email', $email)->value('id');
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function getActiveUserId(string $email): int
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::where('slug', 'atlantic')->firstOrFail();
        $tenant->makeCurrent();

        try {
            return (int) User::where('email', $email)->value('id');
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
