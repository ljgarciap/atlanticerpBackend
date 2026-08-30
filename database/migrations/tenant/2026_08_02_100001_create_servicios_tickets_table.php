<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Servicios (docs/architecture/servicios-fase4-diseno.md, secciones 1/3/4). Batch 1
 * (REQ-216→221 + REQ-249) — núcleo de `tickets`, primer módulo agregado con el schema propio
 * `{tenant}_servicios` (reemplaza `{tenant}_installations`, ver migración hermana
 * `2026_08_02_100000_drop_legacy_installations_schema.php`). Mismo patrón que
 * `2026_07_06_100000_create_notifications_module_tables.php`: el schema no existe todavía para
 * el tenant "illuminations" ya creado, así que esta migración lo crea ella misma
 * (`tenant:create` también lo crea de entrada para tenants nuevos vía `Tenant::schemas()`).
 *
 * `quote_status`/`inspection_report_status` son denormalizados a propósito (ver el ticket de
 * Backend Dev, sección "REQ-218"): Cotización (Batch 11-12) e Informe de Inspección (Batch 8-9)
 * todavía no existen como tablas — estas 2 columnas dejan el gate de REQ-218 100% funcional ya,
 * derivadas al crear (`TicketFolioService`/`TicketService::create()`, REQ-249 RN2) y actualizadas
 * más adelante por los módulos reales vía evento de dominio (`servicios.quote.decided`,
 * `servicios.inspection-report.completed`) cuando existan — no se modela ninguna dependencia hoy.
 *
 * `internal_technician_id` es un FK "suelto" (sin `->constrained()`) a propósito: la tabla
 * `internal_technicians` es Batch 6 (Fase 2, todavía no construida) — se agrega la constraint real
 * en la migración que cree esa tabla, sin bloquear Batch 1 por una dependencia hacia adelante.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS "'.$this->schema().'"');

        $ventasDisenoSchema = Tenant::current()->slug.'_ventas_diseno';

        Schema::create($this->table('tickets'), function (Blueprint $table) use ($ventasDisenoSchema): void {
            $table->id();
            $table->string('numero')->unique('servicios_tickets_numero_uq'); // PREFIJO-AÑO-NNNN, ver TicketFolioService

            $table->string('tipo', 20); // installation | warranty | claim | retrofit
            $table->string('subtipo', 30)->nullable(); // solo installation/warranty tienen subtipo (REQ-279 RN4)
            $table->string('tipo_instalacion', 20); // internal | subcontracted

            $table->text('descripcion');

            $table->foreignId('sales_project_id')->nullable()
                ->constrained("{$ventasDisenoSchema}.sales_projects", 'id', 'servicios_tickets_sales_project_fk')
                ->nullOnDelete();

            // Snapshot al crear (REQ-246/247, Batch 3) — no editable desde acá, se congela en el
            // ticket aunque el proyecto/contacto de origen cambie después.
            $table->string('cliente')->nullable();
            $table->string('contacto')->nullable();
            $table->string('telefono', 50)->nullable();
            $table->string('email')->nullable();
            $table->text('direccion')->nullable();
            $table->text('requerimientos_especiales')->nullable();

            $table->string('estado', 20)->default('reported'); // reported|scheduled|on_site|resolved|closed|cancelled

            // Denormalizado — ver docblock de la clase.
            $table->string('quote_status', 20)->default('pending'); // not_applicable|pending|draft|sent|approved|rejected
            $table->string('inspection_report_status', 20)->default('pending'); // not_applicable|pending|completed

            $table->unsignedBigInteger('internal_technician_id')->nullable(); // FK real llega en Batch 6, ver docblock
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('scheduled_ends_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('estado');
            $table->index('tipo');
            $table->index('internal_technician_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('tickets'));
    }
};
