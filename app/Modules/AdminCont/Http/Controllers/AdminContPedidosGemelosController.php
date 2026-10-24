<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Http\Resources\AdminCont\PedidoGemeloRowResource;
use App\Modules\AdminCont\Services\PedidosGemelosExportService;
use App\Modules\AdminCont\Services\PedidosGemelosReportService;
use App\Shared\Http\Pagination;
use App\Shared\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Vista de validación de pedidos gemelos de ICG — SOLO LECTURA (spec `docs/specs/icg-pedidos-gemelos-vista-validacion.md`).
 * Controller fino: delega en `PedidosGemelosReportService`. Facturar el saldo NO pasa por acá: la UI lleva al flujo de
 * facturación existente.
 */
class AdminContPedidosGemelosController
{
    public const AUDIT_EXPORT = 'admin_contab.pedidos_gemelos.exported';

    public function __construct(
        private readonly PedidosGemelosReportService $report,
        private readonly PedidosGemelosExportService $export,
    ) {}

    /** GET /api/admin-contab/reports/pedidos-gemelos */
    public function index(Request $request): JsonResponse
    {
        $filtros = $this->filtros($request);
        $pag     = Pagination::params($request, PedidosGemelosReportService::ORDENES, 'fecha', 'desc');
        $res     = $this->report->listar($filtros + ['orden' => $pag['sort_by'], 'dir' => $pag['sort_dir']]);

        $paginado = Pagination::ofArray($res['filas'], $pag['per_page'], $pag['page']);

        return response()->json([
            'data'    => PedidoGemeloRowResource::collection(collect($paginado->items())),
            'meta'    => ['total' => $paginado->total(), 'per_page' => $paginado->perPage(), 'current_page' => $paginado->currentPage(), 'last_page' => $paginado->lastPage()],
            'totales' => $res['totales'],
            'resumen' => $res['resumen'],
        ]);
    }

    /** GET /api/admin-contab/reports/pedidos-gemelos/excel */
    public function excel(Request $request, AuditService $audit): StreamedResponse|JsonResponse
    {
        $filtros = $this->filtros($request);
        $res     = $this->report->listar($filtros + ['orden' => 'fecha', 'dir' => 'desc']);

        if ($res['filas'] === []) {
            return response()->json(['message' => 'No hay pedidos gemelos para descargar con el filtro actual.'], 422);
        }

        $audit->log(self::AUDIT_EXPORT, 'order', null, null, null, ['filtros' => $filtros, 'filas' => count($res['filas'])]);

        $bytes = $this->export->toExcel($res['filas'], $res['totales']);

        return response()->streamDownload(
            fn () => print $bytes,
            'Pedidos_gemelos_ICG_'.now()->format('Y-m-d').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /** @return array{hoja: ?string, etapa: ?string, vendedor: ?string, cliente: ?string, desde: ?string, hasta: ?string, q: ?string, solo_advertencias: bool} */
    private function filtros(Request $request): array
    {
        $v = $request->validate([
            'hoja'              => ['nullable', 'string', 'max:40'],
            'etapa'             => ['nullable', 'string', 'in:asignado,picking_pendiente,en_picking,packing,por_despachar,despachado,entregado'],
            'vendedor'          => ['nullable', 'string', 'max:120'],
            'cliente'           => ['nullable', 'string', 'max:120'],
            'desde'             => ['nullable', 'date_format:Y-m-d'],
            'hasta'             => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'q'                 => ['nullable', 'string', 'max:60'],
            'solo_advertencias' => ['nullable', 'boolean'],
        ]);

        return [
            'hoja'              => $v['hoja'] ?? null,
            'etapa'             => $v['etapa'] ?? null,
            'vendedor'          => $v['vendedor'] ?? null,
            'cliente'           => $v['cliente'] ?? null,
            'desde'             => $v['desde'] ?? null,
            'hasta'             => $v['hasta'] ?? null,
            'q'                 => $v['q'] ?? null,
            'solo_advertencias' => (bool) ($v['solo_advertencias'] ?? false),
        ];
    }
}
