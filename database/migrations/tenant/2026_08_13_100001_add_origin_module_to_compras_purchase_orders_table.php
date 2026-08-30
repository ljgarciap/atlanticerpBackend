<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Servicios, Grupo D parte 2 (REQ-274, SCRUM-344) — distingue una `PurchaseOrder`
 * generada automáticamente por Servicios (reposición de la Reserva Servicios de insumos) del resto
 * de órdenes, que hoy nacen todas desde "Nueva Orden" (Compras) sin ningún origen registrado.
 * Nullable a propósito: TODA orden existente y toda orden futura creada desde Compras directo
 * queda con `origin_module = NULL` — este campo es puramente informativo/de enrutamiento para
 * `App\Modules\Servicios\Listeners\SyncInsumoReservationOnPurchaseOrderReceived` (RN de REQ-277),
 * nunca cambia el ciclo de estados real de la orden ni sus reglas de aprobación/envío.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::table($this->table('purchase_orders'), function (Blueprint $table): void {
            $table->string('origin_module')->nullable()->after('created_by');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('purchase_orders'), function (Blueprint $table): void {
            $table->dropColumn('origin_module');
        });
    }
};
