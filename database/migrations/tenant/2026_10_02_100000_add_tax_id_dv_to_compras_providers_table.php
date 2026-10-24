<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1014 — dígito verificador (DV) del RUC del proveedor. Mismo formato que
 * `tax_id_dv` de clientes (SCRUM-928): 1 o 2 dígitos. Nullable — los proveedores existentes y los
 * extranjeros (sin DV) no lo tienen.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::table($this->table('providers'), function (Blueprint $table): void {
            $table->string('tax_id_dv', 2)->nullable()->after('tax_id');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('providers'), function (Blueprint $table): void {
            $table->dropColumn('tax_id_dv');
        });
    }
};
