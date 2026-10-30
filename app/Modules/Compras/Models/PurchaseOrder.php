<?php

declare(strict_types=1);

namespace App\Modules\Compras\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Carbon\Carbon;
use App\Modules\Compras\Services\PurchaseApprovalRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * REQ-128→133 (Nueva Orden, 2H) + REQ-138→156 (Ver Órdenes 2A / Logística 2B, sprint2) — `status`
 * usa las 7 etapas de REQ-117. `requires_primary_approval` se calcula al crear (RN3 de REQ-118).
 *
 * `modality` (hoy solo `directo`) es un eje DISTINTO de `shipping_type` — REQ-153 los trata como
 * 2 datos separados de la tarjeta de envío, no el mismo campo.
 *
 * @property int $id
 * @property int $provider_id
 * @property string $status
 * @property string|null $pending_remainder_status
 * @property string|null $shipping_type
 * @property string|null $who_pays_shipping
 * @property float|null $shipping_cost
 * @property float|null $import_cost
 * @property float|null $freight_cost
 * @property float|null $handling_cost
 * @property float|null $other_cost
 * @property int|null $liquidation_agency_id
 * @property string $modality
 * @property Carbon|null $estimated_arrival_date
 * @property Carbon|null $actual_arrival_date
 * @property string|null $container_number
 * @property string|null $carrier
 * @property bool $requires_primary_approval
 * @property int|null $approved_by
 * @property string|null $currency
 * @property float $total_amount
 * @property string|null $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $approved_at
 * @property Carbon $status_changed_at
 * @property Carbon|null $payment_requested_at
 * @property float|null $pending_amount_change
 * @property int|null $amount_change_requested_by
 * @property Carbon|null $ordenado_at
 * @property Carbon|null $en_transito_at
 * @property Carbon|null $en_aduana_at
 * @property Carbon|null $en_transito_local_at
 * @property string|null $origin_module
 * @property Carbon|null $remainder_status_changed_at
 * @property Carbon|null $remainder_salio_de_origen_at
 * @property Carbon|null $remainder_en_transito_at
 * @property Carbon|null $remainder_en_aduana_at
 * @property Carbon|null $remainder_en_transito_local_at
 */
class PurchaseOrder extends Model
{
    use LogsAuditTrail;

    public const STATUS_POR_APROBAR = 'por_aprobar';

    public const STATUS_PENDIENTE_LIQUIDAR = 'pendiente_liquidar';

    public const STATUS_ORDENADO = 'ordenado';

    public const STATUS_EN_TRANSITO = 'en_transito';

    public const STATUS_EN_ADUANA = 'en_aduana';

    public const STATUS_EN_TRANSITO_LOCAL = 'en_transito_local';

    public const STATUS_RECIBIDO = 'recibido';

    public const SHIPPING_AEREO = 'aereo';

    public const SHIPPING_MARITIMO = 'maritimo';

    public const SHIPPING_TERRESTRE = 'terrestre';

    public const PAYS_ATLANTIC = 'atlantic';

    public const PAYS_PROVIDER = 'proveedor';

    public const MODALITY_DIRECTO = 'directo';

    /**
     * Fase 4 — Servicios, Grupo D parte 2 (REQ-274, SCRUM-344) — único valor real de
     * `origin_module` hoy: órdenes generadas automáticamente por
     * `App\Modules\Servicios\Services\InsumoService::requestPurchase()` para reponer la Reserva
     * Servicios. Toda orden creada directo desde "Nueva Orden" (Compras) queda con
     * `origin_module = NULL`.
     */
    public const ORIGIN_SERVICIOS = 'servicios';

    public const PAYMENT_STATUS_PENDIENTE = 'pendiente';

    public const PAYMENT_STATUS_PARCIAL = 'parcial';

    public const PAYMENT_STATUS_COMPLETO = 'completo';

