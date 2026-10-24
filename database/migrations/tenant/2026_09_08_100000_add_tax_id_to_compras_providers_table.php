<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-852 — identificación fiscal (RUC/NIT/Tax ID) del proveedor, para detectar duplicados que
 * el nombre solo (ya validado, SCRUM-190) no cubre: mismo proveedor real dado de alta 2 veces con
 * nombres ligeramente distintos. Nullable — dato opcional, se completa cuando se conoce.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::table($this->table('providers'), function (Blueprint $table): void {
            $table->string('tax_id')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('providers'), function (Blueprint $table): void {
            $table->dropColumn('tax_id');
        });
    }
};
