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
 * Roles reales usados (BusinessRoleUserSeeder/CoreUserSeeder): lider_admin_contab=Contabilidad,
 * asistente_administrativa=Asistente Administrativa, management=Gerencia, lider_operaciones=Lider Operaciones (comparte nivel
 * "Read Only" original con Asistente Administrativa ANTES de esta migración — verifica que no se afectó).
 */
class AdminContPermissionMatrixTest extends FeatureTestCase
{
    private const SUPERADMIN = 'superadmin@test.com';

    private const FELIX = 'contabilidad@test.com';

    private const YANETH = 'asistenteadministrativa@test.com';

    private const DANIELA = 'gerencia@test.com';

    private const MARIAM = 'lideroperaciones@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function tokenFor(string $email, string $roleKey): string
    {
        return $this->loginAs($email, $email, $roleKey);
    }

    // ── admin_contab.records.manage — Contabilidad + Asistente Administrativa + Gerencia, los 3 pueden ────

    public function test_bank_accounts_store_contabilidad_asistente_y_gerencia_pueden(): void
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

    // ── admin_contab.operations.manage — Contabilidad + Asistente Administrativa, NO Gerencia ─────────────

    public function test_retention_attachment_contabilidad_y_asistente_pueden_gerencia_no(): void
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

    public function test_daily_count_contabilidad_y_asistente_pueden_gerencia_no(): void
    {
        $this->withHeaders($this->authHeaders($this->tokenFor(self::DANIELA, 'management')))
            ->getJson('/api/admin-contab/cash-position/daily-count')
            ->assertStatus(403);

        $status = $this->withHeaders($this->authHeaders($this->tokenFor(self::YANETH, 'asistente_administrativa')))
            ->getJson('/api/admin-contab/cash-position/daily-count')
            ->status();
        $this->assertNotSame(403, $status);
    }

    // ── payments/summary — SCRUM-881 (binax, 2026-09-09): la asistente entra al resumen de cobros, mismo alcance que
    //    Contabilidad; SCRUM-1139 (2026-10-14): operaciones espeja a la asistente. La ruta pasó a records.manage. ─────────────

    public function test_payments_summary_contabilidad_gerencia_asistente_y_operaciones_pueden(): void
    {
        foreach ([
            [self::FELIX, 'lider_admin_contab'],
            [self::DANIELA, 'management'],
            [self::YANETH, 'asistente_administrativa'],
            [self::MARIAM, 'lider_operaciones'],
        ] as [$email, $role]) {
            $status = $this->withHeaders($this->authHeaders($this->tokenFor($email, $role)))
                ->getJson('/api/admin-contab/payments/summary')
                ->status();
            $this->assertNotSame(403, $status, "{$email} no debería recibir 403 en payments/summary");
        }
    }

    public function test_cash_position_real_asistente_no_puede(): void
    {
        $this->withHeaders($this->authHeaders($this->tokenFor(self::YANETH, 'asistente_administrativa')))
            ->getJson('/api/admin-contab/cash-position/real')
            ->assertStatus(403);
    }

    // ── lider_operaciones (nivel propio «Read Only») espeja a la asistente (SCRUM-1139) pero NO gana el
    //    permiso de supervisión (oversight.manage: Contabilidad + Gerencia), que binax tampoco le da. ─────────────

    public function test_lider_operaciones_espeja_a_la_asistente_pero_no_gana_oversight(): void
    {
        $token = $this->tokenFor(self::MARIAM, 'lider_operaciones');

        $this->assertNotSame(403, $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/payments/summary')->status());
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/felix-commission')
            ->assertStatus(403);
    }

    // ── lider_servicios/lider_compras/lider_bodega (comparten nivel con lider_admin_contab ANTES
    //    de esta Fase 2) no deben ganar NADA de Admin&Cont — mismo bug real encontrado y
    //    corregido en la migración de Servicios, verificado también acá. ──────────────────────

    public function test_otros_lideres_no_ganan_ningun_permiso_de_admin_contab(): void
    {
        foreach ([
            ['lider_servicios', 'liderservicios@test.com'],
            ['lider_compras', 'lidercompras@test.com'],
            ['lider_bodega', 'liderbodega@test.com'],
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
