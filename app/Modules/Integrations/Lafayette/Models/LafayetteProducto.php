<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Lafayette\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * SCRUM-990 — espejo de SOLO CONSULTA de `vista_productos_lighting` (catálogo de Lafayette Lighting, que controla otra
 * empresa y otro sistema), aislado del catálogo de AtlanticERP. Lo escribe únicamente `lafayette:sync-productos`
 * (`LafayetteProductoLoader`); la API lo expone solo en lectura (`GET /bodega/zona-libre/productos`).
 *
 * NO usa `LogsAuditTrail` a propósito: el catálogo compartido inundó `audit_logs` con ~172K eventos por cada corrida del
 * sincronizador; la trazabilidad es el resumen de cada corrida (`AuditService::BODEGA_LAFAYETTE_SYNC_COMPLETED`).
 *
 * `search_text` es una columna GENERATED STORED (la mantiene Postgres): no entra en `$fillable`.
 *
 * @property int                 $id
 * @property string              $lafayette_id           `id` de la fuente (ej. LNP0000001) — clave de upsert
 * @property string|null         $referencia
 * @property string|null         $descripcion
 * @property string|null         $marca
 * @property string|null         $categoria
 * @property string|null         $url_imagen
 * @property int                 $disponible
 * @property float|null          $precio_5               decisión de Luis 2026-09-25 (reversible); ya no se muestra (SCRUM-1125)
 * @property float|null          $precio_6               precio que muestra el catálogo de Zona Libre (SCRUM-1125, 2026-10-09)
 * @property \Carbon\Carbon|null $fuente_actualizado_at  `cache_updated_at` de la fuente EN LA ÚLTIMA VEZ QUE CAMBIÓ el dato de esta fila (no entra en la comparación del upsert:
 *                                                      no es la "última reconstrucción de caché" de Lafayette; la última sincronización es la de `LafayetteSyncRun`)
 * @property \Carbon\Carbon|null $fuera_de_fuente_at     marcado cuando el producto desaparece de la fuente (se oculta, no se borra)
 */
class LafayetteProducto extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'lafayette_id', 'referencia', 'descripcion', 'marca', 'categoria', 'url_imagen', 'disponible', 'precio_5', 'precio_6',
        'fuente_actualizado_at', 'fuera_de_fuente_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'disponible'            => 'integer',
            'precio_5'              => 'float',
            'precio_6'              => 'float',
            'fuente_actualizado_at' => 'datetime',
            'fuera_de_fuente_at'    => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'lafayette_productos';
        }

        return "{$tenant->slug}_bodega.lafayette_productos";
    }
}
