<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Sincronización con binax (snapshot 2026-10-22) — SCRUM-1013/1021 concedió `compras.logistica.view`
 * y `bodega.warehouses.view` a los 10 vendedores vía `SpecialPermissionSeeder` (escape hatch por
 * persona). Que LOS 10 lo tengan es capacidad de ROL COMPLETO, no excepción individual (mismo
 * hallazgo que 2026_08_30_100004): `SpecialPermissionSeeder::GRANTS` queda vacío en Atlantic, así
 * que sin esta migración un vendedor nuevo no vería Logística ni «Ver bodegas».
 *
 * Chequeo de fuga: `vendedor_disenador` es el único rol en el Nivel 6 ("Supervisor") — el JSONB
 * de `security_levels.permissions` no se filtra por `role_module_visibility`, así que se
 * verificó que ningún otro rol comparta ese nivel antes de escribir ahí.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /** @var list<string> */
    private const KEYS = ['compras.logistica.view', 'bodega.warehouses.view'];

    public function up(): void
    {
        $level = DB::table($this->table('security_levels'))->where('level', 6)->first();
        if ($level === null) {
            return;
        }

        $sharedBy = DB::table($this->table('roles'))
            ->where('default_security_level_id', $level->id)
            ->where('key', '!=', 'vendedor_disenador')
            ->count();
        if ($sharedBy > 0) {
            throw new RuntimeException('El Nivel 6 lo comparte otro rol: crear un nivel propio para vendedor_disenador antes de conceder permisos finos (ver 2026_08_30_100004).');
        }

        /** @var list<string> $current */
        $current = json_decode($level->permissions, true) ?? [];
        DB::table($this->table('security_levels'))->where('id', $level->id)->update([
            'permissions' => json_encode(array_values(array_unique(array_merge($current, self::KEYS)))),
        ]);
    }

    public function down(): void
    {
        $level = DB::table($this->table('security_levels'))->where('level', 6)->first();
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
