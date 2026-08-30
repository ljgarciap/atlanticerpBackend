<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        $table = $this->table('user_roles');

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS user_roles_role_check");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT user_roles_role_check CHECK (role IN ('superadmin','management','designer','supervisor','electrician','guest'))");
    }

    public function down(): void
    {
        $table = $this->table('user_roles');

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS user_roles_role_check");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT user_roles_role_check CHECK (role IN ('superadmin','management','designer','supervisor','electrician'))");
    }
};
