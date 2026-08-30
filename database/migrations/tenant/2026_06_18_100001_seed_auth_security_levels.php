<?php

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /** @var list<array{level: int, name: string, description: string, permissions: list<string>}> */
    private array $levels = [
        [
            'level'       => 1,
            'name'        => 'Read Only',
            'description' => 'View access only. Cannot create, edit or delete any record.',
            'permissions' => ['read'],
        ],
        [
            'level'       => 2,
            'name'        => 'Basic User',
            'description' => 'Can view and create records. Cannot edit or delete.',
            'permissions' => ['read', 'write'],
        ],
        [
            'level'       => 3,
            'name'        => 'Standard User',
            'description' => 'Can view, create and edit records. Cannot delete or export.',
            'permissions' => ['read', 'write', 'edit'],
        ],
        [
            'level'       => 4,
            'name'        => 'Power User',
            'description' => 'Full record management including export. Cannot manage users.',
            'permissions' => ['read', 'write', 'edit', 'delete', 'export'],
        ],
        [
            'level'       => 5,
            'name'        => 'Team Lead',
            'description' => 'Default level. Full record management plus limited user visibility.',
            'permissions' => ['read', 'write', 'edit', 'delete', 'export'],
        ],
        [
            'level'       => 6,
            'name'        => 'Supervisor',
            'description' => 'Can manage users within their department.',
            'permissions' => ['read', 'write', 'edit', 'delete', 'export', 'manage_users'],
        ],
        [
            'level'       => 7,
            'name'        => 'Manager',
            'description' => 'Can manage users and configure security groups and departments.',
            'permissions' => ['read', 'write', 'edit', 'delete', 'export', 'manage_users', 'manage_security'],
        ],
        [
            'level'       => 8,
            'name'        => 'Senior Manager',
            'description' => 'Full access to all modules plus global settings management.',
            'permissions' => ['read', 'write', 'edit', 'delete', 'export', 'manage_users', 'manage_security', 'manage_settings'],
        ],
        [
            'level'       => 9,
            'name'        => 'Admin',
            'description' => 'System administrator. All permissions except superadmin-exclusive actions.',
            'permissions' => ['read', 'write', 'edit', 'delete', 'export', 'manage_users', 'manage_security', 'manage_settings'],
        ],
        [
            'level'       => 10,
            'name'        => 'Super Admin',
            'description' => 'Unrestricted access. Exclusive to superadmin role. Can assign any level.',
            'permissions' => ['read', 'write', 'edit', 'delete', 'export', 'manage_users', 'manage_security', 'manage_settings', 'manage_all'],
        ],
    ];

    public function up(): void
    {
        $now = now();
        foreach ($this->levels as $level) {
            DB::table($this->table('security_levels'))->insert([
                'level'       => $level['level'],
                'name'        => $level['name'],
                'description' => $level['description'],
                'permissions' => json_encode($level['permissions']),
                'is_active'   => true,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table($this->table('security_levels'))->delete();
    }
};
