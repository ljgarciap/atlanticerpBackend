<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Models\User;
use App\Modules\AdminCont\Services\ItbmsRateResolver;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\WarehouseAssistantMapping;
use App\Modules\Bodega\Services\OrderService;
use App\Modules\Legacy\Models\Pedido;
use App\Modules\Legacy\Models\Producto;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuotePart;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Support\ReceptorTipo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Pedidos gemelos de ICG (spec `docs/specs/icg-pedidos-gemelos.md`, diseño `docs/architecture/icg-pedidos-gemelos-diseno.md` §5, T4):
 * carga cada pedido pendiente por facturar del Excel de Atlantic como un pedido gemelo en AtlanticERP, sin tocar nada contable.
 *
 * Decisión L1: la cotización gemela lleva SOLO las líneas con unidades pendientes en ICG (`unidades_pendientes`), a su precio
 * neto de ICG (`total ÷ cantidad`); la factura de saldo describe solo lo que falta. Los pedidos del Excel en hojas «PARCIAL»
 * (con albarán en ICG) nacen `entregado`; los de la hoja «COMPLETO» (nada entregado) entran a Bodega en `asignado`.
 *
 * Todo lo dudoso se OMITE con un motivo, nunca se carga en silencio: el Excel manda sobre qué pedidos se migran, pero cada uno se
 * cruza contra `legacy.pedidos`. La fecha es la de ICG (las fechas con formato fecha del Excel vienen con día y mes invertidos).
 */
final class PedidosGemelosLoader
{
    public const ACCION_CARGAR = 'cargar';
    public const ACCION_OMITIR = 'omitir';
    public const ACCION_YA_CARGADO = 'ya_cargado';

    public const MOTIVO_NO_ESTA_EN_ICG       = 'no esta en legacy.pedidos';
    public const MOTIVO_SIN_CLIENTE          = 'cliente de ICG sin SubCliente de AtlanticERP';
    public const MOTIVO_CLIENTE_SIN_RUC      = 'SubCliente sin RUC o Consumidor Final';
    public const ADVERTENCIA_SIN_DV          = 'cliente sin DV: se valida contra la DGI al facturar (una empresa sin DV debe completarlo)';
    public const MOTIVO_SIN_UNIDADES         = 'sin unidades pendientes en ICG (o staging sin reextraer)';
    public const MOTIVO_UNIDADES_FRACCION    = 'unidades pendientes fraccionarias';
    public const MOTIVO_TOTAL_DISTINTO       = 'total del Excel distinto del total de ICG';
    public const MOTIVO_PENDIENTE_NO_CUADRA  = 'pendiente por unidades de ICG no cuadra con el Excel';
    public const MOTIVO_DUPLICADO_EN_EXCEL   = 'pedido repetido en el Excel';

    public function __construct(private readonly OrderService $orders)
    {
    }

    /**
     * Filas del Excel de Atlantic. Las columnas se ubican por el encabezado (la hoja «PARCIAL 2025» viene corrida una columna);
     * los montos vienen como texto «B/.96.644,79».
     *
     * @return list<array{hoja: string, vendedor: string, cliente: string, serie: string, numero: int, total: float, facturado: float, pendiente: float}>
     */
    public function leerExcel(string $ruta): array
    {
        $libro = IOFactory::load($ruta);
        $filas = [];

        foreach ($libro->getWorksheetIterator() as $hoja) {
            $columnas = [];
            foreach ($hoja->toArray(null, true, true, false) as $fila) {
                if ($columnas === []) {
                    foreach ($fila as $i => $celda) {
                        $texto = Str::upper(trim(str_replace("\n", ' ', (string) $celda)));
                        if (in_array($texto, ['VENDEDOR', 'CLIENTE', 'SERIE PEDIDO', 'NUMERO PEDIDO', 'TOTAL PEDIDO', 'FACTURADO', 'PENDIENTE POR FACTURAR'], true)) {
                            $columnas[$texto] = $i;
                        }
                    }
                    if (count($columnas) < 7) {
                        $columnas = [];
                    }

                    continue;
                }

                $celda  = static fn (string $k): mixed => $fila[$columnas[$k] ?? -1] ?? null;
                $numero = $celda('NUMERO PEDIDO');
                if (! is_numeric($numero)) {
                    continue;
                }

                $filas[] = [
                    'hoja'      => trim($hoja->getTitle()),
                    'vendedor'  => trim((string) $celda('VENDEDOR')),
                    'cliente'   => trim((string) $celda('CLIENTE')),
                    'serie'     => trim((string) $celda('SERIE PEDIDO')),
                    'numero'    => (int) $numero,
                    'total'     => $this->monto($celda('TOTAL PEDIDO')),
                    'facturado' => $this->monto($celda('FACTURADO')),
                    'pendiente' => $this->monto($celda('PENDIENTE POR FACTURAR')),
                ];
            }
        }

        return $filas;
    }

