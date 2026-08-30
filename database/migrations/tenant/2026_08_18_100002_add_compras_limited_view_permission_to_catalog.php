<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-771 (corrección 2026-08-18, hallazgo de validación visual en test.atlanticerp.ai) — la
 * migración 2026_08_18_100001 le dio a `lider_operaciones` `can_view=VIEW_FULL` en el módulo
 * `compras`, que en `User::effectivePermissionsV2()` emite `compras.read` SIN distinción de
 * pantalla — eso desbloqueaba TODO Compras (Inicio, Proveedores, Reportes, Agencias, Pagos,
 * Garantías), no solo las 4 pantallas que pide el ticket. El ocultamiento de `menuVisibility`
 * (SCRUM-724) es solo cosmético: saca el link del sidebar, nunca bloqueó la ruta real.
 *
 * Corrección real: `compras.limited.view` es un permiso angosto (mismo criterio que
 * `compras.zona-libre.approve`/`compras.sustitutos.manage`) que SOLO las rutas de Ver Órdenes/
 * Logística/Inventario/Ver Registros de Ingreso aceptan como alternativa a `compras.read` —
 * nunca sustituye `compras.read` en el resto de rutas de Compras (Proveedores, Inicio, Reportes,
 * Agencias, Pagos, Garantías siguen exigiendo el permiso completo, que este rol ya no tiene desde
 * la migración 2026_08_18_100003).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        DB::table($this->table('permission_catalog'))->insert([
            'key' => 'compras.limited.view', 'module' => 'compras',
            'label' => 'Ver Órdenes/Logística/Inventario/Registros de Ingreso (sin el resto de Compras)',
            'sort_order' => 74, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table($this->table('permission_catalog'))
            ->where('key', 'compras.limited.view')
            ->delete();
    }
};
