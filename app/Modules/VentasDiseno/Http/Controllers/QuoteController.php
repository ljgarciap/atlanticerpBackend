<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\VentasDiseno\Exceptions\QuoteActionBlockedException;
use App\Modules\VentasDiseno\Exceptions\QuoteValidationBlockedException;
use App\Modules\VentasDiseno\Http\Requests\QuoteRequest;
use App\Modules\VentasDiseno\Models\PricingSettings;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuoteItem;
use App\Modules\VentasDiseno\Models\QuotePart;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Services\QuotePdfService;
use App\Modules\VentasDiseno\Services\QuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Cotización-A (REQ-024 a 032) — campos base del formulario. Ver
 * memory/project_backlog_ventas_diseno_req.md para el resto del desglose
 * (Cotización-B/C/D).
 */
final class QuoteController extends Controller
{
    /**
     * SCRUM-725 (decisión de Luis, 2026-08-05) — revierte la regla de REQ-040/045
     * (Pre-QA SCRUM-132/137) de "nunca revelar el porcentaje mínimo real": el
     * criterio de este ticket pide el número explícito, y de acá en más aplica a
     * todo el sistema de margen para no dejar dos mensajes iguales con
     * comportamiento distinto. Siempre interpolado desde
     * `PricingSettings::min_margin_percent` (configurable, nunca un número fijo).
     * Mismo texto/fórmula que QuoteItemController::marginErrorMessage() (clase
     * distinta, no se puede compartir directamente sin acoplar los dos controllers).
     */
    private function marginErrorMessage(): string
    {
        return 'Este cambio dejaría el margen por debajo del mínimo permitido ('
            . $this->formatPercent(PricingSettings::current()->min_margin_percent) . '%)';
    }

    /** `30.0` -> "30", `28.5` -> "28.5" — sin ceros decimales de relleno. */
    private function formatPercent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * SCRUM-725 — excepción nombrada por el ticket para Mark y David (grant angosto
     * por persona, ver SpecialPermissionSeeder — mismo patrón que
     * ventas_diseno.select_partner_price, nunca hardcodear el email acá).
     */
    private const MARGIN_OVERRIDE_PERMISSION = 'ventas_diseno.override_min_margin';

    public function __construct(
        private readonly QuoteService $service,
        private readonly QuotePdfService $pdf,
    ) {}

    private function hasMarginOverride(User $user): bool
    {
        return $user->hasPermission(self::MARGIN_OVERRIDE_PERMISSION);
    }

