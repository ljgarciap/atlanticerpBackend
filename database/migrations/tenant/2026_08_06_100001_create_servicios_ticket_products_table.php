<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Servicios, Batch 3 parte 2 (REQ-247, docs/architecture/servicios-fase4-diseno.md
 * sección 4/8) — productos reclamados/afectados de un ticket (sección "Productos reclamados /
 * afectados" de Nuevo ticket, opcional, RN2).
 *
 * `catalog_product_id` es FK CROSS-SCHEMA hacia `ventas_diseno.catalog_products` — mismo patrón
 * ya usado por `purchase_order_lines`/`quote_items` (Servicios reutiliza el Catálogo real, no lo
 * duplica). `marca`/`referencia`/`descripcion` son snapshot al agregar el producto (igual criterio
 * que `tickets.cliente`/`contacto`/etc.) — si el producto se borra del catálogo, la línea del
 * ticket no pierde su información visible, solo la trazabilidad hacia el catálogo vivo.
 *
 * `cantidad_reclamo` es el único dato que captura el formulario de creación (REQ-247 RN5).
 * `cantidad_recibida`/`cantidad_pendiente` arrancan en 0/=cantidad_reclamo y las actualiza el resto
 * del ciclo de vida del ticket (REQ-225, ya construido) — no hay UI todavía que las edite además de
 * la edición global existente, no se agrega acá.
 *
 * Constraint nombrado explícitamente — nombre autogenerado con schema calificado trunca en
 * silencio a 63 bytes (mismo gotcha que `purchase_order_lines`).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        $ventasDisenoSchema = Tenant::current()->slug.'_ventas_diseno';

        Schema::create($this->table('ticket_products'), function (Blueprint $table) use ($ventasDisenoSchema): void {
            $table->id();
            $table->foreignId('ticket_id')
                ->constrained($this->table('tickets'), 'id', 'servicios_ticket_products_ticket_fk')
                ->cascadeOnDelete();
            $table->foreignId('catalog_product_id')->nullable()
                ->constrained("{$ventasDisenoSchema}.catalog_products", 'id', 'servicios_ticket_products_product_fk')
                ->nullOnDelete();

            $table->string('marca')->nullable();
            $table->string('referencia')->nullable();
            $table->text('descripcion')->nullable();

            $table->unsignedInteger('cantidad_reclamo');
            $table->unsignedInteger('cantidad_recibida')->default(0);
            $table->unsignedInteger('cantidad_pendiente');

            $table->timestamps();

            $table->index('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('ticket_products'));
    }
};
