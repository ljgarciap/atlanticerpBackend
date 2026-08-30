<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-194 (REQ-131, hallazgo de Daniela Amaya 2026-07-30) — el mockup `2H__Compras_NuevaOrden.html`
 * pide un solo campo de "Costo adicional estimado" con toggle $ monto / % del costo; solo existía
 * `additional_cost_percent`. Se agrega `additional_cost_amount` + `additional_cost_type` en vez de
 * reemplazar la columna existente, para no perder los `additional_cost_percent` ya guardados en
 * órdenes reales — `additional_cost_type` decide cuál de los dos monetarios manda quando ambos
 * están presentes.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::table($this->table('purchase_order_lines'), function (Blueprint $table): void {
            $table->decimal('additional_cost_amount', 12, 2)->nullable()->after('additional_cost_percent');
            $table->string('additional_cost_type', 10)->nullable()->after('additional_cost_amount');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('purchase_order_lines'), function (Blueprint $table): void {
            $table->dropColumn(['additional_cost_amount', 'additional_cost_type']);
        });
    }
};
