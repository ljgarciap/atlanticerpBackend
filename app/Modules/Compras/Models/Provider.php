<?php

declare(strict_types=1);

namespace App\Modules\Compras\Models;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * REQ-120→127 — único campo obligatorio es `name` (REQ-126 RN1); el resto se completa después.
 * `bank_*`/`contact_*` son las 3 secciones planas del modal de detalle (REQ-125) — sin tabla
 * aparte, ningún criterio de aceptación pide múltiples contactos ni múltiples cuentas bancarias.
 *
 * `category` (REQ-126): mockup `2G__Compras_Proveedores.html` — uno de 5 valores fijos (ver
 * CATEGORIES abajo), no texto libre. Realineado 2026-07-30 (SCRUM-189, hallazgo de Gerencia Test,
 * autorizado por Luis) — hasta entonces era texto libre sin relación con el mockup real.
 *
 * @property int $id
 * @property string $name
 * @property string|null $category
 * @property string|null $tax_id
 * @property string|null $tax_id_dv
 * @property string $origin
 * @property string $currency
 * @property string|null $contact_name
 * @property string|null $whatsapp
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property string|null $country
 * @property string|null $bank_name
 * @property string|null $bank_account_number
 * @property string|null $bank_swift
 * @property string|null $bank_beneficiary
 * @property string|null $bank_image_storage_key
 * @property string|null $bank_image_original_filename
 * @property int|null $bank_image_size_bytes
 * @property string|null $bank_image_mime_type
 * @property bool $is_active
 * @property Carbon $created_at
 */
class Provider extends Model
{
    use LogsAuditTrail;

    /** REQ-141 — determina si el ciclo de estados de sus órdenes salta tránsito/aduana. */
    public const ORIGIN_LOCAL = 'local';

    public const ORIGIN_INTERNACIONAL = 'internacional';

    public const CATEGORY_LOCALES = 'locales';

    public const CATEGORY_ZONA_LIBRE = 'zona_libre';

    public const CATEGORY_CHINA = 'china';

    public const CATEGORY_EUROPA = 'europa';

    public const CATEGORY_ONLINE = 'online';

    /** SCRUM-820 — 6ta categoría, agregada 2026-08-31 a pedido de Gerencia Test. */
    public const CATEGORY_LATINOAMERICA = 'latinoamerica';

    /** @var list<string> */
    public const CATEGORIES = [
        self::CATEGORY_LOCALES, self::CATEGORY_ZONA_LIBRE, self::CATEGORY_CHINA,
        self::CATEGORY_EUROPA, self::CATEGORY_ONLINE, self::CATEGORY_LATINOAMERICA,
    ];

    /** @var list<string> */
    protected $fillable = [
        'name', 'category', 'tax_id', 'tax_id_dv', 'origin', 'currency', 'contact_name', 'whatsapp', 'phone', 'email',
        'address', 'country', 'bank_name', 'bank_account_number', 'bank_swift', 'bank_beneficiary',
        'is_active', 'created_by',
        // SCRUM-819 — solo asignados por ProviderBankImageService (upload()/delete()), nunca por
        // StoreProviderRequest/UpdateProviderRequest (esas Requests no los validan, así que
        // $request->validated() nunca los deja pasar por ProviderController::store()/update()).
        'bank_image_storage_key', 'bank_image_original_filename',
        'bank_image_size_bytes', 'bank_image_mime_type',
    ];

    /**
     * REQ-141 — `origin` ya no es un campo editable independiente en el form (SCRUM-189, no está
     * en el mockup): se deriva de `category`. "Locales" es el único origen local; el resto
     * (Zona Libre, China, Europa, Online) es internacional — mismo criterio ya usado a propósito
     * en `ZonaLibreProviderSeeder` (Zona Libre está en Panamá pero SÍ pasa por aduana/liquidación).
     */
    public static function deriveOrigin(?string $category): string
    {
        return $category === self::CATEGORY_LOCALES ? self::ORIGIN_LOCAL : self::ORIGIN_INTERNACIONAL;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'providers';
        }

