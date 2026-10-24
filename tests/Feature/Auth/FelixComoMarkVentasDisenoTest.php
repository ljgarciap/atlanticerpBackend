<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\UserModuleVisibility;
use App\Modules\Auth\Services\UserService;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/** SCRUM-1150 — Félix replica a Mark en Ventas & Diseño con override de edit/approve; el resto no cambia. */
class FelixComoMarkVentasDisenoTest extends FeatureTestCase
{
    private const FELIX = 'contabilidad@test.com';
    private const MARK = 'gerencia3@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function u(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /** @return list<string> */
    private function vd(User $u): array
    {
        $p = array_values(array_filter($u->effectivePermissionsV2(), fn (string $x) => str_starts_with($x, 'ventas_diseno.')));
        sort($p);
        return $p;
    }

    public function test_felix_tiene_todos_los_permisos_de_ventas_diseno_de_mark(): void
    {
        $faltan = array_diff($this->vd($this->u(self::MARK)), $this->vd($this->u(self::FELIX)));

        $this->assertSame([], array_values($faltan), 'Faltan a Félix: '.implode(', ', $faltan));
        $this->assertSame(
            ['view' => true, 'view_team' => true, 'edit' => true, 'approve' => true],
            $this->u(self::FELIX)->modulesPayload()['ventas_diseno'],
        );
    }

    public function test_los_otros_modulos_de_felix_no_cambian(): void
    {
        $m = $this->u(self::FELIX)->modulesPayload();

        $this->assertSame(['view' => true, 'view_team' => true, 'edit' => true, 'approve' => false], $m['admin_contab']);
        foreach (['compras', 'bodega', 'servicios', 'gerencia', 'operaciones'] as $modulo) {
            $this->assertSame(['view' => false, 'view_team' => false, 'edit' => false, 'approve' => false], $m[$modulo], $modulo);
        }
    }

    public function test_mark_queda_intacto(): void
    {
        $fila = UserModuleVisibility::where('user_id', $this->u(self::MARK)->id)->count();

        $this->assertSame(0, $fila);
        $this->assertSame(
            ['view' => true, 'view_team' => true, 'edit' => true, 'approve' => true],
            $this->u(self::MARK)->modulesPayload()['ventas_diseno'],
        );
    }

    public function test_sin_override_el_nivel_sigue_mandando(): void
    {
        $felix = $this->u(self::FELIX);
        UserModuleVisibility::where('user_id', $felix->id)->where('module', 'ventas_diseno')->update(['can_edit' => null, 'can_approve' => null]);

        // Sin override manda el nivel 4: con el módulo visible ya edita, pero no aprueba.
        $m = $felix->fresh()->modulesPayload()['ventas_diseno'];
        $this->assertTrue($m['edit']);
        $this->assertFalse($m['approve']);
        $this->assertNotContains('ventas_diseno.approve', $this->vd($felix->fresh()));
    }

    public function test_guardar_visibilidad_desde_el_admin_no_borra_el_override_de_edit_approve(): void
    {
        $felix = $this->u(self::FELIX);
        $service = app(UserService::class);
        $actual = UserModuleVisibility::where('user_id', $felix->id)->where('module', 'ventas_diseno')->firstOrFail();

        $service->updateModuleVisibility($felix, [[
            'module' => 'ventas_diseno', 'can_view' => 0, 'can_view_team' => false,
        ]], []);

        $despues = UserModuleVisibility::where('user_id', $felix->id)->where('module', 'ventas_diseno')->first();
        $this->assertNotNull($despues);
        $this->assertTrue($despues->can_edit);
        $this->assertTrue($despues->can_approve);
        $this->assertSame($actual->id, $despues->id);
    }

    public function test_felix_ya_edita_cotizaciones_ajenas_como_mark(): void
    {
        $designerId = $this->u('designer@atlantic.test')->id;
        $quoteId = \App\Modules\VentasDiseno\Models\Quote::create(['owner_id' => $designerId])->id;

        $token = $this->loginAs(self::FELIX, self::FELIX, 'lider_admin_contab');
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/quotes/{$quoteId}", ['description' => 'Edición de Félix'])
            ->assertStatus(200);
    }

