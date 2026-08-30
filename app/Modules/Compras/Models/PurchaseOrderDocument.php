<?php

declare(strict_types=1);

namespace App\Modules\Compras\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REQ-155 — checklist de documentos del envío. `category` es una de
 * PurchaseOrderDocument::CATEGORIES (catálogo fijo, no texto libre — mismo criterio que
 * `providers.origin`, necesario para que el checklist sepa qué casillas marcar).
 *
 * @property int    $id
 * @property int    $purchase_order_id
 * @property string $category
 * @property string $original_filename
 * @property string $storage_key
 * @property int    $size_bytes
 * @property string $mime_type
 * @property int|null $uploaded_by
 * @property \Carbon\Carbon $created_at
 */
class PurchaseOrderDocument extends Model
{
    use LogsAuditTrail;

    public const CATEGORY_FACTURA_COMERCIAL = 'factura_comercial';
    public const CATEGORY_DECLARACION_NACIONALIZACION = 'declaracion_nacionalizacion';
    public const CATEGORY_PERMISO_IMPORTACION = 'permiso_importacion';
    /** REQ-155 (SCRUM-218, hallazgo Pre-QA 2026-08-05) — BL/conocimiento de embarque, del mockup 2B__Compras_Logistica.html, nunca existía como categoría. */
    public const CATEGORY_BL = 'bl';
    /** SCRUM-211 — documento del proveedor validado contra la orden vía IA (ver ADR-SCRUM211). */
    public const CATEGORY_CONFIRMACION_PROVEEDOR = 'confirmacion_proveedor';
    public const CATEGORY_OTRO = 'otro';

    /** @var list<string> */
    public const CATEGORIES = [
        self::CATEGORY_FACTURA_COMERCIAL,
        self::CATEGORY_DECLARACION_NACIONALIZACION,
        self::CATEGORY_PERMISO_IMPORTACION,
        self::CATEGORY_BL,
        self::CATEGORY_CONFIRMACION_PROVEEDOR,
        self::CATEGORY_OTRO,
    ];

    /** @var list<string> */
    protected $fillable = [
        'purchase_order_id', 'category', 'original_filename', 'storage_key',
        'size_bytes', 'mime_type', 'uploaded_by',
    ];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'purchase_order_documents';
        }

        return "{$tenant->slug}_compras.purchase_order_documents";
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}
