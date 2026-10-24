<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Http\Controllers;

use App\Modules\Legacy\Models\Albaran;
use App\Modules\Legacy\Models\AlbaranCompra;
use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\Cotizacion;
use App\Modules\Legacy\Models\Factura;
use App\Modules\Legacy\Models\Pedido;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\ProductoCosto;
use App\Modules\Legacy\Models\ProductoPrecio;
use App\Modules\Legacy\Models\Proveedor;
use App\Modules\Legacy\Services\LegacyDocumentPresenter;
use App\Modules\Legacy\Support\PriceTypeMap;
use App\Shared\Http\Pagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * "Vista ICG" (épica SCRUM-749, agregado 2026-09-03 a pedido de Luis) — pantalla propia bajo
 * Migración, con una pestaña por entidad migrada, sin partir de un Subcliente puntual (a
 * diferencia de `SubClientHistoryController`, scoped por diseño). Reusa
 * `LegacyDocumentPresenter` para el documento de Cotizaciones/Pedidos/Albaranes/Facturas — mismo
 * formato exacto que ya consume `SubClientIcgHistoryModal.tsx`, para que el frontend pueda
 * compartir el mismo componente de ficha.
 *
 * Gate `role:superadmin` exclusivo (mismo criterio que Pagos ICG, SCRUM-827 — audiencia técnica,
 * datos crudos de ICG sin traducir). Pagos queda fuera de este controller a propósito — sigue
 * siendo su propio endpoint (`LegacyPagosController`), el frontend solo reusa esa misma API desde
 * la pestaña "Pagos" de esta pantalla nueva, sin duplicar backend.
 *
 * SCRUM-840 (2026-09-04) agrega `albaranes_compra` — mismo formato cabecera+líneas que las otras 4
 * entidades de `DOCUMENT_ENTITIES`, pero vinculado por Proveedor en vez de Cliente (ver
 * `LegacyDocumentPresenter::albaranCompra()`/`proveedorHeader()`). Por eso vive en su propia
 * constante en vez de sumarse a `DOCUMENT_ENTITIES` — comparte el mismo *shape* de documento, no
 * el mismo criterio de búsqueda/relación.
 */
final class LegacyVistaIcgController
{
    private const DOCUMENT_ENTITIES = ['cotizaciones', 'pedidos', 'albaranes', 'facturas'];
    private const ALL_ENTITIES = [
        ...self::DOCUMENT_ENTITIES, 'albaranes_compra', 'clientes', 'proveedores', 'productos',
    ];

    public function index(Request $request, string $entidad): JsonResponse
    {
        if (! in_array($entidad, self::ALL_ENTITIES, true)) {
            abort(404);
        }

        return match ($entidad) {
            'cotizaciones'      => $this->indexCotizaciones($request),
            'pedidos'           => $this->indexPedidos($request),
            'albaranes'         => $this->indexAlbaranes($request),
            'facturas'          => $this->indexFacturas($request),
            'albaranes_compra'  => $this->indexAlbaranesCompra($request),
            'clientes'          => $this->indexClientes($request),
            'proveedores'       => $this->indexProveedores($request),
            'productos'         => $this->indexProductos($request),
        };
    }

    public function show(string $entidad, int $id): JsonResponse
    {
        if (! in_array($entidad, self::ALL_ENTITIES, true)) {
            abort(404);
        }

        $document = match ($entidad) {
            'cotizaciones'      => $this->showCotizacion($id),
            'pedidos'           => $this->showPedido($id),
            'albaranes'         => $this->showAlbaran($id),
            'facturas'          => $this->showFactura($id),
            'albaranes_compra'  => $this->showAlbaranCompra($id),
            'clientes'          => $this->showCliente($id),
            'proveedores'       => $this->showProveedor($id),
            'productos'         => $this->showProducto($id),
        };

        if ($document === null) {
            abort(404);
        }

        return response()->json($document);
    }

    // ---- Documentos (Cotizaciones/Pedidos/Albaranes/Facturas) ------------------------------

