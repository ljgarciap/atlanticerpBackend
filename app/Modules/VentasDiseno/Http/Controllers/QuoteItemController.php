<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\VentasDiseno\Http\Requests\QuoteItemRequest;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\PricingSettings;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuoteItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * REQ-035/036/038/041/042 — ítems de una partida. Puede ser personalizado
 * (usuario tipea reference/description/unit_price a mano) o venir de un
 * producto real del Catálogo (`catalog_product_id`) — ver
 * resolveItemData(), que resuelve unit_price/cost/reference/description
 * server-side desde CatalogProduct en ese caso, nunca confía en lo que mande
 * el cliente para esos campos (el buscador de Catálogo ni siquiera expone
 * `cost` por HTTP — ver CatalogProductController).
 */
final class QuoteItemController extends Controller
{
    /**
     * SCRUM-725 (decisión de Luis, 2026-08-05) — revierte la regla de REQ-040 de
     * "nunca revelar el porcentaje mínimo real": el criterio de este ticket pide el
     * número explícito en el mensaje, y de acá en más aplica a todo el sistema de
     * margen (no solo a este ticket) para no dejar dos mensajes iguales con
     * comportamiento distinto. Siempre se interpola desde
     * `PricingSettings::min_margin_percent` (configurable), nunca un número fijo.
     */
    private function marginErrorMessage(): string
    {
        return 'Este cambio dejaría el margen del producto por debajo del mínimo permitido ('
            . $this->formatPercent(PricingSettings::current()->min_margin_percent) . '%)';
    }

    /** `30.0` -> "30", `28.5` -> "28.5" — sin ceros decimales de relleno. */
    private function formatPercent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * SCRUM-725 — mismo permiso de excepción que QuoteController::confirm() (no se
     * puede compartir la constante entre las dos clases sin acoplarlas).
     */
    private const MARGIN_OVERRIDE_PERMISSION = 'ventas_diseno.override_min_margin';

    private function hasMarginOverride(User $user): bool
    {
        return $user->hasPermission(self::MARGIN_OVERRIDE_PERMISSION);
    }

