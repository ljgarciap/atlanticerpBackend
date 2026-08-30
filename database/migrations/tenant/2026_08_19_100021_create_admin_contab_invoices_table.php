<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 2 de Facturación (SCRUM-513→518, REQ-436→441) — reemplaza el stub `Order::invoice_ready`
 * (`App\Modules\Bodega\Models\Order`, docblock REQ-327/328) con el módulo real de Administración
 * & Contabilidad. `order_id` es una FK "suelta" hacia `bodega.orders` (sin constraint física,
 * mismo criterio ya usado por `tickets.internal_technician_id`) porque son schemas de tenant
 * distintos — Postgres no permite FK cross-schema entre migraciones de módulos independientes en
 * este proyecto. `unique(order_id)`: este batch no modela facturación parcial de una misma
 * entrega — un Order tiene a lo sumo una factura.
 *
 * `cliente`/`monto` son snapshot al momento de facturar (mismo criterio que `tickets.cliente` o
 * `project_documents` de CRM) — no se recalculan si la cotización de origen cambia después.
 *
 * `es_incobrable` nace siempre en `false` (RN1 de REQ-438) y no tiene todavía ningún flujo que la
 * ponga en `true` — la aprobación de Mark (HU-448) es un batch futuro; hasta entonces la pestaña
 * "Cartera incobrable" queda vacía a propósito, mismo patrón que otros paneles del proyecto que
 * anticipan una columna antes que su flujo de escritura (ver `external_technicians.tarifa_visible`
 * o `ticket_products.cantidad_recibida`).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('invoices'), function (Blueprint $table): void {
            $table->id();
            $table->string('numero')->unique();
            $table->unsignedBigInteger('order_id')->unique();
            $table->unsignedBigInteger('quote_id')->nullable();
            $table->unsignedBigInteger('sales_project_id')->nullable();
            $table->unsignedBigInteger('master_client_id')->nullable();
            $table->string('cliente');
            $table->decimal('monto', 12, 2);
            $table->boolean('es_incobrable')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('sales_project_id');
            $table->index('master_client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('invoices'));
    }
};
