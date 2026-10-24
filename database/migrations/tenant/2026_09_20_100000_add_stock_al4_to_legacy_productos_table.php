<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-921 (épica SCRUM-749 — Migración ICG→AtlanticERP). Stock real de `STOCKS WHERE
 * CODALMACEN='AL4'` (Bodega Central) por artículo, capturado por `ProductoExtractor`/
 * `ProductoStagingLoader`/`ProductoIncrementalStagingLoader` — ver docblock de esas clases y de
 * `App\Modules\Legacy\Services\CatalogProductLoader` para el uso (seed de `catalog_products.
 * stock_quantity` solo al crear, nunca en re-sincronización).
 *
 * `null` = el artículo no tiene ninguna fila en AL4 (nunca tuvo movimiento ahí), distinto de `0`
 * (stock real cero) — mismo significado que `catalog_products.stock_quantity` nullable ya usa
 * ("disponibilidad no controlada").
 *
 * Decimal (no integer): `STOCKS.STOCK` en ICG es `float` — se preserva tal cual en staging,
 * incluyendo los valores negativos confirmados en data real (decisión de negocio de Luis
 * 2026-09-20: preservar, no truncar a 0 ni excluir). El redondeo a entero para
 * `catalog_products.stock_quantity` (integer) ocurre recién en `CatalogProductLoader`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::table($this->table('productos'), function (Blueprint $table): void {
            $table->decimal('stock_al4', 12, 2)->nullable()->after('source_hash');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('productos'), function (Blueprint $table): void {
            $table->dropColumn('stock_al4');
        });
    }
};