    public function store(QuoteItemRequest $request, int $quoteId, int $partId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $quote = Quote::find($quoteId);
        if ($quote === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($quote, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $part = $quote->parts()->find($partId);
        if ($part === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $blocked = $this->ensureEditable($quote);
        if ($blocked !== null) {
            return $blocked;
        }

        $resolved = $this->resolveItemData($request->validated(), $part->id);
        if ($resolved === null) {
            return response()->json(['message' => 'Producto de catálogo no encontrado'], 422);
        }
        if (is_string($resolved)) {
            return response()->json(['message' => $resolved], 422);
        }

        if ($resolved['is_custom'] && $this->referenceExistsInQuote($quote, $resolved['reference'])) {
            return response()->json(['message' => 'Esta referencia ya existe'], 422);
        }

        // SCRUM-725 — con permiso de excepción (Mark/David), un margen por debajo
        // del mínimo (por producto o global) ya no bloquea acá — se avisa y se
        // bloquea recién al confirmar la cotización (QuoteController::confirm()).
        if (
            ! $this->hasMarginOverride($user)
            && ($this->violatesMinMargin($resolved, $quote) || $this->violatesGlobalMargin($resolved, $quote))
        ) {
            return response()->json(['message' => $this->marginErrorMessage()], 422);
        }

        $item = $part->items()->create($resolved);

        return response()->json($this->format($item, $quote), 201);
    }

    public function update(QuoteItemRequest $request, int $quoteId, int $partId, int $itemId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $quote = Quote::find($quoteId);
        if ($quote === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($quote, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $part = $quote->parts()->find($partId);
        if ($part === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $item = $part->items()->find($itemId);
        if ($item === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $blocked = $this->ensureEditable($quote);
        if ($blocked !== null) {
            return $blocked;
        }

        $resolved = $this->resolveItemData($request->validated(), $part->id, excludeItemId: $item->id);
        if ($resolved === null) {
            return response()->json(['message' => 'Producto de catálogo no encontrado'], 422);
        }
        if (is_string($resolved)) {
            return response()->json(['message' => $resolved], 422);
        }

        if ($resolved['is_custom'] && $this->referenceExistsInQuote($quote, $resolved['reference'], excludeItemId: $item->id)) {
            return response()->json(['message' => 'Esta referencia ya existe'], 422);
        }

        // SCRUM-725 — misma excepción que store() arriba.
        if (
            ! $this->hasMarginOverride($user)
            && ($this->violatesMinMargin($resolved, $quote) || $this->violatesGlobalMargin($resolved, $quote, excludeItemId: $item->id))
        ) {
            return response()->json(['message' => $this->marginErrorMessage()], 422);
        }

        $item->update($resolved);

        return response()->json($this->format($item, $quote));
    }

    /**
     * REQ-035/036/038: con `catalog_product_id`, reference/description/
     * unit_price/cost se resuelven acá desde el producto real — se ignora
     * cualquier valor que el cliente haya mandado para esos campos, y se
     * valida que no esté duplicado en la misma partida. Sin
     * `catalog_product_id`, es un ítem personalizado: se usa tal cual lo que
     * mandó el cliente (ya validado por QuoteItemRequest). Retorna `null` si
     * el `catalog_product_id` no corresponde a un producto activo (defensa
     * adicional — Rule::exists ya lo cubre a nivel de request, pero un
     * producto puede desactivarse entre la validación y acá), o un `string`
     * con el mensaje de error si el producto ya está en la partida.
     *
     * @param array<string, mixed> $validated
     * @return array{
     *   catalog_product_id: int|null, is_custom: bool, reference: string, description: string,
     *   quantity: float, unit_price: float, cost: float|null,
     *   price_type_override: string|null, discount_percent: float|null,
     * }|string|null
     */
    private function resolveItemData(array $validated, int $partId, ?int $excludeItemId = null): array|string|null
    {
        $catalogProductId = isset($validated['catalog_product_id']) ? (int) $validated['catalog_product_id'] : null;

        if ($catalogProductId !== null) {
            $product = CatalogProduct::where('is_active', true)->find($catalogProductId);
            if ($product === null) {
                return null;
            }
            if ($this->catalogProductExistsInPart($partId, $catalogProductId, $excludeItemId)) {
                return 'Este producto ya está en la partida';
            }

            /** @var array{quantity: float, unit_price_override?: float|null, price_type_override?: string|null, discount_percent?: float|null} $validated */
            return [
                'catalog_product_id'  => $product->id,
                'is_custom'           => false,
                'reference'           => $product->reference,
                'description'         => $product->name,
                'quantity'            => $validated['quantity'],
                // SCRUM-725 — el vendedor/diseñador puede fijar un precio de venta
                // manual SOLO para esta cotización vía `unit_price_override` (nunca
                // toca CatalogProduct::price_full); sin override explícito, sigue
                // tomando el precio real del catálogo — `unit_price` (a secas) sigue
                // ignorado acá, solo aplica a ítems personalizados.
                'unit_price'          => $validated['unit_price_override'] ?? $product->price_full,
                'cost'                => $product->cost,
                'price_type_override' => $validated['price_type_override'] ?? null,
                'discount_percent'    => $validated['discount_percent'] ?? null,
            ];
        }

        /**
         * @var array{
         *   reference: string, description: string, quantity: float, unit_price: float,
         *   cost?: float|null, price_type_override?: string|null, discount_percent?: float|null,
         * } $validated
         */
        return [
            'catalog_product_id'  => null,
            'is_custom'           => true,
            'reference'           => $validated['reference'],
            'description'         => $validated['description'],
            'quantity'            => $validated['quantity'],
            'unit_price'          => $validated['unit_price'],
            'cost'                => $validated['cost'] ?? null,
            'price_type_override' => $validated['price_type_override'] ?? null,
            'discount_percent'    => $validated['discount_percent'] ?? null,
        ];
    }

    /**
     * SCRUM-734 (sección 9, RN9.1) — validación de margen EN VIVO para el modal
     * de edición de precio, sin persistir nada y sin exponer `cost` real al
     * cliente (se mantiene la decisión de CatalogProductController de nunca
     * mandar costo por HTTP — acá el cálculo entero vive server-side, el
     * frontend solo recibe válido/inválido + mensaje). Reusa exactamente las
     * mismas validaciones que update() (violatesMinMargin/violatesGlobalMargin),
     * simulando el nuevo `unit_price` sobre los demás campos YA persistidos del
     * ítem (descuento de línea, tipo de precio override, cantidad) — el botón
     * "Guardar" real del modal sigue pegándole al PUT normal de abajo, que
     * vuelve a validar de forma autoritativa antes de persistir; esto es solo
     * para el feedback mientras el usuario todavía está tipeando.
     */
    public function previewPrice(Request $request, int $quoteId, int $partId, int $itemId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $quote = Quote::find($quoteId);
        if ($quote === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($quote, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $part = $quote->parts()->find($partId);
        if ($part === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $item = $part->items()->find($itemId);
        if ($item === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var array{unit_price: float} $validated */
        $validated = $request->validate(['unit_price' => ['required', 'numeric', 'min:0']]);

        $resolved = [
            'unit_price'          => $validated['unit_price'],
            'cost'                => $item->cost,
            'discount_percent'    => $item->discount_percent,
            'price_type_override' => $item->price_type_override,
            'quantity'            => $item->quantity,
        ];

        $violatesMargin = $this->violatesMinMargin($resolved, $quote)
            || $this->violatesGlobalMargin($resolved, $quote, excludeItemId: $item->id);

        return response()->json([
            'violates_margin'    => $violatesMargin,
            'can_override'       => $this->hasMarginOverride($user),
            'message'            => $violatesMargin ? $this->marginErrorMessage() : null,
            'min_margin_percent' => PricingSettings::current()->min_margin_percent,
        ]);
    }

    /**
     * REQ-037: agrega todos los productos de una familia a la partida en una
     * sola llamada — quantity fija en 1 para cada uno (el usuario ajusta
     * después en la partida, igual que cualquier ítem de catálogo). Los
     * productos que ya están en la partida se saltean sin fallar la
     * operación completa (AC3: "sin duplicar los que ya estaban"), a
     * diferencia de store() que sí retorna 422 para un ítem individual
     * duplicado.
     */
    public function bulkStore(Request $request, int $quoteId, int $partId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $quote = Quote::find($quoteId);
        if ($quote === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($quote, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $part = $quote->parts()->find($partId);
        if ($part === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $blocked = $this->ensureEditable($quote);
        if ($blocked !== null) {
            return $blocked;
        }

        /** @var array{catalog_product_ids: list<int>} $validated */
        $validated = $request->validate([
            'catalog_product_ids'   => ['required', 'array', 'min:1'],
            'catalog_product_ids.*' => ['integer', Rule::exists('catalog_products', 'id')->where(fn ($q) => $q->where('is_active', true))],
        ]);

        // SCRUM-725 — a diferencia de store()/update(), acá un producto que viola el
        // margen se SALTEA (mismo tratamiento que un duplicado, ver docblock arriba)
        // en vez de fallar toda la operación — coherente con el contrato ya existente
        // de "una sola llamada, nunca todo o nada por un ítem puntual". Con permiso de
        // excepción (Mark/David), no se saltea nada por margen.
        $hasMarginOverride = $this->hasMarginOverride($user);

        // Senior Review 2026-07-13, blocker — la spec pide "una sola operación,
        // atómica por request"; sin transacción, un error real de DB a mitad del
        // loop dejaba los ítems anteriores comiteados sin rollback.
        [$created, $skipped] = DB::transaction(function () use ($validated, $part, $quote, $hasMarginOverride): array {
            $created = [];
            $skipped = [];

            foreach (array_unique($validated['catalog_product_ids']) as $catalogProductId) {
                $resolved = $this->resolveItemData(['catalog_product_id' => $catalogProductId, 'quantity' => 1], $part->id);

                if (is_string($resolved) || $resolved === null) {
                    $skipped[] = $catalogProductId;
                    continue;
                }

                if (! $hasMarginOverride) {
                    // Recarga fresca en cada vuelta: cada `create()` de abajo no
                    // actualiza por sí solo la colección `parts.items` ya cacheada en
                    // $quote, así que sin esto el chequeo global no vería los ítems ya
                    // agregados en vueltas anteriores del mismo loop.
                    $quote->load('parts.items');
                    if ($this->violatesMinMargin($resolved, $quote) || $this->violatesGlobalMargin($resolved, $quote)) {
                        $skipped[] = $catalogProductId;
                        continue;
                    }
                }

                $created[] = $this->format($part->items()->create($resolved), $quote);
            }

            return [$created, $skipped];
        });

        return response()->json(['created' => $created, 'skipped_catalog_product_ids' => $skipped], 201);
    }

    public function destroy(Request $request, int $quoteId, int $partId, int $itemId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $quote = Quote::find($quoteId);
        if ($quote === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($quote, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $part = $quote->parts()->find($partId);
        if ($part === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $item = $part->items()->find($itemId);
        if ($item === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $blocked = $this->ensureEditable($quote);
        if ($blocked !== null) {
            return $blocked;
        }

        $item->delete();

        return response()->json(null, 204);
    }

    /**
     * REQ-040 (Pre-QA SCRUM-132): valida el margen resultante ANTES de crear/
     * actualizar el ítem — arma una instancia de QuoteItem sin persistir (los
     * atributos ya resueltos por resolveItemData()) para reusar exactamente la
     * misma fórmula que usa el documento (QuoteItem::violatesMinMargin()), en
     * vez de duplicar el cálculo acá. Cubre las 3 vías que pasan por este
     * controller: precio manual, descuento de línea, y tipo de precio
     * individual por ítem — el tipo de precio general y el descuento
     * global/Totales se validan en QuoteController (afectan a TODOS los ítems
     * a la vez, no a uno solo).
     *
     * @param array{unit_price: float, cost: float|null, discount_percent: float|null, price_type_override: string|null} $resolved
     */
    private function violatesMinMargin(array $resolved, Quote $quote): bool
    {
        $probe = new QuoteItem([
            'unit_price'          => $resolved['unit_price'],
            'cost'                => $resolved['cost'],
            'discount_percent'    => $resolved['discount_percent'],
            'price_type_override' => $resolved['price_type_override'],
        ]);

        return $probe->violatesMinMargin(
            $quote->discount_mode,
            $quote->global_discount_percent,
            $quote->price_type,
            PricingSettings::current(),
        );
    }

    /**
     * SCRUM-725 — a diferencia de violatesMinMargin() de arriba (margen del
     * producto solo, por unidad), esto valida el efecto del ítem nuevo/editado
     * sobre el margen de TODA la cotización, delegado a
     * Quote::costedPortfolioMarginBelowMinimum() — mismo cálculo que usa
     * QuoteController, simulando el ítem sin persistirlo primero ($excludeItemId
     * saca la versión vieja de la suma en un update(), $probe aporta la nueva).
     *
     * @param array{unit_price: float, cost: float|null, discount_percent: float|null, price_type_override: string|null, quantity: float} $resolved
     */
    private function violatesGlobalMargin(array $resolved, Quote $quote, ?int $excludeItemId = null): bool
    {
        $probe = new QuoteItem([
            'unit_price'          => $resolved['unit_price'],
            'cost'                => $resolved['cost'],
            'discount_percent'    => $resolved['discount_percent'],
            'price_type_override' => $resolved['price_type_override'],
            'quantity'            => $resolved['quantity'],
        ]);

        return $quote->costedPortfolioMarginBelowMinimum(
            PricingSettings::current(),
            excludeItemId: $excludeItemId,
            probeItem: $probe,
        );
    }

    /**
     * REQ-038: una referencia personalizada duplicada (sin distinguir mayúsculas/
     * minúsculas) se valida en TODA la cotización, no solo en la partida actual.
     */
    private function referenceExistsInQuote(Quote $quote, string $reference, ?int $excludeItemId = null): bool
    {
        $query = QuoteItem::whereHas('quotePart', fn ($q) => $q->where('quote_id', $quote->id))
            ->whereRaw('lower(reference) = ?', [mb_strtolower($reference)]);

        if ($excludeItemId !== null) {
            $query->where('id', '!=', $excludeItemId);
        }

        return $query->exists();
    }

    /**
     * REQ-038: un producto de catálogo duplicado se valida por PARTIDA (no en
     * toda la cotización, a diferencia de la referencia personalizada de
     * arriba) — mismo producto en dos partidas distintas es un caso de uso
     * real (ej. la misma lámpara en "Sala" y en "Cocina"), no un error.
     */
    private function catalogProductExistsInPart(int $partId, int $catalogProductId, ?int $excludeItemId = null): bool
    {
        $query = QuoteItem::where('quote_part_id', $partId)->where('catalog_product_id', $catalogProductId);

        if ($excludeItemId !== null) {
            $query->where('id', '!=', $excludeItemId);
        }

        return $query->exists();
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
     * SCRUM-734 (RN2.2, Senior Review retroactivo 2026-08-06, hallazgo CRÍTICO
     * real, reproducido en vivo) — mismo bloqueo que `QuoteService::update()` ya
     * aplica a `PUT /quotes/{id}`, replicado acá porque agregar/editar/borrar
     * ítems (store/update/bulkStore/destroy) nunca pasaba por ese método. NO se
     * aplica a `previewPrice()` — nunca persiste nada, no hay nada que proteger.
     */
    private function ensureEditable(Quote $quote): ?JsonResponse
    {
        if ($quote->confirmed_at !== null) {
            return response()->json([
                'message' => 'Una cotización generada no se puede editar directamente. Use "Usar como base para nueva versión".',
            ], 422);
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function format(QuoteItem $item, Quote $quote): array
    {
        return [
            'id' => $item->id, 'catalog_product_id' => $item->catalog_product_id, 'is_custom' => $item->is_custom,
            'reference' => $item->reference, 'description' => $item->description,
            'quantity' => $item->quantity, 'unit_price' => $item->unit_price, 'cost' => $item->cost,
            'price_type_override' => $item->price_type_override, 'discount_percent' => $item->discount_percent,
            // REQ-039 — mismo campo que formatDetail() en QuoteController, ver docblock ahí.
            'stock_quantity' => $item->catalogProduct?->stock_quantity,
            // SCRUM-725 — mismo campo que formatDetail() de QuoteController, para que la
            // fila recién creada/editada ya llegue resaltada sin esperar el refetch de la
            // cotización completa.
            'below_min_margin' => $item->violatesMinMargin($quote->discount_mode, $quote->global_discount_percent, $quote->price_type, PricingSettings::current()),
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
