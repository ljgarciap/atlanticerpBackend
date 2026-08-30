<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        $table = $this->table('users');
        // Backfill with empty string — safe only on greenfield tenants with no real users.
        // If the tenant already has users with NULL last_name, consult the business owner
        // for the correct default before running this migration.
        DB::statement("UPDATE {$table} SET last_name = '' WHERE last_name IS NULL");
        DB::statement("ALTER TABLE {$table} ALTER COLUMN last_name SET NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE {$this->table('users')} ALTER COLUMN last_name DROP NOT NULL");
    }
};
