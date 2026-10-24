<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 1 del aislamiento del núcleo (docs/specs/fase1-limpieza-nucleo.md, bloque D) — las rutas dejan de decidir por NOMBRE de rol.
 *
 * Dos permisos de ROL COMPLETO, deliberadamente FUERA de `permission_catalog`: así `extra_permissions.*` (validado contra el
 * catálogo) nunca puede concedérselos a una persona suelta, que era justo la garantía de SCRUM-1012 («un grant individual de
 * security.users ya no alcanza»). Solo llegan por el nivel de seguridad del rol:
 *   - `platform.management`            → rol base de Gerencia (administración de usuarios/niveles/departamentos, dashboard CRM).
 *   - `ventas_diseno.orders.access`    → Gerencia y Compras (pantalla Pedidos de Ventas & Diseño).
 * `superadmin` entra siempre por `superadmin.all` (CheckPermission). El rol «solo superadmin» pasa a `permission:superadmin.all`.
 *
 * Chequeo de fuga (regla de la Fase 2): el JSONB de `security_levels.permissions` no se filtra por visibilidad de módulo, así que
 * se verifica que ningún otro rol comparta el nivel por defecto de `management`/`lider_compras` antes de escribir ahí.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /** @var array<string, list<string>> role.key => permisos de rol completo (fuera del catálogo) */
    private const GRANTS = [
        'management'   => ['platform.management', 'ventas_diseno.orders.access'],
        'lider_compras' => ['ventas_diseno.orders.access'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $roleKey => $keys) {
            $role = DB::table($this->table('roles'))->where('key', $roleKey)->first();
            if ($role === null || $role->default_security_level_id === null) {
                continue;
            }

            $sharedBy = DB::table($this->table('roles'))
                ->where('default_security_level_id', $role->default_security_level_id)
                ->where('key', '!=', $roleKey)
                ->count();
            if ($sharedBy > 0) {
                throw new RuntimeException("El nivel de «{$roleKey}» lo comparte otro rol: darle un nivel propio antes de escribir permisos finos (ver 2026_08_30_100004).");
            }

            $level = DB::table($this->table('security_levels'))->where('id', $role->default_security_level_id)->first();
            /** @var list<string> $current */
            $current = json_decode($level->permissions, true) ?? [];
            DB::table($this->table('security_levels'))->where('id', $level->id)->update([
                'permissions' => json_encode(array_values(array_unique(array_merge($current, $keys)))),
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::GRANTS as $roleKey => $keys) {
            $role = DB::table($this->table('roles'))->where('key', $roleKey)->first();
            if ($role === null || $role->default_security_level_id === null) {
                continue;
            }
            $level = DB::table($this->table('security_levels'))->where('id', $role->default_security_level_id)->first();
            /** @var list<string> $current */
            $current = json_decode($level->permissions, true) ?? [];
            DB::table($this->table('security_levels'))->where('id', $level->id)->update([
                'permissions' => json_encode(array_values(array_diff($current, $keys))),
            ]);
        }
    }
};
