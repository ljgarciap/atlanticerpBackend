<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Tests\Feature\FeatureTestCase;

/** Fase 25b — pedir `scope=team` sin poder ver al equipo responde 403 también en el Pipeline y el resumen de Bodega. */
final class AlcanceDeEquipoPantallasTest extends FeatureTestCase
{
    /** @return array<string, string> */
    private function como(string $email, string $rol): array
    {
        return $this->authHeaders($this->loginAs($email, $email === 'vendedor@atlantic.test' ? 'Password123!' : $email, $rol));
    }

    public function test_pipeline_scope_team_403_para_quien_no_ve_equipo_y_200_para_quien_si(): void
    {
        $this->withHeaders($this->como('vendedor@atlantic.test', 'designer'))->getJson('/api/ventas-diseno/pipeline?scope=team')
            ->assertStatus(403)->assertJsonPath('code', 'team_scope_forbidden');
        $this->withHeaders($this->como('vendedor@atlantic.test', 'designer'))->getJson('/api/ventas-diseno/pipeline')->assertOk();

        $this->withHeaders($this->authHeaders($this->loginAs()))->getJson('/api/ventas-diseno/pipeline?scope=team')->assertOk();
        // Contabilidad ve el Pipeline completo por sus permisos propios (read_all / edit), sin ver al equipo en general.
        $this->withHeaders($this->como('contabilidad@test.com', 'lider_admin_contab'))->getJson('/api/ventas-diseno/pipeline?scope=team')->assertOk();
    }

    public function test_resumen_de_bodega_scope_team_403_sin_vista_de_equipo(): void
    {
        $asistente = $this->como('auxiliarbodega@test.com', 'auxiliar_bodega');
        $this->withHeaders($asistente)->getJson('/api/bodega/home/summary?scope=team')->assertStatus(403)->assertJsonPath('code', 'team_scope_forbidden');
        $this->withHeaders($asistente)->getJson('/api/bodega/home/summary')->assertOk();

        $this->withHeaders($this->como('liderbodega@test.com', 'lider_bodega'))->getJson('/api/bodega/home/summary?scope=team')->assertOk();
    }
}
