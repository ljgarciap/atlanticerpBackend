<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\PermissionCatalog;
use App\Shared\Multitenancy\Tenant;
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
        // REQ-635 (SCRUM-811, 2026-09-01): admin_contab.settings.configure, 2do permiso de
        // `admin_contab` (umbrales de alertas de fraude, ver AdminContSettingsController).
        // SCRUM-1140 (2026-10-14): admin_contab.edit.receptor_fiscal y admin_contab.edit.receptor_consumidor_final,
        // 3ro y 4to de `admin_contab` (permisos angostos de quien factura, asignables desde la pantalla de usuarios).
        // SCRUM-1166 (2026-10-19): admin_contab.edit.client_itbms_exemption, 5to de `admin_contab` (marcar a un cliente fiscal como exento de ITBMS).
        // SCRUM-1140 (Pre-QA M-A, 2026-10-14): ventas_diseno.edit.client_tax_data (15to de `ventas_diseno`) y bodega.read (3ro de
        // `bodega`): se concedían por seeder sin estar en el catálogo, y guardar a quien ya los tenía daba 422.
        // SCRUM-1013 (rebote) / SCRUM-1021 (2026-10-02): compras.logistica.view (5to de `compras`) y
        // bodega.warehouses.view (2do de `bodega`), permisos angostos de consulta de los vendedores.
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
        $this->assertCount(17, $catalog['ventas_diseno'], 'ventas_diseno debe tener 17 permisos'); // SCRUM-1150: + gerencia_scope
        $this->assertCount(5, $catalog['compras'], 'compras debe tener 5 permisos');
        $this->assertCount(3, $catalog['bodega'], 'bodega debe tener 3 permisos');
        $this->assertCount(9, $catalog['servicios'], 'servicios debe tener 9 permisos'); // 1 de binax + 8 finos de la Fase 2 de Atlantic
        $this->assertCount(8, $catalog['admin_contab'], 'admin_contab debe tener 8 permisos'); // 5 de binax + 3 finos de la Fase 2 de Atlantic; // SCRUM-1166: + edit.client_itbms_exemption

        $totalPermisos = array_sum(array_map('count', $catalog));
        $this->assertSame(54, $totalPermisos, 'El total de permisos en el catálogo debe ser 54'); // SCRUM-1166: + admin_contab.edit.client_itbms_exemption

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

    /** SCRUM-1140 — los permisos de quien factura están en el catálogo, así que un superadmin puede asignarlos desde la UI. */
    public function test_los_permisos_de_quien_factura_estan_en_el_catalogo(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $claves = PermissionCatalog::activeKeys();

        $this->assertContains('admin_contab.edit.receptor_fiscal', $claves);
        $this->assertContains('admin_contab.edit.receptor_consumidor_final', $claves);
    }
}
