<?php

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        $table = $this->table('departments');

        // Remove duplicate rows introduced by running the seeder more than once.
        // Keep the row with the lowest id for each name.
        DB::statement("
            DELETE FROM {$table}
            WHERE id NOT IN (
                SELECT MIN(id) FROM {$table} GROUP BY name
            )
        ");

        Schema::table($table, function (Blueprint $t): void {
            $t->unique('name');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('departments'), function (Blueprint $t): void {
            $t->dropUnique(['name']);
        });
    }
};