    private function indexCotizaciones(Request $request): JsonResponse
    {
        $params = Pagination::params($request, ['fecha', 'total_neto'], 'fecha', 'desc');
        $query  = Cotizacion::query()->with('cliente');
        $this->applyFechaFiltro($query, $request, 'fecha');
        $this->applyClienteQ($query, $request);

        $paginator = $query->orderBy($params['sort_by'], $params['sort_dir'])
            ->paginate($params['per_page'], ['*'], 'page', $params['page']);

        return $this->documentListResponse($paginator, fn (Cotizacion $c): array => [
            'id'      => $c->id,
            'numero'  => "{$c->icg_numserie}-{$c->icg_numpresupuesto}",
            'cliente' => $this->clienteNombre($c->cliente),
            'fecha'   => $c->fecha?->toDateString(),
            'total'   => $c->total_neto,
            'estado'  => $c->idestado,
        ]);
    }

    private function indexPedidos(Request $request): JsonResponse
    {
        $params = Pagination::params($request, ['fechapedido', 'totneto'], 'fechapedido', 'desc');
        $query  = Pedido::query()->with('cliente');
        $this->applyFechaFiltro($query, $request, 'fechapedido');
        $this->applyClienteQ($query, $request);

        $paginator = $query->orderBy($params['sort_by'], $params['sort_dir'])
            ->paginate($params['per_page'], ['*'], 'page', $params['page']);

        return $this->documentListResponse($paginator, fn (Pedido $p): array => [
            'id'      => $p->id,
            'numero'  => "{$p->icg_numserie}-{$p->icg_numpedido}",
            'cliente' => $this->clienteNombre($p->cliente),
            'fecha'   => $p->fechapedido?->toDateString(),
            'total'   => $p->totneto,
            'estado'  => $p->idestado,
        ]);
    }

    private function indexAlbaranes(Request $request): JsonResponse
    {
        $params = Pagination::params($request, ['fecha', 'totalneto'], 'fecha', 'desc');
        $query  = Albaran::query()->with('cliente');
        $this->applyFechaFiltro($query, $request, 'fecha');
        $this->applyClienteQ($query, $request);

        $paginator = $query->orderBy($params['sort_by'], $params['sort_dir'])
            ->paginate($params['per_page'], ['*'], 'page', $params['page']);

        return $this->documentListResponse($paginator, fn (Albaran $a): array => [
            'id'      => $a->id,
            'numero'  => "{$a->icg_numserie}-{$a->icg_numalbaran}",
            'cliente' => $this->clienteNombre($a->cliente),
            'fecha'   => $a->fecha?->toDateString(),
            'total'   => $a->totalneto,
            'estado'  => $a->facturado ? 'facturado' : 'pendiente',
        ]);
    }

    private function indexFacturas(Request $request): JsonResponse
    {
        $params = Pagination::params($request, ['fecha', 'total_neto'], 'fecha', 'desc');
        $query  = Factura::query()->with('cliente');
        $this->applyFechaFiltro($query, $request, 'fecha');
        $this->applyClienteQ($query, $request);

        $paginator = $query->orderBy($params['sort_by'], $params['sort_dir'])
            ->paginate($params['per_page'], ['*'], 'page', $params['page']);

        return $this->documentListResponse($paginator, fn (Factura $f): array => [
            'id'      => $f->id,
            'numero'  => "{$f->icg_numserie}-{$f->icg_numfactura}",
            'cliente' => $this->clienteNombre($f->cliente),
            'fecha'   => $f->fecha?->toDateString(),
            'total'   => $f->total_neto,
            'estado'  => null,
        ]);
    }

    // ---- Albaranes de Compra (SCRUM-840, vinculado por Proveedor) --------------------------

