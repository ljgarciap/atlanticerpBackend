<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Models;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 4 — Servicios, Batch 8 (REQ-240 RN1/RN2) — material/insumo de texto libre usado en la
 * inspección. Sin FK a `catalog_products` a propósito para el registro puramente informativo — ver
 * docblock de la migración original.
 *
 * `catalog_product_id` (Grupo D parte 2, REQ-240 RN3/RN4, SCRUM-361) — NULLABLE, aditivo: solo se
 * setea cuando el técnico selecciona un insumo real trackeado por la Reserva Servicios (ver
 * `InsumoService`). Cuando está presente, `InspectionReportService` genera un movimiento de
 * Consumo real en el Kardex al completar el informe (por primera vez) — ver su docblock. Material
 * sin `catalog_product_id` (todo el histórico previo a este batch, y cualquier insumo no
 * trackeado) sigue siendo puramente informativo, sin efecto de inventario.
 *
 * @property int      $id
 * @property int      $inspection_report_id
 * @property string   $nombre_material
 * @property string   $cantidad
 * @property int|null $catalog_product_id
 * @property-read InspectionReport $inspectionReport
 * @property-read CatalogProduct|null $catalogProduct
 */
class InspectionReportMaterial extends Model
{
    use LogsAuditTrail;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['inspection_report_id', 'nombre_material', 'cantidad', 'catalog_product_id'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'inspection_report_materials';
        }

        return "{$tenant->slug}_servicios.inspection_report_materials";
    }

    /** @return BelongsTo<InspectionReport, $this> */
    public function inspectionReport(): BelongsTo
    {
        return $this->belongsTo(InspectionReport::class);
    }

    /** @return BelongsTo<CatalogProduct, $this> */
    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class);
    }
}
