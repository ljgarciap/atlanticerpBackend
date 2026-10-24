<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-838 — mismo patrón que `2026_09_14_100000_add_source_hash_to_legacy_producto_tables.php`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::table($this->table('proveedores'), function (Blueprint $table): void {
            $table->string('source_hash', 64)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('proveedores'), function (Blueprint $table): void {
            $table->dropColumn('source_hash');
        });
    }
};