    private function indexAlbaranesCompra(Request $request): JsonResponse
    {
        $params = Pagination::params($request, ['fecha', 'totalneto'], 'fecha', 'desc');
        $query  = AlbaranCompra::query()->with('proveedor');
        $this->applyFechaFiltro($query, $request, 'fecha');
        $this->applyProveedorQ($query, $request);

        $paginator = $query->orderBy($params['sort_by'], $params['sort_dir'])
            ->paginate($params['per_page'], ['*'], 'page', $params['page']);

        return $this->documentListResponse($paginator, fn (AlbaranCompra $a): array => [
            'id'        => $a->id,
            'numero'    => "{$a->icg_numserie}-{$a->icg_numalbaran}",
            'proveedor' => $this->proveedorNombre($a->proveedor),
            'fecha'     => $a->fecha?->toDateString(),
            'total'     => $a->totalneto,
            'estado'    => $a->facturado ? 'facturado' : 'pendiente',
        ]);
    }

    /** @return array<string, mixed>|null */
    private function showAlbaranCompra(int $id): ?array
    {
        $a = AlbaranCompra::query()->with(['proveedor', 'items'])->find($id);

        return $a === null ? null : LegacyDocumentPresenter::albaranCompra($a);
    }

    private function proveedorNombre(?Proveedor $proveedor): ?string
    {
        return $proveedor === null ? null : ($proveedor->nombre_comercial ?? $proveedor->nombre);
    }

    /**
     * Busca por nombre/CIF del proveedor vinculado, o por el número de albarán si `q` es numérico
     * — mismo criterio que `applyClienteQ`, adaptado a Proveedor (única entidad de este controller
     * que no se vincula por Cliente).
     *
     * @param Builder<AlbaranCompra> $query
     */
    private function applyProveedorQ(Builder $query, Request $request): void
    {
        $q = $request->query('q');
        if (! is_string($q) || $q === '') {
            return;
        }

        $query->where(function (Builder $sub) use ($q): void {
            $sub->whereHas('proveedor', function (Builder $p) use ($q): void {
                $p->where('nombre', 'ilike', "%{$q}%")
                    ->orWhere('nombre_comercial', 'ilike', "%{$q}%")
                    ->orWhere('cif', 'ilike', "%{$q}%");
            });
            if (ctype_digit($q)) {
                $sub->orWhere('icg_numalbaran', (int) $q);
            }
        });
    }

    /** @return array<string, mixed>|null */
    private function showCotizacion(int $id): ?array
    {
        $c = Cotizacion::query()->with(['cliente', 'items'])->find($id);

        return $c === null ? null : LegacyDocumentPresenter::cotizacion($c);
    }

    /** @return array<string, mixed>|null */
    private function showPedido(int $id): ?array
    {
        $p = Pedido::query()->with(['cliente', 'items'])->find($id);

        return $p === null ? null : LegacyDocumentPresenter::pedido($p);
    }

    /** @return array<string, mixed>|null */
    private function showAlbaran(int $id): ?array
    {
        $a = Albaran::query()->with(['cliente', 'items'])->find($id);

        return $a === null ? null : LegacyDocumentPresenter::albaran($a);
    }

