<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Scaffolding (Atlantic/CLAUDE.md, sesión 2026-08-30) — al vaciar `SpecialPermissionSeeder::GRANTS`
 * (genericización: el array real traía grants de personas específicas de un cliente anterior),
 * la suite de tests reveló que 3 de esos grants NO eran en realidad excepciones de una persona
 * puntual — eran capacidad de ROL COMPLETO implementada por error vía el escape hatch individual
 * (`extra_permissions`) en vez de vía `security_level` (mismo hallazgo de fondo que motivó la
 * Fase 2: "esto es capacidad de rol completo, debe generalizar a cualquiera que entre a ese rol
 * después" — ver docblock de 2026_08_30_100001):
 *   - `lider_compras`: compras.settings.configure, compras.zona-libre.approve, bodega.read
 *     ("es quien aprueba/rechaza las solicitudes que genera Bodega" — describe el ROL, no a la
 *     persona que lo ocupaba).
 *   - `lider_operaciones`: compras.sustitutos.manage, compras.limited.view (acceso a Comparación
 *     de Referencias — capacidad del rol, no de quien lo ocupaba).
 *   - `vendedor_disenador`: admin_contab.commissions_internal.view (LOS 10 vendedores lo tenían —
 *     evidencia clara de que nunca fue una excepción individual).
 *
 * Los grants genuinamente atados a UNA persona nombrada por un ticket específico (Precio Socio,
 * Condiciones, margen mínimo, desglose de costo) no se migran — esos sí quedan vacíos, es
 * exactamente lo que `SpecialPermissionSeeder` vaciado se propone dejar como mecanismo de ejemplo.
 *
 * Chequeo de fuga (regla dura de la Fase 2, repetida acá): `lider_compras` comparte hoy el Nivel 4
 * ("Power User") con `lider_bodega` — escribir estos 3 permisos ahí se filtraría a lider_bodega
 * (un lider_bodega real no debería poder aprobar Zona Libre ni configurar Compras). Se le da su
 * propio nivel (16, clonado del 4), mismo patrón que lider_servicios/lider_admin_contab en la
 * Fase 2. `lider_operaciones` (Nivel 1, "Read Only") y `vendedor_disenador` (Nivel 6,
 * "Supervisor") no comparten nivel con ningún otro rol hoy — verificado antes de escribir en su
 * JSONB, sin necesidad de nivel nuevo.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        // 1) Nivel nuevo para lider_compras, clonado del 4 — el nivel 4 original (lider_bodega)
        //    no recibe nada de esto.
        $level4 = DB::table($this->table('security_levels'))->where('level', 4)->first();
        $liderComprasLevelId = DB::table($this->table('security_levels'))->insertGetId([
            'level' => 16,
            'name' => 'Power User — Compras',
            'description' => 'Igual que Power User, con la capacidad de Compras propia del Líder de Compras (no compartida con Líder de Bodega).',
            'permissions' => json_encode(['compras.settings.configure', 'compras.zona-libre.approve', 'bodega.read']),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (DB::table($this->table('security_level_module_permissions'))->where('security_level_id', $level4->id)->get() as $row) {
            DB::table($this->table('security_level_module_permissions'))->insert([
                'security_level_id' => $liderComprasLevelId,
                'module' => $row->module,
                'can_edit' => $row->can_edit,
                'can_approve' => $row->can_approve,
            ]);
        }

        DB::table($this->table('roles'))
            ->where('key', 'lider_compras')
            ->update(['default_security_level_id' => $liderComprasLevelId]);

        DB::table($this->table('users'))
            ->whereIn('role_id', DB::table($this->table('roles'))->where('key', 'lider_compras')->pluck('id'))
            ->update(['security_level_id' => $liderComprasLevelId]);

        // 2) lider_operaciones (Nivel 1) y vendedor_disenador (Nivel 6) — ningún otro rol
        //    comparte esos niveles hoy, se fusiona directo sin nivel nuevo.
        $this->mergePermissions(1, ['compras.sustitutos.manage', 'compras.limited.view']);
        $this->mergePermissions(6, ['admin_contab.commissions_internal.view']);
    }

    /** @param list<string> $keys */
    private function mergePermissions(int $level, array $keys): void
    {
        $row = DB::table($this->table('security_levels'))->where('level', $level)->first();
        /** @var list<string> $current */
        $current = json_decode($row->permissions, true) ?? [];
        $merged = array_values(array_unique(array_merge($current, $keys)));
        DB::table($this->table('security_levels'))->where('id', $row->id)->update(['permissions' => json_encode($merged)]);
    }

    public function down(): void
    {
        $level4 = DB::table($this->table('security_levels'))->where('level', 4)->first();

        DB::table($this->table('users'))->where('security_level_id', function ($q) {
            $q->select('id')->from($this->table('security_levels'))->where('level', 16);
        })->update(['security_level_id' => $level4->id]);
        DB::table($this->table('roles'))->where('key', 'lider_compras')->update(['default_security_level_id' => $level4->id]);

        DB::table($this->table('security_level_module_permissions'))
            ->where('security_level_id', function ($q) {
                $q->select('id')->from($this->table('security_levels'))->where('level', 16);
            })->delete();
        DB::table($this->table('security_levels'))->where('level', 16)->delete();

        foreach ([
            [1, ['compras.sustitutos.manage', 'compras.limited.view']],
            [6, ['admin_contab.commissions_internal.view']],
        ] as [$level, $keys]) {
            $row = DB::table($this->table('security_levels'))->where('level', $level)->first();
            /** @var list<string> $current */
            $current = json_decode($row->permissions, true) ?? [];
            $remaining = array_values(array_diff($current, $keys));
            DB::table($this->table('security_levels'))->where('id', $row->id)->update(['permissions' => json_encode($remaining)]);
        }
    }
};
