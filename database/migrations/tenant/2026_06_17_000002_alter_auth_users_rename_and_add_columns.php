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
            // Rename existing columns to English
            $table->renameColumn('nombre', 'first_name');
            $table->renameColumn('activo', 'is_active');

            // Add new profile columns (all nullable; seeder populates them)
            $table->string('last_name', 120)->nullable()->after('first_name');
            $table->string('phone', 30)->nullable()->after('email');
            $table->foreignId('department_id')
                ->nullable()
                ->after('phone')
                ->constrained($this->table('departments'))
                ->nullOnDelete();
            $table->foreignId('permission_group_id')
                ->nullable()
                ->after('department_id')
                ->constrained($this->table('permission_groups'))
                ->nullOnDelete();
            $table->text('notes')->nullable()->after('permission_group_id');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('users'), function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('permission_group_id');
            $table->dropColumn(['last_name', 'phone', 'notes']);
            $table->renameColumn('first_name', 'nombre');
            $table->renameColumn('is_active', 'activo');
        });
    }
};
