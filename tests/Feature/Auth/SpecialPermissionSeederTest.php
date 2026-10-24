<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\PermissionCatalog;
use App\Shared\Multitenancy\Tenant;
use Database\Seeders\SpecialPermissionSeeder;
use Tests\Feature\FeatureTestCase;

/**
 * `database/seeders/SpecialPermissionSeeder.php` — genericizado (sesión 2026-08-30,
 * Atlantic/CLAUDE.md): el `GRANTS` real de este proyecto se vació a propósito (eran grants
 * atados a personas de un cliente anterior). Este test ya no puede verificar grants reales
 * (no hay ninguno declarado por default), así que cubre dos cosas: que el seeder es un no-op
 * seguro con `GRANTS` vacío, y que el mecanismo de fusión idempotente (el motivo real por el que
 * este seeder existe) sigue funcionando, vía una subclase de prueba que sí declara grants.
 */
class SpecialPermissionSeederTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_grants_vacio_por_default_no_modifica_a_nadie(): void
    {
        $gerencia = User::where('email', 'gerencia@test.com')->firstOrFail();
        $gerencia->update(['extra_permissions' => ['ventas_diseno.reports.configure']]);

        (new SpecialPermissionSeeder)->run();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $gerencia->refresh();
        $this->assertSame(['ventas_diseno.reports.configure'], $gerencia->extra_permissions ?? []);
    }

    public function test_merge_idempotente_no_pisa_extra_permissions_ya_existentes(): void
    {
        $gerencia = User::where('email', 'gerencia@test.com')->firstOrFail();
        $gerencia->update(['extra_permissions' => ['ventas_diseno.reports.configure']]);

        (new class extends SpecialPermissionSeeder
        {
            protected const GRANTS = [
                'gerencia@test.com' => ['ventas_diseno.override_min_margin', 'servicios.quotes.view_cost_breakdown'],
            ];
        })->run();
        // El seeder hace Tenant::forgetCurrent() en su finally al terminar — restablecer
        // el tenant antes de seguir usando Eloquent en el resto del test.
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $gerencia->refresh();
        $this->assertContains('ventas_diseno.reports.configure', $gerencia->extra_permissions ?? []);
        $this->assertContains('ventas_diseno.override_min_margin', $gerencia->extra_permissions ?? []);
        $this->assertContains('servicios.quotes.view_cost_breakdown', $gerencia->extra_permissions ?? []);
        $this->assertCount(3, $gerencia->extra_permissions ?? []);
    }

    public function test_merge_es_idempotente_al_correr_dos_veces(): void
    {
        $seeder = new class extends SpecialPermissionSeeder
        {
            protected const GRANTS = [
                'gerencia@test.com' => ['bodega.read'],
            ];
        };

        $seeder->run();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $primera = User::where('email', 'gerencia@test.com')->firstOrFail()->extra_permissions ?? [];
        $seeder->run();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $segunda = User::where('email', 'gerencia@test.com')->firstOrFail()->extra_permissions ?? [];

        // La base de tests ya trae las concesiones de FixtureSpecialPermissionSeeder: lo que importa es que
        // el merge agrega `bodega.read` una sola vez y que correr dos veces no cambia nada.
        $this->assertSame(1, count(array_keys($primera, 'bodega.read', true)));
        $this->assertSame($primera, $segunda);
    }

    public function test_usuario_no_encontrado_no_rompe_la_corrida(): void
    {
        $seeder = new class extends SpecialPermissionSeeder
        {
            protected const GRANTS = [
                'no-existe@test.com' => ['bodega.read'],
            ];
        };

        $seeder->run();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $this->assertNull(User::where('email', 'no-existe@test.com')->first());
    }

    public function test_contabilidad_pasa_a_pedido_y_ve_el_pipeline_en_solo_lectura(): void
    {
        // SCRUM-1128 — grants angostos por persona, no de rol.
        $contabilidad = User::where('email', 'contabilidad@test.com')->firstOrFail();

        $this->assertContains('ventas_diseno.pass_to_order', $contabilidad->extra_permissions ?? []);
        $this->assertContains('ventas_diseno.pipeline.read_all', $contabilidad->extra_permissions ?? []);
        // Nunca los permisos de escritura del módulo.
        $this->assertNotContains('ventas_diseno.edit', $contabilidad->extra_permissions ?? []);
        $this->assertNotContains('ventas_diseno.write', $contabilidad->extra_permissions ?? []);
    }

    /**
     * SCRUM-1140 (Pre-QA M-A) — todo permiso que el seeder concede está en `permission_catalog`. `extra_permissions.*` se valida
     * contra el catálogo activo: una clave que no esté ahí hace que guardar el formulario de usuarios de quien ya la tiene dé 422 y
     * que nadie más pueda recibirla desde la pantalla. Falla si alguien agrega un grant al seeder sin su migración de catálogo.
     */
    public function test_todo_permiso_que_concede_el_seeder_esta_en_el_catalogo(): void
    {
        $catalogo = PermissionCatalog::activeKeys();
        $fuera = [];

        foreach (User::query()->get(['email', 'extra_permissions']) as $user) {
            foreach ($user->extra_permissions ?? [] as $permiso) {
                if (! in_array($permiso, $catalogo, true)) {
                    $fuera[$permiso][] = $user->email;
                }
            }
        }

        $this->assertSame([], $fuera, 'Permisos concedidos por el seeder que faltan en permission_catalog: '.json_encode($fuera));
    }

    public function test_1139_contabilidad_edita_el_pipeline_y_1150_replica_los_permisos_de_mark(): void
    {
        $extra = User::where('email', 'contabilidad@test.com')->firstOrFail()->extra_permissions ?? [];

        $this->assertContains('ventas_diseno.pipeline.edit', $extra);
        $this->assertContains('ventas_diseno.edit.approved', $extra);
        $this->assertContains('ventas_diseno.pipeline.read_all', $extra); // se conserva
        $this->assertContains('ventas_diseno.pass_to_order', $extra);     // se conserva
        // SCRUM-1150 (decisión de Luis, 2026-10-15): Contabilidad replica a Gerencia; antes de esa fecha estos dos estaban excluidos.
        $this->assertContains('ventas_diseno.override_min_margin', $extra);
        $this->assertContains('ventas_diseno.select_partner_price', $extra);
        // El permiso amplio de escritura del módulo sigue sin concederse: solo el angosto del Pipeline.
        $this->assertNotContains('ventas_diseno.edit', $extra);
        $this->assertNotContains('ventas_diseno.write', $extra);
    }

    public function test_1139_la_asistente_y_operaciones_replican_receptor_fiscal_de_contabilidad(): void
    {
        $asistente = User::where('email', 'asistenteadministrativa@test.com')->firstOrFail();
        $operaciones = User::where('email', 'lideroperaciones@test.com')->firstOrFail();

        $this->assertContains('admin_contab.edit.receptor_fiscal', $asistente->extra_permissions ?? []);
        $this->assertContains('admin_contab.edit.receptor_fiscal', $operaciones->extra_permissions ?? []);
        $this->assertContains('compras.sustitutos.manage', $operaciones->extra_permissions ?? []); // se conserva
        $this->assertContains('compras.limited.view', $operaciones->extra_permissions ?? []);       // se conserva
        // `receptor_consumidor_final` es de SCRUM-1140 (otro equipo): no se concede acá.
        $this->assertNotContains('admin_contab.edit.receptor_consumidor_final', $asistente->extra_permissions ?? []);
        $this->assertNotContains('admin_contab.edit.receptor_consumidor_final', $operaciones->extra_permissions ?? []);
    }

    public function test_1139_aprobador_no_cambia(): void
    {
        $approver = User::where('email', 'gerencia3@test.com')->firstOrFail();

        $this->assertNotContains('ventas_diseno.pipeline.edit', $approver->extra_permissions ?? []);
    }

    public function test_1139_el_seeder_es_idempotente_y_no_pisa_extras_ajenos(): void
    {
        $asistente = User::where('email', 'asistenteadministrativa@test.com')->firstOrFail();
        $asistente->update(['extra_permissions' => ['reports.export']]);

        $this->seed(\Tests\Support\FixtureSpecialPermissionSeeder::class);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $primera = $asistente->refresh()->extra_permissions ?? [];
        $this->seed(\Tests\Support\FixtureSpecialPermissionSeeder::class);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $segunda = $asistente->refresh()->extra_permissions ?? [];

        $this->assertContains('reports.export', $primera);
        $this->assertContains('admin_contab.edit.receptor_fiscal', $primera);
        $this->assertSame($primera, $segunda);
    }
}
