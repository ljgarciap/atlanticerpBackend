<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-771 — Líder de Operaciones (lideroperaciones@test.com) necesita acceso COMPLETO a
 * Catálogo y Stock → Comparación de Referencias ("Sustitutos" en código, `ReplacementRequestController`
 * /`ReplacementSearchController`), pero SOLO LECTURA en el resto de Compras (Ver Órdenes,
 * Logística & Envío, Inventario, Ver Registros de Ingreso). No se reusa `compras.write`/
 * `compras.edit` (desbloquearía escritura en TODO Compras, no solo Sustitutos) — mismo criterio
 * que `compras.zona-libre.approve` (2026_07_24_100004): permiso angosto vía `extra_permissions`,
 * no atado a ningún `security_level`. Ver `routes/compras.php` (OR con compras.write/.edit en las
 * rutas de escritura de replacement-requests) y `SpecialPermissionSeeder` para el grant real.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        DB::table($this->table('permission_catalog'))->insert([
            'key' => 'compras.sustitutos.manage', 'module' => 'compras',
            'label' => 'Gestionar Comparación de Referencias (Sustitutos): buscar, generar y enviar solicitudes',
            'sort_order' => 73, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table($this->table('permission_catalog'))
            ->where('key', 'compras.sustitutos.manage')
            ->delete();
    }
};
