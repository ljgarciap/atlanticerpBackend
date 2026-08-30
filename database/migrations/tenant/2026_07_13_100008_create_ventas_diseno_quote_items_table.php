<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-035/038/040/041/042 — ítem de una partida. Sin Catálogo real todavía
 * (REQ-036/037 diferidos), todo ítem es personalizado: no hay columna
 * `catalog_product_id`/`is_custom` porque no existe nada contra qué referenciar
 * — se agrega en una migración futura cuando el Catálogo exista (mismo criterio
 * que "folio" en Cotización-A). `unit_price` es tecleado directo por el usuario;
 * las fórmulas de tipo_precio (REQ-033) no tienen efecto sobre ítems
 * personalizados (ver REQ-040 — no hay costo de catálogo de referencia).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::create($this->table('quote_items'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quote_part_id')->constrained()->cascadeOnDelete();
            $table->string('reference');
            $table->string('description');
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 12, 2);
            // REQ-042: nunca "partner" — Precio Socio queda restringido al tipo de
            // precio general de la cotización (permiso ventas_diseno.select_partner_price).
            $table->enum('price_type_override', ['public', 'project', 'special', 'premium'])->nullable();
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('quote_items'));
    }
};
