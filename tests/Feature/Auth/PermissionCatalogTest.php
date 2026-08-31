<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Tests\Feature\FeatureTestCase;

class PermissionCatalogTest extends FeatureTestCase
{
    // ── GET /api/permission-catalog ───────────────────────────────────────────

    public function test_permission_catalog_retorna_catalogo_agrupado_con_42_permisos(): void
    {
        // sprint2: compras.settings.configure (SCRUM-183→196) agrega el módulo `compras` nuevo.
        // SCRUM-500: bodega.settings.configure agrega el módulo `bodega` nuevo.
        // SCRUM-440 (Bloque B3 Zona Libre de Colón): compras.zona-libre.approve, 2do permiso de `compras`.
        // SCRUM-725: ventas_diseno.override_min_margin, 12mo permiso de `ventas_diseno`.
        // Batch 11 (SCRUM-292→297): servicios.quotes.view_cost_breakdown agrega el módulo `servicios` nuevo.
        // SCRUM-771: compras.sustitutos.manage, 3er permiso de `compras`.
        // SCRUM-771 (corrección 2026-08-18): compras.limited.view, 4to permiso de `compras`.
        // Batch 14 Admin&Cont (2026-08-25): admin_contab.commissions_internal.view agrega el
        // módulo `admin_contab` nuevo (mismo patrón que compras.limited.view/SCRUM-771 — ver
        // 2026_08_25_100004_add_admin_contab_commissions_internal_view_permission.php).
        // Fase 2 de la auditoría de permisos (2026-08-30, ver Atlantic/CLAUDE.md): 8 permisos
        // finos nuevos de `servicios` (2026_08_30_100001) + 3 de `admin_contab`
        // (2026_08_30_100002), reemplazando los `role:` hardcodeados de esos 2 archivos de rutas.
        // Fase 3 (2026-08-30): crm.projects.delete (2026_08_30_100003), 5to permiso de `crm`.
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/permission-catalog');

        $response->assertStatus(200);
        $response->assertJsonStructure(['catalog']);

        $catalog = (array) $response->json('catalog');

        $this->assertCount(11, $catalog, 'El catálogo debe tener exactamente 11 módulos');

        $this->assertArrayHasKey('crm', $catalog);
        $this->assertArrayHasKey('reports', $catalog);
        $this->assertArrayHasKey('ai', $catalog);
        $this->assertArrayHasKey('security', $catalog);
        $this->assertArrayHasKey('settings', $catalog);
        $this->assertArrayHasKey('superadmin', $catalog);
        $this->assertArrayHasKey('ventas_diseno', $catalog);
        $this->assertArrayHasKey('compras', $catalog);
        $this->assertArrayHasKey('bodega', $catalog);
        $this->assertArrayHasKey('servicios', $catalog);
        $this->assertArrayHasKey('admin_contab', $catalog);

        $this->assertCount(5, $catalog['crm'], 'crm debe tener 5 permisos');
        $this->assertCount(2, $catalog['reports'], 'reports debe tener 2 permisos');
        $this->assertCount(1, $catalog['ai'], 'ai debe tener 1 permiso');
        $this->assertCount(2, $catalog['security'], 'security debe tener 2 permisos');
        $this->assertCount(1, $catalog['settings'], 'settings debe tener 1 permiso');
        $this->assertCount(1, $catalog['superadmin'], 'superadmin debe tener 1 permiso');
        $this->assertCount(12, $catalog['ventas_diseno'], 'ventas_diseno debe tener 12 permisos');
        $this->assertCount(4, $catalog['compras'], 'compras debe tener 4 permisos');
        $this->assertCount(1, $catalog['bodega'], 'bodega debe tener 1 permiso');
        $this->assertCount(9, $catalog['servicios'], 'servicios debe tener 9 permisos');
        $this->assertCount(4, $catalog['admin_contab'], 'admin_contab debe tener 4 permisos');

        $totalPermisos = array_sum(array_map('count', $catalog));
        $this->assertSame(42, $totalPermisos, 'El total de permisos en el catálogo debe ser 42');

        $crmKeys = array_column((array) $catalog['crm'], 'key');
        $this->assertContains('crm.read', $crmKeys);
        $this->assertContains('crm.write', $crmKeys);
        $this->assertContains('crm.edit', $crmKeys);
        $this->assertContains('crm.delete', $crmKeys);
        $this->assertContains('crm.projects.delete', $crmKeys);

        $firstCrm = $catalog['crm'][0];
        $this->assertArrayHasKey('key', $firstCrm);
        $this->assertArrayHasKey('label', $firstCrm);
        $this->assertArrayHasKey('description', $firstCrm);
    }

    public function test_permission_catalog_sin_rol_management_retorna_403(): void
    {
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/permission-catalog')
            ->assertStatus(403);
    }
}
