<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-440 (ADR-SCRUM433-445-zona-libre-colon.md, sección "Permisos") — permiso angosto para que
 * Yirena (Compras) apruebe/rechace una solicitud de Zona Libre de Bodega. A propósito NO reusa
 * `compras.approve` (2026_07_XX, aprobación de flete aéreo de Mark, REQ-143) — mezclar ambas
 * semánticas confundiría "quién puede aprobar qué" a futuro. Mismo patrón que
 * `compras.settings.configure` (2026_07_20_100005): grant individual vía `extra_permissions`, no
 * atado a ningún `security_level` — `superadmin.all` ya lo bypassa. Ver
 * `database/seeders/SpecialPermissionSeeder.php` para el grant real a Yirena.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        DB::table($this->table('permission_catalog'))->insert([
            'key' => 'compras.zona-libre.approve', 'module' => 'compras',
            'label' => 'Aprobar o rechazar órdenes de Zona Libre de Colón generadas por Bodega',
            'sort_order' => 72, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table($this->table('permission_catalog'))
            ->where('key', 'compras.zona-libre.approve')
            ->delete();
    }
};
