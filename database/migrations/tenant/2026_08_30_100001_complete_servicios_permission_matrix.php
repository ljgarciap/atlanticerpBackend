<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 2 de la auditoría del esquema de permisos (Atlantic/CLAUDE.md, sesión 2026-08-30) —
 * reemplaza los `role:clave1,clave2,...` hardcodeados de routes/servicios.php por permisos finos
 * asignados por NIVEL (no por persona vía extra_permissions — ver docblock de
 * SpecialPermissionSeeder, ese mecanismo es para excepciones nombradas por ticket, esto es
 * capacidad de rol completo, debe generalizar a cualquiera que entre a ese rol después).
 *
 * Hallazgo 1 (el que motivó el nivel de garantías): `tecnico_servicios` y `garantias_servicios`
 * comparten el nivel 5 ("Team Lead") hoy, pero 2 de las 8 combinaciones de rol encontradas en
 * servicios.php excluyen a garantías (claim-sheet, informe de inspección en blanco). Nivel 11
 * nuevo ("Team Lead — Garantías"), clonado del 5.
 *
 * Hallazgo 2 (encontrado DESPUÉS, corriendo la suite de AdminCont — no la de Servicios, que no lo
 * detectó): `lider_servicios` comparte el nivel 4 ("Power User") con OTROS 3 roles líder de otros
 * módulos (`lider_admin_contab`, `lider_compras`, `lider_bodega`). El escape hatch JSONB
 * (`security_levels.permissions`, leído por `User::plainEscapeHatchPermissions()`) se une al
 * payload de permisos SIN pasar por el filtro de `role_module_visibility` que sí protege a la
 * matriz gruesa (`{module}.view/edit/approve`, ver `effectivePermissionsV2()`) — a diferencia de
 * esa matriz, un permiso puesto en el JSONB de un nivel compartido se filtra a TODOS los roles de
 * ese nivel, sin importar qué módulo ven. Poner los permisos finos de Servicios en el nivel 4 tal
 * cual le daba `servicios.tickets.manage` (etc.) a `lider_compras`/`lider_bodega` también — un
 * lider_compras real jamás debería poder editar tickets de Servicios. Nivel 14 nuevo
 * ("Power User — Servicios"), clonado del 4, exclusivo de `lider_servicios` — el nivel 4 original
 * queda sin ningún permiso fino de Servicios, solo lo usan lider_compras/lider_bodega/
 * lider_admin_contab (este último con su propio nivel aparte, ver migración 2026_08_30_100002).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /** @var list<array{key: string, label: string, sort_order: int, levels: list<int>}> */
    private array $permissions = [
        [
            'key' => 'servicios.settings.configure', 'sort_order' => 40,
            'label' => 'Configurar Ajustes de Servicios (SLA, meta de instalaciones)',
            'levels' => [9], // management
        ],
        [
            'key' => 'servicios.tickets.create', 'sort_order' => 41,
            'label' => 'Crear tickets de Servicios y adjuntar archivos de referencia',
            'levels' => [14, 6, 9], // lider_servicios (nivel propio), vendedor_disenador, management
        ],
        [
            'key' => 'servicios.tickets.manage', 'sort_order' => 42,
            'label' => 'Editar/agendar/cancelar tickets, gestionar Herramientas e Insumos (Líder de Servicios)',
            'levels' => [14], // lider_servicios exclusivo
        ],
        [
            'key' => 'servicios.technicians.commission.view_own', 'sort_order' => 43,
            'label' => 'Ver la propia comisión de técnico (o la de cualquiera, Gerencia)',
            'levels' => [5, 9, 11], // tecnico_servicios, management, garantias_servicios
        ],
        [
            'key' => 'servicios.field_work.edit', 'sort_order' => 44,
            'label' => 'Editar Informe de Inspección y Cotización de Servicio',
            'levels' => [14, 5, 11], // lider_servicios, tecnico_servicios, garantias_servicios
        ],
        [
            'key' => 'servicios.claim_sheets.manage', 'sort_order' => 45,
            'label' => 'Editar Hoja de Reclamo y ver Informe de Inspección en blanco (sin Garantías)',
            'levels' => [14, 5], // lider_servicios, tecnico_servicios — NO garantias_servicios
        ],
        [
            'key' => 'servicios.external_technicians.view', 'sort_order' => 46,
            'label' => 'Ver el directorio de Técnicos Externos',
            'levels' => [14, 5, 9, 11],
        ],
        [
            'key' => 'servicios.field_ops.admin', 'sort_order' => 47,
            'label' => 'Administrar Técnicos Externos, captura de comisión y movimientos de Herramientas',
            'levels' => [14, 9], // lider_servicios, management
        ],
    ];

    public function up(): void
    {
        // 1a) Nivel nuevo para garantias_servicios, clonado del nivel 5 (mismo permissions JSONB
        //     genérico, misma fila de capacidad servicios.can_edit) — separa su capacidad de la
        //     de tecnico_servicios sin tocar el nivel 5 original.
        $level5 = DB::table($this->table('security_levels'))->where('level', 5)->first();
        $garantiasLevelId = DB::table($this->table('security_levels'))->insertGetId([
            'level'       => 11,
            'name'        => 'Team Lead — Garantías',
            'description' => 'Igual que Team Lead, con la capacidad angosta de Servicios propia de Garantías (sin Hoja de Reclamo).',
            'permissions' => $level5->permissions,
            'is_active'   => true,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        DB::table($this->table('security_level_module_permissions'))->insert([
            'security_level_id' => $garantiasLevelId,
            'module'            => 'servicios',
            'can_edit'          => true,
            'can_approve'       => false,
        ]);

        DB::table($this->table('roles'))
            ->where('key', 'garantias_servicios')
            ->update(['default_security_level_id' => $garantiasLevelId]);

        DB::table($this->table('users'))
            ->whereIn('role_id', DB::table($this->table('roles'))->where('key', 'garantias_servicios')->pluck('id'))
            ->update(['security_level_id' => $garantiasLevelId]);

        // 1b) Nivel nuevo para lider_servicios, clonado del nivel 4 — el nivel 4 original lo
        //     comparten lider_compras/lider_bodega (y lider_admin_contab hasta la migración
        //     siguiente), así que ningún permiso fino de Servicios puede vivir ahí sin filtrarse
        //     a esos otros roles (ver Hallazgo 2 arriba).
        $level4 = DB::table($this->table('security_levels'))->where('level', 4)->first();
        $liderServiciosLevelId = DB::table($this->table('security_levels'))->insertGetId([
            'level'       => 14,
            'name'        => 'Power User — Servicios',
            'description' => 'Igual que Power User, con la capacidad de Servicios propia del Líder de Servicios (no compartida con otros líderes de módulo).',
            'permissions' => $level4->permissions,
            'is_active'   => true,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        foreach (DB::table($this->table('security_level_module_permissions'))->where('security_level_id', $level4->id)->get() as $row) {
            DB::table($this->table('security_level_module_permissions'))->insert([
                'security_level_id' => $liderServiciosLevelId,
                'module'            => $row->module,
                'can_edit'          => $row->can_edit,
                'can_approve'       => $row->can_approve,
            ]);
        }

        DB::table($this->table('roles'))
            ->where('key', 'lider_servicios')
            ->update(['default_security_level_id' => $liderServiciosLevelId]);

        DB::table($this->table('users'))
            ->whereIn('role_id', DB::table($this->table('roles'))->where('key', 'lider_servicios')->pluck('id'))
            ->update(['security_level_id' => $liderServiciosLevelId]);

        // 2) Catálogo de permisos finos nuevos.
        $now = now();
        foreach ($this->permissions as $p) {
            DB::table($this->table('permission_catalog'))->insert([
                'key' => $p['key'], 'label' => $p['label'], 'module' => 'servicios',
                'sort_order' => $p['sort_order'], 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // 3) Asignar cada permiso a los niveles que corresponden — fusiona con el JSONB existente
        //    de cada nivel, nunca lo reemplaza (mismo criterio idempotente que SpecialPermissionSeeder).
        //    Nota: 4 (el nivel compartido con lider_compras/lider_bodega) NO recibe NADA de esto.
        $levelIds = [$liderServiciosLevelId, 5, 6, 9, $garantiasLevelId];
        foreach ($levelIds as $levelId) {
            $level = DB::table($this->table('security_levels'))->where('id', $levelId)->first();
            /** @var list<string> $current */
            $current = json_decode($level->permissions, true) ?? [];
            $toAdd = [];
            $levelNumber = $levelId === $liderServiciosLevelId ? 14 : ($levelId === $garantiasLevelId ? 11 : (int) $level->level);
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

        $level4 = DB::table($this->table('security_levels'))->where('level', 4)->first();
        $level5 = DB::table($this->table('security_levels'))->where('level', 5)->first();

        DB::table($this->table('users'))->where('security_level_id', function ($q) {
            $q->select('id')->from($this->table('security_levels'))->where('level', 14);
        })->update(['security_level_id' => $level4->id]);
        DB::table($this->table('roles'))->where('key', 'lider_servicios')->update(['default_security_level_id' => $level4->id]);

        DB::table($this->table('users'))->where('security_level_id', function ($q) {
            $q->select('id')->from($this->table('security_levels'))->where('level', 11);
        })->update(['security_level_id' => $level5->id]);
        DB::table($this->table('roles'))->where('key', 'garantias_servicios')->update(['default_security_level_id' => $level5->id]);

        DB::table($this->table('security_level_module_permissions'))
            ->where('security_level_id', function ($q) {
                $q->select('id')->from($this->table('security_levels'))->whereIn('level', [11, 14]);
            })->delete();
        DB::table($this->table('security_levels'))->whereIn('level', [11, 14])->delete();
    }
};
