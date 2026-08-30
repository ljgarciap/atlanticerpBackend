<?php

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        Schema::table($this->table('users'), function (Blueprint $table) {
            $table->boolean('mfa_enabled')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('users'), function (Blueprint $table) {
            $table->dropColumn('mfa_enabled');
        });
    }
};
