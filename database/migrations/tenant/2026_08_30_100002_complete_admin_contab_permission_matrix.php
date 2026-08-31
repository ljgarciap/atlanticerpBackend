<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 2 de la auditoría del esquema de permisos (Atlantic/CLAUDE.md, sesión 2026-08-30),
 * segunda parte — mismo método que la migración de Servicios (2026_08_30_100001), aplicado a
 * routes/admin-contab.php: reemplaza los 24 usos de `role:clave1,clave2,...` (los `primary_approver_only` NO
 * se tocan, es un middleware propio ya correctamente acotado a una persona) por 3 permisos finos
 * asignados por SECURITY_LEVEL, no por lista de roles.
 *
 * Hallazgo 1: `asistente_administrativa` (Yaneth) comparte el nivel 1 ("Read Only") con
 * `lider_operaciones` — pero Yaneth necesita escribir en gran parte de Admin&Cont, mientras
 * Operaciones sí debe quedar en solo-lectura en todos los módulos. Nivel 13 nuevo
 * ("Read Only — Admin & Contab"), clonado del 1.
 *
 * Hallazgo 2 (el mismo que en la migración de Servicios, aplicado acá también):
 * `lider_admin_contab` comparte el nivel 4 ("Power User") con `lider_servicios`, `lider_compras` y
 * `lider_bodega` — el escape hatch JSONB no se filtra por `role_module_visibility` como sí lo hace
 * la matriz gruesa, así que poner los permisos finos de Admin&Cont en el nivel 4 tal cual se los
 * daría también a lider_compras/lider_bodega. Nivel 15 nuevo ("Power User — Admin & Contab"),
 * clonado del 4, exclusivo de `lider_admin_contab`.
 *
 * Los 3 permisos, derivados de las 3 combinaciones reales de rol encontradas en el archivo (16/5/3
 * ocurrencias, verificado por grep antes de diseñar esto):
 *   - records.manage    (16×, "amplio"): lider_admin_contab + asistente_administrativa + management
 *   - operations.manage  (5×, "sin management"): lider_admin_contab + asistente_administrativa
 *   - oversight.manage   (3×, "sin Yaneth"): lider_admin_contab + management
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /** @var list<array{key: string, label: string, sort_order: int, levels: list<int>}> */
    private array $permissions = [
        [
            'key' => 'admin_contab.records.manage', 'sort_order' => 30,
            'label' => 'Gestionar Facturación, Cobros, Notas de Crédito, Reportes y Cuentas Bancarias',
            'levels' => [15, 9, 13], // lider_admin_contab (nivel propio), management, asistente_administrativa
        ],
        [
            'key' => 'admin_contab.operations.manage', 'sort_order' => 31,
            'label' => 'Operar Cuentas por Cobrar, Comisiones Externas, Arqueo diario y Caja Chica (sin Gerencia)',
            'levels' => [15, 13], // lider_admin_contab, asistente_administrativa — NO management
        ],
        [
            'key' => 'admin_contab.oversight.manage', 'sort_order' => 32,
            'label' => 'Ver resumen de Cobros y Flujo de Caja detallado/real (sin Asistente Administrativa)',
            'levels' => [15, 9], // lider_admin_contab, management — NO asistente_administrativa
        ],
    ];

    public function up(): void
    {
        // 1a) Nivel nuevo para asistente_administrativa, clonado del nivel 1 ("Read Only") — separa
        //     su capacidad de la de lider_operaciones sin tocar el nivel 1 original.
        $level1 = DB::table($this->table('security_levels'))->where('level', 1)->first();
        $yanethLevelId = DB::table($this->table('security_levels'))->insertGetId([
            'level' => 13,
            'name' => 'Read Only — Admin & Contab',
            'description' => 'Igual que Read Only, con capacidad angosta de escritura en Administración y Contabilidad.',
            'permissions' => $level1->permissions,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ventas_diseno', 'compras', 'bodega', 'servicios', 'admin_contab', 'gerencia', 'operaciones'] as $module) {
            DB::table($this->table('security_level_module_permissions'))->insert([
                'security_level_id' => $yanethLevelId,
                'module' => $module,
                'can_edit' => false,
                'can_approve' => false,
            ]);
        }

        DB::table($this->table('roles'))
            ->where('key', 'asistente_administrativa')
            ->update(['default_security_level_id' => $yanethLevelId]);

        DB::table($this->table('users'))
            ->whereIn('role_id', DB::table($this->table('roles'))->where('key', 'asistente_administrativa')->pluck('id'))
            ->update(['security_level_id' => $yanethLevelId]);

        // 1b) Nivel nuevo para lider_admin_contab, clonado del nivel 4 — el nivel 4 original lo
        //     comparten lider_compras/lider_bodega (y lider_servicios, con su propio nivel nuevo
        //     desde la migración anterior), así que ningún permiso fino de Admin&Cont puede vivir
        //     ahí sin filtrarse a esos otros roles (ver Hallazgo 2 arriba).
        $level4 = DB::table($this->table('security_levels'))->where('level', 4)->first();
        $felixLevelId = DB::table($this->table('security_levels'))->insertGetId([
            'level' => 15,
            'name' => 'Power User — Admin & Contab',
            'description' => 'Igual que Power User, con la capacidad de Admin&Cont propia del Líder de Admin&Cont (no compartida con otros líderes de módulo).',
            'permissions' => $level4->permissions,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (DB::table($this->table('security_level_module_permissions'))->where('security_level_id', $level4->id)->get() as $row) {
            DB::table($this->table('security_level_module_permissions'))->insert([
                'security_level_id' => $felixLevelId,
                'module' => $row->module,
                'can_edit' => $row->can_edit,
                'can_approve' => $row->can_approve,
            ]);
        }

        DB::table($this->table('roles'))
            ->where('key', 'lider_admin_contab')
            ->update(['default_security_level_id' => $felixLevelId]);

        DB::table($this->table('users'))
            ->whereIn('role_id', DB::table($this->table('roles'))->where('key', 'lider_admin_contab')->pluck('id'))
            ->update(['security_level_id' => $felixLevelId]);

        // 2) Catálogo de permisos finos nuevos.
        $now = now();
        foreach ($this->permissions as $p) {
            DB::table($this->table('permission_catalog'))->insert([
                'key' => $p['key'], 'label' => $p['label'], 'module' => 'admin_contab',
                'sort_order' => $p['sort_order'], 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // 3) Asignar cada permiso a los niveles que corresponden — fusiona con el JSONB existente.
        //    Nota: 4 (el nivel compartido con lider_compras/lider_bodega) NO recibe NADA de esto.
        $levelIds = [$felixLevelId, 9, $yanethLevelId];
        foreach ($levelIds as $levelId) {
            $level = DB::table($this->table('security_levels'))->where('id', $levelId)->first();
            /** @var list<string> $current */
            $current = json_decode($level->permissions, true) ?? [];
            $toAdd = [];
            $levelNumber = $levelId === $felixLevelId ? 15 : ($levelId === $yanethLevelId ? 13 : (int) $level->level);
            foreach ($this->permissions as $p) {
                if (in_array($levelNumber, $p['levels'], true)) {
                    $toAdd[] = $p['key'];
                }
            }
            $merged = array_values(array_unique(array_merge($current, $toAdd)));
            DB::table($this->table('security_levels'))->where('id', $levelId)->update(['permissions' => json_encode($merged)]);
        }
    }

    public function down(): void
    {
        foreach ($this->permissions as $p) {
            DB::table($this->table('permission_catalog'))->where('key', $p['key'])->delete();
        }

        $level1 = DB::table($this->table('security_levels'))->where('level', 1)->first();
        $level4 = DB::table($this->table('security_levels'))->where('level', 4)->first();

        DB::table($this->table('users'))->where('security_level_id', function ($q) {
            $q->select('id')->from($this->table('security_levels'))->where('level', 13);
        })->update(['security_level_id' => $level1->id]);
        DB::table($this->table('roles'))->where('key', 'asistente_administrativa')->update(['default_security_level_id' => $level1->id]);

        DB::table($this->table('users'))->where('security_level_id', function ($q) {
            $q->select('id')->from($this->table('security_levels'))->where('level', 15);
        })->update(['security_level_id' => $level4->id]);
        DB::table($this->table('roles'))->where('key', 'lider_admin_contab')->update(['default_security_level_id' => $level4->id]);

        DB::table($this->table('security_level_module_permissions'))
            ->where('security_level_id', function ($q) {
                $q->select('id')->from($this->table('security_levels'))->whereIn('level', [13, 15]);
            })->delete();
        DB::table($this->table('security_levels'))->whereIn('level', [13, 15])->delete();
    }
};
