<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
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
        $seeder->run();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $gerencia = User::where('email', 'gerencia@test.com')->firstOrFail();
        $this->assertSame(['bodega.read'], $gerencia->extra_permissions ?? []);
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
}
