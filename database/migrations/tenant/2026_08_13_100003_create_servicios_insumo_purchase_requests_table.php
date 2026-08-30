<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Servicios, Grupo D parte 2 (REQ-274/277, SCRUM-344/347) — puente local entre un insumo
 * de la Reserva Servicios y la `PurchaseOrder` REAL generada en Compras para reponerlo. A
 * diferencia de `tool_purchase_requests` (Herramientas, Batch 13 parte 1), esta tabla NUNCA
 * reemplaza el ciclo real de Compras — `purchase_order_id` apunta a la orden verdadera, con su
 * propio `status` real (por_aprobar → ... → recibido). Esta tabla solo trackea, del lado de
 * Servicios: qué insumo se pidió, cuánto, y si esa orden ya llegó — la fuente de verdad de
 * "recibido" la dispara `App\Modules\Servicios\Listeners\SyncInsumoReservationOnPurchaseOrderReceived`
 * cuando la orden real llega a "Recibido" (REQ-277 RN3).
 *
 * `purchase_order_id` cross-schema hacia `compras.purchase_orders` — mismo patrón ya usado para
 * `sales_project_id` en `servicios.tickets` (FK cross-schema entre módulos).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        $ventasDisenoSchema = Tenant::current()->slug.'_ventas_diseno';
        $comprasSchema      = Tenant::current()->slug.'_compras';

        Schema::create($this->table('insumo_purchase_requests'), function (Blueprint $table) use ($ventasDisenoSchema, $comprasSchema): void {
            $table->id();
            $table->foreignId('catalog_product_id')
                ->constrained("{$ventasDisenoSchema}.catalog_products", 'id', 'servicios_insumo_purchase_req_product_fk')
                ->cascadeOnDelete();
            $table->unsignedInteger('cantidad_solicitada');
            $table->string('estado')->default('pendiente'); // pendiente | recibida

            $table->foreignId('purchase_order_id')->nullable()
                ->constrained("{$comprasSchema}.purchase_orders", 'id', 'servicios_insumo_purchase_req_order_fk')
                ->nullOnDelete();

            $table->foreignId('solicitado_por')->nullable()
                ->constrained('users', 'id', 'servicios_insumo_purchase_req_user_fk')
                ->nullOnDelete();

            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index('estado');
            $table->index('purchase_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('insumo_purchase_requests'));
    }
};
