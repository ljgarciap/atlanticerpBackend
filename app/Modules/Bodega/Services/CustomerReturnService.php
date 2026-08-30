<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Models\User;
use App\Modules\Bodega\Events\ReturnReceived;
use App\Modules\Bodega\Exceptions\CustomerReturnException;
use App\Modules\Bodega\Models\CustomerReturn;
use App\Modules\Bodega\Models\CustomerReturnLine;
use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderItem;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\CircuitBreaker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * SCRUM-473→489 (REQ-403→419, Bloque B6 "Devoluciones") — ver
 * ADR-SCRUM473-489-devoluciones.md. Único punto de escritura del ciclo de vida de una
 * devolución: crear (a partir de una Guía de Entrega real), cargar documento firmado, confirmar
 * recepción física (suma al inventario vía `KardexService` + "notifica" a Administración),
 * rechazar (potestad exclusiva de Bodega, sin aprobación de Mark) y finalizar (nota de crédito
 * simulada).
 */
final class CustomerReturnService
{
    private const DISK = 's3-private';

    /** REQ-414 — el buscador de guía de entrega solo mira Order en estas 3 etapas. */
    private const SEARCHABLE_STAGES = [
        Order::STAGE_POR_DESPACHAR,
        Order::STAGE_DESPACHADO,
        Order::STAGE_ENTREGADO,
    ];

    public function __construct(
        private readonly ReturnFolioService $folio,
        private readonly KardexService $kardex,
    ) {}

