<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\Pedido;
use App\Modules\Legacy\Services\ClientesPedidosGemelosCreator;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\FeatureTestCase;

/**
 * Creación ESPECIALIZADA de los clientes que faltan para los pedidos gemelos de ICG: solo los de los pedidos del Excel, sin duplicar y sin placeholders.
 */
final class ClientesPedidosGemelosCreatorTest extends FeatureTestCase
{
    private const EXCEL = 'legacy/test-clientes-gemelos.xlsx';

    private ClientesPedidosGemelosCreator $creator;
    private int $numero = 60000;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->creator = app(ClientesPedidosGemelosCreator::class);
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->delete(self::EXCEL);
        parent::tearDown();
    }

    /** @param array<string, mixed> $extra */
    private function clienteIcg(int $codigo, string $nombre, ?string $ruc, ?string $dv, array $extra = []): Cliente
    {
        return Cliente::query()->create($extra + [
            'icg_codcliente' => $codigo, 'nombre_comercial' => $nombre, 'razon_social' => $nombre, 'cif' => $ruc, 'nif20' => $ruc, 'ruc' => $ruc, 'ruc_dv' => $dv,
            'ruc_origen' => 'nif20', 'ruc_estado' => 'ok', 'sub_client_id' => null, 'match_status' => Cliente::MATCH_UNMATCHED, 'bloqueado' => false,
        ]);
    }

    /** @return array{serie: string, numero: int, pendiente: float} */
    private function pedidoDe(Cliente $c, float $pendiente = 100.0): array
    {
        $numero = ++$this->numero;
        Pedido::create(['icg_numserie' => 'V11P', 'icg_numpedido' => $numero, 'icg_n' => 1, 'legacy_cliente_id' => $c->id, 'icg_codcliente' => $c->icg_codcliente, 'fechapedido' => '2026-05-10', 'totneto' => 107, 'icg_codvendedor' => 7, 'idestado' => 6, 'orphan' => false, 'created_at' => now()]);

        return ['serie' => 'V11P', 'numero' => $numero, 'pendiente' => $pendiente];
    }

    private function existente(string $nombre, string $taxId): SubClient
    {
        $m = MasterClient::create(['name' => $nombre]);

        return SubClient::create(['master_client_id' => $m->id, 'business_name' => $nombre, 'tax_id' => $taxId]);
    }

    public function test_crea_solo_los_clientes_de_los_pedidos_del_excel_con_su_ruc_y_dv_de_icg(): void
    {
        $a = $this->clienteIcg(9001, 'ADVANCE PROJECT SOLUTIONS S.A.', '155690233-2-2020', '87');
        $this->clienteIcg(9002, 'CLIENTE AJENO AL EXCEL S.A.', '155000000-2-2020', '11'); // sin pedidos en el Excel: no se toca
        $plan = $this->creator->plan([$this->pedidoDe($a, 1952.75)]);

        $this->assertCount(1, $plan);
        $this->assertSame(ClientesPedidosGemelosCreator::ACCION_CREAR, $plan[0]['accion']);
        $res = $this->creator->aplicar($plan);

        $this->assertSame(1, $res['creados']);
        $sub = SubClient::where('business_name', 'ADVANCE PROJECT SOLUTIONS S.A.')->firstOrFail();
        $this->assertSame(['155690233-2-2020', '87'], [$sub->tax_id, $sub->tax_id_dv]);
        $this->assertSame($sub->id, $a->fresh()?->sub_client_id);
        $this->assertSame(Cliente::MATCH_PROMOTED, $a->fresh()?->match_status);
        $this->assertNull(Cliente::where('icg_codcliente', 9002)->first()?->sub_client_id, 'Un cliente fuera del Excel no se crea.');
    }

    public function test_no_crea_si_hay_duda_de_duplicado_por_nombre_o_por_ruc_cortado_ni_sin_ruc(): void
    {
        $this->existente('DESIGN STUDIO JOHANA AGUDO S.A.', '1596565-1-66');            // RUC cortado
        $this->existente('Fundación Fale', 'SIN-CIF-1');                                  // mismo nombre
        $design = $this->clienteIcg(9101, 'DESING STUDIO JOHANA AGUDO S A', '1596565-1-665583', null); // el nombre trae un error de tipeo: lo atrapa el RUC
        $fale   = $this->clienteIcg(9102, 'FUNDACION FALE', '25063906-3-2025', null);
        $sinRuc = $this->clienteIcg(9103, 'SIN DOCUMENTO S.A.', null, null);

        $plan = collect($this->creator->plan([$this->pedidoDe($design), $this->pedidoDe($fale), $this->pedidoDe($sinRuc)]))->keyBy('icg_codcliente');

        $this->assertSame(ClientesPedidosGemelosCreator::MOTIVO_DUPLICADO_RUC, $plan[9101]['motivo']);
        $this->assertStringContainsString('DESIGN STUDIO JOHANA AGUDO S.A.', (string) $plan[9101]['existentes']);
        $this->assertSame(ClientesPedidosGemelosCreator::MOTIVO_DUPLICADO_NOMBRE, $plan[9102]['motivo']);
        $this->assertSame(ClientesPedidosGemelosCreator::MOTIVO_SIN_RUC, $plan[9103]['motivo']);
        $antes = SubClient::count();
        $this->assertSame(0, $this->creator->aplicar(array_values($plan->all()))['creados']);
        $this->assertSame($antes, SubClient::count());
    }

    public function test_es_idempotente_y_un_mismo_cliente_con_varios_pedidos_se_crea_una_vez(): void
    {
        $c = $this->clienteIcg(9201, 'CLIENTE NUEVO S.A.', '155111111-2-2021', '22');
        $plan = $this->creator->plan([$this->pedidoDe($c, 10.0), $this->pedidoDe($c, 20.0)]);

        $this->assertCount(1, $plan);
        $this->assertSame(30.0, $plan[0]['pendiente']);
        $this->assertSame(1, $this->creator->aplicar($plan)['creados']);
        $this->assertSame(0, $this->creator->aplicar($plan)['creados'], 'Repetir no duplica.');
        $this->assertSame(1, SubClient::where('business_name', 'CLIENTE NUEVO S.A.')->count());
    }

    public function test_el_comando_en_dry_run_no_escribe_y_con_apply_exige_huella(): void
    {
        $c = $this->clienteIcg(9301, 'OTRO NUEVO S.A.', '155222222-2-2022', '33');
        $fila = $this->pedidoDe($c);
        $libro = new Spreadsheet();
        $libro->getActiveSheet()->setTitle('PARCIAL 2026')->fromArray([[null, 'VENDEDOR', 'CLIENTE', "SERIE\nPEDIDO", "NUMERO\nPEDIDO", 'FECHA', 'TOTAL PEDIDO', 'FACTURADO', 'PENDIENTE POR FACTURAR'], [null, 'X', 'OTRO', 'V11P', $fila['numero'], '10/05/2026', 'B/.107,00', 'B/.0,00', 'B/.100,00']], null, 'A1');
        Storage::disk('local')->makeDirectory('legacy');
        (new Xlsx($libro))->save(Storage::disk('local')->path(self::EXCEL));
        $comando = function (array $o): int {
            $c = Artisan::call('legacy:crear-clientes-pedidos-gemelos', ['--tenant' => 'atlantic', '--excel' => self::EXCEL] + $o);
            Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

            return $c;
        };

        $this->assertSame(0, $comando([]));
        $this->assertStringContainsString('Huella del plan', Artisan::output());
        $this->assertNull($c->fresh()?->sub_client_id, 'El dry-run no escribe.');
        $this->assertSame(1, $comando(['--apply' => true, '--yes' => true, '--operador' => 'luis']));
        $this->assertSame(1, $comando(['--apply' => true, '--yes' => true, '--operador' => 'luis', '--esperado' => 'deadbeef0000']));
        $this->assertNull($c->fresh()?->sub_client_id);
        $huella = $this->creator->huella($this->creator->plan([$fila]));
        $this->assertSame(0, $comando(['--apply' => true, '--yes' => true, '--operador' => 'luis', '--esperado' => $huella]));
        $this->assertNotNull($c->fresh()?->sub_client_id);
    }
}
