<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Modules\Bodega\Services\OrderCommitmentService;
use App\Modules\Bodega\Services\OrderService;
use App\Modules\Compras\Services\InventoryKpiService;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\PipelineCardProductDelivery;
use App\Modules\VentasDiseno\Models\QuoteItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-909 — completa SCRUM-875 (REQ-319): asignación de qué PRODUCTOS de la cotización van en
 * cada fecha de entrega parcial de una tarjeta de Pipeline. Único punto de entrada de esta lógica
 * (mismo patrón que `PipelineService`/`OrderService`) — el controller solo delega acá.
 *
 * Reusa `OrderService::resolveApprovedQuote()` (público desde SCRUM-786) para resolver la
 * cotización del proyecto en vez de duplicar esa query — es EXACTAMENTE la cotización que
 * `OrderService::createFromApprovedQuote()` va a usar al aprobar, así que la tabla del modal
 * (Escenario 1 del ticket) y el pedido real de Bodega (Escenario 5) nunca pueden desincronizarse
 * en QUÉ ítems existen, solo en a qué fecha quedan asignados.
 *
 * "Disponible" (Escenario 1/3) reusa la misma fórmula que Catálogo/Inventario/Compras/Bodega
 * (`InventoryKpiService::disponibleParaVenta()`, SCRUM-851) con el mismo insumo de compromiso que
 * ya usa `CatalogService`/`CatalogProductController` (`OrderCommitmentService::committedMap()`) —
 * nunca un cálculo de stock paralelo.
 */
