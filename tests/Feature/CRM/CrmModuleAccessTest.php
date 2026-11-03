<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 19 — el CRM es un módulo propio: `/api/crm/*` exige `crm.view` (visibilidad del módulo por rol). Los `crm.read/write/edit/delete` que cada nivel
 * de seguridad lleva en su escape hatch ya no bastan. La visibilidad sembrada copia la de Ventas & Diseño (Vendedor y Gerencia).
 */
final class CrmModuleAccessTest extends FeatureTestCase
{
    /** @return array<string, string> */
    private function como(string $email): array
    {
        return $this->authHeaders($this->loginAs($email, $email));
    }

    private function codigo(string $email, string $ruta): int
    {
        return $this->withHeaders($this->como($email))->getJson($ruta)->getStatusCode();
    }

    public function test_solo_los_roles_con_el_modulo_crm_entran(): void
    {
        foreach (['vendedor@test.com', 'gerencia@test.com', 'superadmin@test.com'] as $con) {
            $this->assertSame(200, $this->codigo($con, '/api/crm/projects'), "{$con} entra al CRM");
        }
        foreach (['tecnico@test.com', 'lidercompras@test.com', 'liderbodega@test.com', 'liderservicios@test.com', 'lideroperaciones@test.com', 'contabilidad@test.com'] as $sin) {
            $this->assertSame(403, $this->codigo($sin, '/api/crm/projects'), "{$sin} no entra al CRM aunque su nivel traiga crm.* (ni el alcance de Gerencia lo abre: el CRM se concede como módulo)");
        }
    }

    public function test_sin_el_modulo_no_se_puede_escribir_aunque_se_tenga_crm_write(): void
    {
        $this->withHeaders($this->como('tecnico@test.com'))->postJson('/api/crm/projects', ['nombre' => 'x', 'tipo' => 'diseno'])->assertStatus(403);
        $this->withHeaders($this->como('tecnico@test.com'))->postJson('/api/crm/contacts', ['nombre' => 'x', 'tipo' => 'encargado'])->assertStatus(403);
        $this->withHeaders($this->como('tecnico@test.com'))->deleteJson('/api/crm/contacts/1')->assertStatus(403);
    }

    public function test_el_equipo_sigue_siendo_de_gerencia_y_no_del_vendedor(): void
    {
        $this->assertSame(200, $this->codigo('gerencia@test.com', '/api/crm/reports/team'));
        $this->assertSame(403, $this->codigo('vendedor@test.com', '/api/crm/reports/team'));
    }

    public function test_conceder_y_quitar_el_modulo_a_un_rol_abre_y_cierra_el_acceso(): void
    {
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
        $rolId = (int) DB::table('atlantic_auth.roles')->where('key', 'lider_compras')->value('id');
        $this->assertSame(403, $this->codigo('lidercompras@test.com', '/api/crm/projects'));

        DB::table('atlantic_auth.role_module_visibility')->insert(['role_id' => $rolId, 'module' => 'crm', 'can_view' => 2, 'can_view_team' => false]);
        $this->assertSame(200, $this->codigo('lidercompras@test.com', '/api/crm/projects'), 'con el módulo concedido, entra');

        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
        DB::table('atlantic_auth.role_module_visibility')->where('role_id', $rolId)->where('module', 'crm')->delete();
        $this->assertSame(403, $this->codigo('lidercompras@test.com', '/api/crm/projects'), 'al quitarlo, deja de entrar');
    }

    public function test_el_modulo_crm_existe_en_el_catalogo_y_el_superadmin_siempre_entra(): void
    {
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
        $this->assertContains('crm', \App\Modules\Auth\Support\ModuleCatalog::keys());
        $this->assertSame(200, $this->codigo('superadmin@test.com', '/api/crm/settings'));
    }
}
