<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1136 F5 (épica SCRUM-749) — artículos descatalogados de ICG con stock. Un descatalogado cuya referencia (`ref_proveedor`, recortada y sin distinguir
 * mayúsculas) coincide con la de un artículo ACTIVO ya presente en `legacy.productos` es, para AtlanticERP, el mismo producto físico: `equivalente_icg_codarticulo`
 * guarda el `icg_codarticulo` de ese activo (el vínculo), y `catalog_product_id` del descatalogado apunta al mismo `catalog_products` del activo para que el mapeo
 * de stock sume sobre un solo producto, sin crear un `catalog_products` duplicado.
 *
 * Nullable y sin FK a propósito: es un vínculo informativo dentro del mismo staging (`icg_codarticulo` es UNIQUE pero el activo puede dejar de estar en staging) y
 * nunca debe bloquear una recarga. Aditiva: no cambia ninguna fila existente.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::table($this->table('productos'), function (Blueprint $table): void {
            $table->unsignedInteger('equivalente_icg_codarticulo')->nullable()->after('catalog_product_id');
            $table->index('equivalente_icg_codarticulo', 'legacy_productos_equivalente_idx');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('productos'), function (Blueprint $table): void {
            $table->dropIndex('legacy_productos_equivalente_idx');
            $table->dropColumn('equivalente_icg_codarticulo');
        });
    }
};
