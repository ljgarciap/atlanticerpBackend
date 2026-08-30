<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Models;

use App\Models\User;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-467→472 (REQ-397→402) — una línea del Kardex. Inmutable (REQ-401): se crea solo vía
 * `KardexService::registrar()`, nunca se actualiza ni se borra — por eso `$timestamps = false`
 * (el modelo maneja `created_at` a mano, sin `updated_at`, que no tiene sentido en un log
 * append-only).
 *
 * @property int         $id
 * @property \Carbon\Carbon $created_at
 * @property int         $catalog_product_id
 * @property int         $warehouse_id
 * @property string      $tipo
 * @property int         $cantidad
 * @property string      $referencia_texto
 * @property string|null $referencia_entidad_tipo
 * @property int|null    $referencia_entidad_id
 * @property int|null    $realizado_por
 * @property int         $saldo_inicial
 * @property int         $saldo_resultante
 */
class InventoryMovement extends Model
{
    use LogsAuditTrail;

    public const TIPO_ENTRADA = 'Entrada';
    public const TIPO_SALIDA = 'Salida';
    public const TIPO_AJUSTE = 'Ajuste';
    public const TIPO_REUBICACION = 'Reubicación';
    public const TIPO_DEVOLUCION = 'Devolución';
    /** SCRUM-466 (REQ-396, Bloque B5) — ajuste al Kardex al aplicar un conteo general aprobado. */
    public const TIPO_CONTEO = 'Conteo';

    /**
     * Fase 4 — Servicios, Grupo D parte 2 (REQ-240 RN3, REQ-291 RN3(c)/RN4, SCRUM-361/347) —
     * salida automática de la Reserva Servicios al completar un Informe de Inspección con
     * materiales trackeados. Cantidad SIEMPRE negativa (mismo criterio que `TIPO_SALIDA`) —
     * nunca manual (RN4), disparada solo por `InspectionReportService`.
     */
    public const TIPO_CONSUMO = 'Consumo';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'catalog_product_id', 'warehouse_id', 'tipo', 'cantidad', 'referencia_texto',
        'referencia_entidad_tipo', 'referencia_entidad_id', 'realizado_por',
        'saldo_inicial', 'saldo_resultante', 'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'inventory_movements';
        }

        return "{$tenant->slug}_bodega.inventory_movements";
    }

    /** @return BelongsTo<CatalogProduct, $this> */
    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<User, $this> */
    public function realizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'realizado_por');
    }
}
