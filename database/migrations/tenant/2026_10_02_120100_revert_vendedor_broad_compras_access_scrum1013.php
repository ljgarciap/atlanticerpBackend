<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1013 (rebote de Daniela, 2026-10-02) — revierte lo que 2026_10_01_110000 le dio a
 * Vendedor/Diseñador y que fue más de lo pedido:
 *
 *  1. La fila `role_module_visibility(compras, VIEW_READONLY)` activaba `compras.view`, y con eso el
 *     Sidebar mostraba TODO el menú de Compras. Antes de 1013 el vendedor no tenía módulo Compras:
 *     solo veía Inventario y Comparación de Referencias (por ventas_diseno). Se borra esa fila.
 *  2. `compras.limited.view` (permiso de Operaciones) en los 10 vendedores: hace que Inventario
 *     deje el modo restringido y devuelva costo y margen. Se reemplaza por `compras.logistica.view`
 *     (solo Logística y Envío), que agrega SpecialPermissionSeeder. Como el seeder solo suma, la
 *     remoción tiene que hacerla esta migración.
 *
 * Solo toca a los 10 vendedores de 1013 y la fila exacta que 1013 insertó; Operaciones conserva su
 * `compras.limited.view`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    private const ROLE_KEY = 'vendedor_disenador';

    private const PERMISSION = 'compras.limited.view';

    /** @var list<string> */
    private const VENDEDORES = [
        'vendedordisenador@test.com', 'vendedordisenador2@test.com', 'vendedordisenador3@test.com',
        'vendedordisenador4@test.com', 'vendedordisenador5@test.com', 'vendedordisenador6@test.com',
        'vendedordisenador7@test.com', 'vendedordisenador8@test.com', 'vendedordisenador9@test.com',
        'vendedordisenador10@test.com',
    ];

    public function up(): void
    {
        $roleId = DB::table($this->table('roles'))->where('key', self::ROLE_KEY)->value('id');

        if ($roleId !== null) {
            // Solo la fila exacta que 1013 creó (READONLY, sin vista de equipo): un ajuste manual
            // posterior desde el panel de administración no se borra.
            DB::table($this->table('role_module_visibility'))
                ->where('role_id', $roleId)->where('module', 'compras')
                ->where('can_view', 1)->where('can_view_team', false)
                ->delete();
        }

        $this->mapPermissions(fn (array $perms): array => array_values(array_filter($perms, fn (string $p): bool => $p !== self::PERMISSION)));
    }

    public function down(): void
    {
        $roleId = DB::table($this->table('roles'))->where('key', self::ROLE_KEY)->value('id');

        if ($roleId !== null
            && ! DB::table($this->table('role_module_visibility'))->where('role_id', $roleId)->where('module', 'compras')->exists()) {
            DB::table($this->table('role_module_visibility'))->insert([
                'role_id' => $roleId, 'module' => 'compras', 'can_view' => 1, 'can_view_team' => false,
            ]);
        }

        $this->mapPermissions(fn (array $perms): array => array_values(array_unique([...$perms, self::PERMISSION])));
    }

    /** @param callable(array<int, string>): array<int, string> $transform */
    private function mapPermissions(callable $transform): void
    {
        $users = $this->table('users');

        foreach (DB::table($users)->whereIn('email', self::VENDEDORES)->get(['id', 'extra_permissions']) as $row) {
            /** @var array<int, string> $current */
            $current = json_decode((string) $row->extra_permissions, true) ?: [];
            $next    = $transform($current);

            if ($next !== $current) {
                sort($next);
                DB::table($users)->where('id', $row->id)->update(['extra_permissions' => json_encode($next)]);
            }
        }
    }
};