    public function store(QuoteRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /**
         * @var array{
         *   from_pipeline_card_id?: int|null, from_sub_client_id?: int|null,
         *   master_client_id?: int|null, sub_client_id?: int|null, sales_project_id?: int|null,
         *   ruc?: string|null, description?: string|null, architect_id?: int|null,
         *   delivery_type?: string|null, delivery_dates?: list<string>,
         *   price_type?: string, discount_mode?: string, global_discount_percent?: float,
         *   observations?: string|null, includes_installation?: bool,
         *   discount_totals_type?: string, discount_totals_value?: float,
         * } $validated
         */
        $validated = $request->validated();

        // REQ-025/027 no aplican a la precarga de REQ-050: los datos vienen ya
        // consistentes entre sí desde la tarjeta/subcliente de origen, no del
        // formulario. Solo cross-validamos lo que el propio $validated trae explícito.
        $crossError = $this->crossValidate($validated, null);
        if ($crossError !== null) {
            return response()->json(['message' => $crossError], 422);
        }

        if (! $this->canUsePriceType($validated, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $quote = $this->service->create(
            $validated,
            $user->id,
            $validated['from_pipeline_card_id'] ?? null,
            $validated['from_sub_client_id'] ?? null,
        );

        return response()->json($this->formatDetail($quote, $user), 201);
    }

    /**
     * REQ-086: "Guardar borrador" — igual payload que update(), pero exige que los
     * campos generales obligatorios (todo REQ-047 menos ítems) estén completos; si
     * falta algo, no persiste nada y responde 422 con la lista de faltantes (mismo
     * shape que /validate, para que el frontend reuse el mismo banner).
     */
    public function saveDraft(QuoteRequest $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $quote = $this->service->findDetailed($id);
        if ($quote === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($quote, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        /**
         * @var array{
         *   master_client_id?: int|null, sub_client_id?: int|null, sales_project_id?: int|null,
         *   ruc?: string|null, description?: string|null, architect_id?: int|null,
         *   delivery_type?: string|null, delivery_dates?: list<string>,
         *   price_type?: string, discount_mode?: string, global_discount_percent?: float,
         *   observations?: string|null, includes_installation?: bool,
         *   discount_totals_type?: string, discount_totals_value?: float,
         * } $validated
         */
        $validated = $request->validated();

        $this->reparentOwnedSalesProjectIfNeeded($validated, $quote);

        $crossError = $this->crossValidate($validated, $quote);
        if ($crossError !== null) {
            return response()->json(['message' => $crossError], 422);
        }

        if (! $this->canUsePriceType($validated, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        // SCRUM-725 — con permiso de excepción (Mark/David), el margen bajo el mínimo
        // no bloquea el guardado del borrador: se avisa y bloquea recién en confirm().
        if (
            ! $this->hasMarginOverride($user)
            && ($this->violatesItemMarginsAfterChange($validated, $quote) || $this->violatesGlobalMarginAfterChange($validated, $quote))
        ) {
            return response()->json(['message' => $this->marginErrorMessage()], 422);
        }

        $totalsError = $this->validateTotalsAmount($validated, $quote);
        if ($totalsError !== null) {
            return response()->json(['message' => $totalsError], 422);
        }

        try {
            $quote = $this->service->saveDraft($quote, $validated);
        } catch (QuoteValidationBlockedException $e) {
            return response()->json(['valid' => false, 'missing' => $e->missing], 422);
        } catch (QuoteActionBlockedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->formatDetail($quote, $user));
    }

    /**
     * REQ-047/048/051: "Guardar y generar cotización" — exige el formulario
     * completo (incl. ≥1 ítem), asigna folio atómico (una sola vez) y desbloquea
     * las pestañas de Vista Previa/Externa en el frontend (gate = folio no nulo).
     */
    public function generate(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $quote = $this->service->findDetailed($id);
        if ($quote === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($quote, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        try {
            $quote = $this->service->generate($quote, $this->hasMarginOverride($user));
        } catch (QuoteValidationBlockedException $e) {
            return response()->json(['valid' => false, 'missing' => $e->missing], 422);
        }

        return response()->json($this->formatDetail($quote, $user));
    }

    /**
     * SCRUM-723: nuevo botón "Guardar" en la Vista Previa — registra la cotización
     * oficialmente (confirmed_at) y recién ahí mueve la tarjeta de Pipeline
     * vinculada (si existe) a la etapa "quote". Ver QuoteService::confirm() para
     * el resto del detalle.
     *
     * SCRUM-725: guardar/generar (saveDraft/update/QuoteItemController) ya dejan
     * pasar un margen por debajo del mínimo para Mark/David sin bloquear — el
     * bloqueo real (para todos los demás) y la advertencia con confirmación
     * explícita (para Mark/David) quedan acá, en el paso que realmente publica la
     * cotización. `override_margin_warning: true` en el body es la confirmación
     * explícita de "Continuar" del modal de advertencia — sin ese flag, con
     * violaciones y permiso de excepción, se responde el resumen para que el
     * frontend muestre el modal en vez de reintentar confirm() a ciegas.
     */
    public function confirm(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $quote = $this->service->findDetailed($id);
        if ($quote === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($quote, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $violation = $this->marginViolationSummary($quote);
        if ($violation['item_ids'] !== [] || $violation['global']) {
            if (! $this->hasMarginOverride($user)) {
                return response()->json(['message' => $this->marginErrorMessage()], 422);
            }

            if ($request->boolean('override_margin_warning') !== true) {
                return response()->json([
                    'margin_warning'          => true,
                    'below_min_margin_items'  => $violation['item_ids'],
                    'global_below_minimum'    => $violation['global'],
                    // SCRUM-725 (decisión de Luis, 2026-08-05) — porcentaje real visible.
                    'min_margin_percent'      => PricingSettings::current()->min_margin_percent,
                ], 422);
            }
        }

        try {
            $quote = $this->service->confirm($quote);
        } catch (QuoteValidationBlockedException $e) {
            return response()->json(['valid' => false, 'missing' => $e->missing], 422);
        } catch (QuoteActionBlockedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->formatDetail($quote, $user));
    }

    /**
     * REQ-047: lista de campos obligatorios que faltan antes de poder "generar"
     * la cotización — no bloquea guardar como borrador, solo informa.
     */
    public function validateForGenerating(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $quote = $this->service->findDetailed($id);
        if ($quote === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($quote, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $missing = $quote->validationErrorsForGenerating(skipMarginCheck: $this->hasMarginOverride($user));

        return response()->json(['valid' => $missing === [], 'missing' => $missing]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $quote = $this->service->findDetailed($id);
        if ($quote === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($quote, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        return response()->json($this->formatDetail($quote, $user));
    }

    /**
     * SCRUM-766 — plantilla única de Cotización, generada por PDF real (dompdf), la misma para
     * vista previa interna/externa y descarga (nunca 3 implementaciones divergentes, ver docblock
     * de `QuotePdfService`). `?external=1` oculta precio/descuento/total/observaciones/pago/
     * condiciones/totales/firma — política ya vigente (SCRUM-140), sin cambios de este ticket.
     */
    public function pdf(Request $request, int $id): Response
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            abort(401);
        }

        $quote = $this->service->findDetailed($id);
        if ($quote === null) {
            abort(404);
        }

        if (! $this->canAccess($quote, $user)) {
            abort(403);
        }

        $external = $request->boolean('external');
        $detail   = $this->formatDetail($quote, $user);

        return $this->pdf->generate($detail, $external)
            ->download("Cotizacion-{$quote->folio}.pdf");
    }

    public function update(QuoteRequest $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $quote = $this->service->findDetailed($id);
        if ($quote === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($quote, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        /**
         * @var array{
         *   master_client_id?: int|null, sub_client_id?: int|null, sales_project_id?: int|null,
         *   ruc?: string|null, description?: string|null, architect_id?: int|null,
         *   delivery_type?: string|null, delivery_dates?: list<string>,
         *   price_type?: string, discount_mode?: string, global_discount_percent?: float,
         *   observations?: string|null, includes_installation?: bool,
         *   discount_totals_type?: string, discount_totals_value?: float,
         * } $validated
         */
        $validated = $request->validated();

        $this->reparentOwnedSalesProjectIfNeeded($validated, $quote);

        $crossError = $this->crossValidate($validated, $quote);
        if ($crossError !== null) {
            return response()->json(['message' => $crossError], 422);
        }

        if (! $this->canUsePriceType($validated, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        // SCRUM-725 — misma excepción que saveDraft(): Mark/David pueden guardar por
        // debajo del margen mínimo, el bloqueo real queda para confirm().
        if (
            ! $this->hasMarginOverride($user)
            && ($this->violatesItemMarginsAfterChange($validated, $quote) || $this->violatesGlobalMarginAfterChange($validated, $quote))
        ) {
            return response()->json(['message' => $this->marginErrorMessage()], 422);
        }

        $totalsError = $this->validateTotalsAmount($validated, $quote);
        if ($totalsError !== null) {
            return response()->json(['message' => $totalsError], 422);
        }

        try {
            $quote = $this->service->update($quote, $validated);
        } catch (QuoteActionBlockedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->formatDetail($quote, $user));
    }

    /**
     * SCRUM-734 (RN2.3) — "Usar como base para nueva versión": duplica $id (una
     * cotización confirmada, cualquier versión del historial, no solo la última)
     * en una cotización nueva, en Borrador, sobre el mismo proyecto. El nuevo
     * Borrador queda 100% editable de ahí en más vía update()/saveDraft() como
     * cualquier otro.
     */
    public function duplicate(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $quote = $this->service->findDetailed($id);
        if ($quote === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($quote, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        try {
            $duplicated = $this->service->duplicate($quote, $user->id);
        } catch (QuoteActionBlockedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->formatDetail($duplicated, $user), 201);
    }

    /**
     * SCRUM-734 (RN1.3/RN3) — historial de versiones del proyecto al que
     * pertenece $id, para la sección "Versiones de este proyecto" del documento
     * y el atajo de la lista de Cotizaciones. `card_stage` le permite al frontend
     * decidir si mostrar "Usar como base para nueva versión" (solo si === 'quote').
     */
    public function versions(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $quote = $this->service->findDetailed($id);
        if ($quote === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($quote, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $result = $this->service->versionsFor($quote);

        return response()->json([
            'card_stage' => $result['card_stage'],
            'versions'   => $result['versions']->values()->map(fn (Quote $v, int $index): array => [
                'id'           => $v->id,
                'version'      => $index + 1,
                'folio'        => $v->folio,
                'confirmed_at' => $v->confirmed_at?->toIso8601String(),
                'generated_by' => trim("{$v->owner->first_name} {$v->owner->last_name}"),
                'grand_total'  => $v->grandTotal(),
            ]),
        ]);
    }

    /**
     * QA formal 2026-07-10/11 (SCRUM-116): una cotización creada desde una tarjeta de
     * Pipeline sin Cliente Master vinculado nace con un Proyecto (SalesProject) sin
     * dueño (sub_client_id null) — es el mismo Proyecto, solo le falta el cliente. Si
     * el usuario recién ahora completa el Subcliente en esta pantalla, no tiene
     * sentido exigirle buscar/crear un Proyecto distinto vía crossValidate(): se
     * reparenta el MISMO Proyecto al nuevo Subcliente.
     *
     * Acotado a propósito: solo reparenta si `sales_project_id` en el payload es
     * exactamente el que la cotización YA tenía (no cambió) — si el usuario elige o
     * crea un Proyecto distinto a mano (flujo "+ Nueva cotización" sin precarga, vía
     * el buscador de Proyecto), ese Proyecto es una entidad real y ajena que
     * crossValidate() debe seguir rechazando si no pertenece al Subcliente elegido.
     *
     * @param array{master_client_id?: int|null, sub_client_id?: int|null, sales_project_id?: int|null} $data
     */
    private function reparentOwnedSalesProjectIfNeeded(array $data, ?Quote $existing): void
    {
        if ($existing === null || $existing->sales_project_id === null) {
            return;
        }

        $resultingProjectId = array_key_exists('sales_project_id', $data)
            ? $data['sales_project_id']
            : $existing->sales_project_id;

        if ($resultingProjectId !== $existing->sales_project_id) {
            return;
        }

        $resultingSubId = array_key_exists('sub_client_id', $data)
            ? $data['sub_client_id']
            : $existing->sub_client_id;

        $project = SalesProject::find($resultingProjectId);
        if ($project !== null && $project->sub_client_id !== $resultingSubId) {
            $project->update(['sub_client_id' => $resultingSubId]);
        }
    }

    /**
     * REQ-025: el Subcliente debe pertenecer al Cliente Master resultante. REQ-027:
     * el Proyecto debe pertenecer al Subcliente resultante. Mismo patrón que
     * PipelineController::update().
     *
     * @param array{master_client_id?: int|null, sub_client_id?: int|null, sales_project_id?: int|null} $data
     */
    private function crossValidate(array $data, ?Quote $existing): ?string
    {
        $resultingMasterId = array_key_exists('master_client_id', $data)
            ? $data['master_client_id']
            : $existing?->master_client_id;

        $resultingSubId = array_key_exists('sub_client_id', $data)
            ? $data['sub_client_id']
            : $existing?->sub_client_id;

        if ($resultingSubId !== null) {
            $subClient = SubClient::find($resultingSubId);
            if ($subClient === null || $subClient->master_client_id !== $resultingMasterId) {
                return 'El subcliente no pertenece al Cliente Master indicado';
            }
        }

        $resultingProjectId = array_key_exists('sales_project_id', $data)
            ? $data['sales_project_id']
            : $existing?->sales_project_id;

        if ($resultingProjectId !== null) {
            $project = SalesProject::find($resultingProjectId);
            if ($project === null || $project->sub_client_id !== $resultingSubId) {
                return 'El proyecto no pertenece al Subcliente indicado';
            }
        }

        return null;
    }

    private function canAccess(Quote $quote, User $user): bool
    {
        // ADR-006, Fase C Task 4 — canViewTeamV2() reemplaza hasRole(MANAGEMENT/SUPERADMIN)
        if ($user->canViewTeamV2('ventas_diseno')) {
            return true;
        }

        return $quote->owner_id === $user->id;
    }

    /**
     * REQ-034: "Precio Socio" restringido al mismo permiso angosto ya usado para
     * MasterClient::default_price_type (ventas_diseno.select_partner_price).
     *
     * @param array{price_type?: string} $data
     */
    private function canUsePriceType(array $data, User $user): bool
    {
        if (($data['price_type'] ?? null) !== 'partner') {
            return true;
        }

        return $user->hasPermission('ventas_diseno.select_partner_price');
    }

    /**
     * REQ-040 (Pre-QA SCRUM-132): true si, con el `price_type`/`discount_mode`/
     * `global_discount_percent` resultantes (los que trae `$data`, o si no los
     * trae, los que la cotización ya tenía), CUALQUIER ítem de la cotización
     * quedaría con el margen por debajo del mínimo — cubre el tipo de precio
     * general y el descuento global, que afectan a TODOS los ítems a la vez
     * (a diferencia de QuoteItemController, que valida un ítem por vez). No
     * hace falta re-chequear cuando solo cambia `discount_mode`: el switch de
     * modo siempre RESETEA el descuento que se deja de usar a 0 (ver
     * `QuoteService::resetDiscountsForModeSwitch()`), nunca lo aumenta, así
     * que no puede introducir una violación nueva por sí solo — pero se sigue
     * validando igual acá por las dudas, es una consulta barata (ya viene
     * eager-loaded por `QuoteService::DETAIL_RELATIONS`).
     *
     * @param array{price_type?: string, discount_mode?: string, global_discount_percent?: float} $data
     */
    private function violatesItemMarginsAfterChange(array $data, Quote $quote): bool
    {
        if (
            ! array_key_exists('price_type', $data)
            && ! array_key_exists('discount_mode', $data)
            && ! array_key_exists('global_discount_percent', $data)
        ) {
            return false;
        }

        $priceType      = $data['price_type'] ?? $quote->price_type;
        $discountMode   = $data['discount_mode'] ?? $quote->discount_mode;
        $globalDiscount = $data['global_discount_percent'] ?? $quote->global_discount_percent;
        $settings       = PricingSettings::current();

        foreach ($quote->parts as $part) {
            foreach ($part->items as $item) {
                if ($item->violatesMinMargin($discountMode, $globalDiscount, $priceType, $settings)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * SCRUM-725 — versión GLOBAL del check de arriba: con el price_type/
     * discount_mode/global_discount_percent/discount_totals_* resultantes,
     * ¿el margen de TODA la cotización (no un ítem puntual) cae por debajo del
     * mínimo? Delegado a `Quote::costedPortfolioMarginBelowMinimum()` — cubre
     * en un solo cálculo lo que antes eran dos checks separados (el tipo de
     * precio/descuento general de acá y el descuento de Totales, que antes vivía
     * en `totalsDiscountViolatesMargin()`, ya retirado por duplicar esta misma
     * cuenta).
     *
     * @param array{
     *   price_type?: string, discount_mode?: string, global_discount_percent?: float,
     *   discount_totals_type?: string, discount_totals_value?: float,
     * } $data
     */
    private function violatesGlobalMarginAfterChange(array $data, Quote $quote): bool
    {
        $relevantKeys = ['price_type', 'discount_mode', 'global_discount_percent', 'discount_totals_type', 'discount_totals_value'];
        if (array_intersect_key($data, array_flip($relevantKeys)) === []) {
            return false;
        }

        return $quote->costedPortfolioMarginBelowMinimum(
            PricingSettings::current(),
            priceTypeOverride: $data['price_type'] ?? null,
            discountModeOverride: $data['discount_mode'] ?? null,
            globalDiscountOverride: $data['global_discount_percent'] ?? null,
            discountTotalsTypeOverride: $data['discount_totals_type'] ?? null,
            discountTotalsValueOverride: $data['discount_totals_value'] ?? null,
        );
    }

    /**
     * REQ-045: un descuento de Totales (% o $) que deje el neto en $0 o negativo
     * se rechaza siempre, sin excepción de margen — no es una regla de negocio de
     * rentabilidad (que sí tiene la excepción Mark/David de SCRUM-725), es una
     * integridad de datos mínima (un total en $0 o negativo no es un documento
     * válido para nadie).
     *
     * @param array{discount_totals_type?: string, discount_totals_value?: float} $data
     */
    private function validateTotalsAmount(array $data, Quote $quote): ?string
    {
        if (! array_key_exists('discount_totals_type', $data) && ! array_key_exists('discount_totals_value', $data)) {
            return null;
        }

        $type  = $data['discount_totals_type'] ?? $quote->discount_totals_type;
        $value = $data['discount_totals_value'] ?? $quote->discount_totals_value;

        $subtotal = $quote->subtotal();
        if ($subtotal <= 0) {
            // Nada que proteger todavía — la cotización no tiene ítems, no hay
            // margen real que un descuento pueda dejar en $0 o negativo.
            return null;
        }

        $discountAmount = $type === 'amount' ? $value : round($subtotal * $value / 100, 2);

        if ($subtotal - $discountAmount <= 0) {
            return 'El descuento de Totales no puede dejar el total en $0 o negativo';
        }

        return null;
    }

    /**
     * SCRUM-725 — estado de margen ACTUAL (ya persistido) de la cotización, usado
     * por confirm() para decidir si bloquea/avisa, y por formatDetail() para que
     * el frontend resalte los productos afectados y la sección Totales.
     *
     * @return array{item_ids: list<int>, global: bool}
     */
    private function marginViolationSummary(Quote $quote): array
    {
        $settings = PricingSettings::current();
        $itemIds  = [];

        foreach ($quote->parts as $part) {
            foreach ($part->items as $item) {
                if ($item->violatesMinMargin($quote->discount_mode, $quote->global_discount_percent, $quote->price_type, $settings)) {
                    $itemIds[] = $item->id;
                }
            }
        }

        return [
            'item_ids' => $itemIds,
            'global'   => $quote->costedPortfolioMarginBelowMinimum($settings),
        ];
    }

    /** @return array<string, mixed> */
    private function formatDetail(Quote $quote, User $user): array
    {
        $linkedCard = $quote->linkedPipelineCard();
        // SCRUM-725 — qué está violado, para que el frontend resalte los productos
        // afectados y la sección Totales (el % real va aparte, 'min_margin_percent').
        $marginViolation = $this->marginViolationSummary($quote);

        return [
            'id'               => $quote->id,
            'status'           => $quote->status,
            'folio'            => $quote->folio,
            'generated_at'     => $quote->generated_at?->toIso8601String(),
            'confirmed_at'     => $quote->confirmed_at?->toIso8601String(),
            'pipeline_card_id' => $linkedCard?->id,
            // REQ-083: Estado del documento (Borrador/Enviada/Aprobada) — supersede
            // el campo `status` de arriba (heredado de Cotización-A, sin uso real
            // desde que se definió esta regla en Grupo 5, se deja en la tabla sin
            // tocar para no hacer una migración destructiva por algo inofensivo).
            'document_status'  => $quote->documentStatus($linkedCard),
            'master_client'    => $quote->masterClient !== null ? [
                'id' => $quote->masterClient->id, 'name' => $quote->masterClient->name,
            ] : null,
            'sub_client'       => $quote->subClient !== null ? [
                'id' => $quote->subClient->id, 'business_name' => $quote->subClient->business_name,
            ] : null,
            'sales_project'    => $quote->salesProject !== null ? [
                'id' => $quote->salesProject->id, 'name' => $quote->salesProject->name, 'tag' => $quote->salesProject->tag,
            ] : null,
            'ruc'              => $quote->ruc,
            'description'      => $quote->description,
            'owner'            => [
                'id' => $quote->owner->id, 'name' => trim("{$quote->owner->first_name} {$quote->owner->last_name}"),
            ],
            'architect'        => $quote->architect !== null ? [
                'id' => $quote->architect->id, 'name' => $quote->architect->name,
                'phone' => $quote->architect->phone, 'email' => $quote->architect->email,
            ] : null,
            'delivery_type'    => $quote->delivery_type,
            'delivery_dates'   => $quote->deliveryDates->map(fn ($d) => $d->date->toDateString())->values(),
            'contacts'         => $quote->contacts->map(fn ($c) => [
                'pivot_id' => $c->pivot->id, 'id' => $c->id, 'name' => $c->name,
                'role' => $c->role, 'phone' => $c->phone, 'email' => $c->email,
            ])->values(),
            'price_type'              => $quote->price_type,
            'discount_mode'           => $quote->discount_mode,
            'global_discount_percent' => $quote->global_discount_percent,
            // SCRUM-725 — advertencia de margen a nivel de Totales (no por producto,
            // ver 'below_min_margin' en cada ítem) + si el usuario actual puede
            // guardar/confirmar igual pese a la violación (Mark/David). Decisión de
            // Luis 2026-08-05: el % real ahora se expone (antes nunca se revelaba).
            'global_below_min_margin' => $marginViolation['global'],
            'can_override_min_margin' => $this->hasMarginOverride($user),
            'min_margin_percent'      => PricingSettings::current()->min_margin_percent,
            'parts'                   => (function () use ($quote, $marginViolation) {
                // REQ-033: PricingSettings::current() se resuelve una sola vez acá,
                // no dentro de cada lineTotal()/effectiveUnitPrice() (evita N+1 por
                // cada ítem de la cotización).
                $settings = PricingSettings::current();

                return $quote->parts->map(fn (QuotePart $p) => [
                    'id' => $p->id, 'name' => $p->name, 'position' => $p->position,
                    'subtotal' => $p->subtotal($quote->discount_mode, $quote->global_discount_percent, $quote->price_type, $settings),
                    'items' => $p->items->map(fn (QuoteItem $i): array => [
                        'id' => $i->id, 'catalog_product_id' => $i->catalog_product_id, 'is_custom' => $i->is_custom,
                        'reference' => $i->reference, 'description' => $i->description,
                        'quantity' => $i->quantity, 'unit_price' => $i->unit_price, 'cost' => $i->cost,
                        'price_type_override' => $i->price_type_override, 'discount_percent' => $i->discount_percent,
                        // REQ-033/042: precio ya resuelto según el tipo de precio efectivo
                        // del ítem (override si tiene, si no el general de la cotización).
                        'effective_unit_price' => $i->effectiveUnitPrice($quote->price_type, $settings),
                        'line_total' => $i->lineTotal($quote->discount_mode, $quote->global_discount_percent, $quote->price_type, $settings),
                        // REQ-039 — null = sin control de stock para este producto (no dispara
                        // alerta), número = stock real contra el que el frontend compara `quantity`.
                        'stock_quantity' => $i->catalogProduct?->stock_quantity,
                        // SCRUM-725 — resalte visual del producto en Vista Previa/edición.
                        'below_min_margin' => in_array($i->id, $marginViolation['item_ids'], true),
                        // SCRUM-766 — columna FOTO del PDF (QuotePdfService); null si el ítem es
                        // custom (sin catalog_product_id) o el producto no tiene foto cargada.
                        'photo_url' => $i->catalogProduct?->photo_url,
                    ])->values(),
                ])->values();
            })(),
            'subtotal'                 => $quote->subtotal(),
            'observations'             => $quote->observations,
            'includes_installation'    => $quote->includes_installation,
            'discount_totals_type'     => $quote->discount_totals_type,
            'discount_totals_value'    => $quote->discount_totals_value,
            'discount_totals_amount'   => $quote->discountTotalsAmount(),
            'net_total'                => $quote->netTotal(),
            'itbms'                    => $quote->itbms(),
            'grand_total'              => $quote->grandTotal(),
            'conditions_text'          => $quote->conditions_text,
            'observations_preview'     => $quote->observationsForDocument(),
            'can_edit'         => $quote->isEditableBy($user),
            'created_at'       => $quote->created_at->toIso8601String(),
        ];
    }

    private function resolveUser(Request $request): ?User
    {
        /** @var array{sub?: int}|null $payload */
        $payload = $request->attributes->get('jwt');

        if ($payload === null || ! isset($payload['sub'])) {
            return null;
        }

        return User::find((int) $payload['sub']);
    }
}
