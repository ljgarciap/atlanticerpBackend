<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 30 (decisión 4, grupo 3) — la carga por técnico y la comisión del técnico las ven el Líder de Servicios y
 * Gerencia; el vendedor y el resto de quienes ven Servicios dejan de verlas. Permiso nuevo de rol completo
 * `servicios.reports.team` (Líder y Gerencia) y `servicios.technicians.commission.view_own` también para el Líder.
 *
 * Chequeo de fuga: el JSONB de `security_levels.permissions` no se filtra por visibilidad de módulo; se verifica que
 * ningún otro rol comparta el nivel por defecto de `lider_servicios`/`management` antes de escribir ahí.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    private const TEAM = 'servicios.reports.team';

    private const COMMISSION = 'servicios.technicians.commission.view_own';

    /** @var array<string, list<string>> rol → permisos a conceder */
    private const GRANTS = [
        'lider_servicios' => [self::TEAM, self::COMMISSION],
        'management'      => [self::TEAM],
    ];

    public function up(): void
    {
        DB::table($this->table('permission_catalog'))->insertOrIgnore([
            'key' => self::TEAM, 'module' => 'servicios', 'label' => 'Ver carga por técnico y comisión del equipo (Líder de Servicios y Gerencia)',
            'sort_order' => 48, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

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

            $this->updateLevel((int) $role->default_security_level_id, fn (array $c): array => array_values(array_unique([...$c, ...$keys])));
        }
    }

    public function down(): void
    {
        foreach (self::GRANTS as $roleKey => $keys) {
            $role = DB::table($this->table('roles'))->where('key', $roleKey)->first();
            if ($role === null || $role->default_security_level_id === null) {
                continue;
            }

            // La comisión del Líder es nueva; la de Gerencia ya existía y no se toca.
            $this->updateLevel((int) $role->default_security_level_id, fn (array $c): array => array_values(array_diff($c, $keys)));
        }

        DB::table($this->table('permission_catalog'))->where('key', self::TEAM)->delete();
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
