<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 16 (5.3) — solicitud de «Pasar a Pedido»: el vendedor (que no puede ejecutar el pase) pide que Gerencia lo haga.
 * Se guarda quién, cuándo y una nota opcional. Aditiva y nullable: no cambia el estado real del pedido, que sigue derivándose
 * de la existencia del pedido (`order_status`).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('pipeline_cards'), function (Blueprint $table): void {
            $table->foreignId('order_requested_by')->nullable()->after('order_approved_at')
                ->constrained('users')->nullOnDelete();
            $table->timestampTz('order_requested_at')->nullable()->after('order_requested_by');
            $table->text('order_request_note')->nullable()->after('order_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('pipeline_cards'), function (Blueprint $table): void {
            $table->dropConstrainedForeignId('order_requested_by');
            $table->dropColumn(['order_requested_at', 'order_request_note']);
        });
    }
};
