<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 2 de la auditoría del esquema de permisos (Atlantic/CLAUDE.md, sesión 2026-08-30),
 * segunda parte — reemplaza los `role:clave1,clave2,...` de routes/admin-contab.php por 3
 * permisos finos asignados por security_level (ver migración 2026_08_30_100002). Este test
 * verifica que la frontera real de acceso se mantiene idéntica a la de los `role:` que reemplaza.
 *
 * Roles reales usados (BusinessRoleUserSeeder/CoreUserSeeder): lider_admin_contab=Felix,
 * asistente_administrativa=Yaneth, management=Daniela, lider_operaciones=Mariam (comparte nivel
 * "Read Only" original con Yaneth ANTES de esta migración — verifica que no se afectó).
 */
class AdminContPermissionMatrixTest extends FeatureTestCase
{
    private const SUPERADMIN = 'andres.loi@atlantic.com.pa';

    private const FELIX = 'conta@atlantic.com.pa';

    private const YANETH = 'asistente@atlantic.com.pa';

    private const DANIELA = 'daniela@atlantic.com.pa';

    private const MARIAM = 'operaciones@atlantic.com.pa';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function tokenFor(string $email, string $roleKey): string
    {
        return $this->loginAs($email, $email, $roleKey);
    }

    // ── admin_contab.records.manage — Felix + Yaneth + Daniela, los 3 pueden ────

    public function test_bank_accounts_store_felix_yaneth_y_daniela_pueden(): void
    {
        foreach ([
            [self::FELIX, 'lider_admin_contab'],
            [self::YANETH, 'asistente_administrativa'],
            [self::DANIELA, 'management'],
        ] as [$email, $role]) {
            $status = $this->withHeaders($this->authHeaders($this->tokenFor($email, $role)))
                ->postJson('/api/admin-contab/bank-accounts', ['nombre' => 'Cuenta Test', 'banco' => 'Banco X'])
                ->status();
            $this->assertNotSame(403, $status, "{$email} no debería recibir 403 al crear cuenta bancaria");
        }
    }

    // ── admin_contab.operations.manage — Felix + Yaneth, NO Daniela ─────────────

    public function test_retention_attachment_felix_y_yaneth_pueden_daniela_no(): void
    {
        $this->withHeaders($this->authHeaders($this->tokenFor(self::DANIELA, 'management')))
            ->postJson('/api/admin-contab/payments/1/retention-attachment', [])
            ->assertStatus(403);

        foreach ([[self::FELIX, 'lider_admin_contab'], [self::YANETH, 'asistente_administrativa']] as [$email, $role]) {
            $status = $this->withHeaders($this->authHeaders($this->tokenFor($email, $role)))
                ->postJson('/api/admin-contab/payments/1/retention-attachment', [])
                ->status();
            $this->assertNotSame(403, $status, "{$email} no debería recibir 403 en retention-attachment");
        }
    }

    public function test_daily_count_felix_y_yaneth_pueden_daniela_no(): void
    {
        $this->withHeaders($this->authHeaders($this->tokenFor(self::DANIELA, 'management')))
            ->getJson('/api/admin-contab/cash-position/daily-count')
            ->assertStatus(403);

        $status = $this->withHeaders($this->authHeaders($this->tokenFor(self::YANETH, 'asistente_administrativa')))
            ->getJson('/api/admin-contab/cash-position/daily-count')
            ->status();
        $this->assertNotSame(403, $status);
    }

    // ── admin_contab.oversight.manage — Felix + Daniela, NO Yaneth ──────────────

    public function test_payments_summary_felix_y_daniela_pueden_yaneth_no(): void
    {
        $this->withHeaders($this->authHeaders($this->tokenFor(self::YANETH, 'asistente_administrativa')))
            ->getJson('/api/admin-contab/payments/summary')
            ->assertStatus(403);

        foreach ([[self::FELIX, 'lider_admin_contab'], [self::DANIELA, 'management']] as [$email, $role]) {
            $status = $this->withHeaders($this->authHeaders($this->tokenFor($email, $role)))
                ->getJson('/api/admin-contab/payments/summary')
                ->status();
            $this->assertNotSame(403, $status, "{$email} no debería recibir 403 en payments/summary");
        }
    }

    public function test_cash_position_real_yaneth_no_puede(): void
    {
        $this->withHeaders($this->authHeaders($this->tokenFor(self::YANETH, 'asistente_administrativa')))
            ->getJson('/api/admin-contab/cash-position/real')
            ->assertStatus(403);
    }

    // ── lider_operaciones (compartía nivel "Read Only" con Yaneth antes de la migración) sigue
    //    sin ningún acceso de escritura — la separación de niveles no le dio nada de más. ─────

    public function test_lider_operaciones_no_gana_ningun_permiso_de_admin_contab(): void
    {
        $token = $this->tokenFor(self::MARIAM, 'lider_operaciones');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/bank-accounts', ['nombre' => 'X'])
            ->assertStatus(403);
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/payments/summary')
            ->assertStatus(403);
    }

    // ── lider_servicios/lider_compras/lider_bodega (comparten nivel con lider_admin_contab ANTES
    //    de esta Fase 2) no deben ganar NADA de Admin&Cont — mismo bug real encontrado y
    //    corregido en la migración de Servicios, verificado también acá. ──────────────────────

    public function test_otros_lideres_no_ganan_ningun_permiso_de_admin_contab(): void
    {
        foreach ([
            ['lider_servicios', 'servicio@atlantic.com.pa'],
            ['lider_compras', 'gerencia2@atlantic.com.pa'],
            ['lider_bodega', 'almacen@atlantic.com.pa'],
        ] as [$role, $email]) {
            $token = $this->tokenFor($email, $role);
            $this->withHeaders($this->authHeaders($token))
                ->postJson('/api/admin-contab/bank-accounts', ['nombre' => 'X'])->assertStatus(403);
            $this->withHeaders($this->authHeaders($token))
                ->getJson('/api/admin-contab/payments/summary')->assertStatus(403);
        }
    }

    // ── superadmin siempre pasa ───────────────────────────────────────────────────

    public function test_superadmin_pasa_los_3_permisos_finos(): void
    {
        $token = $this->loginAs(self::SUPERADMIN, self::SUPERADMIN);

        $this->withHeaders($this->authHeaders($token))->getJson('/api/admin-contab/payments/summary')->assertStatus(200);
        $this->withHeaders($this->authHeaders($token))->getJson('/api/admin-contab/cash-position/daily-count')->assertStatus(200);
    }
}
