<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\PermissionCatalog;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1139 — los grants angostos que `SpecialPermissionSeeder` asigna por persona deben estar en el catálogo de permisos;
 * si no, `Rule::in(PermissionCatalog::activeKeys())` responde 422 al guardar a esa persona desde la pantalla de usuarios.
 */
class Scrum1139PermissionCatalogTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_los_permisos_angostos_de_1139_estan_en_el_catalogo(): void
    {
        $activas = PermissionCatalog::activeKeys();

        foreach (['ventas_diseno.pipeline.edit', 'admin_contab.edit.receptor_fiscal', 'ventas_diseno.edit.client_tax_data'] as $clave) {
            $this->assertContains($clave, $activas, "{$clave} debe estar en el catálogo activo");
        }
    }

    /** @return list<array{0: string}> */
    public static function personas(): array
    {
        return [
            ['contabilidad@test.com'],
            ['asistenteadministrativa@test.com'],
            ['lideroperaciones@test.com'],
        ];
    }

    /** @dataProvider personas */
    public function test_un_superadmin_guarda_a_la_persona_con_sus_extra_permissions_actuales_sin_422(string $email): void
    {
        $persona = User::where('email', $email)->firstOrFail();
        $token   = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/users/{$persona->id}", ['extra_permissions' => array_values($persona->extra_permissions ?? [])])
            ->assertStatus(200);
    }

    public function test_el_down_solo_borra_lo_que_agrego_la_migracion(): void
    {
        $migracion = require base_path('database/migrations/tenant/2026_10_14_350000_add_pipeline_edit_and_receptor_permissions_to_catalog.php');

        $migracion->up(); // idempotente: segunda corrida no duplica ni falla
        $this->assertSame(1, PermissionCatalog::where('key', 'ventas_diseno.pipeline.edit')->count());

        $migracion->down();
        $this->assertSame(0, PermissionCatalog::where('key', 'ventas_diseno.pipeline.edit')->count());
        $this->assertContains('ventas_diseno.pass_to_order', PermissionCatalog::activeKeys()); // lo ajeno no se toca

        $migracion->up();
        $this->assertContains('ventas_diseno.pipeline.edit', PermissionCatalog::activeKeys());
    }
}
