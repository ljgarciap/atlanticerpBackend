<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 26b — Contabilidad «Solo ver, no mover» en el Pipeline (decisión de Luis, 2026-11-04). El demo ya no siembra
 * `ventas_diseno.pipeline.edit` para ese rol; esta migración lo quita de las cuentas YA sembradas con el rol `lider_admin_contab`.
 * Solo toca `extra_permissions` de ese rol: una concesión individual a cualquier otra persona no se modifica, y el permiso
 * sigue existiendo en el catálogo para que el superadmin lo conceda donde haga falta.
 * `down()` no restaura nada: no se sabe a quién se le había concedido a propósito.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    private const KEY = 'ventas_diseno.pipeline.edit';

    public function up(): void
    {
        $roleId = DB::table($this->table('roles'))->where('key', 'lider_admin_contab')->value('id');
        if ($roleId === null) {
            return;
        }

        $users = DB::table($this->table('users'))->where('role_id', $roleId)->whereNotNull('extra_permissions')->get(['id', 'extra_permissions']);
        foreach ($users as $user) {
            /** @var list<string> $extra */
            $extra = json_decode((string) $user->extra_permissions, true) ?? [];
            if (! in_array(self::KEY, $extra, true)) {
                continue;
            }
            DB::table($this->table('users'))->where('id', $user->id)
                ->update(['extra_permissions' => json_encode(array_values(array_diff($extra, [self::KEY])))]);
        }
    }

    public function down(): void
    {
        // Intencionalmente vacío: ver el docblock.
    }
};
