<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * `database/seeders/SpecialPermissionSeeder.php` — SCRUM-77 (REQ-008): Mark es la única persona
 * de negocio que el criterio nombra para editar proyectos Aprobado, vía
 * `ventas_diseno.edit.approved`. Antes de este seeder nadie tenía esa clave concedida.
 */
class SpecialPermissionSeederTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_mark_tiene_el_permiso_de_editar_aprobado(): void
    {
        $mark = User::where('email', 'mbekhar@atlantic.com.pa')->firstOrFail();

        $this->assertContains('ventas_diseno.edit.approved', $mark->extra_permissions ?? []);
    }

    public function test_mark_y_david_tienen_la_excepcion_de_margen_minimo(): void
    {
        // SCRUM-725 — el ticket nombra a Mark y David explícitamente por email para la
        // excepción de margen mínimo (30%) en cotizaciones.
        $mark = User::where('email', 'mbekhar@atlantic.com.pa')->firstOrFail();
        $david = User::where('email', 'david@grupolafayette.com')->firstOrFail();

        $this->assertContains('ventas_diseno.override_min_margin', $mark->extra_permissions ?? []);
        $this->assertContains('ventas_diseno.override_min_margin', $david->extra_permissions ?? []);
    }

    public function test_yirena_tiene_bodega_read_para_llegar_a_zona_libre(): void
    {
        // SCRUM-440 — sin esto, RequirePermission("bodega.read") en el frontend la redirige al
        // dashboard antes de poder llegar a /bodega/ordenes-zona-libre a aprobar/rechazar.
        $yirena = User::where('email', 'gerencia2@atlantic.com.pa')->firstOrFail();

        $this->assertContains('bodega.read', $yirena->extra_permissions ?? []);
        $this->assertContains('compras.zona-libre.approve', $yirena->extra_permissions ?? []);
    }

    public function test_el_seeder_no_pisa_extra_permissions_ya_existentes(): void
    {
        // Mark ya tiene otros 2 grants propios (select_partner_price/edit.conditions,
        // REQ-034/046) — este test verifica que un extra_permissions ajeno al catálogo de
        // GRANTS (ej. asignado a mano desde la pantalla de administración) sobrevive la corrida.
        $mark = User::where('email', 'mbekhar@atlantic.com.pa')->firstOrFail();
        $mark->update(['extra_permissions' => ['ventas_diseno.reports.configure']]);

        (new \Database\Seeders\SpecialPermissionSeeder())->run();
        // El seeder hace Tenant::forgetCurrent() en su finally al terminar — restablecer
        // el tenant antes de seguir usando Eloquent en el resto del test.
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $mark->refresh();
        $this->assertContains('ventas_diseno.reports.configure', $mark->extra_permissions ?? []);
        $this->assertContains('ventas_diseno.edit.approved', $mark->extra_permissions ?? []);
        $this->assertContains('ventas_diseno.select_partner_price', $mark->extra_permissions ?? []);
        $this->assertContains('ventas_diseno.edit.conditions', $mark->extra_permissions ?? []);
        // SCRUM-725 — override_min_margin, 5to grant de Mark.
        $this->assertContains('ventas_diseno.override_min_margin', $mark->extra_permissions ?? []);
        // Batch 11 (SCRUM-295) — servicios.quotes.view_cost_breakdown, 6to grant de Mark.
        $this->assertContains('servicios.quotes.view_cost_breakdown', $mark->extra_permissions ?? []);
        $this->assertCount(6, $mark->extra_permissions ?? []);
    }

    public function test_aaron_mark_david_whil_tienen_el_desglose_de_costo_de_cotizacion(): void
    {
        // SCRUM-295 (REQ-232) — desglose de costo real/margen del ítem Subcontratado, personas
        // puntuales nombradas por el ticket (no un rol).
        $aaron = User::where('email', 'servicio@atlantic.com.pa')->firstOrFail();
        $mark  = User::where('email', 'mbekhar@atlantic.com.pa')->firstOrFail();
        $david = User::where('email', 'david@grupolafayette.com')->firstOrFail();
        $whil  = User::where('email', 'whil@atlantic.com.pa')->firstOrFail();

        foreach ([$aaron, $mark, $david, $whil] as $user) {
            $this->assertContains('servicios.quotes.view_cost_breakdown', $user->extra_permissions ?? []);
        }
    }
}