final class ProductDeliveryService
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly InventoryKpiService $inventoryKpis,
        private readonly OrderCommitmentService $commitments,
    ) {}

    /**
     * Escenario 1 — tabla de productos del modal: código, nombre, cantidad pedida, cantidad
     * disponible, fecha asignada (si ya se guardó una) y una fecha SUGERIDA (Escenario 3, nunca
     * bloqueada — el frontend/usuario puede pisarla) cuando falta stock. El contrato HTTP expone
     * fechas como STRING (`YYYY-MM-DD`), nunca el id interno `pipeline_card_delivery_date_id` —
     * el frontend ya tiene la lista de fechas parciales vía `PipelineCardDetail.delivery_dates`,
     * no necesita conocer los ids internos de `PipelineCardDeliveryDate`.
     *
     * Ítems personalizados (`is_custom=true`, sin `catalog_product_id`) no tienen contra qué
     * comparar disponibilidad — `quantity_available`/`suggested_delivery_date` quedan null, pero
     * igual aparecen en la tabla y son asignables (siguen siendo un producto que Bodega despacha).
     *
     * @param list<string>|null $draftDates fechas (`YYYY-MM-DD`) del borrador de edición, solo
     *   para la sugerencia; la asignación guardada siempre se resuelve contra las fechas de BD.
     * @return list<array{
     *   quote_item_id: int, catalog_product_id: int|null, code: string, name: string,
     *   quantity_ordered: int, quantity_available: int|null,
     *   assigned_delivery_date: string|null, suggested_delivery_date: string|null,
     * }>
     */
    public function listForCard(PipelineCard $card, ?array $draftDates = null): array
    {
        $sortedDates  = $card->deliveryDates->sortBy('date')->values();
        // SCRUM-1027 — dentro de la edición las fechas aún no están en BD: la fecha más lejana
        // (Escenario 3) se calcula sobre el borrador que manda el frontend, si lo hay.
        $farthestDate = $draftDates !== null && $draftDates !== []
            ? max($draftDates)
            : $sortedDates->last()?->date->toDateString();

        $items = $this->resolveQuoteItems($card);

        /** @var list<int> $productIds */
        $productIds = array_values($items
            ->pluck('catalog_product_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all());

        $committedMap  = $this->commitments->committedMap($productIds);
        $enExcluidaMap = $this->inventoryKpis->enBodegaExcluidaMap($productIds);

        // quote_item_id => fecha (string), para no resolver el id interno en cada fila.
        $dateById = $sortedDates->keyBy('id');
        /** @var array<int, string> $assignments quote_item_id => fecha asignada (YYYY-MM-DD) */
        $assignments = PipelineCardProductDelivery::where('pipeline_card_id', $card->id)
            ->pluck('pipeline_card_delivery_date_id', 'quote_item_id')
            ->map(fn ($dateId) => $dateById->get((int) $dateId)?->date->toDateString())
            ->filter()
            ->all();

        return array_values($items->map(function (QuoteItem $item) use ($committedMap, $enExcluidaMap, $assignments, $farthestDate): array {
            $product      = $item->catalogProduct;
            $qtyRequested = (int) round($item->quantity);
            $qtyAvailable = null;
            $suggested    = null;

            if ($product !== null) {
                $qtyAvailable = (int) max(0, $this->inventoryKpis->disponibleParaVenta(
                    $product,
                    $committedMap[$product->id] ?? 0,
                    $enExcluidaMap[$product->id] ?? 0,
                ));

                // REQ del ticket, Escenario 3: disponible < pedido o disponible == 0 → sugerir la
                // fecha más lejana. Nunca se persiste sola — es solo una pista para precargar el
                // selector; queda editable como cualquier otra asignación.
                if ($farthestDate !== null && ($qtyAvailable < $qtyRequested)) {
                    $suggested = $farthestDate;
                }
            }

            return [
                'quote_item_id'            => $item->id,
                'catalog_product_id'       => $product?->id,
                'code'                     => $product->reference ?? $item->reference,
                'name'                     => $product->name ?? $item->description,
                'quantity_ordered'         => $qtyRequested,
                'quantity_available'       => $qtyAvailable,
                'assigned_delivery_date'   => $assignments[$item->id] ?? null,
                'suggested_delivery_date'  => $suggested,
            ];
        })->all());
    }

    /**
     * Escenario 2 — persiste la asignación fecha↔producto. Cada entrada reemplaza (upsert) la
     * asignación previa de ese `quote_item_id`, sin tocar las de otros ítems que no vengan en el
     * payload — el modal puede guardar fila por fila o el lote completo, ambos casos quedan bien.
     * `delivery_date: null` borra la asignación existente de ese `quote_item_id` (permite
     * "desasignar" sin dejar una fila apuntando a una fecha inválida).
     *
     * El contrato HTTP manda la fecha como STRING (`YYYY-MM-DD`, una de las ya definidas para
     * esta tarjeta) — acá se resuelve a `pipeline_card_delivery_date_id` para persistir, ya que
     * ese es el FK real de la tabla.
     *
     * @param list<array{quote_item_id: int, delivery_date: string|null}> $assignments
     * @throws \InvalidArgumentException si algún quote_item_id no pertenece a la cotización de
     *   este proyecto, o algún delivery_date no es una de las fechas YA definidas para esta misma
     *   tarjeta (regla dura del ticket: "nunca una fecha distinta a las ya seleccionadas").
     */
    public function saveAssignments(PipelineCard $card, array $assignments): void
    {
        $validItemIds = $this->resolveQuoteItems($card)->pluck('id')->all();
        /** @var array<string, int> $dateIdByDate fecha (YYYY-MM-DD) => pipeline_card_delivery_date_id */
        $dateIdByDate = $card->deliveryDates
            ->mapWithKeys(fn ($d) => [$d->date->toDateString() => $d->id])
            ->all();

        foreach ($assignments as $assignment) {
            if (! in_array($assignment['quote_item_id'], $validItemIds, true)) {
                throw new \InvalidArgumentException(
                    "El producto (quote_item_id={$assignment['quote_item_id']}) no pertenece a la cotización de este proyecto.",
                );
            }
            if ($assignment['delivery_date'] !== null && ! array_key_exists($assignment['delivery_date'], $dateIdByDate)) {
                throw new \InvalidArgumentException(
                    'La fecha de entrega indicada no es una de las fechas definidas para esta tarjeta.',
                );
            }
        }

        DB::transaction(function () use ($card, $assignments, $dateIdByDate): void {
            foreach ($assignments as $assignment) {
                if ($assignment['delivery_date'] === null) {
                    PipelineCardProductDelivery::where('pipeline_card_id', $card->id)
                        ->where('quote_item_id', $assignment['quote_item_id'])
                        ->delete();

                    continue;
                }

                PipelineCardProductDelivery::updateOrCreate(
                    ['pipeline_card_id' => $card->id, 'quote_item_id' => $assignment['quote_item_id']],
                    ['pipeline_card_delivery_date_id' => $dateIdByDate[$assignment['delivery_date']]],
                );
            }
        });
    }

    /**
     * Escenario 4 — gate de `Propuesta -> Aprobado` (llamado desde el controller, junto al resto
     * de `PipelineCard::validationErrorForTransitionTo()`; ver docblock de ese método): solo aplica
     * cuando la entrega es Parcial CON 2+ fechas — con 1 sola fecha no hay nada que elegir (mismo
     * criterio de "sin cambios" que ya usa `OrderService::createFromApprovedQuote()` para decidir
     * si divide en familia), así que no vale la pena forzar una asignación ahí.
     */
    public function validationErrorForApproval(PipelineCard $card): ?string
    {
        if ($card->delivery_type !== 'partial' || $card->deliveryDates->count() < 2) {
            return null;
        }

        $itemIds = $this->resolveQuoteItems($card)->pluck('id')->all();
        if ($itemIds === []) {
            return null; // sin ítems (cotización sin generar todavía) — nada que asignar.
        }

        $assignedCount = PipelineCardProductDelivery::where('pipeline_card_id', $card->id)
            ->whereIn('quote_item_id', $itemIds)
            ->distinct('quote_item_id')
            ->count('quote_item_id');

        if ($assignedCount < count($itemIds)) {
            return 'Falta asignar una fecha de entrega a uno o más productos antes de aprobar.';
        }

        return null;
    }

    /** @return Collection<int, QuoteItem> */
    private function resolveQuoteItems(PipelineCard $card): Collection
    {
        $quote = $this->orderService->resolveApprovedQuote($card);

        return $quote?->parts->flatMap(fn ($part) => $part->items) ?? collect();
    }
}
