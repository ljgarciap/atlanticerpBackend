<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-245→249 (REQ-182→186) — Sustitutos. Tabla completa según el diseño aprobado
 * (docs/architecture/compras-sustitutos-pagos-agencias-reclamos-reportes.md), aunque este batch
 * (SCRUM-248/249) solo construye la tabla de solicitudes (REQ-185) y "Generar orden del original"
 * en rechazadas (REQ-186) — REQ-182→184 (búsqueda + comparación + generar solicitud) quedan en la
 * Oleada 2, bloqueados por el checklist de Cybersecurity del ADR de IA. Se crea el schema completo
 * ahora para no requerir otra migración cuando esa oleada se desbloquee.
 *
 * `catalog_product_id` de original/propuesto son FK CROSS-SCHEMA hacia `ventas_diseno.
 * catalog_products`, mismo patrón que `purchase_order_lines`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        $ventasDisenoSchema = Tenant::current()->slug.'_ventas_diseno';

        Schema::create($this->table('replacement_requests'), function (Blueprint $table) use ($ventasDisenoSchema): void {
            $table->id();
            $table->foreignId('original_catalog_product_id')
                ->constrained("{$ventasDisenoSchema}.catalog_products", 'id', 'replacement_requests_original_fk')
                ->restrictOnDelete();
            $table->foreignId('proposed_catalog_product_id')->nullable()
                ->constrained("{$ventasDisenoSchema}.catalog_products", 'id', 'replacement_requests_proposed_fk')
                ->nullOnDelete();
            $table->decimal('margin_percent', 5, 2)->nullable();
            $table->string('status')->default('pendiente');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('generated_order_id')->nullable()
                ->constrained($this->table('purchase_orders'), 'id', 'replacement_requests_order_fk')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('replacement_requests'));
    }
};
