<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prep — Facturación electrónica PAC (sin ticket todavía, ver
 * docs/architecture/facturacion-electronica-pac-diseno.md). MSL y Digifact exigen la tasa de
 * ITBMS **por línea** de detalle — hoy `admin_contab.invoices.itbms_rate_id` (migración
 * 2026-08-24) solo guarda una tasa para la factura completa, deuda ya documentada en ese mismo
 * archivo. `itbms_rate_id` es FK "suelta" (sin constraint física) porque `order_items` vive en el
 * schema `bodega` e `itbms_rates` en `admin_contab` — mismo criterio ya usado por
 * `invoices.order_id`/`invoices.master_client_id` para referencias cross-schema.
 *
 * Nullable y sin backfill: convive con el campo por-factura existente sin reemplazarlo ni romper
 * ningún flujo de Bodega/Facturación actual. Ningún escritor real todavía.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        Schema::table($this->table('order_items'), function (Blueprint $table): void {
            $table->unsignedBigInteger('itbms_rate_id')->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('order_items'), function (Blueprint $table): void {
            $table->dropColumn('itbms_rate_id');
        });
    }
};
