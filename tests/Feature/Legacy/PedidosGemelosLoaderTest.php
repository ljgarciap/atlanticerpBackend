<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Models\User;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Bodega\Events\OrderCreated;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderStageHistory;
use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\Pedido;
use App\Modules\Legacy\Models\PedidoItem;
use App\Modules\Legacy\Services\PedidosGemelosLoader;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Support\ReceptorTipo;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\FeatureTestCase;

/**
 * Pedidos gemelos de ICG (T4): lectura del Excel de Atlantic, plan (qué se carga y qué se omite, con motivo) y carga idempotente.
 * Fixture base: pedido de 1.200 netos (2 líneas), con 4 de las 10 unidades de la línea 1 pendientes → pendiente neto 400,00, con ITBMS 428,00.
 */
final class PedidosGemelosLoaderTest extends FeatureTestCase
{
    private const EXCEL = 'legacy/test-pedidos-gemelos.xlsx';

    private PedidosGemelosLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->loader = app(PedidosGemelosLoader::class);
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->delete(self::EXCEL);
        parent::tearDown();
    }

    private function sub(string $taxId = '1689571-1-684168', ?string $dv = '33', ?string $tipo = null): SubClient
    {
        $master = MasterClient::create(['name' => 'Cliente '.uniqid()]);

        return SubClient::create(['master_client_id' => $master->id, 'business_name' => 'CONSTRUCTORA GRUPO V, SA', 'tax_id' => $taxId, 'tax_id_dv' => $dv, 'receptor_tipo' => $tipo]);
    }

    private function pedidoIcg(int $numero, ?SubClient $sub, float $totneto = 1284.00, ?float $pendientes = 4.0, string $fecha = '2026-05-10'): Pedido
    {
        $cliente = Cliente::query()->create([
            'icg_codcliente' => $numero, 'nombre_comercial' => 'C'.$numero, 'razon_social' => 'R'.$numero, 'cif' => null,
            'sub_client_id' => $sub?->id, 'match_status' => $sub ? Cliente::MATCH_MATCHED : Cliente::MATCH_UNMATCHED, 'bloqueado' => false,
        ]);
        $pedido = Pedido::create([
            'icg_numserie' => 'V11P', 'icg_numpedido' => $numero, 'icg_n' => 1, 'legacy_cliente_id' => $cliente->id, 'icg_codcliente' => $numero,
            'fechapedido' => $fecha, 'totneto' => $totneto, 'icg_codvendedor' => 7, 'idestado' => 6, 'orphan' => false, 'created_at' => now(),
        ]);
        PedidoItem::create(['legacy_pedido_id' => $pedido->id, 'icg_codarticulo' => 1001, 'referencia' => 'REF-A', 'descripcion' => 'Lampara A', 'cantidad' => 10, 'precio' => 100, 'total' => 1000, 'unidades_pendientes' => $pendientes, 'unidades_recibidas' => 10 - ($pendientes ?? 0)]);
        PedidoItem::create(['legacy_pedido_id' => $pedido->id, 'icg_codarticulo' => 1002, 'referencia' => 'REF-B', 'descripcion' => 'Lampara B', 'cantidad' => 2, 'precio' => 100, 'total' => 200, 'unidades_pendientes' => 0, 'unidades_recibidas' => 2]);

        return $pedido;
    }

    /** @return array{hoja: string, vendedor: string, cliente: string, serie: string, numero: int, total: float, facturado: float, pendiente: float} */
    private function fila(int $numero, string $hoja = 'PARCIAL 2026', float $total = 1284.00, float $pendiente = 428.00): array
    {
        return ['hoja' => $hoja, 'vendedor' => 'NEIL QUIEL', 'cliente' => 'X', 'serie' => 'V11P', 'numero' => $numero, 'total' => $total, 'facturado' => round($total - $pendiente, 2), 'pendiente' => $pendiente];
    }

    private function operador(): User
    {
        return User::query()->firstOrFail();
    }

    public function test_el_monto_del_excel_se_lee_como_numero(): void
    {
        $this->assertSame(96644.79, $this->loader->monto('B/.96.644,79'));
        $this->assertSame(205.81, $this->loader->monto('B/.205,81'));
        $this->assertSame(12.5, $this->loader->monto(12.5));
        $this->assertSame(0.0, $this->loader->monto(null));
    }

    public function test_lee_el_excel_con_las_columnas_corridas_y_montos_en_texto(): void
    {
        $libro = new Spreadsheet();
        $h1    = $libro->getActiveSheet()->setTitle('PARCIAL 2025');
        $h1->fromArray([[null, null, null], [null, null, 'VENDEDOR', 'CLIENTE', "SERIE\nPEDIDO", "NUMERO\nPEDIDO", 'FECHA', 'TOTAL PEDIDO', 'FACTURADO', 'PENDIENTE POR FACTURAR'], [null, null, 'JUAN', 'PROMOTORA', 'V11P', 45333, '12/02/2025', 'B/.11.492,45', 'B/.11.353,35', 'B/.139,10']], null, 'A1');
        $h2 = $libro->createSheet()->setTitle('COMPLETO 2026');
        $h2->fromArray([[null, 'VENDEDOR', 'CLIENTE', "SERIE\nPEDIDO", "NUMERO\nPEDIDO", 'FECHA', 'TOTAL PEDIDO', 'FACTURADO', 'PENDIENTE POR FACTURAR'], [null, 'NEIL', 'WENJUN', 'V11P', 45908, '23/01/2026', 'B/.299,07', 'B/.0,00', 'B/.299,07']], null, 'A3');
        Storage::disk('local')->makeDirectory('legacy');
        (new Xlsx($libro))->save(Storage::disk('local')->path(self::EXCEL));

        $filas = $this->loader->leerExcel(Storage::disk('local')->path(self::EXCEL));

        $this->assertCount(2, $filas);
        $this->assertSame(['hoja' => 'PARCIAL 2025', 'vendedor' => 'JUAN', 'cliente' => 'PROMOTORA', 'serie' => 'V11P', 'numero' => 45333, 'total' => 11492.45, 'facturado' => 11353.35, 'pendiente' => 139.10], $filas[0]);
        $this->assertSame(['COMPLETO 2026', 45908, 299.07], [$filas[1]['hoja'], $filas[1]['numero'], $filas[1]['pendiente']]);
    }

    public function test_el_plan_carga_lo_que_cuadra_y_omite_lo_demas_con_motivo(): void
    {
        $this->pedidoIcg(1, $this->sub());
        $this->pedidoIcg(2, null);                                                       // sin cliente de AtlanticERP
        $this->pedidoIcg(3, $this->sub('', null));                                       // sin RUC
        $this->pedidoIcg(9, $this->sub('1689571-1-684168', null));                       // con RUC y sin DV: se carga con advertencia
        $this->pedidoIcg(4, $this->sub(tipo: ReceptorTipo::CONSUMIDOR_FINAL));           // consumidor final
        $this->pedidoIcg(5, $this->sub(), totneto: 1300.00);                             // total distinto del Excel
        $this->pedidoIcg(6, $this->sub(), pendientes: 0.0);                              // nada pendiente
        $this->pedidoIcg(7, $this->sub(), pendientes: 2.0);                              // pendiente por unidades = 214 vs Excel 428
        $this->pedidoIcg(8, $this->sub(), pendientes: 2.5);                              // fracción

        $plan = collect($this->loader->plan([
            $this->fila(1), $this->fila(2), $this->fila(3), $this->fila(4), $this->fila(5), $this->fila(6), $this->fila(7), $this->fila(8), $this->fila(99), $this->fila(1),
        ], 1.00))->keyBy(fn (array $f): string => $f['clave'].'#'.$f['motivo']);

        $porNumero = collect($this->loader->plan([$this->fila(1), $this->fila(2), $this->fila(3), $this->fila(4), $this->fila(5), $this->fila(6), $this->fila(7), $this->fila(8), $this->fila(9), $this->fila(99)], 1.00))->keyBy('numero');

        $this->assertSame(PedidosGemelosLoader::ACCION_CARGAR, $porNumero[1]['accion']);
        $this->assertSame(428.00, $porNumero[1]['pendiente_icg']);
        $this->assertSame([['icg_codarticulo' => '1001', 'catalog_product_id' => null, 'referencia' => 'REF-A', 'descripcion' => 'Lampara A', 'cantidad' => 4, 'precio' => 100.0]], $porNumero[1]['lineas']);
        $this->assertSame('2026-05-10', $porNumero[1]['fecha'], 'La fecha es la de ICG.');
        $this->assertSame(Order::STAGE_ENTREGADO, $porNumero[1]['etapa']);
        $this->assertSame(PedidosGemelosLoader::MOTIVO_SIN_CLIENTE, $porNumero[2]['motivo']);
        $this->assertSame(PedidosGemelosLoader::MOTIVO_CLIENTE_SIN_RUC, $porNumero[3]['motivo']);
        $this->assertSame(PedidosGemelosLoader::MOTIVO_CLIENTE_SIN_RUC, $porNumero[4]['motivo']);
        $this->assertSame(PedidosGemelosLoader::MOTIVO_TOTAL_DISTINTO, $porNumero[5]['motivo']);
        $this->assertSame(PedidosGemelosLoader::MOTIVO_SIN_UNIDADES, $porNumero[6]['motivo']);
        $this->assertSame(PedidosGemelosLoader::MOTIVO_PENDIENTE_NO_CUADRA, $porNumero[7]['motivo']);
        $this->assertSame(PedidosGemelosLoader::MOTIVO_UNIDADES_FRACCION, $porNumero[8]['motivo']);
        $this->assertSame(PedidosGemelosLoader::MOTIVO_NO_ESTA_EN_ICG, $porNumero[99]['motivo']);
        $this->assertSame(PedidosGemelosLoader::ACCION_CARGAR, $porNumero[9]['accion']);
        $this->assertSame(PedidosGemelosLoader::ADVERTENCIA_SIN_DV, $porNumero[9]['advertencia']);
        $this->assertNull($porNumero[1]['advertencia']);
        $this->assertNotEmpty($plan);
        $this->assertSame(PedidosGemelosLoader::MOTIVO_DUPLICADO_EN_EXCEL, collect($this->loader->plan([$this->fila(1), $this->fila(1)], 1.00))->last()['motivo']);
    }

    public function test_la_tolerancia_la_decide_quien_opera(): void
    {
        $this->pedidoIcg(1, $this->sub());

        $estricto = $this->loader->plan([$this->fila(1, pendiente: 428.50)], 0.10)[0];
        $holgado  = $this->loader->plan([$this->fila(1, pendiente: 428.50)], 1.00)[0];

        $this->assertSame(PedidosGemelosLoader::MOTIVO_PENDIENTE_NO_CUADRA, $estricto['motivo']);
        $this->assertSame(PedidosGemelosLoader::ACCION_CARGAR, $holgado['accion']);
    }

    public function test_aplicar_crea_el_parcial_entregado_y_el_completo_en_asignado_y_es_idempotente(): void
    {
        Event::fake([OrderCreated::class]);
        $this->pedidoIcg(1, $this->sub());
        $this->pedidoIcg(2, $this->sub());
        $filas = [$this->fila(1, 'PARCIAL 2026'), $this->fila(2, 'COMPLETO 2026')];

        $res = $this->loader->aplicar($this->loader->plan($filas, 1.00), $this->operador());

        $this->assertSame(2, $res['cargados']);
        $this->assertSame([], $res['errores']);

        $parcial = Order::where('icg_numero', 1)->firstOrFail();
        $this->assertSame(Order::ORIGIN_ICG_LEGACY, $parcial->origin);
        $this->assertSame('PARCIAL 2026', $parcial->icg_hoja);
        $this->assertNotNull($parcial->icg_vendedor);
        $this->assertSame(Order::STAGE_ENTREGADO, $parcial->stage);
        $this->assertNull($parcial->pipeline_card_id);
        $this->assertSame('2026-05-10', $parcial->created_at?->toDateString());
        $this->assertSame(0, OrderStageHistory::where('order_id', $parcial->id)->count(), 'Un parcial no deja historial de etapa: no cuenta como entrega de hoy.');
        $this->assertEqualsWithDelta(428.00, (float) $parcial->linkedQuote()?->loadMissing('parts.items')->grandTotal(), 0.001);
        $this->assertSame([4], $parcial->items()->pluck('qty_delivered')->all());
        $this->assertNull(Quote::find($parcial->quote_id), 'La cotización gemela queda oculta.');

        $completo = Order::where('icg_numero', 2)->firstOrFail();
        $this->assertSame(Order::STAGE_ASIGNADO, $completo->stage);
        $this->assertSame('COMPLETO 2026', $completo->icg_hoja);
        $this->assertSame(1, OrderStageHistory::where('order_id', $completo->id)->count());
        $this->assertSame([0], $completo->items()->pluck('qty_delivered')->all());
        Event::assertDispatchedTimes(OrderCreated::class, 1);

        $segundo = $this->loader->plan($filas, 1.00);
        $this->assertSame([PedidosGemelosLoader::ACCION_YA_CARGADO, PedidosGemelosLoader::ACCION_YA_CARGADO], array_column($segundo, 'accion'));
        $this->assertSame(0, $this->loader->aplicar($segundo, $this->operador())['cargados']);
        $this->assertSame(2, Order::where('origin', 'icg_legacy')->count());
    }

    public function test_un_pedido_que_falla_no_arrastra_a_los_demas(): void
    {
        $this->pedidoIcg(1, $this->sub());
        $this->pedidoIcg(2, $this->sub());
        $plan = $this->loader->plan([$this->fila(1), $this->fila(2)], 1.00);
        $plan[0]['sub_client_id'] = 0; // SubCliente inexistente: el primero falla

        $res = $this->loader->aplicar($plan, $this->operador());

        $this->assertSame(1, $res['cargados']);
        $this->assertCount(1, $res['errores']);
        $this->assertSame(0, Order::where('icg_numero', 1)->count(), 'El pedido fallido no deja nada a medias.');
        $this->assertSame(1, Order::where('icg_numero', 2)->count());
    }

    private function excelCon(array $filas): void
    {
        $libro = new Spreadsheet();
        $hoja  = $libro->getActiveSheet()->setTitle('PARCIAL 2026');
        $datos = [[null, 'VENDEDOR', 'CLIENTE', "SERIE\nPEDIDO", "NUMERO\nPEDIDO", 'FECHA', 'TOTAL PEDIDO', 'FACTURADO', 'PENDIENTE POR FACTURAR']];
        foreach ($filas as $f) {
            $datos[] = [null, $f['vendedor'], $f['cliente'], $f['serie'], $f['numero'], '10/05/2026', 'B/.'.number_format($f['total'], 2, ',', '.'), 'B/.0,00', 'B/.'.number_format($f['pendiente'], 2, ',', '.')];
        }
        $hoja->fromArray($datos, null, 'A1');
        Storage::disk('local')->makeDirectory('legacy');
        (new Xlsx($libro))->save(Storage::disk('local')->path(self::EXCEL));
    }

    /** El comando suelta el tenant al terminar (como en producción); la prueba lo vuelve a fijar para consultar. */
    private function comando(array $opciones): int
    {
        $codigo = \Illuminate\Support\Facades\Artisan::call('legacy:load-pedidos-gemelos', $opciones);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        return $codigo;
    }

    public function test_el_comando_en_dry_run_no_escribe_y_con_apply_exige_huella_y_confirmacion(): void
    {
        $this->pedidoIcg(1, $this->sub());
        $this->excelCon([$this->fila(1), $this->fila(99)]); // el 99 no está en ICG: se omite antes de resolver el cliente
        $base = ['--tenant' => 'atlantic', '--excel' => self::EXCEL, '--tolerancia' => '1'];

        $this->assertSame(0, $this->comando($base));
        $this->assertStringContainsString('Huella del plan', \Illuminate\Support\Facades\Artisan::output());
        $this->assertSame(0, Order::where('origin', 'icg_legacy')->count(), 'El dry-run no escribe.');

        $this->assertSame(1, $this->comando($base + ['--apply' => true, '--operador' => $this->operador()->email, '--yes' => true]));
        $this->assertSame(1, $this->comando(['--tenant' => 'atlantic', '--excel' => self::EXCEL, '--apply' => true]));
        $this->assertSame(0, Order::where('origin', 'icg_legacy')->count());

        $huella = $this->loader->huella($this->loader->plan([$this->fila(1), $this->fila(99)], 1.0));
        $this->assertSame(1, $this->comando($base + ['--apply' => true, '--operador' => $this->operador()->email, '--yes' => true, '--esperado' => 'deadbeef0000']));
        $this->assertSame(0, Order::where('origin', 'icg_legacy')->count(), 'Una huella distinta no carga nada.');

        $this->assertSame(0, $this->comando($base + ['--apply' => true, '--operador' => $this->operador()->email, '--yes' => true, '--esperado' => $huella]));
        $this->assertSame(1, Order::where('origin', 'icg_legacy')->count());

        $evento = AuditLog::query()->where('action', AuditService::LEGACY_CARGAR_PEDIDOS_GEMELOS)->firstOrFail();
        $this->assertSame($huella, $evento->metadata['huella']);
        $this->assertSame(1, $evento->metadata['cargados']);
        $this->assertSame(2, $evento->metadata['filas']);
        $this->assertCount(1, $evento->metadata['omitidos'], 'El informe de la carga deja los omitidos con su motivo.');
        $this->assertNotSame('', $evento->metadata['omitidos'][0]['motivo']);
    }

    public function test_el_relleno_completa_hoja_y_vendedor_de_los_gemelos_ya_cargados_sin_pisar_nada(): void
    {
        $this->pedidoIcg(1, $this->sub());
        $this->pedidoIcg(2, $this->sub());
        $filas = [$this->fila(1), $this->fila(2), $this->fila(99)];
        $this->excelCon($filas);
        $this->loader->aplicar($this->loader->plan([$this->fila(1), $this->fila(2)], 1.00), $this->operador());
        // Gemelos «viejos»: cargados antes de las columnas. El 2 conserva un vendedor ya existente.
        Order::query()->where('icg_numero', 1)->update(['icg_hoja' => null, 'icg_vendedor' => null]);
        Order::query()->where('icg_numero', 2)->update(['icg_hoja' => null, 'icg_vendedor' => 'YA TENIA']);
        $base = ['--tenant' => 'atlantic', '--excel' => self::EXCEL, '--tolerancia' => '1'];
        $call = function (array $o): int {
            $c = \Illuminate\Support\Facades\Artisan::call('legacy:backfill-pedidos-gemelos-hoja', $o);
            Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

            return $c;
        };

        $this->assertSame(0, $call($base));
        $this->assertNull(Order::where('icg_numero', 1)->value('icg_hoja'), 'El dry-run no escribe.');
        $this->assertSame(0, AuditLog::query()->where('action', AuditService::LEGACY_CARGAR_PEDIDOS_GEMELOS)->count());
        preg_match('/Huella del plan\s*\|\s*([0-9a-f]{32})/', \Illuminate\Support\Facades\Artisan::output(), $m);
        $huella = $m[1];

        $aplicar = $base + ['--apply' => true, '--operador' => 'Luis', '--yes' => true];
        $this->assertSame(1, $call($aplicar + ['--esperado' => 'deadbeef']), 'Una huella distinta no escribe.');
        $this->assertNull(Order::where('icg_numero', 1)->value('icg_hoja'));

        $this->assertSame(0, $call($aplicar + ['--esperado' => $huella]));
        $this->assertSame('PARCIAL 2026', Order::where('icg_numero', 1)->value('icg_hoja'));
        $this->assertSame('NEIL QUIEL', Order::where('icg_numero', 1)->value('icg_vendedor'));
        $this->assertSame('PARCIAL 2026', Order::where('icg_numero', 2)->value('icg_hoja'));
        $this->assertSame('YA TENIA', Order::where('icg_numero', 2)->value('icg_vendedor'), 'Nunca pisa un valor existente.');

        $evento = AuditLog::query()->where('action', AuditService::LEGACY_CARGAR_PEDIDOS_GEMELOS)->firstOrFail();
        $this->assertSame('backfill_hoja', $evento->metadata['origen']);
        $this->assertSame(3, $evento->metadata['filas']);
        $this->assertCount(1, $evento->metadata['omitidos'], 'El 99 no está en ICG: queda como omitido en el informe.');

        // Repetir no cambia nada (ya no quedan nulos) ni duplica el informe.
        $this->assertSame(0, $call($base));
        preg_match('/Huella del plan\s*\|\s*([0-9a-f]{32})/', \Illuminate\Support\Facades\Artisan::output(), $m2);
        $this->assertSame(0, $call($aplicar + ['--esperado' => $m2[1]]));
        $this->assertSame(1, AuditLog::query()->where('action', AuditService::LEGACY_CARGAR_PEDIDOS_GEMELOS)->count());
    }
}
