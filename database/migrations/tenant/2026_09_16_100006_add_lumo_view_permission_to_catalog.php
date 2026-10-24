<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-925 — permiso individual (no de rol) para ver el dashboard de bitácoras de LUMO dentro
 * de AtlanticERP. Mark/David/Felix lo reciben vía `SpecialPermissionSeeder`, no un `security_level`
 * completo — Felix (`lider_admin_contab`) no comparte rol con Mark/David (`management`).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        DB::table($this->table('permission_catalog'))->insert([
            'key' => 'lumo.view', 'module' => 'lumo',
            'label' => 'Ver bitácoras de LUMO',
            'sort_order' => 101, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table($this->table('permission_catalog'))
            ->where('key', 'lumo.view')
            ->delete();
    }
};
