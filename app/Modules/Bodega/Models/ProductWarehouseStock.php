<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Models;

use App\Modules\Bodega\Exceptions\WarehouseSoloConsultaException;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-244 (REQ-181) — desglose de stock por bodega. Sin fila para un producto en una bodega =
 * 0 unidades ahí (ver docblock de la migración). `ubicacion`/`cliente_id` (SCRUM-455/456,
 * REQ-385/386) agregados 2026-07-21 — única fuente para Compras (Ver Inventario) y Bodega.
 *
 * @property int         $id
 * @property int         $catalog_product_id
 * @property int         $warehouse_id
 * @property string|null $ubicacion
 * @property int|null    $cliente_id
 * @property int         $quantity
 */
class ProductWarehouseStock extends Model
{
    use LogsAuditTrail;

    /** @var list<string> */
    protected $fillable = ['catalog_product_id', 'warehouse_id', 'ubicacion', 'cliente_id', 'quantity'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    /**
     * SCRUM-990 — punto único de control de aplicación: una bodega de SOLO CONSULTA (Zona Libre / Lafayette) no admite
     * stock. Lo duplica un trigger de BD (`product_warehouse_stock_solo_consulta`) que cubre también el SQL directo y los
     * `update()` masivos, que no disparan eventos de Eloquent.
     */
    protected static function booted(): void
    {
        static::saving(static function (self $row): void {
            if (isset(self::bodegasSoloConsulta()[(int) $row->warehouse_id])) {
                throw new WarehouseSoloConsultaException((int) $row->warehouse_id);
            }
        });
    }

    /**
     * Ids de las bodegas `solo_consulta`, en memoria por PROCESO y por tenant, con TTL corto
     * (`config('lafayette.cache_bodegas_solo_consulta_segundos')`). Antes el `saving` hacía UNA consulta por fila guardada (conteos
     * generales, recepciones con muchas líneas, `updateOrCreate` en bucles). Invalidación segura: (a) cualquier guardado o borrado de un
     * `Warehouse` la descarta (`Warehouse::booted()`); (b) el TTL acota la antigüedad cuando el cambio llega por SQL directo o desde otro
     * proceso (un worker de Horizon es de larga vida); (c) con TTL 0 consulta siempre. Es solo la red de seguridad de aplicación: el
     * trigger de BD sigue siendo la barrera definitiva y no depende de esta caché.
     *
     * @var array<string, array{0: int, 1: array<int, true>}> slug del tenant => [expira (unix), ids]
     */
    private static array $soloConsultaCache = [];

    /** @return array<int, true> */
    public static function bodegasSoloConsulta(): array
    {
        $slug = Tenant::current()->slug ?? '';
        $ttl = max(0, (int) config('lafayette.cache_bodegas_solo_consulta_segundos', 30));
        $now = time();

        $hit = self::$soloConsultaCache[$slug] ?? null;
        if ($ttl > 0 && $hit !== null && $hit[0] > $now) {
            return $hit[1];
        }

        $ids = [];
        foreach (Warehouse::query()->where('solo_consulta', true)->pluck('id') as $id) {
            $ids[(int) $id] = true;
        }
        self::$soloConsultaCache[$slug] = [$now + $ttl, $ids];

        return $ids;
    }

    /** Descarta la caché del conjunto `solo_consulta` (cambio de una bodega, o pruebas que la alteran por SQL directo). */
    public static function olvidarBodegasSoloConsulta(): void
    {
        self::$soloConsultaCache = [];
    }

    /**
     * SCRUM-990 — solo filas de bodegas OPERATIVAS (`solo_consulta = false`). Todo consumidor que SUMA, DESGLOSA, CUENTA o ASIGNA stock por
     * bodega lo aplica: mientras existan filas residuales de Zona Libre (entre el deploy y `lafayette:aislar-catalogo`, o para siempre en un
     * entorno donde no se corra) no pueden contarse ni recibir reservas.
     *
     * @param \Illuminate\Database\Eloquent\Builder<ProductWarehouseStock> $query
     * @return \Illuminate\Database\Eloquent\Builder<ProductWarehouseStock>
     */
    public function scopeEnBodegaOperativa($query)
    {
        return $query->whereIn($this->qualifyColumn('warehouse_id'), Warehouse::query()->operativas()->select('id'));
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'product_warehouse_stock';
        }

        return "{$tenant->slug}_bodega.product_warehouse_stock";
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<CatalogProduct, $this> */
    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class);
    }

    /** @return BelongsTo<MasterClient, $this> */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(MasterClient::class, 'cliente_id');
    }
}