    /**
     * REQ-165 — secuencia completa, incluso etapas anteriores al `status` real de la orden.
     *
     * `REMAINDER_ORDENADO` agregado 2026-08-07 (Pre-QA, hallazgo real contra el rebote de Gerencia
     * Test en SCRUM-208, 2026-08-06): el AC pide que "las etapas de selección de lo que no va a
     * llegar aún deben estar todas desde ordenado en adelante", pero el selector solo ofrecía
     * `salio_de_origen`/`en_transito`/`en_aduana`/`en_transito_local` — un remanente que todavía
     * no salió del proveedor (ej. una línea que ni siquiera se despachó) no tenía ninguna opción
     * que lo representara. `showsGoodsReceiptLink()` no necesita cambios: `ordenado` no está en
     * `[EN_ADUANA, EN_TRANSITO_LOCAL]`, así que sigue ocultando el enlace de Ingreso de Mercancía
     * hasta que el remanente avance, igual que antes para `salio_de_origen`/`en_transito`.
     */
    public const REMAINDER_ORDENADO = 'ordenado';

    public const REMAINDER_SALIO_DE_ORIGEN = 'salio_de_origen';

    public const REMAINDER_EN_TRANSITO = 'en_transito';

    public const REMAINDER_EN_ADUANA = 'en_aduana';

    public const REMAINDER_EN_TRANSITO_LOCAL = 'en_transito_local';

    /** @var list<string> */
    public const REMAINDER_STATUSES = [
        self::REMAINDER_ORDENADO, self::REMAINDER_SALIO_DE_ORIGEN, self::REMAINDER_EN_TRANSITO,
        self::REMAINDER_EN_ADUANA, self::REMAINDER_EN_TRANSITO_LOCAL,
    ];

    /**
     * SCRUM-208 (rediseño 2026-08-15) — columna de fecha que se setea cuando el REMANENTE
     * (`pending_remainder_status`) alcanza cada etapa, mismo espíritu que `STAGE_DATE_COLUMNS`
     * pero para `advanceRemainder()`. Sin entrada para `REMAINDER_ORDENADO`: el remanente nace
     * directamente ahí (ver docblock de la migración). Sin filtrar por `provider_origin` a
     * propósito — el selector de `pending_remainder_status` en el wizard de Ingreso de Mercancía
     * ya ofrece las 5 etapas sin distinguir proveedor local/internacional (ver
     * `GoodsReceiptController`), esto mantiene el mismo criterio ya establecido.
     *
     * @var array<string, string>
     */
    public const REMAINDER_STAGE_DATE_COLUMNS = [
        self::REMAINDER_SALIO_DE_ORIGEN => 'remainder_salio_de_origen_at',
        self::REMAINDER_EN_TRANSITO => 'remainder_en_transito_at',
        self::REMAINDER_EN_ADUANA => 'remainder_en_aduana_at',
        self::REMAINDER_EN_TRANSITO_LOCAL => 'remainder_en_transito_local_at',
    ];

    /** REQ-145 — "Recepción", por línea y consolidada de la orden. */
    public const RECEPTION_PENDIENTE = 'pendiente';

    public const RECEPTION_PARCIAL = 'parcial';

    public const RECEPTION_COMPLETO = 'completo';

    /**
     * REQ-153/154 (SCRUM-216/217) — columna de fecha que se setea cuando la orden ALCANZA cada
     * etapa (`PurchaseOrderController::advance()`). `STATUS_RECIBIDO` no está acá — reusa
     * `actual_arrival_date`, ya existente, en vez de una 5ta columna nueva.
     *
     * @var array<string, string>
     */
    public const STAGE_DATE_COLUMNS = [
        self::STATUS_ORDENADO => 'ordenado_at',
        self::STATUS_EN_TRANSITO => 'en_transito_at',
        self::STATUS_EN_ADUANA => 'en_aduana_at',
        self::STATUS_EN_TRANSITO_LOCAL => 'en_transito_local_at',
    ];

