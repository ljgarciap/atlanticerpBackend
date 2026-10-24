<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Http\Controllers;

use App\Modules\Legacy\Models\Pago;
use App\Shared\Http\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SCRUM-827 (épica SCRUM-749 — Migración ICG→AtlanticERP) — vista de solo lectura de `legacy.pagos`
 * (TESORERIA), catálogo standalone sin cliente (ver docblock de App\Modules\Legacy\Models\Pago —
 * sin Loader-B ni Matcher, no se puede filtrar por Subcliente como SubClientHistoryController).
 *
 * Audiencia solo equipo técnico (respuesta de Luis 2026-09-02, comentario del ticket) — gate
 * `role:superadmin` exclusivo, más restrictivo que el dashboard de SCRUM-816
 * (`role:superadmin,management`).
 *
 * Los códigos crudos de ICG (`estado`, `icg_origen`, `icg_tipodocumento`) se devuelven tal cual,
 * SIN traducir — el significado real solo está confirmado como hipótesis basada en patrones de
 * datos (ver comentario de Luis en el ticket, 2026-09-02), pendiente de validar contra
 * documentación oficial de ICG. El frontend muestra las hipótesis como nota/tooltip, nunca como
 * label definitivo.
 */
final class LegacyPagosController
{
    private const ESTADOS = ['P', 'S', 'C', 'X'];
    private const ORIGENES = ['C', 'E', 'P'];
    private const TIPOS_DOCUMENTO = ['A', 'E', 'F', 'P'];

    public function index(Request $request): JsonResponse
    {
        $params = Pagination::params($request, ['fecha_documento', 'importe'], 'fecha_documento', 'desc');

        $query = Pago::query();

        $estado = $request->query('estado');
        if (is_string($estado) && in_array($estado, self::ESTADOS, true)) {
            $query->where('estado', $estado);
        }

        $origen = $request->query('icg_origen');
        if (is_string($origen) && in_array($origen, self::ORIGENES, true)) {
            $query->where('icg_origen', $origen);
        }

        $tipoDocumento = $request->query('icg_tipodocumento');
        if (is_string($tipoDocumento) && in_array($tipoDocumento, self::TIPOS_DOCUMENTO, true)) {
            $query->where('icg_tipodocumento', $tipoDocumento);
        }

        $fechaDesde = $request->query('fecha_desde');
        if (is_string($fechaDesde) && $fechaDesde !== '') {
            $query->whereDate('fecha_documento', '>=', $fechaDesde);
        }

        $fechaHasta = $request->query('fecha_hasta');
        if (is_string($fechaHasta) && $fechaHasta !== '') {
            $query->whereDate('fecha_documento', '<=', $fechaHasta);
        }

        $paginator = $query
            ->orderBy($params['sort_by'], $params['sort_dir'])
            ->paginate($params['per_page'], ['*'], 'page', $params['page']);

        $rows = $paginator->getCollection()->map(fn (Pago $pago): array => [
            'id'                => $pago->id,
            'icg_origen'        => $pago->icg_origen,
            'icg_tipodocumento' => $pago->icg_tipodocumento,
            'icg_serie'         => $pago->icg_serie,
            'icg_numero'        => $pago->icg_numero,
            'icg_n'             => $pago->icg_n,
            'icg_posicion'      => $pago->icg_posicion,
            'fecha_documento'   => $pago->fecha_documento?->toDateString(),
            'fecha_vencimiento' => $pago->fecha_vencimiento?->toDateString(),
            'importe'           => $pago->importe,
            'icg_codformapago'  => $pago->icg_codformapago,
            'estado'            => $pago->estado,
            'fecha_saldado'     => $pago->fecha_saldado?->toDateString(),
        ])->values();

        return response()->json([
            'data' => $rows,
            ...Pagination::meta($paginator),
        ]);
    }
}
