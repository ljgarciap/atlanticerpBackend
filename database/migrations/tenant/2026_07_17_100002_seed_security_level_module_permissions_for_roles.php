<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-006 — mapeo rol→nivel (2026-07-09). Siembra `security_level_module_permissions` para los
 * 4 niveles nuevos (2026_07_17_100001), que hasta ahora existía vacía a propósito (Fase A).
 *
 * Mismo catálogo de 7 módulos que `role_module_visibility` (Fase A, migración
 * 2026_07_11_100001) — incluye `gerencia`/`operaciones` aunque hoy solo los roles Gerencia y
 * Líder Operaciones tengan `can_view >= 2` ahí. No hace falta que las filas coincidan con lo que
 * cada rol ve: el algoritmo de resolución (Decisión 7) ya usa el rol como techo, así que una fila
 * "can_edit=true" en un módulo que ningún usuario de ese nivel puede ver nunca se traduce en un
 * permiso real — evita crear una fila distinta por combinación real rol×nivel.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    private const MODULES = [
        'ventas_diseno', 'compras', 'bodega', 'servicios', 'admin_contab', 'gerencia', 'operaciones',
    ];

    public function up(): void
    {
        $levelIds = DB::table($this->table('security_levels'))
            ->whereIn('level', [11, 12, 13, 14])
            ->pluck('id', 'level');

        $rows = [];

        foreach (self::MODULES as $module) {
            // Gerencia Total (11): Crear-Editar + Aprobar en todo.
            $rows[] = ['security_level_id' => $levelIds[11], 'module' => $module, 'can_edit' => true, 'can_approve' => true];

            // Gerencia Restringida (12, Whil): Crear-Editar en todo, Aprobar solo Ventas & Diseño.
            $rows[] = [
                'security_level_id' => $levelIds[12],
                'module'            => $module,
                'can_edit'          => true,
                'can_approve'       => $module === 'ventas_diseno',
            ];

            // Editor sin aprobar (13): Crear-Editar en todo, nunca Aprobar.
            $rows[] = ['security_level_id' => $levelIds[13], 'module' => $module, 'can_edit' => true, 'can_approve' => false];

            // Solo Ver (14): ninguna de las dos.
            $rows[] = ['security_level_id' => $levelIds[14], 'module' => $module, 'can_edit' => false, 'can_approve' => false];
        }

        DB::table($this->table('security_level_module_permissions'))->insert($rows);
    }

    public function down(): void
    {
        $levelIds = DB::table($this->table('security_levels'))
            ->whereIn('level', [11, 12, 13, 14])
            ->pluck('id');

        DB::table($this->table('security_level_module_permissions'))
            ->whereIn('security_level_id', $levelIds)
            ->delete();
    }
};
