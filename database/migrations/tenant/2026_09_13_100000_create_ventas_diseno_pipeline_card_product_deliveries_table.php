<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-909 — completa SCRUM-875 (REQ-319): una tarjeta con "Fecha de entrega Parcial" define
 * 2..X fechas (`pipeline_card_delivery_dates`), pero hasta acá ningún dato decía QUÉ productos
 * van en cada fecha — `OrderService::createFromApprovedQuote()` copiaba TODOS los ítems de la
 * cotización a CADA tarjeta de la familia de Bodega. Esta tabla es la asignación producto→fecha
 * que el modal de Pipeline arma antes de aprobar: cada `quote_item_id` (línea real de la
 * cotización resuelta del proyecto, ver `OrderService::resolveApprovedQuote()`) queda ligado a
 * UNA de las fechas parciales ya definidas para esa misma tarjeta — nunca una fecha ajena, de ahí
 * que `pipeline_card_id` también viva acá (permite validar pertenencia sin un join extra a
 * `pipeline_card_delivery_dates` en cada escritura).
 *
 * `quote_item_id` en vez de `catalog_product_id` — la asignación es por LÍNEA de cotización
 * (incluye ítems personalizados sin producto de Catálogo, is_custom=true), no por producto: si el
 * mismo producto apareciera en 2 partidas distintas de la misma cotización, cada línea se asigna
 * por separado. `unique(pipeline_card_id, quote_item_id)`: un ítem tiene una única fecha asignada
 * a la vez (reasignar = `updateOrCreate`, no un historial de asignaciones).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        // Nombres de constraint explícitos y cortos — el nombre autogenerado por Laravel
        // (schema + tabla + columna + "_foreign") supera los 63 bytes que Postgres admite y
        // se trunca, colisionando entre sí (ver checklist de gotchas de atlanticerp-local-dev).
        Schema::create($this->table('pipeline_card_product_deliveries'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pipeline_card_id')
                ->constrained($this->table('pipeline_cards'), indexName: 'pcpd_pipeline_card_id_foreign')
                ->cascadeOnDelete();
            $table->foreignId('quote_item_id')
                ->constrained($this->table('quote_items'), indexName: 'pcpd_quote_item_id_foreign')
                ->cascadeOnDelete();
            $table->foreignId('pipeline_card_delivery_date_id')
                ->constrained($this->table('pipeline_card_delivery_dates'), indexName: 'pcpd_delivery_date_id_foreign')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['pipeline_card_id', 'quote_item_id'], 'pcpd_card_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('pipeline_card_product_deliveries'));
    }
};