    /** @return array<string, mixed>|null */
    private function showFactura(int $id): ?array
    {
        $f = Factura::query()->with('cliente')->find($id);

        return $f === null ? null : LegacyDocumentPresenter::factura($f);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     * @param LengthAwarePaginator<int, TModel>       $paginator
     * @param \Closure(TModel): array<string, mixed>  $mapper
     */
    private function documentListResponse(LengthAwarePaginator $paginator, \Closure $mapper): JsonResponse
    {
        return response()->json([
            'data' => $paginator->getCollection()->map($mapper)->values(),
            ...Pagination::meta($paginator),
        ]);
    }

    private function clienteNombre(?Cliente $cliente): ?string
    {
        return $cliente === null ? null : ($cliente->nombre_comercial ?? $cliente->razon_social);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     * @param Builder<TModel> $query
     */
    private function applyFechaFiltro(Builder $query, Request $request, string $column): void
    {
        $desde = $request->query('fecha_desde');
        if (is_string($desde) && $desde !== '') {
            $query->whereDate($column, '>=', $desde);
        }

        $hasta = $request->query('fecha_hasta');
        if (is_string($hasta) && $hasta !== '') {
            $query->whereDate($column, '<=', $hasta);
        }
    }

    /**
     * Busca por nombre/CIF del cliente vinculado, o por el número de folio si `q` es numérico.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     * @param Builder<TModel> $query
     */
    private function applyClienteQ(Builder $query, Request $request): void
    {
        $q = $request->query('q');
        if (! is_string($q) || $q === '') {
            return;
        }

        $numeroColumn = match (true) {
            $query->getModel() instanceof Cotizacion => 'icg_numpresupuesto',
            $query->getModel() instanceof Pedido      => 'icg_numpedido',
            $query->getModel() instanceof Albaran     => 'icg_numalbaran',
            $query->getModel() instanceof Factura     => 'icg_numfactura',
            default                                   => null,
        };

        $query->where(function (Builder $sub) use ($q, $numeroColumn): void {
            $sub->whereHas('cliente', function (Builder $c) use ($q): void {
                $c->where('nombre_comercial', 'ilike', "%{$q}%")
                    ->orWhere('razon_social', 'ilike', "%{$q}%")
                    ->orWhere('cif', 'ilike', "%{$q}%");
            });
            if ($numeroColumn !== null && ctype_digit($q)) {
                $sub->orWhere($numeroColumn, (int) $q);
            }
        });
    }

    // ---- Clientes ---------------------------------------------------------------------------

    private function indexClientes(Request $request): JsonResponse
    {
        $params = Pagination::params($request, ['razon_social', 'created_at'], 'razon_social', 'asc');
        $query  = Cliente::query();

        $q = $request->query('q');
        if (is_string($q) && $q !== '') {
            $query->where(function (Builder $sub) use ($q): void {
                $sub->where('nombre_comercial', 'ilike', "%{$q}%")
                    ->orWhere('razon_social', 'ilike', "%{$q}%")
                    ->orWhere('cif', 'ilike', "%{$q}%");
            });
        }

        $paginator = $query->orderBy($params['sort_by'], $params['sort_dir'])
            ->paginate($params['per_page'], ['*'], 'page', $params['page']);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Cliente $c): array => [
                'id'               => $c->id,
                'nombre_comercial' => $c->nombre_comercial,
                'razon_social'     => $c->razon_social,
                'cif'              => $c->cif,
                'match_status'     => $c->match_status,
                'bloqueado'        => $c->bloqueado,
            ])->values(),
            ...Pagination::meta($paginator),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function showCliente(int $id): ?array
    {
        $c = Cliente::query()->with('contactos')->find($id);
        if ($c === null) {
            return null;
        }

        return [
            'entidad' => 'clientes',
            'header'  => [
                'icg_codcliente'        => $c->icg_codcliente,
                'nombre_comercial'      => $c->nombre_comercial,
                'razon_social'          => $c->razon_social,
                'cif'                   => $c->cif,
                'direccion'             => $c->direccion,
                'poblacion'             => $c->poblacion,
                'provincia'             => $c->provincia,
                'pais'                  => $c->pais,
                'telefono1'             => $c->telefono1,
                'telefono2'             => $c->telefono2,
                'mobil'                 => $c->mobil,
                'email'                 => $c->email,
                'icg_codvendedor'       => $c->icg_codvendedor,
                'match_status'          => $c->match_status,
                'sub_client_id'         => $c->sub_client_id,
                'bloqueado'             => $c->bloqueado,
                'fecha_modificado_icg'  => $c->fecha_modificado_icg?->toDateString(),
            ],
            'contactos' => $c->contactos->map(fn ($contacto): array => [
                'cargo'    => $contacto->cargo,
                'nombre'   => $contacto->nombre,
                'telefono' => $contacto->telefono,
                'mobil'    => $contacto->mobil,
                'email'    => $contacto->email,
            ])->values(),
        ];
    }

    // ---- Proveedores ------------------------------------------------------------------------

    private function indexProveedores(Request $request): JsonResponse
    {
        $params = Pagination::params($request, ['nombre', 'created_at'], 'nombre', 'asc');
        $query  = Proveedor::query();

        $q = $request->query('q');
        if (is_string($q) && $q !== '') {
            $query->where(function (Builder $sub) use ($q): void {
                $sub->where('nombre', 'ilike', "%{$q}%")
                    ->orWhere('nombre_comercial', 'ilike', "%{$q}%")
                    ->orWhere('cif', 'ilike', "%{$q}%");
            });
        }

        $paginator = $query->orderBy($params['sort_by'], $params['sort_dir'])
            ->paginate($params['per_page'], ['*'], 'page', $params['page']);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Proveedor $p): array => [
                'id'               => $p->id,
                'nombre'           => $p->nombre,
                'nombre_comercial' => $p->nombre_comercial,
                'cif'              => $p->cif,
                'match_status'     => $p->match_status,
            ])->values(),
            ...Pagination::meta($paginator),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function showProveedor(int $id): ?array
    {
        $p = Proveedor::query()->find($id);
        if ($p === null) {
            return null;
        }

        return [
            'entidad' => 'proveedores',
            'header'  => [
                'icg_codproveedor' => $p->icg_codproveedor,
                'nombre'           => $p->nombre,
                'nombre_comercial' => $p->nombre_comercial,
                'cif'              => $p->cif,
                'direccion'        => $p->direccion,
                'telefono1'        => $p->telefono1,
                'email'            => $p->email,
                'match_status'     => $p->match_status,
                'provider_id'      => $p->provider_id,
            ],
        ];
    }

