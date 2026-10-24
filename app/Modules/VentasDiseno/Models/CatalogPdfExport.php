<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * SCRUM-998 — exportación del Catálogo en segundo plano (ZIP de volúmenes). Ver la migración
 * `create_ventas_diseno_catalog_pdf_exports_table` y docs/specs/scrum998-catalog-pdf-background.md.
 *
 * @property int                                                   $id
 * @property string                                                $uuid
 * @property int                                                   $requested_by
 * @property string                                                $mode
 * @property list<int>|null                                        $product_ids
 * @property int                                                   $total_products
 * @property int                                                   $volumes
 * @property int                                                   $volume_size
 * @property string                                                $status
 * @property string|null                                           $storage_key
 * @property int|null                                              $size_bytes
 * @property string|null                                           $error_message
 * @property \Illuminate\Support\Carbon|null                       $started_at
 * @property \Illuminate\Support\Carbon|null                       $finished_at
 * @property \Illuminate\Support\Carbon|null                       $expires_at
 * @property \Illuminate\Support\Carbon|null                       $created_at
 */
class CatalogPdfExport extends Model
{
    public const STATUS_QUEUED  = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE    = 'done';
    public const STATUS_FAILED  = 'failed';

    public const MODE_COMPLETO      = 'completo';
    public const MODE_SELECCIONADOS = 'seleccionados';

    /** @var list<string> */
    protected $fillable = [
        'uuid', 'requested_by', 'mode', 'product_ids', 'total_products', 'volumes', 'volume_size', 'status',
        'storage_key', 'size_bytes', 'error_message', 'started_at', 'finished_at', 'expires_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'product_ids' => 'array',
            'started_at'  => 'datetime',
            'finished_at' => 'datetime',
            'expires_at'  => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'catalog_pdf_exports';
        }

        return "{$tenant->slug}_ventas_diseno.catalog_pdf_exports";
    }
}