    /**
     * REQ-414 — busca `Order` por N° de pedido, cliente o producto (referencia/descripción de
     * alguno de sus ítems), acotado a las 3 etapas confirmadas por Luis 2026-07-26 (ver ADR).
     *
     * @return Collection<int, Order>
     */
    public function searchOrders(string $search): Collection
    {
        $search = trim($search);
        if ($search === '') {
            return new Collection();
        }

        return Order::query()
            ->whereIn('stage', self::SEARCHABLE_STAGES)
            ->where(function (Builder $query) use ($search): void {
                $query->where('order_number', 'ilike', "%{$search}%")
                    ->orWhere('customer_name', 'ilike', "%{$search}%")
                    ->orWhereHas('items', function (Builder $itemQuery) use ($search): void {
                        $itemQuery->where('reference_snapshot', 'ilike', "%{$search}%")
                            ->orWhere('description_snapshot', 'ilike', "%{$search}%");
                    });
            })
            ->with(['items.catalogProduct', 'pipelineCard.owner', 'pipelineCard.salesProject'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();
    }

    /**
     * REQ-415 — al elegir la guía: Cliente/Proyecto (solo lectura) + resumen (pedido, fecha
     * entrega, vendedor) + tabla de productos REALMENTE entregados (`qty_delivered > 0`), único
     * universo elegible para la devolución (REQ-417 RN2: tope = `qty_delivered`).
     *
     * @return array<string, mixed>
     */
    public function orderDetail(Order $order): array
    {
        $order->loadMissing(['items.catalogProduct', 'pipelineCard.owner', 'pipelineCard.salesProject']);

        return [
            'order_id'                => $order->id,
            'order_number'            => $order->order_number,
            'customer_name'           => $order->customer_name,
            'project'                 => $order->pipelineCard?->salesProject?->name,
            'committed_delivery_date' => $order->committed_delivery_date?->toDateString(),
            'vendedor'                => $this->fullName($order->pipelineCard?->owner),
            'items'                   => $order->items
                ->filter(fn (OrderItem $item): bool => $item->qty_delivered > 0)
                ->map(fn (OrderItem $item): array => [
                    'order_item_id'     => $item->id,
                    'reference'         => $item->catalogProduct->reference ?? $item->reference_snapshot,
                    'factory_reference' => $item->catalogProduct?->factory_reference,
                    'description'       => $item->description_snapshot ?? $item->catalogProduct?->description,
                    'qty_delivered'     => $item->qty_delivered,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * REQ-419 — valida ≥1 producto, contacto completo (garantizado por FormRequest/validate en el
     * Controller) y `qty_requested <= order_item.qty_delivered` por línea (REQ-417 RN2), crea en
     * `pendiente`.
     *
     * @param array{
     *     order_id: int,
     *     contact_name: string,
     *     contact_phone: string,
     *     items: list<array{order_item_id: int, qty_requested: int, reason: string, reason_detail?: string|null}>,
     * } $data
     */
    public function create(array $data, User $actor): CustomerReturn
    {
        if ($data['items'] === []) {
            throw new CustomerReturnException(
                'Debe seleccionar al menos un producto.',
                ['items' => ['Debe seleccionar al menos un producto.']],
            );
        }

        return DB::transaction(function () use ($data, $actor): CustomerReturn {
            /** @var array<string, list<string>> $errors */
            $errors = [];
            /** @var list<array{orderItem: OrderItem, input: array{order_item_id: int, qty_requested: int, reason: string, reason_detail?: string|null}}> $lineInputs */
            $lineInputs = [];
            /** @var array<int, int> $pendingInThisRequest — order_item_id => suma ya contada en este mismo payload, para que dos líneas del mismo request para el mismo producto no se cuelen sumando por separado contra el mismo tope. */
            $pendingInThisRequest = [];

            foreach ($data['items'] as $index => $item) {
                /** @var OrderItem|null $orderItem */
                $orderItem = OrderItem::where('order_id', $data['order_id'])->find($item['order_item_id']);

                if ($orderItem === null) {
                    $errors["items.{$index}.order_item_id"] = ['Producto no encontrado en el pedido.'];

                    continue;
                }

                // RN2 (REQ-417) — el tope es acumulado: la suma de lo YA solicitado en cualquier
                // otra devolución no rechazada del mismo `order_item_id` + lo que se pide ahora no
                // puede superar `qty_delivered`. Sin este chequeo se podían crear N devoluciones
                // distintas que, sumadas, devuelven más unidades de las que el cliente recibió
                // (hallazgo Pre-QA 2026-07-26, confirmado con la fixture VR-9001/PERFIL-2M-VR: 4
                // solicitudes activas de 1 unidad cada una sobre un ítem con qty_delivered=3, y el
                // sistema todavía aceptaba una quinta).
                $alreadyRequested = (int) CustomerReturnLine::query()
                    ->where('order_item_id', $orderItem->id)
                    ->whereHas('customerReturn', fn (Builder $q) => $q->where('status', '!=', CustomerReturn::STATUS_RECHAZADA))
                    ->sum('qty_requested');
                $alreadyRequested += $pendingInThisRequest[$orderItem->id] ?? 0;

                if ($item['qty_requested'] < 1 || ($item['qty_requested'] + $alreadyRequested) > $orderItem->qty_delivered) {
                    $available = max(0, $orderItem->qty_delivered - $alreadyRequested);
                    $errors["items.{$index}.qty_requested"] = [
                        "La cantidad no puede exceder lo disponible para devolver ({$available} unidad(es) disponibles de {$orderItem->qty_delivered} entregadas; ya hay {$alreadyRequested} solicitadas en otras devoluciones).",
                    ];

                    continue;
                }

                $pendingInThisRequest[$orderItem->id] = $alreadyRequested + $item['qty_requested'];
                $lineInputs[] = ['orderItem' => $orderItem, 'input' => $item];
            }

            if ($errors !== []) {
                throw new CustomerReturnException('No se pudo crear la devolución.', $errors);
            }

            $customerReturn = CustomerReturn::create([
                'return_number' => $this->folio->next(),
                'order_id'      => $data['order_id'],
                'status'        => CustomerReturn::STATUS_PENDIENTE,
                'contact_name'  => $data['contact_name'],
                'contact_phone' => $data['contact_phone'],
                'created_by'    => $actor->id,
            ]);

            foreach ($lineInputs as $entry) {
                $orderItem = $entry['orderItem'];
                $input     = $entry['input'];

                // 'reason' se escribe con el valor crudo primero (columna NOT NULL) y luego
                // `setReason()` lo normaliza embebiendo el texto libre de "otra" si aplica.
                $line = $customerReturn->lines()->create([
                    'order_item_id'        => $orderItem->id,
                    'reference_snapshot'   => $orderItem->catalogProduct->reference ?? $orderItem->reference_snapshot,
                    'description_snapshot' => $orderItem->catalogProduct->name ?? $orderItem->description_snapshot,
                    // SCRUM-786 — snapshot de lo que ya trae el OrderItem (NULL para pedidos
                    // creados antes de este fix, ver bodega:backfill-order-item-prices).
                    'unit_price'           => $orderItem->unit_price,
                    'qty_requested'        => $input['qty_requested'],
                    'reason'               => $input['reason'],
                ]);
                $line->setReason($input['reason'], $input['reason_detail'] ?? null);
            }

            return $customerReturn->fresh('lines') ?? $customerReturn;
        });
    }

    /** REQ-408 — sube el documento firmado (foto o PDF); habilita "Confirmar recepción física". */
    public function uploadSignedDocument(CustomerReturn $customerReturn, UploadedFile $file): CustomerReturn
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $dir = "bodega/customer-returns/{$customerReturn->id}";
        $key = "{$dir}/firmado-".Str::uuid()->toString().($ext !== '' ? ".{$ext}" : '');

        (new CircuitBreaker(self::DISK))->call(function () use ($file, $dir, $key): void {
            $uploaded = Storage::disk(self::DISK)->putFileAs($dir, $file, basename($key));
            if ($uploaded === false) {
                throw new \RuntimeException('S3 upload failed for disk ['.self::DISK."] key [{$key}]");
            }
        });

        $customerReturn->update([
            'signed_document_path'        => $key,
            'signed_document_uploaded_at' => now(),
        ]);

        return $customerReturn->fresh() ?? $customerReturn;
    }

    /**
     * REQ-409/410 (transacción) — cantidad real por línea (RN1: puede diferir de lo solicitado) +
     * una sola bodega destino para toda la devolución. Suma al inventario vía `KardexService`
     * (RN2-RN5 de REQ-402, "Devolución desde recepción física") y sella `credit_note_notified_at`
     * en el mismo momento (RN1 de REQ-410) — ambas cosas atómicas con la resolución de la
     * devolución, mismo mecanismo de lock+chequeo-de-estado-dentro-de-la-transacción que
     * `RelocationRequestController::approve()` para que una confirmación doble no aplique el
     * movimiento otra vez.
     *
     * @param list<array{customer_return_line_id: int, qty_received: int}> $lineInputs
     */
    public function confirmReception(CustomerReturn $customerReturn, array $lineInputs, int $destinationWarehouseId, User $actor): CustomerReturn
    {
        return DB::transaction(function () use ($customerReturn, $lineInputs, $destinationWarehouseId, $actor): CustomerReturn {
            /** @var CustomerReturn|null $locked */
            $locked = CustomerReturn::lockForUpdate()->find($customerReturn->id);
            if ($locked === null) {
                throw new CustomerReturnException('Devolución no encontrada.', [], 404);
            }
            if (! $locked->isPending()) {
                throw new CustomerReturnException('Esta devolución ya fue resuelta.');
            }
            if (! $locked->hasSignedDocument()) {
                throw new CustomerReturnException('Debe cargar el documento firmado antes de confirmar la recepción.');
            }

            $locked->loadMissing('lines.orderItem');
            /** @var Collection<int, CustomerReturnLine> $linesById */
            $linesById = $locked->lines->keyBy('id');

            foreach ($lineInputs as $input) {
                /** @var CustomerReturnLine|null $line */
                $line = $linesById->get($input['customer_return_line_id']);
                if ($line === null) {
                    throw new CustomerReturnException('Línea de devolución no encontrada.');
                }
                if ($input['qty_received'] < 0) {
                    throw new CustomerReturnException('La cantidad recibida no puede ser negativa.');
                }

                $line->update(['qty_received' => $input['qty_received']]);

                // Ítems personalizados (QuoteItem.is_custom, sin catalog_product_id) no tienen
                // stock que ajustar — mismo criterio que el resto del módulo (ver docblock de
                // OrderItem::$catalog_product_id).
                if ($input['qty_received'] > 0 && $line->orderItem->catalog_product_id !== null) {
                    $this->kardex->registrar(
                        catalogProductId: $line->orderItem->catalog_product_id,
                        warehouseId: $destinationWarehouseId,
                        tipo: InventoryMovement::TIPO_DEVOLUCION,
                        cantidad: $input['qty_received'],
                        referenciaTexto: "Devolución {$locked->return_number} recibida",
                        referenciaEntidadTipo: 'CUSTOMER_RETURN',
                        referenciaEntidadId: $locked->id,
                        realizadoPor: $actor->id,
                    );
                }
            }

            $locked->update([
                'received_by'              => $actor->id,
                'received_at'              => now(),
                'destination_warehouse_id' => $destinationWarehouseId,
                'credit_note_notified_at'  => now(),
                'status'                   => CustomerReturn::STATUS_ESPERANDO_NOTA_CREDITO,
            ]);

            event(new ReturnReceived($locked->id, $locked->return_number, $locked->order_id, $this->requiredTenantSlug()));

            return $locked->fresh(['lines', 'destinationWarehouse', 'receivedBy']) ?? $locked;
        });
    }

    /**
     * REQ-411 — potestad EXCLUSIVA de Bodega (sin aprobación de Mark, a diferencia de todos los
     * demás flujos de rechazo del módulo), solo permitido antes de `confirmReception()`. Motivo
     * obligatorio (validado en el Controller); NO toca inventario, NO setea
     * `credit_note_notified_at`. Doble confirmación es responsabilidad del frontend (RN2 del ADR).
     */
    public function reject(CustomerReturn $customerReturn, string $reason, User $actor): CustomerReturn
    {
        return DB::transaction(function () use ($customerReturn, $reason, $actor): CustomerReturn {
            /** @var CustomerReturn|null $locked */
            $locked = CustomerReturn::lockForUpdate()->find($customerReturn->id);
            if ($locked === null) {
                throw new CustomerReturnException('Devolución no encontrada.', [], 404);
            }
            if (! $locked->isPending()) {
                throw new CustomerReturnException('Esta devolución ya fue resuelta.');
            }

            $locked->update([
                'status'           => CustomerReturn::STATUS_RECHAZADA,
                'rejected_by'      => $actor->id,
                'rejected_at'      => now(),
                'rejection_reason' => $reason,
            ]);

            return $locked->fresh(['rejectedBy']) ?? $locked;
        });
    }

    /**
     * Batch 12 de Admin&Cont (SCRUM-565→570, REQ-491) — cierra el círculo que
     * `confirmReception()` dejó abierto: `credit_note_notified_at` "simulaba" el aviso a
     * Administración & Contabilidad porque ese módulo no existía todavía (ver docblock de
     * `CustomerReturn`); ahora sí existe y esta es la llamada real. Se invoca desde
     * `AdminCont\Services\CreditNoteService` DENTRO de la misma transacción con la que se crea la
     * nota de crédito — decisión explícita de Luis (ver ADR-SCRUM565-570): el efecto es 1:1 e
     * inmediato, a diferencia del bridge de stock (SCRUM-406, evento+listener con try/catch
     * aislado) — acá si esta llamada falla, la nota de crédito tampoco debe quedar creada.
     */
    public function finalizeForCreditNote(int $customerReturnId, string $creditNoteNumero, User $actor): void
    {
        /** @var CustomerReturn|null $locked */
        $locked = CustomerReturn::lockForUpdate()->find($customerReturnId);
        if ($locked === null) {
            throw new CustomerReturnException('Devolución no encontrada.', [], 404);
        }
        if (! $locked->isAwaitingCreditNote()) {
            throw new CustomerReturnException("La devolución {$locked->return_number} ya no está esperando nota de crédito (estado actual: {$locked->status}).");
        }

        $locked->update(['status' => CustomerReturn::STATUS_FINALIZADO, 'finalized_at' => now()]);

        Log::info("Devolución {$locked->return_number} finalizada — nota de crédito {$creditNoteNumero} (usuario {$actor->id}).");
    }

    /**
     * REQ-405 — historial cronológico reconstruido desde los timestamps ya presentes en la fila
     * (ver docblock de la migración/ADR para el porqué de no usar una tabla de historial aparte).
     *
     * @return list<array<string, mixed>>
     */
    public function historyPayload(CustomerReturn $customerReturn): array
    {
        $customerReturn->loadMissing(['createdBy', 'receivedBy', 'rejectedBy', 'destinationWarehouse']);

        $steps = [
            [
                'step'  => 'created',
                'label' => 'Devolución creada',
                'at'    => $customerReturn->created_at->toIso8601String(),
                'by'    => $this->fullName($customerReturn->createdBy),
            ],
        ];

        if ($customerReturn->signed_document_uploaded_at !== null) {
            $steps[] = [
                'step'  => 'signed_document_uploaded',
                'label' => 'Documento firmado cargado',
                'at'    => $customerReturn->signed_document_uploaded_at->toIso8601String(),
                'by'    => null,
            ];
        }

        if ($customerReturn->received_at !== null) {
            $steps[] = [
                'step'                  => 'received',
                'label'                 => 'Recepción física confirmada',
                'at'                    => $customerReturn->received_at->toIso8601String(),
                'by'                    => $this->fullName($customerReturn->receivedBy),
                'destination_warehouse' => $customerReturn->destinationWarehouse?->name,
            ];
        }

        if ($customerReturn->credit_note_notified_at !== null) {
            $steps[] = [
                'step'  => 'credit_note_notified',
                'label' => 'Administración & Contabilidad notificada',
                'at'    => $customerReturn->credit_note_notified_at->toIso8601String(),
                'by'    => null,
            ];
        }

        if ($customerReturn->finalized_at !== null) {
            $steps[] = [
                'step'  => 'finalized',
                'label' => 'Finalizado',
                'at'    => $customerReturn->finalized_at->toIso8601String(),
                'by'    => null,
            ];
        }

        if ($customerReturn->rejected_at !== null) {
            $steps[] = [
                'step'   => 'rejected',
                'label'  => 'Rechazada',
                'at'     => $customerReturn->rejected_at->toIso8601String(),
                'by'     => $this->fullName($customerReturn->rejectedBy),
                'reason' => $customerReturn->rejection_reason,
            ];
        }

        return $steps;
    }

    private function fullName(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $name = trim("{$user->first_name} {$user->last_name}");

        return $name !== '' ? $name : null;
    }

    private function requiredTenantSlug(): string
    {
        $tenant = Tenant::current();

        if ($tenant === null) {
            throw new \RuntimeException('Cannot dispatch domain event without an active tenant.');
        }

        return $tenant->slug;
    }
}
