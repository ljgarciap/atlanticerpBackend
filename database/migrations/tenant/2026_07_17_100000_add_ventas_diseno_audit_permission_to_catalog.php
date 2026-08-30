<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * REQ-053: permiso angosto para consultar el historial/auditoría de Ventas &
 * Diseño (GET /ventas-diseno/audit-log) — mismo patrón "grant individual" ya
 * usado en reports.configure/commissions.mark_paid, no atado a ningún
 * security_level por defecto. El ticket pide "al menos Líder/Gerencia".
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        DB::table($this->table('permission_catalog'))->insert([
            'key' => 'ventas_diseno.audit', 'module' => 'ventas_diseno',
            'label' => 'Ver historial y auditoría de Ventas & Diseño',
            'sort_order' => 69, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table($this->table('permission_catalog'))
            ->where('key', 'ventas_diseno.audit')
            ->delete();
    }
};
