<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-460/461/462 (REQ-390/391/392, Bloque B5) — una línea de producto dentro de un conteo
 * general. `cantidad_sistema`/`cantidad_contada`/`diferencia` quedan NULL hasta "Evaluar"
 * (SCRUM-462, REQ-392): `store()` solo precarga qué productos entran al conteo
 * (`catalog_product_id`), `cantidad_sistema` se toma como snapshot FRESCO de
 * `ProductWarehouseStock.quantity` recién en el momento de evaluar (no en `store()`), para
 * reflejar cualquier movimiento de Kardex ocurrido entre iniciar y evaluar el conteo — ver
 * ADR-SCRUM460-466-conteos-generales.md.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        $ventasDisenoSchema = Tenant::current()->slug.'_ventas_diseno';

        Schema::create($this->table('general_count_request_lines'), function (Blueprint $table) use ($ventasDisenoSchema): void {
            $table->id();
            $table->foreignId('general_count_request_id')
                ->constrained($this->table('general_count_requests'), 'id', 'general_count_request_lines_request_fk')
                ->cascadeOnDelete();
            $table->foreignId('catalog_product_id')
                ->constrained("{$ventasDisenoSchema}.catalog_products", 'id', 'general_count_request_lines_product_fk');
            $table->integer('cantidad_sistema')->nullable();
            $table->integer('cantidad_contada')->nullable();
            $table->integer('diferencia')->nullable();
            $table->timestamps();

            $table->unique(['general_count_request_id', 'catalog_product_id'], 'general_count_request_lines_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('general_count_request_lines'));
    }
};
