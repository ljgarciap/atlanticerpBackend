<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-037: Familias de productos — el vendedor/diseñador selecciona una familia
 * en el buscador de Catálogo y agrega todos sus componentes de una sola vez a
 * la partida. Sin pantalla de administración en esta iteración (mismo
 * precedente que catalog_products: solo lectura en runtime, se siembra por
 * seeder) — si el cliente pide administrar familias desde la UI más adelante,
 * es una pantalla nueva, no un cambio de este modelo.
 *
 * Relación N:M vía pivot (`catalog_product_family_items`) en vez de un
 * `family_id` directo en `catalog_products`: un mismo producto puede
 * pertenecer a más de una familia (ej. un spot en "Kit Baño" y en "Promoción
 * Verano" a la vez) sin duplicar la fila del catálogo.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::create($this->table('catalog_product_families'), function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create($this->table('catalog_product_family_items'), function (Blueprint $table): void {
            $table->id();
            // Senior Review 2026-07-13, sugerencia #1 — nombradas explícitas: el
            // nombre autogenerado por Laravel incluye el schema calificado
            // ("atlantic_ventas_diseno_catalog_product_family_items_family_id_
            // foreign"), supera los 63 bytes de Postgres y trunca en silencio (ver
            // memory/feedback_tenant_migration_constraint_name_truncation.md).
            $table->foreignId('family_id')
                ->constrained($this->table('catalog_product_families'), 'id', 'catalog_product_family_items_family_fk')
                ->cascadeOnDelete();
            $table->foreignId('catalog_product_id')
                ->constrained($this->table('catalog_products'), 'id', 'catalog_product_family_items_product_fk')
                ->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['family_id', 'catalog_product_id'], 'catalog_product_family_items_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('catalog_product_family_items'));
        Schema::dropIfExists($this->table('catalog_product_families'));
    }
};
