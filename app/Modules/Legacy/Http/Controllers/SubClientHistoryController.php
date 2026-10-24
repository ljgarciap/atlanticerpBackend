<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Http\Controllers;

use App\Modules\Legacy\Models\Albaran;
use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\Cotizacion;
use App\Modules\Legacy\Models\Factura;
use App\Modules\Legacy\Models\Pedido;
use App\Modules\Legacy\Services\LegacyDocumentPresenter;
use App\Shared\Http\Pagination;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * SCRUM-762 (épica SCRUM-749 — Migración ICG→AtlanticERP) — pestaña "Historial ICG", solo lectura,
 * en el detalle de Subcliente (Illumination/migracion-icg-diseno.md §6, Día 8 del plan).
 *
 * Alcance: Cotizaciones/Pedidos/Albaranes/Facturas — NO Pagos. `legacy.pagos` (TESORERIA) no
 * referencia cliente en absoluto (ver docblock de App\Modules\Legacy\Models\Pago: "sin Loader-B
 * ni Matcher... TESORERIA no referencia cliente en absoluto en el subconjunto documentado"), así
 * que no hay forma de filtrarlo por Subcliente ni siquiera indirectamente vía Albarán/Factura
 * (esos links a Pago tampoco existen) — corrección de alcance real encontrada al implementar,
 * distinta del borrador original del ticket que asumía las 5 entidades transaccionales.
 *
 * Las 4 entidades cubiertas se relacionan con `legacy.clientes` (nunca directo con
 * `ventas_diseno.sub_clients`) vía `legacy_cliente_id` — y `legacy.clientes.sub_client_id` no
 * tiene constraint UNIQUE, así que un Subcliente puede tener 0, 1 o más `Cliente` (icg_codcliente)
 * vinculados; se agregan todos, no solo el primero.
 */
final class SubClientHistoryController
{
    private const ENTITIES = ['cotizaciones', 'pedidos', 'albaranes', 'facturas'];

    public function index(Request $request, int $subClientId): JsonResponse
    {
        $entidad = (string) $request->query('entidad', 'cotizaciones');
        if (! in_array($entidad, self::ENTITIES, true)) {
            $entidad = 'cotizaciones';
        }

        // Subcliente sin match ICG (huérfano/ambiguo, o legítimamente sin historial) — estado
        // vacío, nunca error (AC del ticket).
        $clienteIds = Cliente::query()->where('sub_client_id', $subClientId)->pluck('id');

        return match ($entidad) {
            'cotizaciones' => $this->paginate(
                $request,
                Cotizacion::class,
                $clienteIds,
                sortableColumns: ['fecha', 'total_neto'],
                defaultSort:     'fecha',
                mapper:          fn (Cotizacion $c): array => [
                    'id'     => $c->id,
                    'numero' => "{$c->icg_numserie}-{$c->icg_numpresupuesto}",
                    'fecha'  => $c->fecha?->toDateString(),
                    'total'  => $c->total_neto,
                    'estado' => $c->idestado,
                ],
            ),
            'pedidos' => $this->paginate(
                $request,
                Pedido::class,
                $clienteIds,
                sortableColumns: ['fechapedido', 'totneto'],
                defaultSort:     'fechapedido',
                mapper:          fn (Pedido $p): array => [
                    'id'     => $p->id,
                    'numero' => "{$p->icg_numserie}-{$p->icg_numpedido}",
                    'fecha'  => $p->fechapedido?->toDateString(),
                    'total'  => $p->totneto,
                    'estado' => $p->idestado,
                ],
            ),
            'albaranes' => $this->paginate(
                $request,
                Albaran::class,
                $clienteIds,
                sortableColumns: ['fecha', 'totalneto'],
                defaultSort:     'fecha',
                mapper:          fn (Albaran $a): array => [
                    'id'     => $a->id,
                    'numero' => "{$a->icg_numserie}-{$a->icg_numalbaran}",
                    'fecha'  => $a->fecha?->toDateString(),
                    'total'  => $a->totalneto,
                    'estado' => $a->facturado ? 'facturado' : 'pendiente',
                ],
            ),
            'facturas' => $this->paginate(
                $request,
                Factura::class,
                $clienteIds,
                sortableColumns: ['fecha', 'total_neto'],
                defaultSort:     'fecha',
                mapper:          fn (Factura $f): array => [
                    'id'     => $f->id,
                    'numero' => "{$f->icg_numserie}-{$f->icg_numfactura}",
                    'fecha'  => $f->fecha?->toDateString(),
                    'total'  => $f->total_neto,
                    'estado' => null,
                ],
            ),
        };
    }

    /**
     * Vista "gemela" tipo documento (cabecera + líneas) de un registro puntual — a pedido de Luis
     * 2026-09-02, para que el usuario vea el documento con una forma parecida a como se veía en
     * ICG (cabecera + tabla de ítems), no solo la fila resumen de `index()`. Sin referencia visual
     * real de ICG disponible — layout estándar de ERP-POS (mismo patrón que ya usan las
     * Cotizaciones/Pedidos nativos de AtlanticERP), decisión explícita de Luis.
     *
     * `id` se busca siempre DENTRO de `$clienteIds` (nunca por `id` a secas) — mismo control de
     * acceso que `index()`: un id real de otro Subcliente devuelve 404, no el documento ajeno.
     */
    public function show(int $subClientId, string $entidad, int $id): JsonResponse
    {
        if (! in_array($entidad, self::ENTITIES, true)) {
            abort(404);
        }

        $clienteIds = Cliente::query()->where('sub_client_id', $subClientId)->pluck('id');

        $document = match ($entidad) {
            'cotizaciones' => $this->showCotizacion($id, $clienteIds),
            'pedidos'      => $this->showPedido($id, $clienteIds),
            'albaranes'    => $this->showAlbaran($id, $clienteIds),
            'facturas'     => $this->showFactura($id, $clienteIds),
        };

        if ($document === null) {
            abort(404);
        }

        return response()->json($document);
    }

    /**
     * @param Collection<int, int> $clienteIds
     * @return array<string, mixed>|null
     */
    private function showCotizacion(int $id, Collection $clienteIds): ?array
    {
        $c = Cotizacion::query()->with(['cliente', 'items'])
            ->whereIn('legacy_cliente_id', $clienteIds)->find($id);

        return $c === null ? null : LegacyDocumentPresenter::cotizacion($c);
    }

    /**
     * @param Collection<int, int> $clienteIds
     * @return array<string, mixed>|null
     */
    private function showPedido(int $id, Collection $clienteIds): ?array
    {
        $p = Pedido::query()->with(['cliente', 'items'])
            ->whereIn('legacy_cliente_id', $clienteIds)->find($id);

        return $p === null ? null : LegacyDocumentPresenter::pedido($p);
    }

    /**
     * @param Collection<int, int> $clienteIds
     * @return array<string, mixed>|null
     */
    private function showAlbaran(int $id, Collection $clienteIds): ?array
    {
        $a = Albaran::query()->with(['cliente', 'items'])
            ->whereIn('legacy_cliente_id', $clienteIds)->find($id);

        return $a === null ? null : LegacyDocumentPresenter::albaran($a);
    }

    /**
     * @param Collection<int, int> $clienteIds
     * @return array<string, mixed>|null
     */
    private function showFactura(int $id, Collection $clienteIds): ?array
    {
        $f = Factura::query()->with('cliente')
            ->whereIn('legacy_cliente_id', $clienteIds)->find($id);

        return $f === null ? null : LegacyDocumentPresenter::factura($f);
    }

    /**
     * @template TModel of Model
     * @param class-string<TModel>   $modelClass
     * @param Collection<int, int>   $clienteIds
     * @param list<string>           $sortableColumns
     * @param \Closure(TModel): array<string, mixed> $mapper
     */
    private function paginate(
        Request $request,
        string $modelClass,
        Collection $clienteIds,
        array $sortableColumns,
        string $defaultSort,
        \Closure $mapper,
    ): JsonResponse {
        $params = Pagination::params($request, $sortableColumns, $defaultSort, 'desc');

        $paginator = $modelClass::query()
            ->whereIn('legacy_cliente_id', $clienteIds)
            ->orderBy($params['sort_by'], $params['sort_dir'])
            ->paginate($params['per_page'], ['*'], 'page', $params['page']);

        /** @var Collection<int, TModel> $rows */
        $rows = $paginator->getCollection();

        return response()->json([
            'data' => $rows->map($mapper)->values(),
            ...Pagination::meta($paginator),
        ]);
    }
}
