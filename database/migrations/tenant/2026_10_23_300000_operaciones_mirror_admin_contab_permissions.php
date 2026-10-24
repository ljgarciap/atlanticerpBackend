<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Sincronización con binax (snapshot 2026-10-22) — SCRUM-1139: «Operaciones espeja a la asistente» en los
 * visores de Admin&Cont. Las rutas de binax listan `lider_operaciones` junto a `asistente_administrativa` en
 * los grupos «amplio» y «sin management»; al pasar esos `role:` a permisos finos (Fase 2) el rol quedó
 * afuera. Es capacidad de ROL COMPLETO → va en su `security_level` (no en SpecialPermissionSeeder):
 *   - admin_contab.records.manage     (lider_admin_contab + asistente + management + operaciones)
 *   - admin_contab.operations.manage  (lider_admin_contab + asistente + operaciones, sin management)
 * NO recibe `admin_contab.oversight.manage` (binax tampoco lo incluye en esos grupos).
 *
 * Chequeo de fuga: el JSONB de `security_levels.permissions` no se filtra por visibilidad de módulo, así que
 * se verifica que ningún otro rol comparta el Nivel 1 antes de escribir ahí.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /** @var list<string> */
    private const KEYS = ['admin_contab.records.manage', 'admin_contab.operations.manage'];

    public function up(): void
    {
        $level = DB::table($this->table('security_levels'))->where('level', 1)->first();
        if ($level === null) {
            return;
        }

        $sharedBy = DB::table($this->table('roles'))
            ->where('default_security_level_id', $level->id)
            ->where('key', '!=', 'lider_operaciones')
            ->count();
        if ($sharedBy > 0) {
            throw new RuntimeException('El Nivel 1 lo comparte otro rol: crear un nivel propio para lider_operaciones antes de conceder permisos finos (ver 2026_08_30_100004).');
        }

        /** @var list<string> $current */
        $current = json_decode($level->permissions, true) ?? [];
        DB::table($this->table('security_levels'))->where('id', $level->id)->update([
            'permissions' => json_encode(array_values(array_unique(array_merge($current, self::KEYS)))),
        ]);
    }

    public function down(): void
    {
        $level = DB::table($this->table('security_levels'))->where('level', 1)->first();
        if ($level === null) {
            return;
        }

        /** @var list<string> $current */
        $current = json_decode($level->permissions, true) ?? [];
        DB::table($this->table('security_levels'))->where('id', $level->id)->update([
            'permissions' => json_encode(array_values(array_diff($current, self::KEYS))),
        ]);
    }
};