        return "{$tenant->slug}_compras.providers";
    }

    /** @return HasMany<PurchaseOrder, $this> */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /**
     * Productos del Catálogo (Ventas & Diseño) asociados a este proveedor.
     *
     * @return HasMany<CatalogProduct, $this>
     */
    public function catalogProducts(): HasMany
    {
        return $this->hasMany(CatalogProduct::class);
    }

    /**
     * REQ-124: 40% entregas a tiempo + 35% entregas completas + 25% sin reclamos. RN2: sin
     * órdenes recibidas = "Sin calificar" (null), nunca 0/100.
     *
     * Actualizado 2026-07-18 (hallazgo de QA en SCRUM-264, decisión de alcance con Luis):
     * "entregas completas" (35%) sigue sin datos reales — depende de Ingreso de Mercancía
     * (recepción parcial por línea, REQ-145/SCRUM-220→230, sin construir). "Entregas a tiempo"
     * (40%) y "sin reclamos" (25%, ya existen Garantías y Reclamos, SCRUM-257→261) SÍ son
     * calculables hoy. En vez de seguir devolviendo null indefinidamente, se calcula con los 2
     * componentes disponibles, RENORMALIZANDO sus pesos para que sumen 100% (40/65 y 25/65) —
     * así un proveedor perfecto en ambos ejes disponibles muestra 100, no 65 (un tope fijo en 65
     * se leería como un bug, no como "falta un componente"). El número se recalculará solo
     * cuando "entregas completas" exista — no hace falta tocar este método ese día, solo agregar
     * el tercer componente al peso disponible.
     */
    public function ratingScore(): ?float
    {
        /** @var Collection<int, PurchaseOrder> $received */
        $received = $this->receivedOrders();

        if ($received->isEmpty()) {
            return null;
        }

        $onTimePercent = $this->computeOnTimePercent($received);
        $noClaimsPercent = $this->noClaimsPercent($received);

        // "Sin reclamos" (25%) siempre está disponible acá — nunca se llega a este punto con
        // $received vacío (ya se retornó null arriba) — por eso $weight nunca puede ser 0.
        $weight = 25.0;
        $weightedSum = $noClaimsPercent * 25;
        if ($onTimePercent !== null) {
            $weightedSum += $onTimePercent * 40;
            $weight += 40;
        }

        return round($weightedSum / $weight, 1);
    }

    /**
     * SCRUM-264 — expuesto aparte de ratingScore() para que el panel "Calificación de
     * proveedores" (REQ-201) muestre el mismo % que compone la calificación, no un cálculo
     * paralelo que pueda desincronizarse (mismo criterio que InventoryKpiService).
     */
    public function onTimePercent(): ?float
    {
        return $this->computeOnTimePercent($this->receivedOrders());
    }

    /**
     * SCRUM-264 — "entregas completas" (35% de REQ-124) depende de Ingreso de Mercancía
     * (recepción parcial por línea, REQ-145/SCRUM-220→230), que no existe. Null explícito, nunca
     * un número inventado — el panel debe mostrar "—", no un valor calculado sin datos reales.
     */
    public function completePercent(): ?float
    {
        return null;
    }

    /** @return Collection<int, PurchaseOrder> */
    private function receivedOrders(): Collection
    {
        return $this->purchaseOrders()->where('status', PurchaseOrder::STATUS_RECIBIDO)->get();
    }

    /**
     * @param  Collection<int, PurchaseOrder>  $received
     */
    private function computeOnTimePercent(Collection $received): ?float
    {
        // "A tiempo" solo se puede evaluar contra órdenes con fecha estimada — sin fecha
        // estimada no hay contra qué medir, se excluyen del denominador en vez de asumir.
        // PurchaseOrder::isOnTime() (fix 2026-07-20, REQ-112) hace exactamente este mismo check
        // per-orden, reusado acá para no duplicar la fórmula.
        $withEstimate = $received->filter(fn (PurchaseOrder $o) => $o->isOnTime() !== null);
        if ($withEstimate->isEmpty()) {
            return null;
        }

        $onTime = $withEstimate->filter(fn (PurchaseOrder $o): bool => $o->isOnTime() === true);

        return round($onTime->count() / $withEstimate->count() * 100, 1);
    }

    /** @param Collection<int, PurchaseOrder> $received */
    private function noClaimsPercent(Collection $received): float
    {
        $orderIds = $received->pluck('id');
        $ordersWithClaims = PurchaseOrderClaim::whereIn('purchase_order_id', $orderIds)
            ->distinct('purchase_order_id')->count('purchase_order_id');

        return (($received->count() - $ordersWithClaims) / $received->count()) * 100;
    }
}