    /** «B/.96.644,79» → 96644.79 (también acepta un número de celda). */
    public function monto(mixed $valor): float
    {
        if (is_int($valor) || is_float($valor)) {
            return round((float) $valor, 2);
        }

        $limpio = preg_replace('/[^0-9,.\-]/', '', (string) $valor) ?? '';
        if ($limpio === '' || $limpio === '-') {
            return 0.0;
        }

        return round((float) str_replace(',', '.', str_replace('.', '', $limpio)), 2);
    }

    /**
     * Informe de un plan (lo que la vista de validación de gemelos compara contra el Excel): pendiente del Excel por hoja y lo que
     * se cargaría, los omitidos con su motivo y las claves con advertencia de DV. Solo claves de pedido, sin nombres de cliente.
     *
     * @param list<array<string, mixed>> $plan
     * @return array{filas: int, por_hoja: array<string, array{excel: float, cargar: float, n: int}>, omitidos: list<array{clave: string, hoja: string, motivo: string, pendiente_excel: float}>, sin_dv: list<string>}
     */
    public function informe(array $plan): array
    {
        $porHoja  = [];
        $omitidos = [];
        $sinDv    = [];
        foreach ($plan as $f) {
            $hoja = (string) $f['hoja'];
            $porHoja[$hoja] ??= ['excel' => 0.0, 'cargar' => 0.0, 'n' => 0];
            $porHoja[$hoja]['excel'] += (float) $f['pendiente'];
            if ($f['accion'] === self::ACCION_CARGAR) {
                $porHoja[$hoja]['cargar'] += (float) $f['pendiente_icg'];
                $porHoja[$hoja]['n']++;
                if ($f['advertencia'] !== null) {
                    $sinDv[] = (string) $f['clave'];
                }
            } elseif ($f['accion'] === self::ACCION_OMITIR) {
                $omitidos[] = ['clave' => (string) $f['clave'], 'hoja' => $hoja, 'motivo' => (string) $f['motivo'], 'pendiente_excel' => (float) $f['pendiente']];
            }
        }

        return ['filas' => count($plan), 'por_hoja' => $porHoja, 'omitidos' => $omitidos, 'sin_dv' => $sinDv];
    }

