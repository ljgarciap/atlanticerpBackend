<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-191→196 (REQ-128→133) — flujo de creación de una orden de compra (Nueva Orden, 2H).
 *
 * `status` usa las mismas 7 etapas ya documentadas en REQ-117/REQ-182 (Ver Órdenes/Logística,
 * diferidas a backlog) aunque este sprint solo produzca/consuma 'por_aprobar' — sentar el enum
 * completo ahora evita una migración de ALTER TYPE cuando esas pantallas se construyan. Precedente
 * de riesgo aceptado: `quotes.status` (2026_07_13_100003) se pensó de antemano y terminó sin uso
 * real porque el estado real se resolvió distinto (Quote::documentStatus(), computado) — acá SÍ
 * hay una razón concreta para que sea una columna real desde ya: no existe ningún otro lugar
 * (pipeline/kanban) donde vivir ese estado, y Nueva Orden necesita persistirlo al crear la orden.
 *
 * `requires_primary_approval`/`approved_by`/`approved_at` — RN3 de REQ-118 ("aéreo + paga
 * Atlantic requiere aprobación de Mark"), regla que aplica a Nueva Orden (2H) aunque el
 * texto completo solo esté escrito en el ticket diferido REQ-118 (ver docs/specs/
 * compras-proveedores-sprint2.md, hallazgo 5).
 *
 * `sales_project_id` NO se agrega a nivel de cabecera a propósito (ver hallazgo del PM en la
 * spec) — la asignación real vive por línea (`purchase_order_lines.sales_project_id`), porque
 * ningún criterio de aceptación pide una asignación única por orden completa.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::create($this->table('purchase_orders'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')
                ->constrained($this->table('providers'), 'id', 'purchase_orders_provider_fk')
                ->restrictOnDelete();
            $table->string('status')->default('por_aprobar');
            $table->string('shipping_type')->nullable();
            $table->string('who_pays_shipping')->nullable();
            $table->boolean('requires_primary_approval')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('purchase_orders'));
    }
};
