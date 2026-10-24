<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Controllers;

use App\Modules\Auth\Models\AuditLog;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Services\ReplenishmentService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/** SCRUM-1184 — GET /api/compras/replenishment y PUT .../products/{id}/provider. Ver docblock de ReplenishmentService. */
final class ReplenishmentController extends Controller
{
    public function __construct(private readonly ReplenishmentService $service) {}

    public function index(): JsonResponse
    {
        return response()->json($this->service->summary());
    }

    /**
     * Guarda el proveedor que Compras elige desde «Pendientes de reposición» para un producto del catálogo, para que no haya que volver a elegirlo.
     *
     * `expected_provider_id` es el principal que la pantalla cree que tiene el producto (null = «Sin proveedor»): si otra persona lo cambió entretanto
     * responde 409 y no pisa nada. Con `expected_provider_id` null el proveedor se AÑADE a los que el producto ya tenga y pasa a ser su principal; con un
     * valor (corregir una elección hecha en esta misma pantalla) reemplaza a ese proveedor por el nuevo. Nunca quita ningún otro proveedor del producto
     * ni toca órdenes de compra existentes (reusa `CatalogProduct::syncProviders()`, SCRUM-1126).
     *
     * Acotado a propósito: editar el proveedor de un producto en Inventario exige `compras.edit`, y esta ruta solo `compras.write` (quien genera órdenes).
     * Por eso solo aplica a productos que hoy esperan reposición, y una corrección (`expected_provider_id` no null) solo si el proveedor esperado lo dejó
     * esta misma pantalla (última auditoría `compras.replenishment.provider_assigned` del producto) — nunca para cambiar un proveedor ya establecido.
     */
    public function assignProvider(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'provider_id'          => ['required', 'integer', 'min:1'],
            'expected_provider_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $newId      = (int) $data['provider_id'];
        $expectedId = isset($data['expected_provider_id']) ? (int) $data['expected_provider_id'] : null;

        if (! Provider::where('is_active', true)->whereKey($newId)->exists()) {
            return response()->json(['message' => 'El proveedor no existe o está inactivo.', 'errors' => ['provider_id' => ['El proveedor no existe o está inactivo.']]], 422);
        }

        /** @var JsonResponse $response */
        $response = DB::transaction(function () use ($id, $newId, $expectedId): JsonResponse {
            $product = CatalogProduct::query()->lockForUpdate()->find($id);
            if ($product === null) {
                return response()->json(['message' => 'Producto no encontrado.'], 404);
            }

            if (! $this->isPending($id)) {
                return $this->scopeError();
            }
            // Ya está así (p. ej. la petición se repitió tras un corte de red): no hay nada que cambiar ni que auditar.
            if ($product->provider_id === $newId) {
                return $this->payload($product);
            }
            if ($expectedId !== null && ! $this->lastAssignedHere($id, $expectedId)) {
                return $this->scopeError();
            }

            $current = $product->provider_id;
            if ($current !== $expectedId) {
                return response()->json([
                    'message' => 'El proveedor de este producto cambió mientras tanto. Recarga la pantalla.',
                    'code'    => 'provider_changed',
                    'current_provider_id' => $current,
                ], 409);
            }

            if ($current !== $newId) {
                /** @var list<int> $ids */
                $ids = $product->providers()->pluck('provider_id')->map(fn ($v): int => (int) $v)->all();
                if ($expectedId !== null) {
                    $ids = array_values(array_filter($ids, fn (int $v): bool => $v !== $expectedId));
                }
                $ids[] = $newId;
                $product->syncProviders($ids, $newId);

                app(AuditService::class)->log(
                    action:     AuditService::COMPRAS_REPLENISHMENT_PROVIDER_ASSIGNED,
                    entityType: 'catalog_product',
                    entityId:   $product->id,
                    oldValues:  ['provider_id' => $current],
                    newValues:  ['provider_id' => $newId],
                );
            }

            return $this->payload($product);
        });

        return $response;
    }

    /** ¿Hoy hay pedidos «Sin stock» esperando este producto? (misma condición que `ReplenishmentService::summary()`.) */
    private function isPending(int $productId): bool
    {
        return OrderItem::query()
            ->where('catalog_product_id', $productId)
            ->where('shortage_reason', OrderItem::SHORTAGE_SIN_STOCK)
            ->where('found_at_location', false)
            ->whereHas('order', fn ($q) => $q->where('order_type', Order::ORDER_TYPE_FALTANTE)->where('stage', Order::STAGE_ASIGNADO))
            ->exists();
    }

    /** ¿La última asignación de proveedor de este producto desde la pantalla fue justamente `$providerId`? */
    private function lastAssignedHere(int $productId, int $providerId): bool
    {
        $last = AuditLog::query()
            ->where('action', AuditService::COMPRAS_REPLENISHMENT_PROVIDER_ASSIGNED)
            ->where('entity_type', 'catalog_product')
            ->where('entity_id', (string) $productId)
            ->orderByDesc('id')
            ->first();

        return $last !== null && (int) data_get($last->new_values, 'provider_id') === $providerId;
    }

    private function scopeError(): JsonResponse
    {
        return response()->json(['message' => 'Este producto no se puede cambiar desde Pendientes de reposición.', 'code' => 'replenishment_scope'], 403);
    }

    private function payload(CatalogProduct $product): JsonResponse
    {
        $product->load('providers', 'provider');

        return response()->json(['catalog_product_id' => $product->id, 'provider_id' => $product->provider_id, 'providers' => $product->providersPayload()]);
    }
}
