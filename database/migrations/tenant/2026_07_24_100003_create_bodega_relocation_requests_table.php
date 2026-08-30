<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-454/457/458/459 (REQ-384/387/388/389, Bloque B4 "Reubicación entre bodegas") — ver
 * ADR-SCRUM454-459-reubicacion-bodegas.md. Una sola tabla (no el patrón contenedora+líneas de
 * `adjustment_requests`/`adjustment_request_lines`) porque cada solicitud siempre es exactamente
 * 1 producto/1 bodega origen/1 bodega destino, a diferencia del ajuste (que puede tocar N bodegas
 * a la vez). RN1 (REQ-387) — `destination_warehouse_id != origin_warehouse_id` — se valida
 * server-side en `RelocationRequestController::store()`, no acá (una CHECK constraint cruzada
 * entre dos columnas de la misma fila es válida en Postgres, pero el mensaje de error de
 * violación de constraint es peor UX que un 422 con mensaje claro — mismo criterio que el resto
 * de RNs de este módulo, todas viven en el controller).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        $ventasDisenoSchema = Tenant::current()->slug.'_ventas_diseno';
        $authSchema = Tenant::current()->slug.'_auth';

        Schema::create($this->table('relocation_requests'), function (Blueprint $table) use ($ventasDisenoSchema, $authSchema): void {
            $table->id();
            $table->foreignId('catalog_product_id')
                ->constrained("{$ventasDisenoSchema}.catalog_products", 'id', 'relocation_requests_product_fk');
            $table->foreignId('origin_warehouse_id')
                ->constrained($this->table('warehouses'), 'id', 'relocation_requests_origin_fk');
            $table->foreignId('destination_warehouse_id')
                ->constrained($this->table('warehouses'), 'id', 'relocation_requests_destination_fk');
            $table->unsignedInteger('cantidad');
            $table->text('motivo');
            $table->foreignId('solicitado_por')
                ->constrained("{$authSchema}.users", 'id', 'relocation_requests_user_fk');
            $table->string('estado')->default('pendiente'); // pendiente|aprobada|rechazada
            $table->text('motivo_rechazo')->nullable();
            $table->foreignId('resuelto_por')->nullable()
                ->constrained("{$authSchema}.users", 'id', 'relocation_requests_resolver_fk')
                ->nullOnDelete();
            $table->timestamp('resuelto_at')->nullable();
            $table->timestamps();

            $table->index('estado', 'relocation_requests_estado_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('relocation_requests'));
    }
};