    /**
     * Plan de la carga: una entrada por fila del Excel. No escribe nada.
     *
     * @param list<array{hoja: string, vendedor: string, cliente: string, serie: string, numero: int, total: float, facturado: float, pendiente: float}> $filas
     * @param float $tolerancia diferencia máxima (B/.) aceptada entre el Excel y ICG; la fija quien opera, no el código
     * @return list<array<string, mixed>>
     */
    public function plan(array $filas, float $tolerancia): array
    {
        $pct       = ItbmsRateResolver::defaultPercentage();
        $plan      = [];
        $vistos    = [];

        foreach ($filas as $fila) {
            $clave = "{$fila['serie']}-{$fila['numero']}";
            $base  = $fila + ['clave' => $clave, 'accion' => self::ACCION_OMITIR, 'motivo' => null, 'lineas' => [], 'sub_client_id' => null, 'fecha' => null, 'pendiente_icg' => null, 'total_icg' => null, 'etapa' => null, 'advertencia' => null];

            if (isset($vistos[$clave])) {
                $plan[] = ['motivo' => self::MOTIVO_DUPLICADO_EN_EXCEL] + $base;
                continue;
            }
            $vistos[$clave] = true;

            if (Order::query()->where('origin', Order::ORIGIN_ICG_LEGACY)->where('icg_serie', $fila['serie'])->where('icg_numero', $fila['numero'])->exists()) {
                $plan[] = ['accion' => self::ACCION_YA_CARGADO, 'motivo' => 'ya existe el gemelo'] + $base;
                continue;
            }

            $pedido = Pedido::query()->with(['cliente', 'items'])->where('icg_numserie', $fila['serie'])->where('icg_numpedido', $fila['numero'])->first();
            if ($pedido === null) {
                $plan[] = ['motivo' => self::MOTIVO_NO_ESTA_EN_ICG] + $base;
                continue;
            }

            $base['fecha']     = $pedido->fechapedido?->toDateString();
            $base['total_icg'] = round((float) $pedido->totneto, 2);
            $base['etapa']     = Str::upper($fila['hoja']) === 'COMPLETO 2026' || Str::startsWith(Str::upper($fila['hoja']), 'COMPLETO') ? Order::STAGE_ASIGNADO : Order::STAGE_ENTREGADO;

            $subClient = $pedido->cliente?->sub_client_id !== null ? SubClient::find($pedido->cliente->sub_client_id) : null;
            if ($subClient === null) {
                $plan[] = ['motivo' => self::MOTIVO_SIN_CLIENTE] + $base;
                continue;
            }
            $base['sub_client_id'] = $subClient->id;
            $base['advertencia']   = trim((string) $subClient->tax_id_dv) === '' ? self::ADVERTENCIA_SIN_DV : null;
            // El DV no bloquea la carga (decisión de Luis 2026-10-14): al facturar, la DGI decide si hace falta (una empresa sin DV debe
            // completarlo con la corrección de cliente; una persona natural con cédula no lo necesita). Solo se advierte.
            if (trim((string) $subClient->tax_id) === '' || $subClient->receptor_tipo === ReceptorTipo::CONSUMIDOR_FINAL) {
                $plan[] = ['motivo' => self::MOTIVO_CLIENTE_SIN_RUC] + $base;
                continue;
            }

            if (abs($base['total_icg'] - $fila['total']) > $tolerancia) {
                $plan[] = ['motivo' => self::MOTIVO_TOTAL_DISTINTO] + $base;
                continue;
            }

            $codigos = $pedido->items->pluck('icg_codarticulo')->filter()->unique()->all();
            /** @var array<string, int|null> $catalogo */
            $catalogo = $codigos === [] ? [] : Producto::query()->whereIn('icg_codarticulo', $codigos)->pluck('catalog_product_id', 'icg_codarticulo')->all();

            $lineas = [];
            $neto   = 0.0;
            foreach ($pedido->items as $item) {
                $pendientes = $item->unidades_pendientes !== null ? (float) $item->unidades_pendientes : null;
                if ($pendientes === null || $pendientes <= 0.0) {
                    continue;
                }
                if (abs($pendientes - round($pendientes)) > 0.0001) {
                    $plan[] = ['motivo' => self::MOTIVO_UNIDADES_FRACCION] + $base;
                    continue 2;
                }
                $cantidad = (float) $item->cantidad;
                if ($cantidad <= 0.0) {
                    continue;
                }
                $precio  = round((float) $item->total / $cantidad, 2);
                $lineas[] = [
                    'icg_codarticulo'    => (string) $item->icg_codarticulo,
                    'catalog_product_id' => isset($catalogo[$item->icg_codarticulo]) ? (int) $catalogo[$item->icg_codarticulo] : null,
                    'referencia'         => (string) ($item->referencia ?? ''),
                    'descripcion'        => (string) ($item->descripcion ?? ''),
                    'cantidad'           => (int) round($pendientes),
                    'precio'             => $precio,
                ];
                $neto += round($pendientes * $precio, 2);
            }

            if ($lineas === []) {
                $plan[] = ['motivo' => self::MOTIVO_SIN_UNIDADES] + $base;
                continue;
            }

            $base['lineas']        = $lineas;
            $base['pendiente_icg'] = round($neto * (1 + $pct / 100), 2);
            if (abs($base['pendiente_icg'] - $fila['pendiente']) > $tolerancia) {
                $plan[] = ['motivo' => self::MOTIVO_PENDIENTE_NO_CUADRA] + $base;
                continue;
            }

            $plan[] = ['accion' => self::ACCION_CARGAR] + $base;
        }

        return $plan;
    }

    /**
     * Huella del plan revisado (un `--apply` solo corre si el plan recalculado da la misma): claves, acción, etapa, cantidades y precios.
     *
     * @param list<array<string, mixed>> $plan
     */
    public function huella(array $plan): string
    {
        $partes = [];
        foreach ($plan as $f) {
            $partes[] = implode('|', [$f['clave'], $f['accion'], $f['etapa'] ?? '', $f['fecha'] ?? '', $f['advertencia'] ?? '', $f['sub_client_id'] ?? '', json_encode($f['lineas'])]);
        }
        sort($partes);

        return substr(hash('sha256', implode("\n", $partes)), 0, 12);
    }

    /**
     * Carga los pedidos «cargar» del plan, cada uno en su propia transacción (uno que falle no arrastra a los demás).
     *
     * @param list<array<string, mixed>> $plan
     * @return array{cargados: int, errores: list<array{clave: string, error: string}>}
     */
    public function aplicar(array $plan, User $operador): array
    {
        $cargados = 0;
        $errores  = [];

        foreach ($plan as $f) {
            if ($f['accion'] !== self::ACCION_CARGAR) {
                continue;
            }

            try {
                DB::transaction(function () use ($f, $operador): void {
                    $this->cargarUno($f, $operador);
                });
                $cargados++;
            } catch (\Throwable $e) {
                $errores[] = ['clave' => (string) $f['clave'], 'error' => class_basename($e).': '.Str::limit((string) strtok($e->getMessage(), "\n"), 160)];
            }
        }

        return ['cargados' => $cargados, 'errores' => $errores];
    }

