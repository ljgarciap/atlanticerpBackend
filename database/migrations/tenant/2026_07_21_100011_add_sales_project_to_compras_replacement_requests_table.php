<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-247 (REQ-184) — "Generar solicitud" necesita asociar la solicitud de sustituto al
 * proyecto de venta que la origina (mismo patrón cross-schema de `purchase_order_lines`).
 * FK CROSS-SCHEMA hacia `ventas_diseno.sales_projects`, nullOnDelete (un proyecto borrado no
 * debe arrastrar la solicitud histórica).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        $ventasDisenoSchema = Tenant::current()->slug.'_ventas_diseno';

        Schema::table($this->table('replacement_requests'), function (Blueprint $table) use ($ventasDisenoSchema): void {
            $table->foreignId('sales_project_id')->nullable()->after('original_catalog_product_id')
                ->constrained("{$ventasDisenoSchema}.sales_projects", 'id', 'replacement_requests_sales_project_fk')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('replacement_requests'), function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sales_project_id');
        });
    }
};
