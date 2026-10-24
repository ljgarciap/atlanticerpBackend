<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * docs/specs/demo-mode.md — cubre B1/B2/B3 del desglose de PM: el gate GLOBAL
 * (system_settings.demo_mode_enabled) y el gate PERSONAL (users.preferences.demo_mode_enabled)
 * de forma aislada. El filtrado real sobre Catálogo/Inventario/selector de producto (scope +
 * los 3 endpoints, B4-B8) tiene su propia cobertura en cada test de esos controllers.
 *
 * Las aserciones post-request consultan `atlantic_auth.*` vía DB::table() en vez de los
 * modelos Eloquent (SystemSetting/AuditLog) — mismo gotcha ya documentado en
 * DocumentControllerTest.php: su getTable() necesita tenant activo, pero el middleware JWT ya
 * llamó Tenant::forgetCurrent() para cuando la respuesta HTTP vuelve al test.
 */
class DemoModeSettingTest extends FeatureTestCase
{
    // ── Gate global ──────────────────────────────────────────────────────────

    public function test_default_es_apagado_cuando_no_hay_fila_en_system_settings(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/settings/system')
            ->assertStatus(200)
            ->assertJsonPath('demo_mode_enabled', false);
    }

    public function test_superadmin_activa_el_gate_global_y_persiste(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->patchJson('/api/settings/system', ['demo_mode_enabled' => true])
            ->assertStatus(200)
            ->assertJsonPath('demo_mode_enabled', true);

        $setting = DB::table('atlantic_auth.system_settings')->where('key', 'demo_mode_enabled')->first();
        $this->assertNotNull($setting);
        $this->assertTrue((bool) json_decode((string) $setting->value, true));
    }

    public function test_activar_el_gate_global_queda_auditado(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->patchJson('/api/settings/system', ['demo_mode_enabled' => true])
            ->assertStatus(200);

        $log = DB::table('atlantic_auth.audit_logs')
            ->where('action', 'settings.updated')
            ->where('entity_id', 'demo_mode_enabled')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(['demo_mode_enabled' => false], json_decode((string) $log->old_values, true));
        $this->assertSame(['demo_mode_enabled' => true], json_decode((string) $log->new_values, true));
    }

    public function test_reenviar_el_mismo_valor_no_duplica_el_audit_log(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->patchJson('/api/settings/system', ['demo_mode_enabled' => true])
            ->assertStatus(200);

        $countAfterFirst = DB::table('atlantic_auth.audit_logs')->where('entity_id', 'demo_mode_enabled')->count();

        $this->withHeaders($this->authHeaders($token))
            ->patchJson('/api/settings/system', ['demo_mode_enabled' => true])
            ->assertStatus(200);

        $this->assertSame(
            $countAfterFirst,
            DB::table('atlantic_auth.audit_logs')->where('entity_id', 'demo_mode_enabled')->count(),
        );
    }

    // ── GET /settings/demo-mode-status — hallazgo de Frontend Dev (2026-09-04) ───────────────
    // El toggle personal necesita saber si el gate global está encendido sin requerir
    // settings.global (o el toggle nunca se mostraría para nadie sin ese permiso).

    public function test_usuario_sin_settings_global_igual_puede_leer_el_status_del_gate_global(): void
    {
        $admin = $this->loginAs();
        $this->withHeaders($this->authHeaders($admin))
            ->patchJson('/api/settings/system', ['demo_mode_enabled' => true])
            ->assertStatus(200);

        $designer = $this->loginAs('designer@atlantic.test', 'Password123!');
        $this->withHeaders($this->authHeaders($designer))
            ->getJson('/api/settings/demo-mode-status')
            ->assertStatus(200)
            ->assertJsonPath('enabled', true);
    }

    public function test_status_del_gate_global_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/settings/demo-mode-status')->assertStatus(401);
    }

    public function test_usuario_sin_settings_global_no_puede_tocar_el_gate_global(): void
    {
        $designer = $this->loginAs('designer@atlantic.test', 'Password123!');

        $this->withHeaders($this->authHeaders($designer))
            ->patchJson('/api/settings/system', ['demo_mode_enabled' => true])
            ->assertStatus(403);
    }

    public function test_valor_no_booleano_retorna_422(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->patchJson('/api/settings/system', ['demo_mode_enabled' => 'sí'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['demo_mode_enabled']]);
    }

    // ── Gate personal ────────────────────────────────────────────────────────

    public function test_cualquier_usuario_autenticado_activa_su_propia_preferencia_sin_permiso_especial(): void
    {
        $designer = $this->loginAs('designer@atlantic.test', 'Password123!');

        $this->withHeaders($this->authHeaders($designer))
            ->patchJson('/api/settings/preferences', ['demo_mode_enabled' => true])
            ->assertStatus(200)
            ->assertJsonPath('demo_mode_enabled', true);

        $this->withHeaders($this->authHeaders($designer))
            ->getJson('/api/settings/preferences')
            ->assertStatus(200)
            ->assertJsonPath('demo_mode_enabled', true);
    }

    public function test_gate_personal_es_independiente_del_gate_global(): void
    {
        // Gate global sigue apagado (default) -- el usuario igual puede prender su preferencia
        // personal; el efecto combinado de ambos gates lo valida el scope, no este endpoint.
        $designer = $this->loginAs('designer@atlantic.test', 'Password123!');

        $this->withHeaders($this->authHeaders($designer))
            ->patchJson('/api/settings/preferences', ['demo_mode_enabled' => true])
            ->assertStatus(200)
            ->assertJsonPath('demo_mode_enabled', true);

        $this->assertNull(DB::table('atlantic_auth.system_settings')->where('key', 'demo_mode_enabled')->first());
    }

    public function test_activar_la_preferencia_personal_no_genera_audit_log(): void
    {
        $designer = $this->loginAs('designer@atlantic.test', 'Password123!');

        $this->withHeaders($this->authHeaders($designer))
            ->patchJson('/api/settings/preferences', ['demo_mode_enabled' => true])
            ->assertStatus(200);

        $this->assertSame(0, DB::table('atlantic_auth.audit_logs')->where('entity_id', 'demo_mode_enabled')->count());
    }
}
