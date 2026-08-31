<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\RoleModuleVisibility;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * ADR-006 — verifica el seed de migración: Fase A (13 roles de negocio + Superadmin, combinaciones
 * rol×módulo de `role_module_visibility`) + mapeo rol→nivel (`default_security_level_id` por rol,
 * `security_level_module_permissions` de 4 arquetipos de capacidad que reusan la escala existente
 * 1-10 -- corrección 2026-07-10, ver 2026_07_18_100002_reuse_existing_levels_for_role_defaults.php).
 * No es un test de la lógica de resolución de permisos (`effectivePermissionsV2`, ver
 * EffectivePermissionsV2Test) — solo de que el schema y los datos de las migraciones quedaron como
 * dice el ADR.
 */
class RolesSeedTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_se_siembran_14_roles_13_de_negocio_mas_superadmin(): void
    {
        $this->assertSame(14, Role::count());
        $this->assertTrue(Role::where('key', 'superadmin')->where('is_superadmin', true)->exists());
    }

    public function test_todos_los_roles_son_is_system_y_estan_activos(): void
    {
        $this->assertSame(0, Role::where('is_system', false)->count());
        $this->assertSame(0, Role::where('is_active', false)->count());
    }

    public function test_default_security_level_id_esta_poblado_para_los_14_roles(): void
    {
        // ADR-006, mapeo rol→nivel (2026-07-09, aprobado por Luis) — ya no queda NULL.
        $this->assertSame(14, Role::whereNotNull('default_security_level_id')->count());
    }

    public function test_gerencia_usa_nivel_9_y_vendedor_usa_nivel_6(): void
    {
        // Corrección 2026-07-10: los arquetipos de capacidad reusan la escala existente 1-10
        // (Gerencia Total = Nivel 9 "Admin") en vez de niveles nuevos -- ver
        // 2026_07_18_100002_reuse_existing_levels_for_role_defaults.php.
        //
        // Fase 4 — Servicios (2026-08-02, 2026_08_02_100003_grant_servicios_view_to_vendedor_disenador.php):
        // vendedor_disenador se movió de Nivel 4 ("Power User", compartido con lider_servicios/
        // lider_bodega/etc. y con can_edit=true en bloque para los 7 módulos) a un Nivel 6
        // ("Supervisor", sin uso previo) angosto — necesario para poder darle visibilidad de
        // solo-lectura en `servicios` sin heredar el `can_edit=true` en bloque del Nivel 4, que
        // hubiera abierto edición real ahí (ver docblock de esa migración).
        $gerencia = Role::with('defaultSecurityLevel')->where('key', 'management')->firstOrFail();
        $vendedor = Role::with('defaultSecurityLevel')->where('key', 'vendedor_disenador')->firstOrFail();
        $superadmin = Role::with('defaultSecurityLevel')->where('key', 'superadmin')->firstOrFail();

        $this->assertSame(9, $gerencia->defaultSecurityLevel->level);
        $this->assertSame(6, $vendedor->defaultSecurityLevel->level);
        $this->assertSame(10, $superadmin->defaultSecurityLevel->level);
    }

    public function test_gerencia_ve_los_7_modulos_con_alcance_equipo(): void
    {
        $gerencia = Role::where('key', 'management')->firstOrFail();
        $rows = RoleModuleVisibility::where('role_id', $gerencia->id)->get();

        $this->assertCount(7, $rows);
        $this->assertTrue($rows->every(fn (RoleModuleVisibility $r) => $r->can_view === RoleModuleVisibility::VIEW_FULL));
        $this->assertTrue($rows->every(fn (RoleModuleVisibility $r) => $r->can_view_team === true));
    }

    public function test_vendedor_disenador_ve_ventas_diseno_y_servicios_sin_alcance_equipo(): void
    {
        // Fase 4 — Servicios (2026-08-02): además de ventas_diseno (Fase A original), ahora
        // también ve `servicios` en modo solo lectura (REQ-216 RN6/REQ-217 RN6) — ver
        // 2026_08_02_100003_grant_servicios_view_to_vendedor_disenador.php.
        // Batch 14 Admin&Cont (2026-08-25): admin_contab en VIEW_READONLY (no VIEW_FULL, a
        // propósito — ver docblock de 2026_08_25_100002_*.php, mismo criterio anti-leak que
        // SCRUM-771 en Compras) — nunca desbloquea `admin_contab.read` como los otros 2.
        $role = Role::where('key', 'vendedor_disenador')->firstOrFail();
        $rows = RoleModuleVisibility::where('role_id', $role->id)->get();

        $this->assertCount(3, $rows);
        $this->assertTrue($rows->every(fn (RoleModuleVisibility $r) => $r->can_view_team === false));
        $this->assertSame(['admin_contab', 'servicios', 'ventas_diseno'], $rows->pluck('module')->sort()->values()->all());

        $byModule = $rows->keyBy('module');
        $this->assertSame(RoleModuleVisibility::VIEW_FULL, $byModule['servicios']->can_view);
        $this->assertSame(RoleModuleVisibility::VIEW_FULL, $byModule['ventas_diseno']->can_view);
        $this->assertSame(RoleModuleVisibility::VIEW_READONLY, $byModule['admin_contab']->can_view);
    }

    public function test_lider_bodega_y_asistente_bodega_tienen_alcance_equipo_pero_ayudante_no(): void
    {
        $liderBodega = Role::where('key', 'lider_bodega')->firstOrFail();
        $asistenteBodega = Role::where('key', 'asistente_bodega')->firstOrFail();
        $ayudanteBodega = Role::where('key', 'ayudante_general_bodega')->firstOrFail();

        $this->assertTrue(RoleModuleVisibility::where('role_id', $liderBodega->id)->first()->can_view_team);
        $this->assertTrue(RoleModuleVisibility::where('role_id', $asistenteBodega->id)->first()->can_view_team);
        $this->assertFalse(RoleModuleVisibility::where('role_id', $ayudanteBodega->id)->first()->can_view_team);
    }

    public function test_no_hay_filas_para_combinaciones_marcadas_como_pendientes_por_el_cliente(): void
    {
        // Ninguno de los 13 roles ve "gerencia" salvo el propio rol Gerencia — deny-by-default,
        // sin filas sembradas para nada que el cliente no confirmó explícitamente.
        $noGerencia = Role::where('key', '!=', 'management')->where('key', '!=', 'superadmin')->get();
        foreach ($noGerencia as $role) {
            $this->assertFalse(
                RoleModuleVisibility::where('role_id', $role->id)->where('module', 'gerencia')->exists(),
                "El rol {$role->key} no debería tener visibilidad sembrada sobre el módulo 'gerencia'.",
            );
        }
    }

    public function test_security_level_module_permissions_tiene_67_filas(): void
    {
        // 35 filas (5 niveles × 7 módulos) desde 2026_07_22_100010 (Pre-QA CRÍTICO, SCRUM-329
        // Batch A2): se sumó el Nivel 3 "solo-Bodega" a los 4 arquetipos previos (1, 4, 8, 9).
        //
        // +3 filas — Fase 4 Servicios (2026-08-02), niveles angostos con UNA sola fila cada uno
        // (no las 7 del resto, ver docblock de cada migración):
        //   Nivel 5 "Team Lead" + servicios       (tecnico_servicios/garantias_servicios, can_edit=true)
        //   Nivel 6 "Supervisor" + ventas_diseno  (vendedor_disenador, can_edit=true, preserva su capacidad actual)
        //   Nivel 6 "Supervisor" + servicios      (vendedor_disenador, can_edit=false — el punto de crear este nivel)
        //
        // +22 filas — Fase 2 de la auditoría de permisos (2026-08-30, ver Atlantic/CLAUDE.md),
        // 4 niveles nuevos para que ningún permiso fino de módulo se filtre a otro rol que
        // comparta nivel (el escape hatch JSONB no respeta role_module_visibility, a diferencia
        // de esta tabla — ver docblock de 2026_08_30_100001 y 100002 para el hallazgo completo):
        //   Nivel 11 "Team Lead — Garantías"        + servicios (1 fila, clon angosto del Nivel 5)
        //   Nivel 13 "Read Only — Admin & Contab"   (7 filas, clon completo del Nivel 1)
        //   Nivel 14 "Power User — Servicios"       (7 filas, clon completo del Nivel 4)
        //   Nivel 15 "Power User — Admin & Contab"  (7 filas, clon completo del Nivel 4)
        //
        // +7 filas — genericización de identidades (2026-08-30, ver Atlantic/CLAUDE.md): al vaciar
        // SpecialPermissionSeeder se encontraron 3 grants que en realidad eran capacidad de rol
        // completo, no de una persona puntual (2026_08_30_100004). `lider_compras` comparte el
        // Nivel 4 con `lider_bodega`, así que se le dio nivel propio:
        //   Nivel 16 "Power User — Compras"         (7 filas, clon completo del Nivel 4)
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $count = DB::table("{$slug}_auth.security_level_module_permissions")->count();
        $this->assertSame(67, $count);
    }

    public function test_nivel_3_edita_solo_bodega_resto_solo_lectura(): void
    {
        // Pre-QA 2026-07-22 (CRÍTICO) — asistente_bodega/ayudante_general_bodega/transporte
        // estaban en Nivel 1 (solo lectura en TODOS los módulos, incl. bodega), contradiciendo
        // RN3 de SCRUM-383 (los Asistentes también pueden asignar el picking). En vez de subirlos
        // al Nivel 4 (over-grant: can_edit en los 7 módulos), se reusa el Nivel 3 (sin uso previo)
        // con can_edit=true SOLO en bodega.
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $levelId = DB::table("{$slug}_auth.security_levels")->where('level', 3)->value('id');

        $rows = DB::table("{$slug}_auth.security_level_module_permissions")
            ->where('security_level_id', $levelId)
            ->get();

        $this->assertCount(7, $rows);
        $this->assertTrue($rows->where('module', 'bodega')->first()->can_edit, 'Nivel 3 debe poder editar bodega.');
        $this->assertSame(
            1,
            $rows->where('can_edit', true)->count(),
            'Nivel 3 no debe poder editar ningún módulo fuera de bodega.',
        );
        $this->assertSame(0, $rows->where('can_approve', true)->count(), 'Nivel 3 no aprueba en ningún módulo.');
    }

    public function test_asistente_bodega_ayudante_y_transporte_usan_nivel_3(): void
    {
        $asistente = Role::with('defaultSecurityLevel')->where('key', 'asistente_bodega')->firstOrFail();
        $ayudante = Role::with('defaultSecurityLevel')->where('key', 'ayudante_general_bodega')->firstOrFail();
        $transporte = Role::with('defaultSecurityLevel')->where('key', 'transporte')->firstOrFail();
        $liderBodega = Role::with('defaultSecurityLevel')->where('key', 'lider_bodega')->firstOrFail();

        $this->assertSame(3, $asistente->defaultSecurityLevel->level);
        $this->assertSame(3, $ayudante->defaultSecurityLevel->level);
        $this->assertSame(3, $transporte->defaultSecurityLevel->level);
        $this->assertSame(4, $liderBodega->defaultSecurityLevel->level, 'El Jefe de Bodega sigue en Nivel 4, sin cambios.');
    }

    public function test_gerencia_restringida_aprueba_solo_ventas_diseno(): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $levelId = DB::table("{$slug}_auth.security_levels")->where('level', 8)->value('id');

        $rows = DB::table("{$slug}_auth.security_level_module_permissions")
            ->where('security_level_id', $levelId)
            ->get();

        $this->assertCount(7, $rows);
        $this->assertTrue($rows->every(fn ($r) => (bool) $r->can_edit === true));
        $this->assertTrue(
            $rows->where('module', 'ventas_diseno')->first()->can_approve,
            'Gerencia Restringida (Whil) debe poder aprobar en Ventas & Diseño.',
        );
        $this->assertSame(
            1,
            $rows->where('can_approve', true)->count(),
            'Gerencia Restringida no debe poder aprobar fuera de Ventas & Diseño.',
        );
    }
}
