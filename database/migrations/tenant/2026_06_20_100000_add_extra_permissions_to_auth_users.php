<?php

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        Schema::table($this->table('users'), function (Blueprint $table): void {
            $table->jsonb('extra_permissions')
                  ->default('[]')
                  ->after('security_level_id');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('users'), function (Blueprint $table): void {
            $table->dropColumn('extra_permissions');
        });
    }
};
