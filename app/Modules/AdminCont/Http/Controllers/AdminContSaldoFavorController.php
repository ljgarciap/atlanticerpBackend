<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Modules\AdminCont\Services\SaldoFavorService;
use App\Shared\Http\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** SCRUM-903 — Saldo a Favor de Clientes (4N), pantalla de solo consulta. */
class AdminContSaldoFavorController
{
    public function __construct(private readonly SaldoFavorService $saldoFavor) {}

    /**
     * GET /api/admin-contab/saldo-favor?search=&cliente=&proyecto=&factura=&master_client_id=&sales_project_id=&invoice_id=&estado=&origen=&page=&per_page=
     * SCRUM-947 — paginación estándar sobre `rows`; `indicadores` sigue siempre sobre el total
     * real sin filtrar (§3.1 de SCRUM-903, no se toca). `cliente`/`proyecto`/`factura` (texto,
     * nuevos) reemplazan el filtro client-side que tenía el frontend — con paginación real, un
     * filtro que solo mira la página actual deja de tener sentido.
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->saldoFavor->list([
            'search'           => $request->filled('search') ? $request->string('search')->toString() : null,
            'cliente'          => $request->filled('cliente') ? $request->string('cliente')->toString() : null,
            'proyecto'         => $request->filled('proyecto') ? $request->string('proyecto')->toString() : null,
            'factura'          => $request->filled('factura') ? $request->string('factura')->toString() : null,
            'master_client_id' => $request->filled('master_client_id') ? (int) $request->integer('master_client_id') : null,
            'sales_project_id' => $request->filled('sales_project_id') ? (int) $request->integer('sales_project_id') : null,
            'invoice_id'       => $request->filled('invoice_id') ? (int) $request->integer('invoice_id') : null,
            'estado'           => $request->filled('estado') ? $request->string('estado')->toString() : null,
            'origen'           => $request->filled('origen') ? $request->string('origen')->toString() : null,
        ]);

        $pagination = Pagination::params($request, [], 'fecha', 'desc');
        $paginator  = Pagination::ofArray(array_values($result['rows']), $pagination['per_page'], $pagination['page']);

        return response()->json([
            'indicadores' => $result['indicadores'],
            'rows'        => $paginator->items(),
            'meta'        => Pagination::meta($paginator),
        ]);
    }
}
