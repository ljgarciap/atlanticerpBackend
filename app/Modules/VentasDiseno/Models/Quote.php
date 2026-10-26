<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Modules\VentasDiseno\Support\ReceptorTipo;
use App\Models\User;
use App\Modules\AdminCont\Services\ItbmsRateResolver;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cotización-A (REQ-024 a 032) — campos base del formulario. El resto del ciclo de
 * vida (partidas/precios, totales, condiciones, folio, vista previa, borrador) llega
 * en Cotización-B/C/D, ver memory/project_backlog_ventas_diseno_req.md.
 *
 * @property int                                    $id
 * @property int|null                                $master_client_id
 * @property int|null                                $sub_client_id
 * @property int|null                                $sales_project_id
 * @property string|null                              $ruc
 * @property string|null                              $description
 * @property int                                      $owner_id
 * @property int|null                                $architect_id
 * @property string|null                              $delivery_type
 * @property string                                    $status
 * @property string|null                              $folio
 * @property \Carbon\Carbon|null                        $generated_at
 * @property \Carbon\Carbon|null                        $confirmed_at
 * @property string                                    $price_type
 * @property string                                    $discount_mode
 * @property float                                      $global_discount_percent
 * @property string|null                              $observations
 * @property bool                                      $includes_installation
 * @property string                                    $discount_totals_type
 * @property float                                      $discount_totals_value
 * @property string|null                              $conditions_text
 * @property MasterClient|null                        $masterClient
 * @property SubClient|null                            $subClient
 * @property SalesProject|null                        $salesProject
 * @property User                                      $owner
 * @property Architect|null                            $architect
 * @property Collection<int, SubClientContact>          $contacts
 * @property Collection<int, QuoteDeliveryDate>          $deliveryDates
 * @property Collection<int, QuotePart>                  $parts
 * @property \Carbon\Carbon                            $created_at
  * @property bool $itbms_exento SCRUM-1166 — cotización de un cliente exento de ITBMS (sigue al cliente hasta confirmarse; después queda fija)
 */
class Quote extends Model
{
    use LogsAuditTrail;

    protected static function booted(): void
    {
        // SCRUM-1166 — la cotización sigue al cliente fiscal mientras no esté confirmada: al crearla, o si cambia de cliente, copia su exención de
        // ITBMS. Una cotización confirmada queda fija (ni cambiar la marca del cliente ni nada posterior la mueve); el cambio de marca de un cliente
        // actualiza sus borradores desde `ItbmsExemptionService`.
        static::saving(function (Quote $quote): void {
            if ($quote->confirmed_at !== null || ($quote->exists && ! $quote->isDirty('sub_client_id'))) {
                return;
            }

            $quote->itbms_exento = $quote->sub_client_id !== null
                && (bool) SubClient::query()->whereKey($quote->sub_client_id)->value('itbms_exento');
        });
    }

    /**
     * REQ-046: texto fijo por defecto de Condiciones al crear una cotización —
     * editable solo por quien tenga ventas_diseno.edit.conditions.
     */
    public const DEFAULT_CONDITIONS_TEXT = <<<'TEXT'
        1. 50% abono inicial, 50% contra entrega.
        2. Entregas de mercancía solo en Ciudad de Panamá (monto mayor a $500).
        3. Cotización válida por 15 días.
        4. Reserva de productos, únicamente con abono.
        5. No se aceptan devoluciones, pasados 15 días.
        6. Devoluciones, solamente con factura original y mercancía intacta con su empaque.
        7. Se cobrará gastos de almacenaje a partir del primer mes.
        TEXT;

    /** @var list<string> */
    protected $fillable = [
        'master_client_id', 'sub_client_id', 'sales_project_id', 'ruc', 'description',
        'owner_id', 'architect_id', 'delivery_type', 'status', 'folio', 'generated_at', 'confirmed_at',
        'price_type', 'discount_mode', 'global_discount_percent',
        'observations', 'includes_installation', 'discount_totals_type', 'discount_totals_value',
        'conditions_text',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'global_discount_percent' => 'float',
            'includes_installation'   => 'boolean',
            'discount_totals_value'   => 'float',
            'generated_at'            => 'datetime',
            'confirmed_at'            => 'datetime',
            'itbms_exento'            => 'boolean',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'quotes';
        }

