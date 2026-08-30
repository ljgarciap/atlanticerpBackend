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
        Schema::create($this->table('security_levels'), function (Blueprint $table) {
            $table->id();
            $table->smallInteger('level')->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->jsonb('permissions')->default(DB::raw("'[]'::jsonb"));
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table($this->table('users'), function (Blueprint $table) {
            $table->unsignedBigInteger('security_level_id')->nullable()->after('permission_group_id');
            $table->foreign('security_level_id')
                  ->references('id')
                  ->on($this->table('security_levels'))
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('users'), function (Blueprint $table) {
            $table->dropForeign(['security_level_id']);
            $table->dropColumn('security_level_id');
        });
        Schema::dropIfExists($this->table('security_levels'));
    }
};
