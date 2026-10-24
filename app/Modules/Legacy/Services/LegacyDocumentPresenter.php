<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Models\Albaran;
use App\Modules\Legacy\Models\AlbaranCompra;
use App\Modules\Legacy\Models\AlbaranCompraItem;
use App\Modules\Legacy\Models\AlbaranItem;
use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\Cotizacion;
use App\Modules\Legacy\Models\CotizacionItem;
use App\Modules\Legacy\Models\Factura;
use App\Modules\Legacy\Models\Pedido;
use App\Modules\Legacy\Models\PedidoItem;
use App\Modules\Legacy\Models\Proveedor;

/**
 * Vista "gemela" tipo documento (cabecera + líneas) — extraído de
 * `SubClientHistoryController` (SCRUM-762/SCRUM-749, 2026-09-03) para reusarse también desde la
 * vista global "Vista ICG" (`LegacyVistaIcgController`), que arma el mismo documento pero sin
 * scoping por Subcliente. Comportamiento de mapeo idéntico al original — ningún cambio de forma,
 * solo movido a un lugar compartido.
 */
final class LegacyDocumentPresenter
{
    /** @return array<string, mixed> */
    public static function cotizacion(Cotizacion $c): array
    {
        return [
            'entidad' => 'cotizaciones',
            'header'  => [
                'folio'           => "{$c->icg_numserie}-{$c->icg_numpresupuesto}-{$c->icg_n}-{$c->icg_version}",
                'cliente'         => self::clienteHeader($c->cliente),
                'icg_codcliente'  => $c->icg_codcliente,
                'icg_codvendedor' => $c->icg_codvendedor,
                'fecha'           => $c->fecha?->toDateString(),
                'estado'          => $c->idestado,
                'total_neto'      => $c->total_neto,
                'total_bruto'     => $c->total_bruto,
                'supresupuesto'   => $c->supresupuesto,
                // SCRUM-842 — campos libres (PRESUPUESTOSCAMPOSLIBRES)
                'proyecto'        => $c->proyecto,
                'promocion'       => $c->promocion,
                'orphan'          => $c->orphan,
            ],
            'items' => $c->items->map(fn (CotizacionItem $i): array => [
                'codigo_articulo' => $i->icg_codarticulo,
                'referencia'      => $i->referencia,
                'descripcion'     => $i->descripcion,
                'cantidad'        => $i->cantidad,
                'precio'          => $i->precio,
                'descuento'       => $i->descuento,
                'total'           => $i->total,
                'costo'           => $i->costo,
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    public static function pedido(Pedido $p): array
    {
        return [
            'entidad' => 'pedidos',
            'header'  => [
                'folio'               => "{$p->icg_numserie}-{$p->icg_numpedido}-{$p->icg_n}",
                'cliente'             => self::clienteHeader($p->cliente),
                'icg_codcliente'      => $p->icg_codcliente,
                'icg_codvendedor'     => $p->icg_codvendedor,
                'fecha'               => $p->fechapedido?->toDateString(),
                'fecha_entrega'       => $p->fechaentrega?->toDateString(),
                'estado'              => $p->idestado,
                'total_neto'          => $p->totneto,
                'albaran_relacionado' => ($p->icg_seriealbaran !== null && $p->icg_numeroalbaran !== null)
                    ? "{$p->icg_seriealbaran}-{$p->icg_numeroalbaran}"
                    : null,
                // SCRUM-842 — campo libre (PEDVENTACAMPOSLIBRES)
                'comision' => $p->comision,
                'orphan'   => $p->orphan,
            ],
            'items' => $p->items->map(fn (PedidoItem $i): array => [
                'codigo_articulo' => $i->icg_codarticulo,
                'referencia'      => $i->referencia,
                'descripcion'     => $i->descripcion,
                'cantidad'        => $i->cantidad,
                'precio'          => $i->precio,
                'total'           => $i->total,
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    public static function albaran(Albaran $a): array
    {
        return [
            'entidad' => 'albaranes',
            'header'  => [
                'folio'               => "{$a->icg_numserie}-{$a->icg_numalbaran}-{$a->icg_n}",
                'cliente'             => self::clienteHeader($a->cliente),
                'icg_codcliente'      => $a->icg_codcliente,
                'icg_codvendedor'     => $a->icg_codvendedor,
                'fecha'               => $a->fecha?->toDateString(),
                'total_neto'          => $a->totalneto,
                'facturado'           => $a->facturado,
                'factura_relacionada' => ($a->icg_numseriefac !== null && $a->icg_numfac !== null)
                    ? "{$a->icg_numseriefac}-{$a->icg_numfac}"
                    : null,
                // SCRUM-842 — campo libre (ALBVENTACAMPOSLIBRES)
                'observaciones' => $a->observaciones,
                'orphan'        => $a->orphan,
            ],
            'items' => $a->items->map(fn (AlbaranItem $i): array => [
                'codigo_articulo' => $i->icg_codarticulo,
                'referencia'      => $i->referencia,
                'descripcion'     => $i->descripcion,
                'cantidad'        => $i->cantidad,
                'precio'          => $i->precio,
                'total'           => $i->total,
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    public static function factura(Factura $f): array
    {
        return [
            'entidad' => 'facturas',
            'header'  => [
                'folio'           => "{$f->icg_numserie}-{$f->icg_numfactura}-{$f->icg_n}",
                'cliente'         => self::clienteHeader($f->cliente),
                'icg_codcliente'  => $f->icg_codcliente,
                'icg_codvendedor' => $f->icg_codvendedor,
                'fecha'           => $f->fecha?->toDateString(),
                'total_neto'      => $f->total_neto,
                'total_impuestos' => $f->total_impuestos,
                'total_bruto'     => $f->total_bruto,
                'sufactura'       => $f->sufactura,
                // SCRUM-841 — Facturación Electrónica (FACTURASVENTACAMPOSLIBRES)
                'fe_qr'                => $f->fe_qr,
                'fe_auth_number'       => $f->fe_auth_number,
                'fe_serie'             => $f->fe_serie,
                'fe_numero'            => $f->fe_numero,
                'fe_fecha_emision'     => $f->fe_fecha_emision,
                'fe_fecha_recepcion'   => $f->fe_fecha_recepcion,
                'fe_error'             => $f->fe_error,
                'fe_estado'            => $f->fe_estado,
                'orphan'          => $f->orphan,
            ],
            // FACTURASVENTA nunca tuvo tabla de líneas en el origen — nunca `items: []`, siempre
            // `null`, para que el frontend distinga "sin líneas por diseño" de "cero líneas reales".
            'items' => null,
        ];
    }

    /**
     * SCRUM-840 — Albaranes de Compra, vinculado por Proveedor (no por Cliente, a diferencia de
     * las otras 4 entidades de este presenter) — ver `proveedorHeader()`.
     *
     * @return array<string, mixed>
     */
    public static function albaranCompra(AlbaranCompra $a): array
    {
        return [
            'entidad' => 'albaranes_compra',
            'header'  => [
                'folio'               => "{$a->icg_numserie}-{$a->icg_numalbaran}-{$a->icg_n}",
                'proveedor'           => self::proveedorHeader($a->proveedor),
                'icg_codproveedor'    => $a->icg_codproveedor,
                'fecha'               => $a->fecha?->toDateString(),
                'total_neto'          => $a->totalneto,
                'facturado'           => $a->facturado,
                'factura_relacionada' => ($a->icg_numseriefac !== null && $a->icg_numfac !== null)
                    ? "{$a->icg_numseriefac}-{$a->icg_numfac}"
                    : null,
                'orphan' => $a->orphan,
            ],
            'items' => $a->items->map(fn (AlbaranCompraItem $i): array => [
                'codigo_articulo' => $i->icg_codarticulo,
                'referencia'      => $i->referencia,
                'descripcion'     => $i->descripcion,
                'cantidad'        => $i->cantidad,
                'precio'          => $i->precio,
                'total'           => $i->total,
            ])->values(),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function clienteHeader(?Cliente $cliente): ?array
    {
        if ($cliente === null) {
            return null;
        }

        return [
            'id'               => $cliente->id,
            'nombre_comercial' => $cliente->nombre_comercial,
            'razon_social'     => $cliente->razon_social,
            'cif'              => $cliente->cif,
        ];
    }

    /** @return array<string, mixed>|null */
    public static function proveedorHeader(?Proveedor $proveedor): ?array
    {
        if ($proveedor === null) {
            return null;
        }

        return [
            'id'               => $proveedor->id,
            'nombre_comercial' => $proveedor->nombre_comercial,
            'nombre'           => $proveedor->nombre,
            'cif'              => $proveedor->cif,
        ];
    }
}
