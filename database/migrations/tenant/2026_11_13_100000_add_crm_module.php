<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 19 (docs/specs/fase19-modulo-crm.md) — el CRM pasa a ser un módulo propio (`modules.key = 'crm'`) con visibilidad por rol.
 *
 * Antes `crm.read/write/edit/delete` vivían en el escape hatch de TODOS los niveles y `crm` no era módulo, así que cualquier rol llegaba al CRM por API.
 * Ahora `/api/crm/*` exige `crm.view` (la matriz gruesa del módulo, que sí se filtra por `role_module_visibility`).
 *
 * Siembra la visibilidad COPIANDO la de `ventas_diseno` (Vendedor, Gerencia, Líder de Operaciones…): no cambia quién ve el CRM en el menú, que ya lo
 * trata como parte de Ventas & Diseño. Contabilidad y Servicios no entran por defecto (decisión de Luis); el superadmin se lo concede en Ajustes → Roles.
 * Las excepciones POR USUARIO de Ventas & Diseño (`user_module_visibility`) NO se copian: son concesiones personales para ese módulo (p. ej. Contabilidad)
 * y el CRM se concede aparte, por rol o por usuario, en Ajustes. Idempotente.
 * `down()` borra el módulo y sus filas de visibilidad.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        $auth = Tenant::current()?->slug.'_auth';
        $now = now();

        if (! DB::table("{$auth}.modules")->where('key', 'crm')->exists()) {
            DB::table("{$auth}.modules")->insert([
                'key' => 'crm', 'name' => 'CRM', 'is_system' => true, 'is_active' => true,
                'sort_order' => (int) DB::table("{$auth}.modules")->max('sort_order') + 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach (DB::table("{$auth}.role_module_visibility")->where('module', 'ventas_diseno')->get() as $fila) {
            $existe = DB::table("{$auth}.role_module_visibility")->where('role_id', $fila->role_id)->where('module', 'crm')->exists();
            if ($existe) {
                continue;
            }
            $copia = (array) $fila;
            unset($copia['id']);
            DB::table("{$auth}.role_module_visibility")->insert(array_merge($copia, ['module' => 'crm']));
        }
    }

    public function down(): void
    {
        $auth = Tenant::current()?->slug.'_auth';
        DB::table("{$auth}.user_module_visibility")->where('module', 'crm')->delete();
        DB::table("{$auth}.role_module_visibility")->where('module', 'crm')->delete();
        DB::table("{$auth}.modules")->where('key', 'crm')->delete();
    }
};
