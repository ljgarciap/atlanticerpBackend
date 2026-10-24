<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1139 — el líder de operaciones (`lider_operaciones`, Lider Operaciones Díaz) accede a Administración & Contabilidad EXACTAMENTE
 * como la asistente administrativa (`asistente_administrativa`, Asistente Administrativa): mismos grupos de ruta, mismas exclusiones deliberadas
 * (posición de caja REAL y su exportación, reporte de comisión de Contabilidad, grupos `primary_approver_only`) y mismo recorte de
 * `cash-position/projected` (solo totales).
 */
class OperacionesAdminContMirrorTest extends FeatureTestCase
{
    private const OPERACIONES = 'lideroperaciones@test.com';
    private const YANETH      = 'asistenteadministrativa@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @return array<string, string> */
    private function headersFor(string $email, string $role): array
    {
        $headers = $this->authHeaders($this->loginAs($email, $email, $role));
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        return $headers;
    }

    /** @return list<string> roles del `role:` de una ruta, o [] si no tiene */
    private static function rolesDeLaRuta(LaravelRoute $route): array
    {
        foreach ($route->gatherMiddleware() as $mw) {
            if (is_string($mw) && str_starts_with($mw, 'role:')) {
                return explode(',', substr($mw, 5));
            }
        }

        return [];
    }

    /**
     * En binax esto se verificaba leyendo las listas `role:` de cada ruta. En Atlantic las rutas de Admin&Cont usan
     * permisos finos (Fase 2), así que el espejo se verifica sobre los permisos efectivos: Operaciones tiene todo lo
     * de la asistente en records/operations y NO gana `oversight.manage` (Contabilidad + Gerencia).
     */
    public function test_operaciones_espeja_a_la_asistente_en_permisos_de_admin_cont(): void
    {
        $asistente = User::where('email', 'asistenteadministrativa@test.com')->firstOrFail();
        $operaciones = User::where('email', 'lideroperaciones@test.com')->firstOrFail();

        foreach (['admin_contab.records.manage', 'admin_contab.operations.manage'] as $permiso) {
            $this->assertContains($permiso, $asistente->effectivePermissionsV2(), "la asistente debe tener {$permiso}");
            $this->assertContains($permiso, $operaciones->effectivePermissionsV2(), "operaciones debe espejar {$permiso}");
        }
        $this->assertNotContains('admin_contab.oversight.manage', $operaciones->effectivePermissionsV2());
        $this->assertNotContains('admin_contab.oversight.manage', $asistente->effectivePermissionsV2());
    }

    public function test_los_grupos_primary_approver_only_no_incluyen_a_operaciones(): void
    {
        $markOnly = 0;
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/admin-contab')) {
                continue;
            }
            if (in_array('primary_approver_only', $route->gatherMiddleware(), true)) {
                $markOnly++;
                $this->assertNotContains('lider_operaciones', self::rolesDeLaRuta($route), "{$route->uri()} es primary_approver_only.");
            }
        }

        $this->assertGreaterThan(0, $markOnly);
    }

    public function test_operaciones_llega_a_cobros_y_a_la_posicion_de_caja_proyectada_recortada(): void
    {
        $headers = $this->headersFor(self::OPERACIONES, 'lider_operaciones');

        $this->withHeaders($headers)->getJson('/api/admin-contab/payments/summary')->assertOk();

        $this->withHeaders($headers)->getJson('/api/admin-contab/cash-position/projected?window=30')
            ->assertOk()->assertJsonPath('entradas', [])->assertJsonPath('salidas', []);
    }

    public function test_las_exclusiones_deliberadas_siguen_vigentes_para_operaciones_y_para_la_asistente(): void
    {
        foreach ([[self::OPERACIONES, 'lider_operaciones'], [self::YANETH, 'asistente_administrativa']] as [$email, $role]) {
            $headers = $this->headersFor($email, $role);

            $this->withHeaders($headers)->getJson('/api/admin-contab/cash-position/real?window=30')->assertStatus(403);
            $this->withHeaders($headers)->getJson('/api/admin-contab/reports/felix-commission')->assertStatus(403);
        }
    }

    public function test_operaciones_ve_el_modulo_admin_cont_como_contabilidad_sin_cambiar_sus_otros_modulos(): void
    {
        $operaciones = User::where('email', self::OPERACIONES)->firstOrFail();
        $modulos     = $operaciones->modulesPayload();

        $this->assertTrue($modulos['admin_contab']['view']);
        $this->assertTrue($modulos['admin_contab']['view_team']);
        // La edición de módulo depende del nivel de seguridad (1 = solo ver): no se toca. Las rutas de Admin&Cont las abre el rol.
        $this->assertFalse($modulos['admin_contab']['edit']);
        $this->assertTrue($modulos['compras']['view']);
        $this->assertTrue($modulos['operaciones']['view']);
        foreach (['ventas_diseno', 'bodega', 'servicios', 'gerencia'] as $otro) {
            $this->assertFalse($modulos[$otro]['view'], "{$otro} no debe cambiar para operaciones");
        }
        $this->assertContains('admin_contab.edit.receptor_fiscal', $operaciones->extra_permissions ?? []);
        $this->assertContains('compras.sustitutos.manage', $operaciones->extra_permissions ?? []);
        $this->assertContains('compras.limited.view', $operaciones->extra_permissions ?? []);
    }
}
