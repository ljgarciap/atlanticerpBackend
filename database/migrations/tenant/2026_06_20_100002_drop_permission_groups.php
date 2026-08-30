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
            $table->dropConstrainedForeignId('permission_group_id');
        });

        Schema::dropIfExists($this->table('permission_groups'));
    }

    public function down(): void
    {
        Schema::create($this->table('permission_groups'), function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->jsonb('permissions')->default('[]');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table($this->table('users'), function (Blueprint $table): void {
            $table->foreignId('permission_group_id')
                ->nullable()
                ->after('department_id')
                ->constrained($this->table('permission_groups'))
                ->nullOnDelete();
        });
    }
};
