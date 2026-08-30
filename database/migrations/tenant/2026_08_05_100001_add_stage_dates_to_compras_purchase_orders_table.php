<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-153/154 (SCRUM-216/217, batch fusionado 2026-08-05) — línea de tiempo del envío (Visual
 * Reviewer, hallazgo CRÍTICO 2026-08-05: la línea de tiempo del mockup 2B__Compras_Logistica.html
 * no existe en el desarrollo porque el dato para dibujarla no existe — `purchase_orders` solo
 * guardaba `status` + `status_changed_at`, que se pisa en cada transición).
 *
 * 4 columnas nuevas, una por etapa intermedia de `PurchaseOrder::statusSequence()` — la etapa
 * terminal "Recibido" reusa `actual_arrival_date` (ya existe desde
 * `2026_07_20_100007_add_lifecycle_fields...`), no se crea una quinta columna. Cada una se setea
 * en `PurchaseOrderController::advance()` cuando la orden ALCANZA esa etapa (nunca se recalculan
 * ni se pisan después — son un registro histórico, mismo espíritu que `approved_at`).
 *
 * `en_aduana_at`/`en_transito_at` quedan siempre NULL para un proveedor `local`
 * (`Provider::ORIGIN_LOCAL`) — su secuencia salta esas 2 etapas por completo (ver
 * `statusSequence()`), así que nunca se alcanzan.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::table($this->table('purchase_orders'), function (Blueprint $table): void {
            $table->timestampTz('ordenado_at')->nullable()->after('status_changed_at');
            $table->timestampTz('en_transito_at')->nullable()->after('ordenado_at');
            $table->timestampTz('en_aduana_at')->nullable()->after('en_transito_at');
            $table->timestampTz('en_transito_local_at')->nullable()->after('en_aduana_at');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('purchase_orders'), function (Blueprint $table): void {
            $table->dropColumn(['ordenado_at', 'en_transito_at', 'en_aduana_at', 'en_transito_local_at']);
        });
    }
};
