<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1128 — trazabilidad de la aprobación explícita "Pasar a Pedido": quién la confirmó y
 * cuándo. Nullable a propósito: las tarjetas aprobadas ANTES de este cambio ya tienen su pedido
 * en Bodega (se generaba automáticamente) y quedan como "Aprobadas" sin usuario ni fecha — el
 * estado real (`order_status`) se deriva de la existencia del pedido, no de estas columnas.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('pipeline_cards'), function (Blueprint $table): void {
            $table->foreignId('order_approved_by')->nullable()->after('created_by')
                ->constrained('users')->nullOnDelete();
            $table->timestampTz('order_approved_at')->nullable()->after('order_approved_by');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('pipeline_cards'), function (Blueprint $table): void {
            $table->dropConstrainedForeignId('order_approved_by');
            $table->dropColumn('order_approved_at');
        });
    }
};
