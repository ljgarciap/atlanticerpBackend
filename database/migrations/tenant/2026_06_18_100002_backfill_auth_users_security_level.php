<?php

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        // Assign level 5 (Team Lead — default) to every user that has no security_level_id yet.
        $defaultLevelId = DB::table($this->table('security_levels'))
            ->where('level', 5)
            ->value('id');

        if ($defaultLevelId !== null) {
            DB::table($this->table('users'))
                ->whereNull('security_level_id')
                ->update(['security_level_id' => $defaultLevelId]);
        }

        // Make security_level_id NOT NULL now that every row has a value.
        DB::statement(
            'ALTER TABLE ' . $this->table('users') .
            ' ALTER COLUMN security_level_id SET NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE ' . $this->table('users') .
            ' ALTER COLUMN security_level_id DROP NOT NULL'
        );
    }
};