    /** @var list<string> */
    protected $fillable = [
        'provider_id', 'status', 'shipping_type', 'who_pays_shipping', 'shipping_cost', 'modality',
        'import_cost', 'freight_cost', 'handling_cost', 'other_cost',
        'estimated_arrival_date', 'actual_arrival_date', 'container_number', 'carrier',
        'requires_primary_approval', 'approved_by', 'approved_at', 'currency', 'total_amount',
        'notes', 'created_by', 'status_changed_at', 'liquidation_agency_id',
        'payment_requested_at', 'pending_amount_change', 'amount_change_requested_by',
        'pending_remainder_status', 'ordenado_at', 'en_transito_at', 'en_aduana_at', 'en_transito_local_at',
        'origin_module', 'remainder_status_changed_at', 'remainder_salio_de_origen_at',
        'remainder_en_transito_at', 'remainder_en_aduana_at', 'remainder_en_transito_local_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'requires_primary_approval' => 'boolean',
            'total_amount' => 'float',
            'shipping_cost' => 'float',
            'import_cost' => 'float',
            'freight_cost' => 'float',
            'handling_cost' => 'float',
            'other_cost' => 'float',
            'approved_at' => 'datetime',
            'estimated_arrival_date' => 'date',
            'actual_arrival_date' => 'date',
            'status_changed_at' => 'datetime',
            'payment_requested_at' => 'datetime',
            'pending_amount_change' => 'float',
            'ordenado_at' => 'datetime',
            'en_transito_at' => 'datetime',
            'en_aduana_at' => 'datetime',
            'en_transito_local_at' => 'datetime',
            'remainder_status_changed_at' => 'datetime',
            'remainder_salio_de_origen_at' => 'datetime',
            'remainder_en_transito_at' => 'datetime',
            'remainder_en_aduana_at' => 'datetime',
            'remainder_en_transito_local_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'purchase_orders';
        }

