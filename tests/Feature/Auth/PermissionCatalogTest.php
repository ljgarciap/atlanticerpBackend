<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\PermissionCatalog;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

class PermissionCatalogTest extends FeatureTestCase
{
    // ── GET /api/permission-catalog ───────────────────────────────────────────

    /**
     * El endpoint devuelve el catálogo de la BASE (agrupado por módulo), sin conteos fijos a mano: un permiso nuevo ya no obliga a tocar este test.
     * Lo que sí se fija son los módulos y las claves de las que depende el resto del sistema.
     */
    public function test_permission_catalog_retorna_el_catalogo_agrupado_por_modulo(): void
    {
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/permission-catalog');

        $response->assertStatus(200);
        $response->assertJsonStructure(['catalog']);

        $catalog = (array) $response->json('catalog');

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $esperado = PermissionCatalog::where('is_active', true)->get()->groupBy('module');

        $this->assertEqualsCanonicalizing($esperado->keys()->all(), array_keys($catalog), 'los módulos del endpoint son los de la tabla');
        foreach ($esperado as $modulo => $filas) {
            $this->assertEqualsCanonicalizing(
                $filas->pluck('key')->all(),
                array_column((array) $catalog[$modulo], 'key'),
                "los permisos de {$modulo} son los de la tabla",
            );
        }
        $this->assertGreaterThan(0, array_sum(array_map('count', $catalog)));

        foreach (['crm', 'reports', 'ai', 'security', 'settings', 'superadmin', 'ventas_diseno', 'compras', 'bodega', 'servicios', 'admin_contab'] as $modulo) {
            $this->assertArrayHasKey($modulo, $catalog);
        }

        $crmKeys = array_column((array) $catalog['crm'], 'key');
        foreach (['crm.read', 'crm.write', 'crm.edit', 'crm.delete', 'crm.projects.delete'] as $clave) {
            $this->assertContains($clave, $crmKeys);
        }

        $firstCrm = $catalog['crm'][0];
        $this->assertArrayHasKey('key', $firstCrm);
        $this->assertArrayHasKey('label', $firstCrm);
        $this->assertArrayHasKey('description', $firstCrm);
    }

    public function test_permission_catalog_sin_rol_management_retorna_403(): void
    {
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

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
