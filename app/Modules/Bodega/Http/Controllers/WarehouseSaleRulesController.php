<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Http\Controllers;

use App\Modules\Bodega\Models\OrderItem;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Services\StockReservationService;
use App\Modules\Bodega\Services\WarehouseUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1196 — panel de superadmin «Reglas de bodega» (Configuración de Bodega): qué bodegas pueden surtir ventas (`permite_venta`), cuál es la de despacho por
 * defecto (`despacho_por_defecto`) y cuáles se excluyen del «Disponible» (`excluye_disponible`, SCRUM-851). Regla global del proyecto: nada de esto vive fijo en el
 * código; se cambia sin deploy. Auditado por `LogsAuditTrail` del modelo `Warehouse`.
 *
 * Fase 8b — también administra los almacenes: alta (`store`), renombre/tipo/desactivación (`update`) y baja protegida (`destroy`). Un almacén de
 * sistema (`funcion`), el de despacho por defecto, uno con stock o con historial NO se borra: se desactiva (`is_active`), y solo si está vacío.
 *
 * Invariantes que este controlador protege (la base impone además un índice único parcial y un CHECK):
 *  - siempre existe EXACTAMENTE UNA bodega de despacho por defecto, y es de venta;
 *  - una bodega de solo consulta (Zona Libre) nunca permite venta;
 *  - desactivar `permite_venta` de la bodega por defecto, o dejar el sistema sin por defecto, se rechaza con 422.
 */
final class WarehouseSaleRulesController extends Controller
{
    public function __construct(
        private readonly StockReservationService $reservations,
        private readonly WarehouseUsage $usage,
    ) {}

    /** GET /api/bodega/settings/warehouses */
    public function index(): JsonResponse
    {
        return response()->json(['data' => Warehouse::query()->orderBy('id')->get()->map(fn (Warehouse $w): array => $this->format($w))->values()->all()]);
    }

