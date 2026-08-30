<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Http\Controllers;

use App\Modules\Bodega\Models\InventoryMovement;
use App\Shared\Http\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * SCRUM-467→472 (REQ-397→402) — pantalla "Movimientos de inventario" (Kardex). Solo lectura: no
 * hay ningún endpoint de creación/edición/borrado a propósito (REQ-401 — inmutable, las líneas
 * se generan únicamente vía `KardexService::registrar()` desde otras entidades).
 */
final class KardexController extends Controller
{
    /** @var list<string> */
    private const ALLOWED_SORTS = ['created_at'];

    public function index(Request $request): JsonResponse
    {
        $query = InventoryMovement::query()->with(['catalogProduct', 'warehouse', 'realizadoPor']);

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->whereHas(
                'catalogProduct',
                fn ($q) => $q->where('reference', 'ilike', "%{$search}%")
                    ->orWhere('name', 'ilike', "%{$search}%"),
            );
        }

        $tipo = $request->query('tipo');
        if (is_string($tipo) && $tipo !== '') {
            $query->where('tipo', $tipo);
        }

        $warehouseId = $request->query('warehouse_id');
        if ($warehouseId !== null && $warehouseId !== '') {
            $query->where('warehouse_id', (int) $warehouseId);
        }

        // `id` como desempate: 2 movimientos pueden compartir el mismo `created_at` a nivel de
        // segundo (ej. las 2 líneas simultáneas de una Reubicación, REQ-402 RN4) — sin esto el
        // orden entre ellos queda indefinido.
        $pagination = Pagination::params($request, self::ALLOWED_SORTS, 'created_at', 'desc');
        $paginator = $query
            ->orderBy('created_at', $pagination['sort_dir'])
            ->orderBy('id', $pagination['sort_dir'])
            ->paginate($pagination['per_page'], ['*'], 'page', $pagination['page']);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (InventoryMovement $m) => $this->format($m))->values(),
            'meta' => Pagination::meta($paginator),
        ]);
    }

    /** @return array<string, mixed> */
    private function format(InventoryMovement $movement): array
    {
        return [
            'id'             => $movement->id,
            'fecha'          => $movement->created_at->toIso8601String(),
            'producto'       => [
                'id'          => $movement->catalogProduct?->id,
                'reference'   => $movement->catalogProduct?->reference,
                'description' => $movement->catalogProduct?->name,
            ],
            'bodega'         => $movement->warehouse?->name,
            'tipo'           => $movement->tipo,
            'cantidad'       => $movement->cantidad,
            'referencia'     => $movement->referencia_texto,
            'realizado_por'  => $movement->realizadoPor?->first_name !== null
                ? trim("{$movement->realizadoPor->first_name} {$movement->realizadoPor->last_name}")
                : 'Sistema',
            'saldo_inicial'    => $movement->saldo_inicial,
            'saldo_resultante' => $movement->saldo_resultante,
        ];
    }
}
