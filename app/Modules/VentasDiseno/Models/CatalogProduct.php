<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Compras\Models\Provider;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * REQ-036: producto real del Catálogo — reference/description/brand/photo_url
 * son la "ficha técnica" (REQ-036 AC2: foto, marca, descripción, disponible,
 * precio). `price_full`/`cost` son los datos que REQ-033 necesita y que un
 * ítem personalizado nunca tuvo — al seleccionar un producto (REQ-035), estos
 * valores se COPIAN a quote_items.unit_price/cost, no se referencian en vivo.
 *
 * `provider_id`/`factory_reference`/`reorder_point` — sprint2, módulo Compras (REQ-130/131):
 * `reference` (columna original) pasa a representar la referencia PÚBLICA (única global);
 * `factory_reference` es la referencia del proveedor, única solo dentro de `provider_id` (ver
 * docs/specs/compras-proveedores-sprint2.md). El "Inventario" que piden los tickets de Compras
 * es este mismo Catálogo, no una tabla nueva.
 *
 * `import_cost`/`freight_cost`/`handling_cost`/`other_cost` (SCRUM-237, REQ-174/164): desglose de
 * costo editable en Inventario. Para proveedor no local, `GoodsReceiptController` los puebla con
 * el prorrateo real de cada Ingreso de Mercancía (REQ-164), reemplazando cualquier estimado
 * manual anterior. Para proveedor local no aplican (quedan en 0; ITBMS sigue plegado en `cost`).
 * `costTotal()` es la única fuente de verdad del margen — no volver a copiar la fórmula
 * `(price_full - cost) / price_full` en otro lugar, usar este método.
 *
 * `technical_spec` (SCRUM-425/426, REQ-355/356): ficha técnica de 12 campos libres (voltage,
 * power, socket_type, color_temperature, luminous_flux, dimensions, weight, material_finish,
 * ip_rating, estimated_lifespan, warranty, certifications), persistidos juntos como un solo JSONB.
 * Nullable — productos creados antes de este ticket no la tienen y no se backfillea con datos
 * falsos; `StoreInventoryProductRequest` la exige completa (los 12 sub-campos) solo al CREAR un
 * producto nuevo desde Compras (REQ-356 RN1/RN2), nunca retroactivo.
 *
 * `technical_sheet_key`/`technical_sheet_filename`/`technical_sheet_uploaded_at` (SCRUM-698,
 * REQ-618): ficha técnica de la pantalla Catálogo — un ARCHIVO real (PDF/PNG/JPG) en S3, concepto
 * DISTINTO de `technical_spec` de arriba (esa es data estructurada, esta es un documento). Sin
 * versionado múltiple — ver `CatalogService::uploadTechnicalSheet()`.
 *
 * `name` (SCRUM-237/240, rebotes de Gerencia Test 2026-08-12): nombre visible del producto,
 * independiente de `description`. Antes de esto `description` hacía doble función de "nombre" y
 * "descripción" — editar una cambiaba la otra en toda la app (tablas, búsqueda, PDF, Excel,
 * candidatos de sustitutos). Todo lugar que muestra/ordena/busca por el nombre del producto usa
 * `name`, nunca `description` — ver migración
 * `2026_08_15_100000_add_name_backfill_from_description_to_ventas_diseno_catalog_products_table`.
 *
 * @property int $id
 * @property string $reference
 * @property string|null $factory_reference
 * @property string $name
 * @property string $description
 * @property string|null $brand
 * @property string|null $photo_url
 * @property float $price_full
 * @property float|null $cost
 * @property float $import_cost
 * @property float $freight_cost
 * @property float $handling_cost
 * @property float $other_cost
 * @property array<string, string>|null $technical_spec
 * @property int|null $stock_quantity
 * @property int|null $reorder_point
 * @property int|null $provider_id
 * @property bool $is_active
 * @property string|null $rotation
 * @property string|null $category
 * @property string|null $technical_sheet_key
 * @property string|null $technical_sheet_filename
 * @property Carbon|null $technical_sheet_uploaded_at
 * @property string|null $barcode
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CatalogProduct extends Model
{
    use LogsAuditTrail;

    public const ROTATION_ALTA = 'alta';

    public const ROTATION_MEDIA = 'media';

    public const ROTATION_BAJA = 'baja';

    // SCRUM-194 (REQ-131, mockup 2H__Compras_NuevaOrden.html) — "Compra única": producto que no
    // se reordena por patrón de rotación, se compra puntual para un proyecto específico.
    public const ROTATION_UNICA = 'unica';

    /** @var list<string> */
    public const ROTATIONS = [
        self::ROTATION_ALTA, self::ROTATION_MEDIA, self::ROTATION_BAJA, self::ROTATION_UNICA,
    ];

    /**
     * SCRUM-194 (REQ-131) — 8 valores fijos del mockup, distinto de `catalog_product_families`
     * (REQ-037: combos que se cotizan juntos, ej. "Kit Baño" — concepto no relacionado).
     *
     * @var list<string>
     */
    public const CATEGORIES = [
        'candelabros_colgantes', 'iluminacion_techo', 'apliques_pared', 'iluminacion_bano',
        'iluminacion_exterior', 'lamparas_piso_mesa', 'iluminacion_empotrada', 'bombillos',
    ];

    /**
     * SCRUM-425/426 (REQ-355/356) — los 12 sub-campos de `technical_spec`, fuente única de verdad
     * para la validación de `StoreInventoryProductRequest` y para el modal de solo lectura de
     * SCRUM-425 — no repetir esta lista en otro lugar.
     *
     * @var list<string>
     */
    public const TECHNICAL_SPEC_FIELDS = [
        'voltage', 'power', 'socket_type', 'color_temperature', 'luminous_flux', 'dimensions',
        'weight', 'material_finish', 'ip_rating', 'estimated_lifespan', 'warranty', 'certifications',
    ];

    /** @var list<string> */
    protected $fillable = [
        'reference', 'factory_reference', 'name', 'description', 'brand', 'photo_url', 'price_full', 'cost',
        'import_cost', 'freight_cost', 'handling_cost', 'other_cost', 'technical_spec',
        'stock_quantity', 'reorder_point', 'provider_id', 'is_active', 'rotation', 'category',
        'technical_sheet_key', 'technical_sheet_filename', 'technical_sheet_uploaded_at', 'barcode',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price_full' => 'float',
            'cost' => 'float',
            'import_cost' => 'float',
            'freight_cost' => 'float',
            'handling_cost' => 'float',
            'other_cost' => 'float',
            'technical_spec' => 'array',
            'stock_quantity' => 'integer',
            'reorder_point' => 'integer',
            'is_active' => 'boolean',
            'technical_sheet_uploaded_at' => 'datetime',
        ];
    }

    /** REQ-615 RN4 — badge "✓ Ficha" / "⚠ Sin ficha". */
    public function hasTechnicalSheet(): bool
    {
        return $this->technical_sheet_key !== null;
    }

    /** @return BelongsTo<Provider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /**
     * SCRUM-237 — N:M real (un producto puede estar en varias familias), pero el modal de
     * Inventario edita una sola a la vez (ver InventoryController::update()) por simplicidad de
     * UI; esta relación se mantiene N:M para no perder membresías creadas desde otro flujo.
     *
     * @return BelongsToMany<CatalogProductFamily, $this>
     */
    public function families(): BelongsToMany
    {
        $tenant = Tenant::current();
        $pivot = $tenant !== null
            ? "{$tenant->slug}_ventas_diseno.catalog_product_family_items"
            : 'catalog_product_family_items';

        return $this->belongsToMany(CatalogProductFamily::class, $pivot, 'catalog_product_id', 'family_id');
    }

    /**
     * SCRUM-743 (REQ n/a — filtro de bodegas en Inventario) — cross-schema
     * (`ventas_diseno.catalog_products` → `bodega.product_warehouse_stock`), mismo patrón ya usado
     * en otros módulos para FKs cross-schema (ej. `sales_project_id` en Servicios). Solo usada para
     * el `whereHas()` del filtro `warehouse_ids[]` en `InventoryController::index()` — el dato en sí
     * (nombre/cantidad por bodega) lo sigue sirviendo `InventoryKpiService::warehousesMap()`, no se
     * duplica acá.
     *
     * @return HasMany<ProductWarehouseStock, $this>
     */
    public function warehouseStocks(): HasMany
    {
        return $this->hasMany(ProductWarehouseStock::class, 'catalog_product_id');
    }

    public function costTotal(): float
    {
        return round(
            (float) $this->cost + $this->import_cost + $this->freight_cost
                + $this->handling_cost + $this->other_cost,
            2
        );
    }

    public function marginPercent(): ?float
    {
        if ($this->price_full <= 0) {
            return null;
        }

        return round((($this->price_full - $this->costTotal()) / $this->price_full) * 100, 2);
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'catalog_products';
        }

        return "{$tenant->slug}_ventas_diseno.catalog_products";
    }
}
