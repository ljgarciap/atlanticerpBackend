<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-006 — corrección 2026-07-10 (daily con Luis). Las migraciones 2026_07_17_100001-100003
 * habían creado 4 `security_levels` NUEVOS (11-14, "Gerencia Total/Restringida", "Editor sin
 * aprobar", "Solo Ver") para servir de default a los 13 roles de negocio. Luis corrigió el
 * diseño: los niveles 1-10 YA SON la escala de capacidad (10 = todo, 1 = solo lectura,
 * intermedios asignados según se necesite) — los roles son solo visibilidad de módulo, no deben
 * generar niveles nuevos. Mapeo confirmado por Luis:
 *   Gerencia Total (Mark/David/Daniela)     -> Nivel 9  (Admin)           [ya es su nivel actual]
 *   Gerencia Restringida (Whil)             -> Nivel 8  (Senior Manager)
 *   Editor sin aprobar (Felix/Esteban/...)  -> Nivel 4  (Power User)
 *   Solo Ver (Yaneth/técnicos/Mariam/...)   -> Nivel 1  (Read Only)
 *
 * Esta migración corrige tanto instalaciones frescas (donde 100001-100003 ya crearon 11-14 unos
 * segundos antes, en la misma corrida) como entornos ya desplegados (dev.atlanticerp.ai, donde
 * 100001-100003 ya corrieron en un deploy anterior) — no se edita el historial de esas 3
 * migraciones, se corrige hacia adelante, mismo patrón que el rename de rol
 * (2026_07_18_100001).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    private const MODULES = [
        'ventas_diseno', 'compras', 'bodega', 'servicios', 'admin_contab', 'gerencia', 'operaciones',
    ];

    /** @var array<string, int> rol key => nivel existente (1-10) */
    private array $defaults = [
        'management'               => 9,
        'lider_admin_contab'       => 4,
        'asistente_administrativa' => 1,
        'lider_compras'            => 1,
        'lider_bodega'             => 4,
        'asistente_bodega'         => 1,
        'ayudante_general_bodega'  => 1,
        'transporte'               => 1,
        'lider_servicios'          => 4,
        'tecnico_servicios'        => 1,
        'garantias_servicios'      => 1,
        'vendedor_disenador'       => 4,
        'lider_operaciones'        => 1,
    ];

    private const WHIL_EMAIL = 'whil@illuminations.com.pa';

    public function up(): void
    {
        $rolesTable   = $this->table('roles');
        $levelsTable  = $this->table('security_levels');
        $matrixTable  = $this->table('security_level_module_permissions');
        $usersTable   = $this->table('users');

        $oldLevelIds = DB::table($levelsTable)->whereIn('level', [11, 12, 13, 14])->pluck('id', 'level');
        $newLevelIds = DB::table($levelsTable)->whereIn('level', [1, 4, 8, 9])->pluck('id', 'level');

        // 1) Remapear CUALQUIER usuario ya apuntando a un nivel viejo (ej. en un entorno donde
        //    BusinessRoleUserSeeder ya corrió con el diseño anterior -- no solo Whil: los otros
        //    3 arquetipos también asignaban 13/14 como default a los 25 usuarios de negocio) al
        //    nivel nuevo equivalente. Genérico por diseño -- necesario antes del paso 4 (delete),
        //    que si no violaría el NOT NULL de users.security_level_id.
        $levelRemap = [11 => 9, 12 => 8, 13 => 4, 14 => 1];
        foreach ($levelRemap as $oldLevel => $newLevel) {
            if (! isset($oldLevelIds[$oldLevel], $newLevelIds[$newLevel])) {
                continue;
            }
            DB::table($usersTable)
                ->where('security_level_id', $oldLevelIds[$oldLevel])
                ->update(['security_level_id' => $newLevelIds[$newLevel]]);
        }

        // 2) Repuntar los defaults de rol de los niveles viejos a los existentes.
        foreach ($this->defaults as $roleKey => $level) {
            if (! isset($newLevelIds[$level])) {
                continue;
            }
            DB::table($rolesTable)->where('key', $roleKey)->update(['default_security_level_id' => $newLevelIds[$level]]);
        }

        // 3) Reseed de security_level_module_permissions sobre los niveles existentes.
        DB::table($matrixTable)->whereIn('security_level_id', $oldLevelIds->values()->all())->delete();

        $rows = [];
        foreach (self::MODULES as $module) {
            if (isset($newLevelIds[9])) {
                $rows[] = ['security_level_id' => $newLevelIds[9], 'module' => $module, 'can_edit' => true, 'can_approve' => true];
            }
            if (isset($newLevelIds[8])) {
                $rows[] = ['security_level_id' => $newLevelIds[8], 'module' => $module, 'can_edit' => true, 'can_approve' => $module === 'ventas_diseno'];
            }
            if (isset($newLevelIds[4])) {
                $rows[] = ['security_level_id' => $newLevelIds[4], 'module' => $module, 'can_edit' => true, 'can_approve' => false];
            }
            if (isset($newLevelIds[1])) {
                $rows[] = ['security_level_id' => $newLevelIds[1], 'module' => $module, 'can_edit' => false, 'can_approve' => false];
            }
        }
        if ($rows !== []) {
            DB::table($matrixTable)->insert($rows);
        }

        // 4) Los niveles 11-14 ya no tienen ningún default ni usuario apuntándolos -- eliminarlos.
        DB::table($levelsTable)->whereIn('level', [11, 12, 13, 14])->delete();
    }

    public function down(): void
    {
        $rolesTable  = $this->table('roles');
        $levelsTable = $this->table('security_levels');
        $matrixTable = $this->table('security_level_module_permissions');
        $usersTable  = $this->table('users');
        $now         = now();

        DB::table($levelsTable)->insert([
            ['level' => 11, 'name' => 'Gerencia Total', 'description' => 'Crear-Editar y Aprobar en los 7 módulos. Mark, David, Daniela.', 'permissions' => json_encode([]), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['level' => 12, 'name' => 'Gerencia Restringida', 'description' => 'Crear-Editar en los 7 módulos; Aprobar solo en Ventas & Diseño.', 'permissions' => json_encode([]), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['level' => 13, 'name' => 'Editor sin aprobar', 'description' => 'Crear-Editar en los 7 módulos, sin Aprobar.', 'permissions' => json_encode([]), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['level' => 14, 'name' => 'Solo Ver', 'description' => 'Sin Crear-Editar ni Aprobar en ningún módulo.', 'permissions' => json_encode([]), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $oldLevelIds = DB::table($levelsTable)->whereIn('level', [11, 12, 13, 14])->pluck('id', 'level');
        $newLevelIds = DB::table($levelsTable)->whereIn('level', [1, 4, 8, 9])->pluck('id', 'level');

        $rows = [];
        foreach (self::MODULES as $module) {
            $rows[] = ['security_level_id' => $oldLevelIds[11], 'module' => $module, 'can_edit' => true, 'can_approve' => true];
            $rows[] = ['security_level_id' => $oldLevelIds[12], 'module' => $module, 'can_edit' => true, 'can_approve' => $module === 'ventas_diseno'];
            $rows[] = ['security_level_id' => $oldLevelIds[13], 'module' => $module, 'can_edit' => true, 'can_approve' => false];
            $rows[] = ['security_level_id' => $oldLevelIds[14], 'module' => $module, 'can_edit' => false, 'can_approve' => false];
        }
        DB::table($matrixTable)->insert($rows);

        DB::table($matrixTable)->whereIn('security_level_id', $newLevelIds->values()->all())->delete();

        $reverseLevelForRole = ['management' => 11, 'lider_admin_contab' => 13, 'asistente_administrativa' => 14, 'lider_compras' => 14, 'lider_bodega' => 13, 'asistente_bodega' => 14, 'ayudante_general_bodega' => 14, 'transporte' => 14, 'lider_servicios' => 13, 'tecnico_servicios' => 14, 'garantias_servicios' => 14, 'vendedor_disenador' => 13, 'lider_operaciones' => 14];

        // Remapear usuarios de estos 13 roles DE VUELTA solo si su security_level_id coincide con
        // el nuevo default de su rol (la huella de que vino de BusinessRoleUserSeeder bajo el
        // diseño corregido) -- por role_id, no por nivel crudo, para no tocar fixtures no
        // relacionadas que comparten el mismo número de nivel (ej. 'multi@illuminations.test' en
        // Nivel 8 sin relación con Gerencia Restringida).
        $levelRemapReverse = [11 => 9, 12 => 8, 13 => 4, 14 => 1];
        foreach ($reverseLevelForRole as $roleKey => $oldLevel) {
            $newLevelNumber = $levelRemapReverse[$oldLevel];
            $roleId = DB::table($rolesTable)->where('key', $roleKey)->value('id');
            if ($roleId === null || ! isset($newLevelIds[$newLevelNumber], $oldLevelIds[$oldLevel])) {
                continue;
            }
            DB::table($usersTable)
                ->where('role_id', $roleId)
                ->where('security_level_id', $newLevelIds[$newLevelNumber])
                ->update(['security_level_id' => $oldLevelIds[$oldLevel]]);

            DB::table($rolesTable)->where('id', $roleId)->update(['default_security_level_id' => $oldLevelIds[$oldLevel]]);
        }

        // Whil: excepción individual (Gerencia Restringida), remapeada por email específicamente.
        if (isset($newLevelIds[8], $oldLevelIds[12])) {
            DB::table($usersTable)
                ->where('email', self::WHIL_EMAIL)
                ->where('security_level_id', $newLevelIds[8])
                ->update(['security_level_id' => $oldLevelIds[12]]);
        }
    }
};
