<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-944/945 — auditoría de valores de negocio hardcodeados. `DashboardService`/
 * `ProjectService` tenían 3 umbrales de días hardcodeados (entrega próxima, frescura de
 * actividad reciente/fría), migrados acá a `crm_settings` (ver `CrmSettingsService`).
 */
class CrmSettingsControllerTest extends FeatureTestCase
{
    private function loginAsSuperadmin(): string
    {
        return $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');
    }

    public function test_show_sin_fila_sembrada_devuelve_los_defaults(): void
    {
        $token = $this->loginAsSuperadmin();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/settings')
            ->assertStatus(200);

        $response->assertJson([
            'entrega_proxima_dias'  => 3,
            'freshness_recent_dias' => 7,
            'freshness_stale_dias'  => 21,
        ]);
    }

    public function test_update_persiste_y_show_lo_refleja(): void
    {
        $token = $this->loginAsSuperadmin();

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/crm/settings', ['entrega_proxima_dias' => 10])
            ->assertStatus(200)
            ->assertJson(['entrega_proxima_dias' => 10]);

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/settings')
            ->assertStatus(200)
            ->assertJson(['entrega_proxima_dias' => 10, 'freshness_recent_dias' => 7]);
    }

    public function test_update_rechaza_valor_0(): void
    {
        $token = $this->loginAsSuperadmin();

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/crm/settings', ['freshness_stale_dias' => 0])
            ->assertStatus(422);
    }

    // vendedor@atlantic.test — rol 'designer' puro (level 4), sin superadmin.all. La cuenta
    // demo 'management@atlantic.test' (loginAs() por default) tiene AMBOS roles
    // ['management','superadmin'] sembrados (AuthUserSeeder), así que no sirve para probar un
    // rechazo por falta de superadmin.all.
    private function loginAsDesigner(): string
    {
        return $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
    }

    public function test_show_rechaza_a_un_rol_sin_superadmin_all(): void
    {
        $token = $this->loginAsDesigner();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/settings')
            ->assertStatus(403);
    }

    public function test_update_rechaza_a_un_rol_sin_superadmin_all(): void
    {
        $token = $this->loginAsDesigner();

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/crm/settings', ['entrega_proxima_dias' => 5])
            ->assertStatus(403);
    }

    /**
     * REQ original hardcodeaba `addDays(3)` — con `entrega_proxima_dias` configurado a 10, un
     * proyecto con entrega en 8 días AHORA cuenta como "próximo" (con el código viejo, 8 > 3
     * nunca hubiera entrado en el filtro).
     */
    public function test_filtro_urgency_proximo_usa_el_umbral_configurado(): void
    {
        $token = $this->loginAsSuperadmin();
        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/crm/settings', ['entrega_proxima_dias' => 10])
            ->assertStatus(200);

        $create = $this->withHeaders($this->authHeaders($token))->postJson('/api/crm/projects', [
            'nombre' => 'Proyecto SCRUM-944', 'tipo' => 'ambos', 'etapa' => 'lead',
            'fecha_entrega' => now()->addDays(8)->toDateString(),
        ])->assertStatus(201);
        $projectId = (int) $create->json('id');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/projects?urgency=proximo')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertContains($projectId, $ids);
    }

    // ── Pre-QA SCRUM-945, hallazgo C ─────────────────────────────────────────────────────────

    public function test_rechaza_freshness_recent_mayor_o_igual_que_stale(): void
    {
        $token = $this->loginAsSuperadmin();

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/crm/settings', ['freshness_recent_dias' => 60, 'freshness_stale_dias' => 10])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['freshness_recent_dias', 'freshness_stale_dias']);

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/crm/settings', ['freshness_recent_dias' => 15, 'freshness_stale_dias' => 15])
            ->assertStatus(422);

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/settings')
            ->assertJson(['freshness_recent_dias' => 7, 'freshness_stale_dias' => 21]);
    }

    public function test_freshness_con_un_solo_campo_se_compara_contra_el_valor_persistido(): void
    {
        $token = $this->loginAsSuperadmin();

        // stale persistido = 21 (default): recent 30 lo excede.
        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/crm/settings', ['freshness_recent_dias' => 30])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['freshness_recent_dias']);

        // recent persistido = 7: stale 5 queda por debajo.
        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/crm/settings', ['freshness_stale_dias' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['freshness_stale_dias']);

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/crm/settings', ['freshness_stale_dias' => 40])
            ->assertStatus(200)
            ->assertJson(['freshness_recent_dias' => 7, 'freshness_stale_dias' => 40]);

        // Editar solo entrega_proxima_dias no dispara la regla.
        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/crm/settings', ['entrega_proxima_dias' => 5])
            ->assertStatus(200);
    }

    /** Observaciones BAJAS 2 y 3 — decimales rechazados (`integer`) y sin sufijo en inglés. */
    public function test_rechaza_decimales_y_el_message_general_no_trae_sufijo_en_ingles(): void
    {
        $token = $this->loginAsSuperadmin();

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/crm/settings', ['entrega_proxima_dias' => 12.5])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['entrega_proxima_dias']);

        // Dos errores a la vez (recent >= stale marca los dos campos) → "(y 1 error más)".
        $message = (string) $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/crm/settings', ['freshness_recent_dias' => 60, 'freshness_stale_dias' => 10])
            ->assertStatus(422)
            ->json('message');

        $this->assertStringContainsString('(y 1 error más)', $message);
        $this->assertStringNotContainsString('(and ', $message);
    }
}
