<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 1b del aislamiento del núcleo (docs/specs/fase1b-frontend-bloque-d.md, §6.1) — el Kardex de herramientas
 * dejaba de gatearse por NOMBRE de rol en el frontend (`lider_servicios`, `management`, `superadmin`). Pasa a un
 * permiso de rol completo, asignado por `security_level` (superadmin entra por `superadmin.all`).
 *
 * Chequeo de fuga: el JSONB de `security_levels.permissions` no se filtra por visibilidad de módulo, así que se
 * verifica que ningún otro rol comparta el nivel por defecto de `lider_servicios`/`management` antes de escribir ahí.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    private const KEY = 'servicios.tools.kardex.view';

    /** @var list<string> */
    private const ROLES = ['lider_servicios', 'management'];

    public function up(): void
    {
        DB::table($this->table('permission_catalog'))->insertOrIgnore([
            'key' => self::KEY, 'module' => 'servicios', 'label' => 'Ver el Kardex de herramientas (Líder de Servicios y Gerencia)',
            'sort_order' => 47, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (self::ROLES as $roleKey) {
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

            $this->updateLevel((int) $role->default_security_level_id, fn (array $c): array => array_values(array_unique([...$c, self::KEY])));
        }
    }

    public function down(): void
    {
        foreach (self::ROLES as $roleKey) {
            $role = DB::table($this->table('roles'))->where('key', $roleKey)->first();
            if ($role === null || $role->default_security_level_id === null) {
                continue;
            }
            $this->updateLevel((int) $role->default_security_level_id, fn (array $c): array => array_values(array_diff($c, [self::KEY])));
        }

        DB::table($this->table('permission_catalog'))->where('key', self::KEY)->delete();
    }

    /** @param callable(list<string>): list<string> $change */
    private function updateLevel(int $levelId, callable $change): void
    {
        $level = DB::table($this->table('security_levels'))->where('id', $levelId)->first();
        /** @var list<string> $current */
        $current = json_decode($level->permissions, true) ?? [];
        DB::table($this->table('security_levels'))->where('id', $levelId)->update(['permissions' => json_encode($change($current))]);
    }
};
