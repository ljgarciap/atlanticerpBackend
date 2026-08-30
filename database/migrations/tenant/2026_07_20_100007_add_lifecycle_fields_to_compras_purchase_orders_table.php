<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-138→156 (Ver Órdenes 2A + Logística 2B, sprint2, SCRUM-201→219) — campos de ciclo de vida.
 *
 * `modality` (directo|zona_libre) es un eje DISTINTO de `shipping_type` (aereo|maritimo|
 * terrestre) — REQ-153 los trata como 2 datos separados de la tarjeta de envío ("modalidad" y
 * "tipo de envío"); confundirlos en un solo campo habría sido incorrecto.
 *
 * `status_changed_at` — necesario para REQ-139 ("3+ días Por aprobar" = crítica), mismo patrón
 * ya usado en `ventas_diseno.pipeline_cards.stage_changed_at` ("días en etapa" calculado desde
 * este timestamp en vez de un contador que necesitaría un cron).
 *
 * `estimated_arrival_date`/`actual_arrival_date`/`container_number`/`carrier` — REQ-134/156.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::table($this->table('purchase_orders'), function (Blueprint $table): void {
            $table->string('modality')->default('directo')->after('who_pays_shipping');
            $table->date('estimated_arrival_date')->nullable()->after('modality');
            $table->date('actual_arrival_date')->nullable()->after('estimated_arrival_date');
            $table->string('container_number')->nullable()->after('actual_arrival_date');
            $table->string('carrier')->nullable()->after('container_number');
            $table->timestampTz('status_changed_at')->useCurrent()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('purchase_orders'), function (Blueprint $table): void {
            $table->dropColumn([
                'modality', 'estimated_arrival_date', 'actual_arrival_date',
                'container_number', 'carrier', 'status_changed_at',
            ]);
        });
    }
};