    /** PUT /api/bodega/settings/warehouses/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'permite_venta'        => ['sometimes', 'boolean'],
            'despacho_por_defecto' => ['sometimes', 'boolean'],
            'excluye_disponible'   => ['sometimes', 'boolean'],
            'name'                 => ['sometimes', 'string', 'min:2', 'max:60'],
            'tipo'                 => ['sometimes', 'in:'.Warehouse::TIPO_BODEGA.','.Warehouse::TIPO_EXHIBICION],
            'is_active'            => ['sometimes', 'boolean'],
        ]);

        try {
            /** @var JsonResponse $response */
            $response = $this->applyChange($id, $validated);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Dos superadmin cambiaron la bodega por defecto a la vez: la base impide dos (índice único parcial); se pide reintentar en vez de un 500.
            return response()->json(['message' => 'Otro cambio simultáneo de la bodega por defecto ganó la carrera. Recarga la pantalla y vuelve a intentarlo.', 'code' => 'conflict'], 409);
        }

        // Cambiar qué bodegas venden o cuál es la por defecto mueve las reservas de los pedidos en picking: se recalculan ya, no en el próximo evento de picking.
        if ($response->getStatusCode() === 200 && (array_key_exists('permite_venta', $validated) || array_key_exists('despacho_por_defecto', $validated) || array_key_exists('is_active', $validated))) {
            /** @var list<int> $productIds */
            $productIds = OrderItem::query()->whereNotNull('catalog_product_id')
                ->whereHas('order', fn ($q) => $q->whereIn('stage', [Order::STAGE_EN_PICKING]))
                ->distinct()->pluck('catalog_product_id')->map(fn ($id) => (int) $id)->all();
            $this->reservations->reallocateForProducts($productIds);
        }

        return $response;
    }

    /** @param array<string, mixed> $validated */
    private function applyChange(int $id, array $validated): JsonResponse
    {
        return DB::transaction(function () use ($id, $validated): JsonResponse {
            $warehouse = Warehouse::query()->lockForUpdate()->find($id);
            if ($warehouse === null) {
                return response()->json(['message' => 'Bodega no encontrada.'], 404);
            }

            $permiteVenta = array_key_exists('permite_venta', $validated) ? (bool) $validated['permite_venta'] : $warehouse->permite_venta;
            $porDefecto   = array_key_exists('despacho_por_defecto', $validated) ? (bool) $validated['despacho_por_defecto'] : $warehouse->despacho_por_defecto;

            if ($porDefecto && ! $permiteVenta) {
                return $this->invalid('La bodega de despacho por defecto debe permitir ventas.');
            }
            if ($warehouse->despacho_por_defecto && ! $porDefecto) {
                return $this->invalid('Siempre debe haber una bodega de despacho por defecto: elige otra bodega como por defecto en lugar de quitar esta.');
            }

            $excluye = array_key_exists('excluye_disponible', $validated) ? (bool) $validated['excluye_disponible'] : $warehouse->excluye_disponible;
            if ($porDefecto && $excluye) {
                // Excluirla dejaría el «Disponible» de TODOS los productos en 0 (solo cuenta la bodega de despacho por defecto).
                return $this->invalid('La bodega de despacho por defecto no puede excluirse del Disponible: el Disponible que ven los vendedores es el de esa bodega.');
            }

            $changes = array_intersect_key($validated, array_flip(['permite_venta', 'excluye_disponible', 'tipo']));

            if (array_key_exists('name', $validated)) {
                $name = trim((string) $validated['name']);
                if (Warehouse::query()->where('name', $name)->whereKeyNot($warehouse->id)->exists()) {
                    return $this->invalid('Ya existe un almacén con ese nombre.');
                }
                $changes['name'] = $name;
            }

            if (array_key_exists('is_active', $validated)) {
                $activo = (bool) $validated['is_active'];
                if (! $activo && $warehouse->is_active) {
                    if (($motivo = $this->usage->motivoParaNoDesactivar($warehouse)) !== null) {
                        return $this->invalid($motivo);
                    }
                    $changes['is_active'] = false;
                    $changes['permite_venta'] = false;
                } elseif ($activo && ! $warehouse->is_active) {
                    $changes['is_active'] = true;
                }
            }
            if (! ($changes['is_active'] ?? $warehouse->is_active) && ($changes['permite_venta'] ?? false)) {
                return $this->invalid('Un almacén desactivado no puede surtir ventas: actívalo primero.');
            }
            if ($porDefecto && ! $warehouse->despacho_por_defecto) {
                // La anterior deja de serlo en el mismo movimiento (índice único parcial: nunca dos a la vez).
                Warehouse::query()->where('despacho_por_defecto', true)->update(['despacho_por_defecto' => false]);
                $changes['despacho_por_defecto'] = true;
            }
            if ($changes !== []) {
                $warehouse->update($changes);
            }

            return response()->json($this->format($warehouse->fresh() ?? $warehouse));
        });
    }

    /** POST /api/bodega/settings/warehouses — alta de un almacén (nunca de sistema, ni por defecto). */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'               => ['required', 'string', 'min:2', 'max:60'],
            'tipo'               => ['sometimes', 'in:'.Warehouse::TIPO_BODEGA.','.Warehouse::TIPO_EXHIBICION],
            'modo_detalle'       => ['sometimes', 'in:'.Warehouse::MODO_UBICACION_EXACTA.','.Warehouse::MODO_PENDIENTE.','.Warehouse::MODO_CLIENTE],
            'permite_venta'      => ['sometimes', 'boolean'],
            'excluye_disponible' => ['sometimes', 'boolean'],
        ]);
        $name = trim($validated['name']);

        if (Warehouse::query()->where('name', $name)->exists()) {
            return $this->invalid('Ya existe un almacén con ese nombre.');
        }

        $warehouse = Warehouse::query()->create([
            'name'               => $name,
            'tipo'               => $validated['tipo'] ?? Warehouse::TIPO_BODEGA,
            'modo_detalle'       => $validated['modo_detalle'] ?? Warehouse::MODO_UBICACION_EXACTA,
            'permite_venta'      => (bool) ($validated['permite_venta'] ?? false),
            'excluye_disponible' => (bool) ($validated['excluye_disponible'] ?? false),
            'is_active'          => true,
        ]);

        return response()->json($this->format($warehouse->fresh() ?? $warehouse), 201);
    }

    /** DELETE /api/bodega/settings/warehouses/{id} — baja protegida: solo un almacén vacío, sin historial y que no sea de sistema. */
    public function destroy(int $id): JsonResponse
    {
        return DB::transaction(function () use ($id): JsonResponse {
            $warehouse = Warehouse::query()->lockForUpdate()->find($id);
            if ($warehouse === null) {
                return response()->json(['message' => 'Bodega no encontrada.'], 404);
            }
            if (($motivo = $this->usage->motivoParaNoBorrar($warehouse)) !== null) {
                return response()->json(['message' => $motivo, 'errors' => ['warehouse' => [$motivo]], 'code' => 'no_se_puede_borrar'], 422);
            }

            // Filas de stock en 0 (sin movimiento) no son historial: caen con el almacén.
            \App\Modules\Bodega\Models\ProductWarehouseStock::query()->where('warehouse_id', $warehouse->id)->delete();
            $warehouse->delete();

            return response()->json(null, 204);
        });
    }

    private function invalid(string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => ['warehouse' => [$message]]], 422);
    }

    /** @return array<string, mixed> */
    private function format(Warehouse $w): array
    {
        return [
            'id'                   => $w->id,
            'name'                 => $w->name,
            'permite_venta'        => $w->permite_venta,
            'despacho_por_defecto' => $w->despacho_por_defecto,
            'excluye_disponible'   => $w->excluye_disponible,
            'tipo'                 => $w->tipo,
            'funcion'              => $w->funcion,
            'is_active'            => $w->is_active,
            'modo_detalle'         => $w->modo_detalle,
        ];
    }
}
