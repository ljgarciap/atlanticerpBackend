<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-036: Catálogo real de productos — buscador con ficha técnica antes de
 * agregar un ítem a una cotización. No existe módulo Compras/Inventario real
 * todavía (los links del sidebar son placeholders "coming soon"), así que el
 * Catálogo vive dentro de Ventas & Diseño por ahora, no se importa de otro
 * lado.
 *
 * `price_full` (precio de lista) y `cost` (costo) son los datos que REQ-033
 * necesitaba y que un ítem personalizado nunca tuvo — acá sí existen de
 * verdad, viven en el producto de catálogo, no en el ítem. Al seleccionar un
 * producto (REQ-035), sus valores se COPIAN a `quote_items.unit_price`/`cost`
 * (no una referencia en vivo) — mismo criterio que "la Descripción se
 * autocompleta y queda bloqueada".
 *
 * `stock_quantity` nullable = disponibilidad no controlada para ese producto
 * (no bloquea nada); sienta la base de REQ-039 (alerta de stock insuficiente,
 * ticket separado, no implementado en esta migración).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::create($this->table('catalog_products'), function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->string('description');
            $table->string('brand')->nullable();
            $table->string('photo_url')->nullable();
            $table->decimal('price_full', 12, 2);
            $table->decimal('cost', 12, 2)->nullable();
            $table->integer('stock_quantity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('catalog_products'));
    }
};