    /** @param array<string, mixed> $f */
    private function cargarUno(array $f, User $operador): void
    {
        $subClient = SubClient::findOrFail((int) $f['sub_client_id']);
        $fecha     = $f['fecha'] !== null ? \Carbon\CarbonImmutable::parse((string) $f['fecha']) : now();

        $quoteId = DB::table((new Quote())->getTable())->insertGetId([
            'origin' => 'icg_legacy', 'owner_id' => $operador->id, 'master_client_id' => $subClient->master_client_id, 'sub_client_id' => $subClient->id,
            'folio' => "ICG-{$f['clave']}", 'generated_at' => $fecha, 'status' => 'draft', 'price_type' => 'public', 'discount_mode' => 'line',
            'global_discount_percent' => 0, 'discount_totals_type' => 'percent', 'discount_totals_value' => 0, 'includes_installation' => false,
            'created_at' => $fecha, 'updated_at' => $fecha,
        ]);
        $parte = QuotePart::create(['quote_id' => $quoteId, 'name' => "Saldo pendiente del pedido ICG {$f['clave']}", 'position' => 1]);
        $items = [];
        foreach ($f['lineas'] as $l) {
            $items[] = [$parte->items()->create([
                'catalog_product_id' => $l['catalog_product_id'], 'is_custom' => $l['catalog_product_id'] === null,
                'reference' => $l['referencia'] !== '' ? $l['referencia'] : $l['icg_codarticulo'], 'description' => $l['descripcion'],
                'quantity' => $l['cantidad'], 'unit_price' => $l['precio'], 'discount_percent' => 0,
            ]), $l];
        }

        $numero = (int) $f['numero'];
        $atributos = [
            'order_number' => "ICG-{$f['clave']}", 'order_type' => Order::ORDER_TYPE_PEDIDO, 'stage' => (string) $f['etapa'],
            'origin' => Order::ORIGIN_ICG_LEGACY, 'icg_serie' => (string) $f['serie'], 'icg_numero' => $numero,
            'icg_facturado' => (float) $f['facturado'], 'icg_total_neto' => (float) $f['total_icg'], 'icg_hoja' => (string) $f['hoja'], 'icg_vendedor' => (string) $f['vendedor'],
            'quote_id' => $quoteId, 'sub_client_id' => $subClient->id, 'customer_name' => $subClient->business_name,
        ];

        $entregado  = $f['etapa'] === Order::STAGE_ENTREGADO;
        $itemsOrden = [];
        foreach ($items as [$quoteItem, $l]) {
            $itemsOrden[] = [
                'quote_item_id' => $quoteItem->id, 'catalog_product_id' => $l['catalog_product_id'],
                'reference_snapshot' => $quoteItem->reference, 'description_snapshot' => $quoteItem->description, 'unit_price' => $l['precio'],
                'qty_requested' => $l['cantidad'], 'qty_picked' => $entregado ? $l['cantidad'] : 0, 'qty_delivered' => $entregado ? $l['cantidad'] : 0,
            ];
        }

        if (! $entregado) {
            $atributos['assistant_id'] = $this->asistenteDe((string) $f['vendedor'])?->assistant_user_id;
            $this->orders->createIcgTwinForDispatch($atributos, $itemsOrden, $fecha);

            return;
        }

        // Los parciales ya se entregaron en ICG: nacen `entregado`, sin historial de etapa, sin evento y sin reserva de stock.
        $orden = Order::create($atributos);
        $orden->forceFill(['created_at' => $fecha, 'updated_at' => $fecha])->saveQuietly();
        foreach ($itemsOrden as $item) {
            $orden->items()->create($item);
        }
    }

    /** Asistente de Bodega del vendedor del Excel, si su nombre calza con UN solo usuario activo que tenga asistente asignado. */
    private function asistenteDe(string $vendedor): ?WarehouseAssistantMapping
    {
        $buscado = $this->normalizar($vendedor);
        if ($buscado === '') {
            return null;
        }

        $coinciden = User::query()->where('is_active', true)->get()
            ->filter(fn (User $u): bool => $this->normalizar($u->first_name.' '.$u->last_name) === $buscado);
        if ($coinciden->count() !== 1) {
            return null;
        }

        return WarehouseAssistantMapping::where('seller_user_id', $coinciden->first()?->id)->where('is_active', true)->first();
    }

    private function normalizar(string $texto): string
    {
        return trim((string) preg_replace('/\s+/', ' ', Str::upper(Str::ascii($texto))));
    }
}
