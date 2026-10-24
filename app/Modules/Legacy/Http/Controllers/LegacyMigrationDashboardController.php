<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Http\Controllers;

use App\Modules\Legacy\Models\Albaran;
use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\Cotizacion;
use App\Modules\Legacy\Models\Factura;
use App\Modules\Legacy\Models\Pago;
use App\Modules\Legacy\Models\Pedido;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\Proveedor;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Models\SyncRunDetail;
use App\Shared\Http\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SCRUM-816 (épica SCRUM-749 — Migración ICG→AtlanticERP) — dashboard de solo lectura para que el
 * equipo técnico (y, vía Bloque 1/2, Gerencia/CTO) vea el resultado real de las corridas de
 * `legacy:sync-*` sin tener que consultar SQL directo. 3 bloques (spec cerrada con Luis
 * 2026-09-01/02):
 *   1. Resumen histórico de `legacy.sync_runs` — paginado, filtrable por entidad.
 *   2. Comparación de conteos staging (`legacy.*`) vs destino real (snapshot actual, sin paginar —
 *      7 filas fijas, una por entidad).
 *   3. Muestra de registros excluidos con motivo (`legacy.sync_run_details`, SCRUM-816) — paginado,
 *      filtrable por entidad/status. Solo cubre corridas desde que existe esta tabla — ver
 *      docblock de la migración.
 */
final class LegacyMigrationDashboardController
{
    private const ENTITIES = [
        'productos', 'clientes', 'proveedores', 'cotizaciones', 'pedidos', 'albaranes', 'facturas', 'pagos',
        'stock', // SCRUM-1114
    ];

    public function runs(Request $request): JsonResponse
    {
        $entidad = $request->query('entidad');
        $params  = Pagination::params($request, ['started_at', 'entidad'], 'started_at', 'desc');

        $query = SyncRun::query();
        if (is_string($entidad) && in_array($entidad, self::ENTITIES, true)) {
            $query->where('entidad', $entidad);
        }

        $paginator = $query
            ->orderBy($params['sort_by'], $params['sort_dir'])
            ->paginate($params['per_page'], ['*'], 'page', $params['page']);

        return response()->json([
            'data' => $paginator->getCollection()->map(static fn (SyncRun $run): array => [
                'id'          => $run->id,
                'entidad'     => $run->entidad,
                'extraidos'   => $run->extraidos,
                'staged'      => $run->staged,
                'mapeados'    => $run->mapeados,
                'cargados'    => $run->cargados,
                'excluidos'   => $run->excluidos,
                'errores'     => $run->errores,
                'duracion_ms' => $run->duracion_ms,
                'status'      => $run->status,
                'started_at'  => $run->started_at->toIso8601String(),
                'finished_at' => $run->finished_at->toIso8601String(),
                // SCRUM-1114 — informe de divergencia (solo la entidad `stock`; null para el resto).
                'resumen'     => $run->resumen,
            ])->values(),
            ...Pagination::meta($paginator),
        ]);
    }

    public function counts(): JsonResponse
    {
        return response()->json([
            'data' => [
                $this->countRow('productos', Producto::query()->count(), Producto::query()->whereNotNull('catalog_product_id')->count()),
                $this->countRow('clientes', Cliente::query()->count(), Cliente::query()->where('match_status', Cliente::MATCH_MATCHED)->count()),
                $this->countRow('proveedores', Proveedor::query()->count(), Proveedor::query()->where('match_status', Proveedor::MATCH_MATCHED)->count()),
                $this->countRow('cotizaciones', Cotizacion::query()->count(), Cotizacion::query()->where('orphan', false)->count()),
                $this->countRow('pedidos', Pedido::query()->count(), Pedido::query()->where('orphan', false)->count()),
                $this->countRow('albaranes', Albaran::query()->count(), Albaran::query()->where('orphan', false)->count()),
                $this->countRow('facturas', Factura::query()->count(), Factura::query()->where('orphan', false)->count()),
                // Pagos es catálogo standalone sin destino/matching (ver docblock de Pago) —
                // "resuelto" no aplica, se expone null para que el frontend lo muestre como "N/A".
                $this->countRow('pagos', Pago::query()->count(), null),
            ],
        ]);
    }

    /** @return array{entidad: string, staging: int, resuelto: int|null} */
    private function countRow(string $entidad, int $staging, ?int $resuelto): array
    {
        return ['entidad' => $entidad, 'staging' => $staging, 'resuelto' => $resuelto];
    }

    public function excluded(Request $request): JsonResponse
    {
        $entidad = $request->query('entidad');
        $status  = $request->query('status');
        $params  = Pagination::params($request, ['created_at'], 'created_at', 'desc');

        $query = SyncRunDetail::query()->with('syncRun:id,entidad');

        if (is_string($entidad) && in_array($entidad, self::ENTITIES, true)) {
            $query->whereHas('syncRun', static fn ($q) => $q->where('entidad', $entidad));
        }
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $paginator = $query
            ->orderBy($params['sort_by'], $params['sort_dir'])
            ->paginate($params['per_page'], ['*'], 'page', $params['page']);

        return response()->json([
            'data' => $paginator->getCollection()->map(static fn (SyncRunDetail $d): array => [
                'id'         => $d->id,
                'entidad'    => $d->syncRun?->entidad,
                'referencia' => $d->referencia,
                'status'     => $d->status,
                'motivo'     => $d->motivo,
                'created_at' => $d->created_at?->toIso8601String(),
            ])->values(),
            ...Pagination::meta($paginator),
        ]);
    }
}
