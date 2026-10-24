<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Services\Billing\InvoiceBillingPlanner;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\Quote;
use App\Shared\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Vista de validación de los pedidos gemelos de ICG (spec `docs/specs/icg-pedidos-gemelos-vista-validacion.md`, diseño
 * `docs/architecture/icg-pedidos-gemelos-vista-validacion-diseno.md`). SOLO LECTURA: parte de `bodega.orders` con
 * `origin = icg_legacy` (un gemelo no tiene tarjeta de pipeline) y calcula el pendiente con la misma fuente que usa el flujo de
 * facturación (total con ITBMS de su cotización gemela, que lleva solo las líneas pendientes por L1), para que la vista no
 * pueda divergir de lo que luego se factura.
 *
 * Son del orden de 200 filas: se cargan todas las que cumplen el filtro (tope duro), se calculan en PHP y se ordenan/paginan en
 * memoria, de modo que los totales al pie cubren SIEMPRE todo el resultado filtrado y no solo la página.
 */
final class PedidosGemelosReportService
{
    /** Tope duro de filas por consulta (defensa; la carga real son ~192). */
    public const MAX_FILAS = 2000;

    public const ORDENES = ['fecha', 'icg', 'cliente', 'vendedor', 'hoja', 'total_icg', 'pendiente', 'facturado_atlanticerp', 'saldo', 'etapa'];

    public const ADV_SIN_DV       = 'Sin DV del cliente';
    public const ADV_SIN_VINCULAR = 'Cliente sin vincular';

    /**
     * @param array{hoja?: ?string, etapa?: ?string, vendedor?: ?string, cliente?: ?string, desde?: ?string, hasta?: ?string, q?: ?string, solo_advertencias?: bool, orden?: ?string, dir?: ?string} $filtros
     * @return array{filas: list<array<string, mixed>>, totales: array<string, float|int>, resumen: array<string, mixed>}
     */
    public function listar(array $filtros): array
    {
        $filas = $this->filas($filtros);
        if (($filtros['solo_advertencias'] ?? false) === true) {
            $filas = array_values(array_filter($filas, static fn (array $f): bool => $f['advertencias'] !== []));
        }

        $orden = in_array($filtros['orden'] ?? null, self::ORDENES, true) ? (string) $filtros['orden'] : 'fecha';
        $desc  = ($filtros['dir'] ?? 'desc') !== 'asc';
        usort($filas, static function (array $a, array $b) use ($orden, $desc): int {
            $cmp = $a[$orden] <=> $b[$orden];

            return ($desc ? -$cmp : $cmp) ?: ($a['order_id'] <=> $b['order_id']);
        });

        return ['filas' => $filas, 'totales' => $this->totales($filas), 'resumen' => $this->resumen()];
    }

    /**
     * @param array<string, mixed> $f
     * @return list<array<string, mixed>>
     */
    private function filas(array $f): array
    {
        $ordenes = $this->consulta($f)->limit(self::MAX_FILAS)->get();
        if ($ordenes->isEmpty()) {
            return [];
        }

        $facturadoPorPedido = AdminContInvoice::query()
            ->whereIn('family_root_id', $ordenes->pluck('id')->all())
            ->whereNull('anulada_at')
            ->selectRaw('family_root_id, SUM(monto) AS total')
            ->groupBy('family_root_id')
            ->pluck('total', 'family_root_id');

        $cotizaciones = Quote::withIcgLegacy()->with('parts.items')->whereIn('id', $ordenes->pluck('quote_id')->filter()->all())->get()->keyBy('id');

        $filas = [];
        foreach ($ordenes as $o) {
            /** @var Order $o */
            $quote     = $cotizaciones->get($o->quote_id);
            $pendiente = $quote === null ? 0.0 : $quote->grandTotal();
            $facturado = round((float) ($facturadoPorPedido[$o->id] ?? 0), 2);
            $saldo     = max(0.0, round($pendiente - $facturado, 2));
            $sub       = $o->subClient;

            $adv = [];
            if ($sub === null) {
                $adv[] = self::ADV_SIN_VINCULAR;
            } elseif (trim((string) $sub->tax_id_dv) === '') {
                $adv[] = self::ADV_SIN_DV;
            }

            $motivo = match (true) {
                $saldo <= InvoiceBillingPlanner::EPSILON => 'No queda saldo por facturar',
                $sub === null                            => 'El pedido no tiene cliente vinculado',
                trim((string) $sub->tax_id_dv) === ''    => 'El cliente no tiene DV: complétalo en Clientes fiscales',
                default                                  => null,
            };

            $filas[] = [
                'order_id'              => $o->id,
                'icg'                   => $o->icg_serie.'-'.$o->icg_numero,
                'order_number'          => $o->order_number,
                'cliente'               => $sub !== null ? $sub->business_name : $o->customer_name,
                'ruc'                   => $sub?->tax_id,
                'dv'                    => $sub?->tax_id_dv,
                'vendedor'              => $o->icg_vendedor,
                'fecha'                 => $o->created_at->toDateString(),
                'hoja'                  => $o->icg_hoja,
                'etapa'                 => $o->stage,
                'total_icg'             => round((float) $o->icg_total_neto, 2),
                'facturado_icg'         => round((float) $o->icg_facturado, 2),
                'pendiente'             => $pendiente,
                'facturado_atlanticerp'       => $facturado,
                'saldo'                 => $saldo,
                'advertencias'          => $adv,
                'facturable'            => $motivo === null,
                'motivo_no_facturable'  => $motivo,
            ];
        }

        return $filas;
    }