        return "{$tenant->slug}_compras.purchase_orders";
    }

    /** @return BelongsTo<Provider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /**
     * REQ-153 — "responsable" mostrado en la tarjeta de envío.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * SCRUM-206 — quien realmente aprobó (para mostrar el nombre real en la UI, en vez del
     * texto fijo "Aprobada por Gerencia" que no reflejaba `approved_by`).
     *
     * @return BelongsTo<User, $this>
     */
    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * SCRUM-210 (REQ-147, alcance reducido) — agencia de liquidación asignada, solo aplica a
     * órdenes de proveedores internacionales.
     *
     * @return BelongsTo<LiquidationAgency, $this>
     */
    public function liquidationAgency(): BelongsTo
    {
        return $this->belongsTo(LiquidationAgency::class);
    }

    /** @return HasMany<PurchaseOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    /**
     * SCRUM-253 (REQ-190) — historial de pagos, ordenado por antigüedad ascendente.
     *
     * @return HasMany<PurchaseOrderPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(PurchaseOrderPayment::class)->orderBy('created_at');
    }

    /** REQ-189 RN2/Escenario 2 — suma de pagos ya registrados. */
    public function paidAmount(): float
    {
        return (float) $this->payments->sum('amount');
    }

    /**
     * SCRUM-1145 — suma de los 4 costos adicionales opcionales (importación, flete, manejo, otros).
     */
    public function additionalCostsTotal(): float
    {
        return round((float) $this->import_cost + (float) $this->freight_cost
            + (float) $this->handling_cost + (float) $this->other_cost, 2);
    }

    /**
     * SCRUM-1145 — envío Aéreo pagado por Atlantic: es un gasto de la orden que se suma al pago
     * al proveedor. Con cualquier otro tipo/pagador `shipping_cost` no cuenta (ver
     * `normalizeShippingFields()`).
     */
    public function atlanticShippingCost(): float
    {
        return $this->shipping_type === self::SHIPPING_AEREO && $this->who_pays_shipping === self::PAYS_ATLANTIC
            ? (float) $this->shipping_cost
            : 0.0;
    }

    /**
     * SCRUM-1145 — total a pagar al proveedor: productos (`total_amount`) + envío Aéreo pagado por
     * Atlantic + costos adicionales. `total_amount` queda intacto a propósito (PDF, reportes,
     * reclamos, KPI de Home).
     */
    public function payableTotal(): float
    {
        return round($this->total_amount + $this->atlanticShippingCost() + $this->additionalCostsTotal(), 2);
    }

    /**
     * Fase 13 (2.7) — lo que se puede haber pagado SIN que sea un anticipo: lo recibido valorado al costo de la orden (cada línea de recepción:
     * cantidad × costo), más lo que se paga aunque no llegue mercancía (envío de Atlantic, costos adicionales y las líneas de ajuste de un cambio
     * de monto aprobado). Informativo: un pago por encima de esto genera una advertencia, no un bloqueo.
     */
    public function paidWithoutAdvanceLimit(): float
    {
        $recibido = (float) PurchaseOrderReceiptLine::query()
            ->whereHas('receipt', fn ($q) => $q->where('purchase_order_id', $this->id))
            ->get()
            ->sum(fn (PurchaseOrderReceiptLine $l): float => $l->quantity * (float) $l->unit_cost);

        $ajustes = (float) $this->lines()->where('is_adjustment', true)->sum('subtotal');

        return round($recibido + $ajustes + $this->atlanticShippingCost() + $this->additionalCostsTotal(), 2);
    }

    /** REQ-189 Escenario 2 — pendiente/parcial/completo según lo pagado contra `payableTotal()`. */
    public function paymentStatus(): string
    {
        // Redondeado a centavos: con costos decimales la suma de pagos en float puede dar 1179.9999999 contra 1180.00.
        $paid = round($this->paidAmount(), 2);

        if ($paid <= 0.0) {
            return self::PAYMENT_STATUS_PENDIENTE;
        }

        return $paid >= $this->payableTotal() ? self::PAYMENT_STATUS_COMPLETO : self::PAYMENT_STATUS_PARCIAL;
    }

    /**
     * REQ-155 — checklist de documentos del envío.
     *
     * @return HasMany<PurchaseOrderDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(PurchaseOrderDocument::class);
    }

    /**
     * SCRUM-205 (REQ-142) — ingresos de mercancía registrados contra esta orden, usado por
     * `GoodsReceiptConfirmationService::confirmPendingForOrder()` para la auto-confirmación al
     * llegar a "Recibido".
     *
     * @return HasMany<PurchaseOrderReceipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseOrderReceipt::class);
    }

    /**
     * REQ-151: "envíos actualmente en movimiento" — desde Ordenado hasta Recibido inclusive,
     * excluye Por aprobar y Pendiente por liquidar (todavía no son un envío real en curso).
     *
     * @return list<string>
     */
    public static function activeShipmentStatuses(): array
    {
        return [
            self::STATUS_ORDENADO, self::STATUS_EN_TRANSITO, self::STATUS_EN_ADUANA,
            self::STATUS_EN_TRANSITO_LOCAL, self::STATUS_RECIBIDO,
        ];
    }

    /**
     * RN3 (REQ-118, aplica a Nueva Orden). Fase 14a: ya no está fija en el código — la deciden las reglas de aprobación de la empresa
     * (`purchase_approval_rules`, primera regla activa que coincide); la regla sembrada es la histórica (aéreo + paga la empresa).
     * `$payableTotal` es el total a pagar al proveedor (`payableTotal()`).
     */
    public static function computeRequiresPrimaryApproval(?string $shippingType, ?string $whoPaysShipping, float $payableTotal = 0.0): bool
    {
        return app(PurchaseApprovalRules::class)->requiresApproval($shippingType, $whoPaysShipping, $payableTotal);
    }

    /**
     * SCRUM-954 — `who_pays_shipping`/`shipping_cost` son exclusivos de envío Aéreo (RN de
     * REQ-134/SCRUM-197). El frontend ya oculta esos campos para Marítimo/Terrestre, pero no
     * garantiza que el payload los traiga vacíos (ej. el usuario los llenó en Aéreo y luego
     * cambió el tipo antes de guardar) — se normaliza acá, server-side, para no depender de que
     * el cliente siempre limpie su propio estado antes de enviar.
     *
     * @return array{who_pays_shipping: string|null, shipping_cost: float|null}
     */
    public static function normalizeShippingFields(?string $shippingType, ?string $whoPaysShipping, ?float $shippingCost): array
    {
        if ($shippingType !== self::SHIPPING_AEREO) {
            return ['who_pays_shipping' => null, 'shipping_cost' => null];
        }

        return ['who_pays_shipping' => $whoPaysShipping, 'shipping_cost' => $shippingCost];
    }

    /**
     * REQ-141: secuencia de estados según el origen del proveedor — un proveedor local se salta
     * tránsito/aduana (RN1/Escenario 1). `pendiente_liquidar` pasaba fuera de la secuencia
     * automática a propósito (se alcanzaba solo vía el botón "Liquidar", REQ-147) —
     * realineado 2026-08-06 (hallazgo Gerencia Test, SCRUM-204): TODA orden pasa por
     * "Pendiente/Por liquidar" entre "Por aprobar" y "Ordenado", sin importar la modalidad — ver
     * `orderStatusLabel()` (frontend, `atlanticerp-frontend/src/utils/orderStatusLabel.ts`) para el
     * texto mostrado según `modality` — la derivación es del lado del cliente, no hay un método
     * PHP equivalente.
     *
     * @return list<string>
     */
    private function statusSequence(): array
    {
        if ($this->provider?->origin === Provider::ORIGIN_LOCAL) {
            return [
                self::STATUS_POR_APROBAR, self::STATUS_PENDIENTE_LIQUIDAR, self::STATUS_ORDENADO,
                self::STATUS_EN_TRANSITO_LOCAL, self::STATUS_RECIBIDO,
            ];
        }

        return [
            self::STATUS_POR_APROBAR, self::STATUS_PENDIENTE_LIQUIDAR, self::STATUS_ORDENADO, self::STATUS_EN_TRANSITO,
            self::STATUS_EN_ADUANA, self::STATUS_EN_TRANSITO_LOCAL, self::STATUS_RECIBIDO,
        ];
    }

    /** REQ-141/142 — null si ya está en el último estado (Recibido) o el estado actual no está en la secuencia (ej. `pendiente_liquidar`). */
    public function nextStatus(): ?string
    {
        $sequence = $this->statusSequence();
        $index = array_search($this->status, $sequence, true);

        if ($index === false) {
            return null;
        }

        return $sequence[$index + 1] ?? null;
    }

    /**
     * Si el estado actual no está en la secuencia de este proveedor (ej. `pendiente_liquidar`) o
     * `$stage` no aplica a esta orden (ej. EN_ADUANA para un proveedor local, que se lo salta),
     * devuelve false — nunca lanza.
     */
    public function hasReachedStage(string $stage): bool
    {
        $sequence = $this->statusSequence();
        $currentIndex = array_search($this->status, $sequence, true);
        $stageIndex = array_search($stage, $sequence, true);

        if ($currentIndex === false || $stageIndex === false) {
            return false;
        }

        return $currentIndex >= $stageIndex;
    }

    /**
     * SCRUM-208 (rediseño 2026-08-15) — próxima etapa del REMANENTE, análoga a `nextStatus()`
     * pero sobre `REMAINDER_STATUSES` en vez de `statusSequence()`. null si no hay remanente
     * pendiente o si ya está en la última etapa (`REMAINDER_EN_TRANSITO_LOCAL` — de ahí en más el
     * remanente se cierra registrando el ingreso que lo cubre, no avanzando una etapa más).
     */
    public function nextRemainderStatus(): ?string
    {
        if ($this->pending_remainder_status === null) {
            return null;
        }

        $index = array_search($this->pending_remainder_status, self::REMAINDER_STATUSES, true);
        if ($index === false) {
            return null;
        }

        return self::REMAINDER_STATUSES[$index + 1] ?? null;
    }

    /**
     * REQ-191/REQ-268 (Agencias de Liquidación / Reportes) — hallazgo de QA 2026-07-18: la
     * definición original usaba `hasReachedStage(STATUS_EN_ADUANA)`, pero un proveedor `local`
     * SALTA esa etapa por completo (ver statusSequence()) — cualquier orden con
     * proveedor local quedaba "por_liquidar" para siempre, sin importar cuánto avanzara. El
     * criterio de aceptación real del ticket es "cuando esa orden llega a Recibido, pasa a
     * contar como Liquidada" — Recibido es el estado terminal de AMBAS secuencias (local e
     * internacional), así que este único método cubre los dos casos.
     */
    public function isLiquidated(): bool
    {
        return $this->hasReachedStage(self::STATUS_RECIBIDO);
    }

    /**
     * REQ-143: mientras la orden siga en "Por aprobar" y requiera aprobación de Gerencia que todavía
     * no se dio, no se puede avanzar — el aviso deja de ser meramente informativo (brecha que el
     * propio REQ-143 marcó como "deber ser", no construida en el mockup original).
     */
    public function isBlockedByPrimaryApproval(): bool
    {
        return $this->status === self::STATUS_POR_APROBAR
            && $this->requires_primary_approval
            && $this->approved_by === null;
    }

    /**
     * REQ-139 RN2: crítica si lleva 3+ días "Por aprobar" O su llegada estimada ya pasó sin estar
     * Recibida — cualquiera de las 2 basta. Única definición de "crítica", reutilizada también
     * por Logística (REQ-152, "Retrasados" usa el mismo criterio).
     */
    public function isCritical(): bool
    {
        if ($this->status === self::STATUS_POR_APROBAR) {
            return $this->status_changed_at->diffInDays(now()) >= app(\App\Modules\Compras\Services\ComprasThresholds::class)->criticalDaysPorAprobar();
        }

        if ($this->status !== self::STATUS_RECIBIDO && $this->estimated_arrival_date !== null) {
            return $this->estimated_arrival_date->isPast();
        }

        return false;
    }

    /**
     * REQ-112 (Inicio de Compras, "Entregado a tiempo") — extraído de
     * `Provider::computeOnTimePercent()` (SCRUM-264) para reusar el mismo check per-orden en un
     * agregado distinto (compañía completa, mes en curso) sin duplicar la fórmula. Null si no hay
     * fecha estimada contra qué medir (se excluye del denominador en el caller, nunca se asume).
     */
    public function isOnTime(): ?bool
    {
        if ($this->estimated_arrival_date === null || $this->actual_arrival_date === null) {
            return $this->estimated_arrival_date === null ? null : false;
        }

        return $this->actual_arrival_date->lessThanOrEqualTo($this->estimated_arrival_date);
    }

    /**
     * REQ-157 — enlace "Ingreso de mercancía" visible desde "En aduana" en adelante (secuencia
     * internacional) o desde "En tránsito local" en adelante (proveedor local, que salta aduana
     * por completo, ver statusSequence()). Si una entrega parcial dejó un remanente pendiente en
     * una etapa anterior (REQ-165), ese remanente manda sobre el status real de la orden — "se
     * refleja como el estado visible... hasta que se registre otro ingreso" — y el enlace se
     * oculta hasta que el remanente vuelva a llegar a "En aduana"/"En tránsito local".
     *
     * SCRUM-861 punto 2 (hallazgo real, no solo reportado por el cliente): esta condición nunca
     * consultaba si la orden ya recibió TODO lo esperado. Una vez que `pending_remainder_status`
     * se limpia a null (recepción completa, ver `GoodsReceiptController::saveReceipt()`), caía
     * siempre en la rama `hasReachedStage(...)` — que para una orden ya `Recibido` es
     * necesariamente `true` (pasó por esas etapas para llegar ahí), así que el botón quedaba
     * clickeable para siempre después de un ingreso completo. `$isFullyReceived` viene de
     * `GoodsReceiptConfirmationService::isFullyReceived()` (fuente única, SCRUM-347) — manda sobre
     * cualquier otra condición: una orden completamente recibida nunca vuelve a mostrar el enlace.
     */
    public function showsGoodsReceiptLink(bool $isFullyReceived): bool
    {
        if ($isFullyReceived) {
            return false;
        }

        if ($this->pending_remainder_status !== null) {
            return in_array(
                $this->pending_remainder_status,
                [self::REMAINDER_EN_ADUANA, self::REMAINDER_EN_TRANSITO_LOCAL],
                true
            );
        }

        return $this->hasReachedStage(self::STATUS_EN_ADUANA) || $this->hasReachedStage(self::STATUS_EN_TRANSITO_LOCAL);
    }

    /**
     * REQ-145/158/160 — única fuente de verdad del cálculo de "Recepción" (por línea y
     * consolidada), consumida por Ver Órdenes y Logística vía el mismo `formatSummary()` — nunca
     * deben mostrar resultados distintos para la misma orden (nota técnica explícita del ticket:
     * el mockup original tenía 2 implementaciones redundantes).
     *
     * Un movimiento cuenta para Recepción sin importar si en Inventario todavía está "Por
     * ingresar" o ya fue confirmado (a diferencia de Disponible/Stock en Inventario) — si hay un
     * movimiento asociado, algo llegó físicamente aunque Inventario no lo haya formalizado.
     *
     * @param  Collection<int, int>|null  $receivedByProduct  catalog_product_id => unidades ya
     *                                                        registradas (confirmado o no). Si es null, se consulta acá mismo (1 query) — para
     *                                                        listados, pasar el mapa ya precalculado en batch (ver receivedQuantitiesForOrders()) y
     *                                                        evitar 1 query por orden.
     * @return array{status: string, lines: array<int, array{catalog_product_id: int, expected: int, received: int, status: string}>}
     */
    public function receptionSummary(?Collection $receivedByProduct = null): array
    {
        $received = $receivedByProduct ?? $this->receivedQuantitiesByProduct();
        /** @var Collection<int, int> $expected */
        // Fase 13: la línea de ajuste del cambio de monto no es mercancía, no cuenta como «por recibir».
        $expected = $this->lines->reject(fn ($line) => $line->is_adjustment)->groupBy('catalog_product_id')
            ->map(fn ($lines) => (int) $lines->sum('quantity'));

        $lines = $expected->map(function (int $exp, $productId) use ($received): array {
            $rec = (int) ($received[$productId] ?? 0);

            return [
                'catalog_product_id' => (int) $productId,
                'expected' => $exp,
                'received' => $rec,
                'status' => self::lineReceptionStatus($rec, $exp),
            ];
        })->values()->all();

        if ($lines === []) {
            return ['status' => self::RECEPTION_PENDIENTE, 'lines' => []];
        }

        $statuses = array_unique(array_column($lines, 'status'));
        $consolidated = count($statuses) === 1 ? $statuses[0] : self::RECEPTION_PARCIAL;

        return ['status' => $consolidated, 'lines' => $lines];
    }

    private static function lineReceptionStatus(int $received, int $expected): string
    {
        return match (true) {
            $received <= 0 => self::RECEPTION_PENDIENTE,
            $received >= $expected => self::RECEPTION_COMPLETO,
            default => self::RECEPTION_PARCIAL,
        };
    }

    /** @return Collection<int, int> catalog_product_id => unidades ya registradas (confirmado o no) para ESTA orden (1 query). */
    public function receivedQuantitiesByProduct(): Collection
    {
        return PurchaseOrderReceiptLine::query()
            ->whereHas('receipt', fn ($q) => $q->where('purchase_order_id', $this->id))
            ->get()
            ->groupBy('catalog_product_id')
            ->map(fn ($lines) => (int) $lines->sum('quantity'));
    }

    /**
     * Batch: 1 sola query para N órdenes, en vez de `receivedQuantitiesByProduct()` por cada una
     * (usado por Ver Órdenes/Logística, que listan varias órdenes a la vez).
     *
     * @param  list<int>  $orderIds
     * @return array<int, Collection<int, int>> purchase_order_id => (catalog_product_id => unidades)
     */
    public static function receivedQuantitiesForOrders(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        return PurchaseOrderReceiptLine::query()
            ->whereHas('receipt', fn ($q) => $q->whereIn('purchase_order_id', $orderIds))
            ->with('receipt:id,purchase_order_id')
            ->get()
            ->groupBy(fn (PurchaseOrderReceiptLine $line): int => (int) $line->receipt?->purchase_order_id)
            ->map(fn ($lines) => $lines->groupBy('catalog_product_id')->map(fn ($l) => (int) $l->sum('quantity')))
            ->all();
    }
}
