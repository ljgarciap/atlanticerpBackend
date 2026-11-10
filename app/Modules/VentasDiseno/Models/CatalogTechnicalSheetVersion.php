<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Fase 31a — versión retirada de la ficha técnica de un producto (reemplazada o quitada). El archivo se conserva en el disco.
 *
 * @property int         $id
 * @property int         $catalog_product_id
 * @property string      $storage_key
 * @property string|null $filename
 * @property Carbon|null $uploaded_at
 * @property Carbon      $retired_at
 * @property string      $retired_reason
 * @property int|null    $retired_by
 */
class CatalogTechnicalSheetVersion extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['catalog_product_id', 'storage_key', 'filename', 'uploaded_at', 'retired_at', 'retired_reason', 'retired_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['uploaded_at' => 'datetime', 'retired_at' => 'datetime'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();

        return $tenant === null ? 'catalog_technical_sheet_versions' : "{$tenant->slug}_ventas_diseno.catalog_technical_sheet_versions";
    }
}