    /**
     * @param array<string, mixed> $f
     * @return Builder<Order>
     */
    private function consulta(array $f): Builder
    {
        $q = Order::query()->where('origin', Order::ORIGIN_ICG_LEGACY)->with('subClient');

        foreach (['hoja' => 'icg_hoja', 'etapa' => 'stage'] as $param => $columna) {
            if (filled($f[$param] ?? null)) {
                $q->where($columna, (string) $f[$param]);
            }
        }
        if (filled($f['vendedor'] ?? null)) {
            $q->where('icg_vendedor', 'ilike', '%'.self::like((string) $f['vendedor']).'%');
        }
        if (filled($f['cliente'] ?? null)) {
            $texto = '%'.self::like((string) $f['cliente']).'%';
            $q->where(fn ($w) => $w->where('customer_name', 'ilike', $texto)
                ->orWhereHas('subClient', fn ($s) => $s->where('tax_id', 'ilike', $texto)));
        }
        if (filled($f['desde'] ?? null)) {
            $q->whereDate('created_at', '>=', (string) $f['desde']);
        }
        if (filled($f['hasta'] ?? null)) {
            $q->whereDate('created_at', '<=', (string) $f['hasta']);
        }
        if (filled($f['q'] ?? null)) {
            $texto = trim((string) $f['q']);
            $q->where(fn ($w) => $w->where('order_number', 'ilike', '%'.self::like($texto).'%')
                ->orWhereRaw('CAST(icg_numero AS TEXT) = ?', [$texto]));
        }

        return $q;
    }

    /** Escapa los comodines de `ILIKE` del texto del usuario. */
    private static function like(string $texto): string
    {
        return addcslashes(trim($texto), '%_\\');
    }

    /**
     * @param list<array<string, mixed>> $filas
     * @return array<string, float|int>
     */
    private function totales(array $filas): array
    {
        return [
            'pedidos'         => count($filas),
            'total_icg'       => round(array_sum(array_column($filas, 'total_icg')), 2),
            'pendiente'       => round(array_sum(array_column($filas, 'pendiente')), 2),
            'facturado_atlanticerp' => round(array_sum(array_column($filas, 'facturado_atlanticerp')), 2),
            'saldo'           => round(array_sum(array_column($filas, 'saldo')), 2),
        ];
    }

    /**
     * Resumen SIN filtros: cargados por hoja (pendiente de AtlanticERP) contra lo esperado según el informe de la última carga
     * (`legacy.cargar_pedidos_gemelos`), con los omitidos y su motivo. Sin informe de carga (cargados por otra vía) solo trae lo cargado.
     *
     * @return array<string, mixed>
     */
    private function resumen(): array
    {
        $todas = collect($this->filas([]));
        $porHoja = $todas->groupBy(fn (array $f): string => (string) ($f['hoja'] ?? 'Sin hoja'))->map(fn (Collection $g): array => [
            'cargados'  => $g->count(),
            'pendiente' => round((float) $g->sum('pendiente'), 2),
        ])->all();

        $evento = AuditLog::query()->where('action', AuditService::LEGACY_CARGAR_PEDIDOS_GEMELOS)->latest('id')->first();
        $meta   = $evento !== null ? (array) $evento->metadata : [];

        $excel = [];
        foreach ((array) ($meta['por_hoja'] ?? []) as $hoja => $v) {
            $excel[(string) $hoja] = round((float) ($v['excel'] ?? 0), 2);
        }

        return [
            'por_hoja'       => $porHoja,
            'excel_por_hoja' => $excel,
            'excel_total'    => round(array_sum($excel), 2),
            'filas_excel'    => $evento === null ? null : (int) ($meta['filas'] ?? 0),
            'cargados'       => $todas->count(),
            'omitidos'       => array_values((array) ($meta['omitidos'] ?? [])),
            'informe_carga'  => $evento?->created_at?->toIso8601String(),
        ];
    }
}