    public function test_felix_sigue_viendo_todos_los_pedidos_en_status_de_pedidos(): void
    {
        $token = $this->loginAs(self::FELIX, self::FELIX, 'lider_admin_contab');

        $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/orders/status')->assertStatus(200);
    }

    public function test_felix_entra_a_las_rutas_por_rol_de_mark_en_ventas_diseno(): void
    {
        $h = $this->authHeaders($this->loginAs(self::FELIX, self::FELIX, 'lider_admin_contab'));

        foreach (['/api/ventas-diseno/dashboard/summary', '/api/ventas-diseno/orders'] as $ruta) {
            $r = $this->withHeaders($h)->getJson($ruta);
            $this->assertNotContains($r->getStatusCode(), [401, 403], "{$ruta} debe abrirse a Félix como a Mark");
        }
    }

    public function test_felix_ve_el_alcance_de_gerencia_en_crm(): void
    {
        $h = $this->authHeaders($this->loginAs(self::FELIX, self::FELIX, 'lider_admin_contab'));

        $this->withHeaders($h)->getJson('/api/crm/reports/team')->assertStatus(200);
    }

    public function test_el_alcance_de_gerencia_no_se_filtra_fuera_de_crm_y_ventas_diseno(): void
    {
        $h = $this->authHeaders($this->loginAs(self::FELIX, self::FELIX, 'lider_admin_contab'));

        // Rutas de Gerencia fuera de CRM/V&D siguen cerradas: Seguridad no es parte del ticket.
        $this->withHeaders($h)->getJson('/api/admin/security/anomalies')->assertStatus(403);
    }

    public function test_sin_el_permiso_gerencia_scope_las_rutas_por_rol_vuelven_a_403(): void
    {
        $felix = $this->u(self::FELIX);
        $felix->update(['extra_permissions' => array_values(array_diff($felix->extra_permissions ?? [], ['ventas_diseno.gerencia_scope']))]);
        $h = $this->authHeaders($this->loginAs(self::FELIX, self::FELIX, 'lider_admin_contab'));

        $this->withHeaders($h)->getJson('/api/ventas-diseno/dashboard/summary')->assertStatus(403);
        // /api/crm/reports/team: en binax era `role:superadmin,management`; en Atlantic (Fase 3) usa el permiso
        // `ventas_diseno.view_team`, que se resuelve por visibilidad de módulo y no por el claim gerencia_scope.
    }

    public function test_la_reversa_en_ensayo_no_escribe_y_con_apply_deja_a_felix_como_antes(): void
    {
        $felix = $this->u(self::FELIX);

        $this->artisan('users:revertir-felix-como-mark')->assertExitCode(0);
        $this->assertNotNull(UserModuleVisibility::where('user_id', $felix->id)->where('module', 'ventas_diseno')->first(), 'el ensayo no debe escribir');
        $this->assertContains('ventas_diseno.gerencia_scope', $this->u(self::FELIX)->extra_permissions ?? []);

        $this->artisan('users:revertir-felix-como-mark', ['--apply' => true])->assertExitCode(0);
        $this->artisan('users:revertir-felix-como-mark', ['--apply' => true])->assertExitCode(0); // idempotente

        $despues = $this->u(self::FELIX);
        $this->assertNull(UserModuleVisibility::where('user_id', $despues->id)->where('module', 'ventas_diseno')->first());
        foreach (['ventas_diseno.select_partner_price', 'ventas_diseno.override_min_margin', 'ventas_diseno.gerencia_scope'] as $p) {
            $this->assertNotContains($p, $despues->extra_permissions ?? []);
        }
        // Lo anterior a SCRUM-1150 se conserva.
        foreach (['ventas_diseno.pipeline.edit', 'ventas_diseno.edit.approved', 'admin_contab.edit.receptor_fiscal'] as $p) {
            $this->assertContains($p, $despues->extra_permissions ?? []);
        }
        $this->assertFalse($despues->modulesPayload()['ventas_diseno']['view']);
    }
}