        return "{$tenant->slug}_ventas_diseno.quotes";
    }

    /** @return BelongsTo<MasterClient, $this> */
    public function masterClient(): BelongsTo
    {
        return $this->belongsTo(MasterClient::class);
    }

    /** @return BelongsTo<SubClient, $this> */
    public function subClient(): BelongsTo
    {
        return $this->belongsTo(SubClient::class);
    }

    /** @return BelongsTo<SalesProject, $this> */
    public function salesProject(): BelongsTo
    {
        return $this->belongsTo(SalesProject::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<Architect, $this> */
    public function architect(): BelongsTo
    {
        return $this->belongsTo(Architect::class);
    }

    /** @return HasMany<QuoteDeliveryDate, $this> */
    public function deliveryDates(): HasMany
    {
        return $this->hasMany(QuoteDeliveryDate::class);
    }

    /**
     * SCRUM-953 — sin `orderBy('position')` explícito, Postgres devuelve las filas en el orden
     * que decida el plan de consulta (coincidía con el orden de creación hasta que dejó de
     * coincidir); `position` ya se asigna correctamente al crear/duplicar partidas
     * (QuotePartController), pero nada ordenaba por él al leer.
     * @return HasMany<QuotePart, $this>
     */
    public function parts(): HasMany
    {
        return $this->hasMany(QuotePart::class)->orderBy('position');
    }

    /**
     * REQ-035: subtotal general — suma de los subtotales de todas las partidas.
     */
    public function subtotal(): float
    {
        $settings = PricingSettings::current();

        return round(
            $this->parts->sum(fn (QuotePart $part) => $part->subtotal($this->discount_mode, $this->global_discount_percent, $this->price_type, $settings)),
            2,
        );
    }

    /**
     * REQ-045: monto del descuento adicional de Totales (% o $), aplicado sobre
     * el subtotal general.
     */
    public function discountTotalsAmount(): float
    {
        if ($this->discount_totals_type === 'amount') {
            return round($this->discount_totals_value, 2);
        }

        return round($this->subtotal() * $this->discount_totals_value / 100, 2);
    }

    /** REQ-045: (Subtotal − Descuento de Totales). */
    public function netTotal(): float
    {
        return round($this->subtotal() - $this->discountTotalsAmount(), 2);
    }

    /**
     * SCRUM-725 — margen GLOBAL de la cotización: a diferencia de
     * QuoteItem::violatesMinMargin() (por unidad, un ítem a la vez), esto suma el
     * ingreso y costo de TODOS los ítems con costo real, aplica proporcionalmente
     * el descuento de Totales (mismo prorateo que ya usaba
     * QuoteController::totalsDiscountViolatesMargin()) y compara el margen
     * resultante contra PricingSettings::min_margin_percent.
     *
     * Los overrides opcionales permiten validar un estado CANDIDATO antes de
     * persistirlo: los *Override sirven para un cambio de precio general/descuento
     * todavía sin guardar (QuoteController::saveDraft()/update()); $excludeItemId +
     * $probeItem simulan un ítem agregado/editado sin persistirlo primero
     * (QuoteItemController::store()/update()) — $excludeItemId saca el ítem viejo
     * de la suma y $probeItem aporta los valores nuevos en su lugar.
     */
    public function costedPortfolioMarginBelowMinimum(
        PricingSettings $settings,
        ?string $priceTypeOverride = null,
        ?string $discountModeOverride = null,
        ?float $globalDiscountOverride = null,
        ?string $discountTotalsTypeOverride = null,
        ?float $discountTotalsValueOverride = null,
        ?int $excludeItemId = null,
        ?QuoteItem $probeItem = null,
        bool $ignoreItemPriceOverrides = false,
    ): bool {
        $priceType      = $priceTypeOverride ?? $this->price_type;
        $discountMode   = $discountModeOverride ?? $this->discount_mode;
        $globalDiscount = $globalDiscountOverride ?? $this->global_discount_percent;

        $subtotal       = 0.0;
        $costedSubtotal = 0.0;
        $costedCost     = 0.0;

        foreach ($this->parts as $part) {
            foreach ($part->items as $item) {
                if ($excludeItemId !== null && $item->id === $excludeItemId) {
                    continue;
                }

                if ($ignoreItemPriceOverrides && $item->price_type_override !== null) {
                    // SCRUM-1164 — simula «Aplicar a todos»: copia en memoria sin el tipo de precio propio (nada se persiste).
                    $item = clone $item;
                    $item->price_type_override = null;
                }

                $lineTotal = $item->lineTotal($discountMode, $globalDiscount, $priceType, $settings);
                $subtotal += $lineTotal;

                // Mismo criterio que QuoteItem::violatesMinMargin(): "partner" da un
                // margen fijo por fórmula, deliberado y ya gateado por su propio
                // permiso (REQ-034/select_partner_price) — no participa del chequeo
                // de margen, ni acá ni por ítem.
                if ($item->cost !== null && ($item->price_type_override ?? $priceType) !== 'partner') {
                    $costedSubtotal += $lineTotal;
                    $costedCost     += $item->cost * $item->quantity;
                }
            }
        }

        if ($probeItem !== null) {
            $lineTotal = $probeItem->lineTotal($discountMode, $globalDiscount, $priceType, $settings);
            $subtotal += $lineTotal;

            if ($probeItem->cost !== null && ($probeItem->price_type_override ?? $priceType) !== 'partner') {
                $costedSubtotal += $lineTotal;
                $costedCost     += $probeItem->cost * $probeItem->quantity;
            }
        }

        if ($costedSubtotal <= 0 || $subtotal <= 0) {
            // Sin ítems con costo real, no hay margen que proteger (mismo criterio
            // 3 que QuoteItem::violatesMinMargin()).
            return false;
        }

        $discountTotalsType  = $discountTotalsTypeOverride ?? $this->discount_totals_type;
        $discountTotalsValue = $discountTotalsValueOverride ?? $this->discount_totals_value;
        $discountAmount      = $discountTotalsType === 'amount'
            ? $discountTotalsValue
            : round($subtotal * $discountTotalsValue / 100, 2);

        $proportionalDiscount = round($discountAmount * ($costedSubtotal / $subtotal), 2);
        $netForCosted         = $costedSubtotal - $proportionalDiscount;

        if ($netForCosted <= 0) {
            return true;
        }

        $marginPercent = ($netForCosted - $costedCost) / $netForCosted * 100;

        return $marginPercent < $settings->min_margin_percent;
    }

    /**
     * REQ-045: neto por la tasa de ITBMS por defecto (Batch 10, REQ-480/`ItbmsRateResolver`) — antes
     * un 7% hardcodeado, sin conexión a `admin_contab.itbms_rates` (Configuración Fiscal).
     */
    public function itbms(): float
    {
        return round($this->netTotal() * ($this->itbmsPercentage() / 100), 2);
    }

    /** SCRUM-1166 — la cotización es de un cliente exento de ITBMS (marca propia, fija desde que se confirma). */
    public function isItbmsExempt(): bool
    {
        return (bool) $this->itbms_exento;
    }

    /** Porcentaje que aplica a esta cotización: 0 si es exenta (sin consultar nada), la tasa por defecto si no. */
    public function itbmsPercentage(): float
    {
        return $this->isItbmsExempt() ? 0.0 : ItbmsRateResolver::defaultPercentage();
    }

    /** Tasa con la que se factura esta cotización: «0% — Exento» si es exenta, la tasa por defecto si no. */
    public function itbmsRate(): \App\Modules\AdminCont\Models\AdminContItbmsRate
    {
        return $this->isItbmsExempt() ? ItbmsRateResolver::exemptRate() : ItbmsRateResolver::defaultRate();
    }

    /** REQ-045: neto + ITBMS. */
    public function grandTotal(): float
    {
        return round($this->netTotal() + $this->itbms(), 2);
    }

    /**
     * REQ-043: texto de observaciones para el documento — el texto libre del
     * usuario (si lo hay) seguido de la línea automática de fecha(s) de entrega
     * y, si corresponde, la nota de instalación pendiente de envío.
     */
    public function observationsForDocument(): string
    {
        $lines = [];

        if (! empty($this->observations)) {
            $lines[] = $this->observations;
        }

        if ($this->delivery_type === 'tbd') {
            // SCRUM-796 (secc. 7) — "Por definir": sin deliveryDates por diseño, el documento
            // igual debe mencionar la fecha de entrega en vez de omitir la línea por completo.
            $lines[] = 'Fecha de entrega: Por definir';
        } elseif ($this->deliveryDates->isNotEmpty()) {
            $dates = $this->deliveryDates->map(fn (QuoteDeliveryDate $d) => $d->date->format('d/m/Y'))->implode(', ');
            $lines[] = $this->delivery_type === 'partial'
                ? "Entrega parcial: {$dates}"
                : "Entrega completa: {$dates}";
        }

        if ($this->includes_installation) {
            $lines[] = 'La cotización de instalación queda pendiente de envío — se enviará en los próximos días.';
        }

        return implode("\n", $lines);
    }

    /**
     * REQ-047/REQ-086: campos obligatorios — Cliente Master, Subcliente, RUC,
     * Proyecto, Descripción, al menos 1 Contacto, al menos 1 fecha de
     * entrega, al menos 1 ítem en Partidas. "Generar" (REQ-047) exige los 3 últimos
     * ($requireItems=true, $requireContacts=true por defecto); "Guardar borrador"
     * (REQ-086) los relaja — un borrador puede quedar sin productos NI contacto.
     *
     * $requireContacts=false existe porque agregar un contacto exige que el
     * Subcliente ya esté guardado (ver QuotePage::contactsReady en el frontend), así
     * que una cotización que nace sin cliente/contacto heredado de la tarjeta de
     * origen quedaba en un callejón sin salida: no podía guardar el Subcliente
     * (exigía Contacto) ni agregar el Contacto (exigía Subcliente ya guardado).
     * Encontrado en QA formal 2026-07-10/11 (SCRUM-116).
     *
     * SCRUM-734 (sección 6, RN10) — "margen" pasa a ser el último ítem de esta
     * misma lista unificada en vez de vivir en un flujo de error aparte (el
     * bloqueo real de "Guardar y generar" seguía viviendo solo en confirm()/
     * QuoteController, sin aparecer nunca en el banner de "todos los faltantes a
     * la vez"). $skipMarginCheck es la excepción nombrada de Gerencia/David
     * (`ventas_diseno.override_min_margin`, SCRUM-725) — para ellos "generar" no
     * bloquea por margen, el bloqueo/confirmación real sigue en confirm().
     *
     * @return list<string>
     */
    public function validationErrorsForGenerating(bool $requireItems = true, bool $requireContacts = true, bool $skipMarginCheck = false): array
    {
        $errors = [];

        if ($this->master_client_id === null) {
            $errors[] = 'Cliente Master';
        }
        if ($this->sub_client_id === null) {
            $errors[] = 'Subcliente';
        }
        // SCRUM-1003 — un SubCliente Consumidor Final (`02`) no tiene RUC ni cédula: no se exige. Sin esto ningún Consumidor Final
        // podía cotizarse y, sin cotización, no hay pedido ni factura (hallazgo de la Senior Review).
        if (empty($this->ruc) && $this->subClient?->receptor_tipo !== ReceptorTipo::CONSUMIDOR_FINAL) {
            $errors[] = 'RUC / Cédula'; // SCRUM-796 (secc. 8) — rename de label, mismo campo/regla
        }
        if ($this->sales_project_id === null) {
            $errors[] = 'Proyecto';
        }
        if (empty($this->description)) {
            $errors[] = 'Descripción';
        }
        // SCRUM-873 — "Arquitecto" ya NO es obligatorio para guardar/generar: el campo
        // está asociado a una comisión que no siempre existe (ArchitectCommissionService
        // ya trata architect_id=null como "sin comisión de arquitecto", no como error —
        // ver `where q.architect_id is not null` ahí). Cuando SÍ se completa, el cálculo
        // de comisión sigue funcionando exactamente igual que antes.
        if ($requireContacts && $this->contacts->isEmpty()) {
            $errors[] = 'Contacto';
        }
        // SCRUM-716 (Pre-QA, hallazgo CRÍTICO) — defensa en profundidad: el punto de
        // entrada real (QuoteService::update() desvincula contactos de otro Subcliente
        // al cambiar sub_client_id) ya lo previene, pero un contacto de otro Subcliente
        // colado en `quote.contacts` (ej. por un bug futuro en otro punto de entrada)
        // no debe poder llegar a "Generado" ni aparecer en el PDF real sin que este
        // check lo bloquee primero.
        if ($this->contacts->contains(fn (SubClientContact $c): bool => $c->sub_client_id !== $this->sub_client_id)) {
            $errors[] = 'Contacto de otro Subcliente vinculado — revisar antes de generar';
        }
        // SCRUM-796 (secc. 7) — "Por definir" (delivery_type='tbd') exime de tener al
        // menos 1 fecha de entrega; para 'single'/'partial' sigue siendo obligatoria.
        if ($this->delivery_type !== 'tbd' && $this->deliveryDates->isEmpty()) {
            $errors[] = 'Fecha de entrega';
        }
        if ($requireItems && $this->parts->flatMap(fn (QuotePart $p) => $p->items)->isEmpty()) {
            $errors[] = 'Ítems';
        }
        if (! $skipMarginCheck && $requireItems && $this->hasAnyMarginViolation()) {
            $errors[] = 'Margen';
        }

        return $errors;
    }

    /**
     * SCRUM-734/725 — true si algún ítem individual o el margen global de la
     * cotización quedan por debajo de PricingSettings::min_margin_percent, con
     * el estado YA persistido (sin overrides candidatos — para eso está
     * costedPortfolioMarginBelowMinimum() con sus parámetros opcionales).
     */
    public function hasAnyMarginViolation(): bool
    {
        $settings = PricingSettings::current();

        foreach ($this->parts as $part) {
            foreach ($part->items as $item) {
                if ($item->violatesMinMargin($this->discount_mode, $this->global_discount_percent, $this->price_type, $settings)) {
                    return true;
                }
            }
        }

        return $this->costedPortfolioMarginBelowMinimum($settings);
    }

    /**
     * REQ-048: la tarjeta de Pipeline vinculada a esta cotización, si existe — no
     * hay FK única (PipelineCard.sales_project_id es un belongsTo normal), la
     * relación 1:1 es una convención de negocio (PipelineService::create() siempre
     * nace junto a un único SalesProject), así que se resuelve por búsqueda.
     */
    public function linkedPipelineCard(): ?PipelineCard
    {
        if ($this->sales_project_id === null) {
            return null;
        }

        return PipelineCard::where('sales_project_id', $this->sales_project_id)->first();
    }

    /**
     * REQ-083: Estado del documento — distinto de la Etapa del proyecto en
     * Pipeline. Decisión confirmada con Luis (2026-07-08): no existe un botón
     * "Enviar" separado.
     *
     * SCRUM-723 (2026-08-03): el gate de "Borrador" pasó de `folio === null` a
     * `confirmed_at === null`. Antes, "Guardar y generar cotización" asignaba el
     * folio Y sacaba la cotización de Borrador en el mismo paso — pero eso
     * significaba que abrir la Vista Previa ya la contaba como oficial, aunque el
     * usuario nunca confirmara el guardado (ver QuoteService::generate() vs
     * ::confirm()). Ahora "generar" solo asigna folio (necesario para numerar el
     * PDF de la Vista Previa); confirmar vía el botón "Guardar" es lo único que
     * saca a una cotización de Borrador. Una vez confirmada, es "Enviada" salvo
     * que el proyecto ya haya llegado a Propuesta/Aprobado en Pipeline (momento en
     * el que se asume que la cotización ya quedó firmada/aceptada — REQ-016 de
     * Pipeline), en cuyo caso es "Aprobada".
     *
     * @param PipelineCard|null $preloadedCard pasar la tarjeta ya resuelta evita
     *   una query por cotización al listar muchas a la vez (ver QuoteListService).
     */
    public function documentStatus(?PipelineCard $preloadedCard = null): string
    {
        if ($this->confirmed_at === null) {
            return 'draft';
        }

        $card = func_num_args() > 0 ? $preloadedCard : $this->linkedPipelineCard();

        if ($card !== null && in_array($card->stage, ['proposal', 'approved'], true)) {
            return 'approved';
        }

        return 'sent';
    }

    /**
     * SCRUM-1121 — asociados de una cotización que TODAVÍA NO tiene proyecto (borrador); con proyecto, los asociados viven en el proyecto
     * (ver `ProjectAssociateService`).
     *
     * @return BelongsToMany<Architect, $this>
     */
    public function draftAssociates(): BelongsToMany
    {
        $tenant = Tenant::current();
        $pivot  = $tenant !== null ? "{$tenant->slug}_ventas_diseno.quote_associates" : 'quote_associates';

        return $this->belongsToMany(Architect::class, $pivot, 'quote_id', 'architect_id')->withTimestamps()->orderBy('name');
    }

    /** @return BelongsToMany<SubClientContact, $this> */
    public function contacts(): BelongsToMany
    {
        $tenant = Tenant::current();
        $pivot  = $tenant !== null
            ? "{$tenant->slug}_ventas_diseno.quote_contacts"
            : 'quote_contacts';

        return $this->belongsToMany(SubClientContact::class, $pivot, 'quote_id', 'sub_client_contact_id')
            ->withPivot('id');
    }

    /**
     * SCRUM-734 (RN2.2) — una cotización confirmada nunca es editable, sin
     * excepción de rol/ownership; ver QuoteService::update() para el bloqueo real
     * del lado servidor. Esto solo controla el flag `can_edit` que el frontend usa
     * para mostrar/ocultar el formulario de edición.
     */
    public function isEditableBy(User $user): bool
    {
        if ($this->confirmed_at !== null) {
            return false;
        }

        // ADR-006, Fase C Task 4 — canViewTeamV2() reemplaza hasRole(MANAGEMENT/SUPERADMIN)
        if ($user->canViewTeamV2('ventas_diseno')) {
            return true;
        }

        return $this->owner_id === $user->id;
    }
}
