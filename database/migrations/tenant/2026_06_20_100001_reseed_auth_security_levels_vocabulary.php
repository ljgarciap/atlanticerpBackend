<?php

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Re-seeds security_levels with the approved module-scoped permission vocabulary.
 *
 * Vocabulary (11 strings):
 *   crm.read, crm.write, crm.edit, crm.delete
 *   reports.read, reports.export
 *   ai.use
 *   security.users, security.levels
 *   settings.global
 *   superadmin.all
 *
 * Levels 5, 6, 7 share the same permissions today and will diverge when the
 * Installations module is specced. The level number itself enforces the
 * management scope hierarchy (a Level-N user can manage Levels 1..N-1).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    private const CRM_FULL = ['crm.read', 'crm.write', 'crm.edit', 'crm.delete', 'reports.read', 'reports.export', 'ai.use'];

    /** @var array<int, list<string>> */
    private array $levels = [
        1  => ['crm.read'],
        2  => ['crm.read', 'crm.write'],
        3  => ['crm.read', 'crm.write', 'crm.edit', 'reports.read'],
        4  => self::CRM_FULL,
        5  => [...self::CRM_FULL, 'security.users'],
        6  => [...self::CRM_FULL, 'security.users'],
        7  => [...self::CRM_FULL, 'security.users'],
        8  => [...self::CRM_FULL, 'security.users', 'settings.global'],
        9  => [...self::CRM_FULL, 'security.users', 'settings.global', 'security.levels'],
        10 => [...self::CRM_FULL, 'security.users', 'settings.global', 'security.levels', 'superadmin.all'],
    ];

    public function up(): void
    {
        $table = $this->table('security_levels');

        foreach ($this->levels as $levelNumber => $permissions) {
            DB::table($table)
                ->where('level', $levelNumber)
                ->update(['permissions' => json_encode($permissions)]);
        }
    }

    public function down(): void
    {
        // Restore original generic vocabulary — informational only, not used in enforcement.
        $original = [
            1  => ['read'],
            2  => ['read', 'write'],
            3  => ['read', 'write', 'edit'],
            4  => ['read', 'write', 'edit', 'delete', 'export'],
            5  => ['read', 'write', 'edit', 'delete', 'export'],
            6  => ['read', 'write', 'edit', 'delete', 'export', 'manage_users'],
            7  => ['read', 'write', 'edit', 'delete', 'export', 'manage_users'],
            8  => ['read', 'write', 'edit', 'delete', 'export', 'manage_users', 'manage_settings'],
            9  => ['read', 'write', 'edit', 'delete', 'export', 'manage_users', 'manage_settings'],
            10 => ['read', 'write', 'edit', 'delete', 'export', 'manage_users', 'manage_settings', 'manage_all'],
        ];

        $table = $this->table('security_levels');
        foreach ($original as $levelNumber => $permissions) {
            DB::table($table)
                ->where('level', $levelNumber)
                ->update(['permissions' => json_encode($permissions)]);
        }
    }
};