    // ---- Productos ----------------------------------------------------------------------------

    private function indexProductos(Request $request): JsonResponse
    {
        $params = Pagination::params($request, ['descripcion', 'created_at'], 'descripcion', 'asc');
        $query  = Producto::query();

        $q = $request->query('q');
        if (is_string($q) && $q !== '') {
            $query->where(function (Builder $sub) use ($q): void {
                $sub->where('descripcion', 'ilike', "%{$q}%")
                    ->orWhere('marca_nombre', 'ilike', "%{$q}%")
                    ->orWhere('familia_nombre', 'ilike', "%{$q}%");
                if (ctype_digit($q)) {
                    $sub->orWhere('icg_codarticulo', (int) $q);
                }
            });
        }

        $paginator = $query->orderBy($params['sort_by'], $params['sort_dir'])
            ->paginate($params['per_page'], ['*'], 'page', $params['page']);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Producto $p): array => [
                'id'                 => $p->id,
                'icg_codarticulo'    => $p->icg_codarticulo,
                'descripcion'        => $p->descripcion,
                'marca_nombre'       => $p->marca_nombre,
                'familia_nombre'     => $p->familia_nombre,
                'catalog_product_id' => $p->catalog_product_id,
            ])->values(),
            ...Pagination::meta($paginator),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function showProducto(int $id): ?array
    {
        $p = Producto::query()->find($id);
        if ($p === null) {
            return null;
        }

        $precios = ProductoPrecio::query()->where('icg_codarticulo', $p->icg_codarticulo)->get();
        $costos  = ProductoCosto::query()->where('icg_codarticulo', $p->icg_codarticulo)->get();

        return [
            'entidad' => 'productos',
            'header'  => [
                'icg_codarticulo'    => $p->icg_codarticulo,
                'descripcion'        => $p->descripcion,
                'marca_nombre'       => $p->marca_nombre,
                'familia_nombre'     => $p->familia_nombre,
                'ref_proveedor'      => $p->ref_proveedor,
                'descatalogado'      => $p->descatalogado,
                'catalog_product_id' => $p->catalog_product_id,
            ],
            'precios' => $precios->map(fn (ProductoPrecio $precio): array => [
                'idtarifav'    => $precio->idtarifav,
                'price_type'   => PriceTypeMap::forIdTarifav($precio->idtarifav),
                'precio_neto'  => $precio->precio_neto,
                'precio_bruto' => $precio->precio_bruto,
                'descuento'    => $precio->descuento,
            ])->values(),
            'costos' => $costos->map(fn (ProductoCosto $costo): array => [
                'codproveedor' => $costo->codproveedor,
                'costo_neto'   => $costo->costo_neto,
            ])->values(),
        ];
    }
}
