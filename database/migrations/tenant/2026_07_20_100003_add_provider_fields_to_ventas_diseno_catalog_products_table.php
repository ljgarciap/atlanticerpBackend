<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-183→196, sprint2 — el "Inventario (2C)" de los tickets de Compras es este mismo Catálogo
 * (ver docs/specs/compras-proveedores-sprint2.md, hallazgo 1), no una tabla nueva. Se extiende en
 * vez de duplicar para no arriesgar 2 catálogos desincronizados (REQ-127 exige justo lo
 * contrario: consistencia entre pantallas).
 *
 * `reference` (columna existente, unique global) pasa a representar la "referencia pública" —
 * ya lo era en la práctica, no cambia comportamiento (REQ-131 Escenario 3).
 *
 * `factory_reference` es única solo DENTRO del mismo `provider_id` (REQ-131 Escenarios 1/2: la
 * misma referencia de fábrica puede repetirse entre proveedores distintos) — índice único
 * parcial, no una constraint `unique()` simple de Laravel.
 *
 * `provider_id` es FK CROSS-SCHEMA hacia `compras.providers` — este módulo (ventas_diseno) pasa
 * a depender de que `compras` ya haya corrido sus migraciones (2026_07_20_100000). Se prueba
 * contra la suite COMPLETA de VentasDiseno, no solo tests nuevos de Compras (riesgo aceptado
 * documentado en la spec: es la única migración de este sprint que toca una tabla de un módulo
 * ya entregado en producción).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        $comprasSchema = Tenant::current()->slug.'_compras';

        Schema::table($this->table('catalog_products'), function (Blueprint $table) use ($comprasSchema): void {
            $table->foreignId('provider_id')->nullable()->after('id')
                ->constrained("{$comprasSchema}.providers", 'id', 'catalog_products_provider_fk')
                ->restrictOnDelete();
            $table->string('factory_reference')->nullable()->after('reference');
            $table->integer('reorder_point')->nullable()->after('stock_quantity');
        });

        // Índice único parcial (WHERE factory_reference IS NOT NULL) — Blueprint no expone
        // una cláusula WHERE en unique(), se crea con SQL crudo. Se crea en el mismo schema
        // que la tabla automáticamente (CREATE INDEX no requiere calificar el nombre del índice).
        DB::statement(
            'CREATE UNIQUE INDEX catalog_products_provider_factory_ref_unique '
            .'ON '.$this->table('catalog_products').' (provider_id, factory_reference) '
            .'WHERE factory_reference IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement(
            'DROP INDEX IF EXISTS '.$this->table('catalog_products_provider_factory_ref_unique')
        );

        Schema::table($this->table('catalog_products'), function (Blueprint $table): void {
            $table->dropConstrainedForeignId('provider_id');
            $table->dropColumn(['factory_reference', 'reorder_point']);
        });
    }
};
