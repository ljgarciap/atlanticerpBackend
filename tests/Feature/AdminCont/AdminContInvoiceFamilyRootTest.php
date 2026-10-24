<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\Bodega\Models\Order;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-929 (B1b) — `family_root_id` / `tipo` / `invoice_lines` en `admin_contab.invoices`. Cubre el
 * modelo (default y resolución de raíz al crear, `forOrder()` por familia, líneas) y los
 * invariantes que la BD sí garantiza (CHECK de `tipo`, un solo activo por `order_id`). El guard de
 * "no cobrar dos veces" entre parcial y completo es del servicio (B2) y se prueba allí.
 */
class AdminContInvoiceFamilyRootTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @return array{root: Order, children: list<Order>} */
    private function makeFamily(int $partes = 3): array
    {
        $root = Order::create([
            'order_number'       => 'PED-2026-'.self::uniqueTestNumber(),
            'order_type'        => Order::ORDER_TYPE_PEDIDO,
            'stage'             => Order::STAGE_POR_DESPACHAR,
            'customer_name'     => 'Cliente B1b',
            'sequence_in_family' => 1,
            'total_in_family'   => $partes,
        ]);
        $children = [];
        for ($i = 2; $i <= $partes; $i++) {
            $children[] = Order::create([
                'order_number'       => $root->order_number,
                'order_type'         => Order::ORDER_TYPE_PEDIDO,
                'stage'              => Order::STAGE_POR_DESPACHAR,
                'customer_name'      => $root->customer_name,
                'family_id'          => $root->id,
                'sequence_in_family' => $i,
                'total_in_family'    => $partes,
            ]);
        }

        return ['root' => $root->fresh() ?? $root, 'children' => $children];
    }

    /**
     * La BD rechazó la operación con exactamente este SQLSTATE (23505 = unique_violation, 23514 =
     * check_violation), no con cualquier error SQL. Corre dentro de un savepoint: en Postgres una
     * sentencia fallida aborta la transacción del test y sin él no se podría seguir consultando.
     */
    private function assertLaBdRechazaCon(string $sqlState, callable $operacion): void
    {
        try {
            DB::transaction($operacion);
            $this->fail("Se esperaba que la BD rechazara la operación con SQLSTATE {$sqlState}.");
        } catch (QueryException $e) {
            $this->assertSame($sqlState, (string) $e->getCode(), 'SQLSTATE: '.$e->getMessage());
        }
    }

    /** @param array<string, mixed> $overrides */
    private function makeInvoice(int $orderId, array $overrides = []): AdminContInvoice
    {
        return AdminContInvoice::create([
            'numero'         => 'FAC-B1B-'.self::uniqueTestNumber(),
            'order_id'       => $orderId,
            'cliente'        => 'Cliente B1b',
            'monto'          => 100,
            'saldo_aplicado' => 0,
            ...$overrides,
        ])->fresh();
    }

    public function test_factura_nueva_sobre_la_raiz_es_completo_y_su_family_root_id_es_la_raiz(): void
    {
        ['root' => $root] = $this->makeFamily();

        $invoice = $this->makeInvoice($root->id);

        $this->assertSame($root->id, $invoice->family_root_id);
        $this->assertSame(AdminContInvoice::TIPO_COMPLETO, $invoice->tipo, 'Sin tipo explícito, toda factura es completo (compatibilidad con SCRUM-876).');
    }

    public function test_factura_sobre_una_entrega_hija_resuelve_family_root_id_a_la_raiz_y_conserva_su_order_id(): void
    {
        ['root' => $root, 'children' => $children] = $this->makeFamily();

        $invoice = $this->makeInvoice($children[0]->id, ['tipo' => AdminContInvoice::TIPO_PARCIAL]);

        $this->assertSame($children[0]->id, $invoice->order_id, 'order_id de una parcial es la ENTREGA, no la raíz.');
        $this->assertSame($root->id, $invoice->family_root_id);
        $this->assertSame(AdminContInvoice::TIPO_PARCIAL, $invoice->tipo);
    }

    public function test_un_family_root_id_explicito_distinto_del_que_resolveria_el_hook_se_respeta(): void
    {
        ['children' => $children] = $this->makeFamily();
        ['root' => $otra] = $this->makeFamily(1);

        // El hook resolvería la raíz de `$children[0]`; se pasa la de OTRO pedido y no se pisa.
        $invoice = $this->makeInvoice($children[0]->id, ['family_root_id' => $otra->id]);

        $this->assertSame($otra->id, $invoice->family_root_id);
        $this->assertNotSame($children[0]->family_id, $invoice->family_root_id);
    }

    public function test_si_la_tarjeta_no_existe_el_family_root_id_cae_al_order_id(): void
    {
        $sinTarjeta = 986_000_000 + self::uniqueTestNumber();

        $invoice = $this->makeInvoice($sinTarjeta);

        $this->assertSame($sinTarjeta, $invoice->family_root_id);
    }

    public function test_for_order_devuelve_todas_las_facturas_del_pedido_desde_cualquier_entrega(): void
    {
        ['root' => $root, 'children' => $children] = $this->makeFamily();
        $this->makeInvoice($children[0]->id, ['tipo' => AdminContInvoice::TIPO_PARCIAL]);
        $this->makeInvoice($children[1]->id, ['tipo' => AdminContInvoice::TIPO_PARCIAL]);
        // Otro pedido: no debe mezclarse.
        ['root' => $otro] = $this->makeFamily(1);
        $this->makeInvoice($otro->id);

        $this->assertSame(2, AdminContInvoice::forOrder($root)->count());
        $this->assertSame(2, AdminContInvoice::forOrder($children[0])->count());
        $this->assertSame(2, AdminContInvoice::forOrder($children[1]->id)->count(), 'También acepta el id crudo.');
        $this->assertSame(1, AdminContInvoice::forOrder($otro)->count());
    }

    public function test_dos_entregas_distintas_pueden_tener_cada_una_su_factura_parcial_activa(): void
    {
        ['children' => $children] = $this->makeFamily();

        $a = $this->makeInvoice($children[0]->id, ['tipo' => AdminContInvoice::TIPO_PARCIAL]);
        $b = $this->makeInvoice($children[1]->id, ['tipo' => AdminContInvoice::TIPO_PARCIAL]);

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame($a->family_root_id, $b->family_root_id);
    }

    public function test_las_lineas_de_factura_se_persisten_y_se_leen_desde_la_relacion(): void
    {
        ['root' => $root] = $this->makeFamily();
        $invoice = $this->makeInvoice($root->id, ['monto' => 321.00]);

        $invoice->lines()->create([
            'quote_item_id' => self::uniqueTestNumber(), 'descripcion' => 'Lámpara A', 'cantidad' => 2.5,
            'precio_unitario' => 100.00, 'subtotal' => 250.00, 'itbms' => 17.50, 'total' => 267.50,
        ]);
        $invoice->lines()->create([
            'quote_item_id' => self::uniqueTestNumber(), 'descripcion' => 'Lámpara B', 'cantidad' => 1,
            'precio_unitario' => 50.00, 'subtotal' => 50.00, 'itbms' => 3.50, 'total' => 53.50,
        ]);

        $lines = $invoice->fresh()->lines;
        $this->assertCount(2, $lines);
        $this->assertSame(2.5, $lines->first()->cantidad);
        $this->assertSame(321.0, round($lines->sum('total'), 2), 'Σ líneas = monto de la factura.');
        $this->assertTrue($lines->first()->invoice->is($invoice));
    }

    /**
     * El riesgo real de B1b: el backfill corre contra datos con facturas huérfanas de antes de
     * SCRUM-876 (colgadas de una tarjeta hermana) y con tarjetas que ya no existen. Se simula el
     * estado "previo a la migración" (columna sin valor) y se ejecuta el backfill REAL de la
     * migración, no una copia del SQL.
     */
    public function test_el_backfill_resuelve_huerfanas_a_la_raiz_y_cae_al_order_id_si_la_tarjeta_no_existe(): void
    {
        ['root' => $root, 'children' => $children] = $this->makeFamily();
        $conRaiz  = $this->makeInvoice($root->id);
        $huerfana = $this->makeInvoice($children[1]->id); // colgada de una hermana, no de la raíz
        $tabla    = (new AdminContInvoice())->getTable();
        $sinTarjeta = 987_000_000 + self::uniqueTestNumber();

        $migration = require database_path('migrations/tenant/2026_09_18_110000_add_family_root_id_and_tipo_to_admin_contab_invoices_table.php');

        DB::statement("ALTER TABLE {$tabla} ALTER COLUMN family_root_id DROP NOT NULL");
        try {
            DB::table($tabla)->insert([
                'numero' => 'FAC-B1B-'.self::uniqueTestNumber(), 'order_id' => $sinTarjeta, 'cliente' => 'Sin tarjeta',
                'monto' => 10, 'saldo_aplicado' => 0, 'family_root_id' => null,
            ]);
            DB::table($tabla)->whereIn('id', [$conRaiz->id, $huerfana->id])->update(['family_root_id' => null]);

            $migration->backfillFamilyRootId();

            $this->assertSame($root->id, (int) DB::table($tabla)->where('id', $conRaiz->id)->value('family_root_id'));
            $this->assertSame($root->id, (int) DB::table($tabla)->where('id', $huerfana->id)->value('family_root_id'), 'La huérfana de una hermana resuelve a la RAÍZ del pedido.');
            $this->assertSame($sinTarjeta, (int) DB::table($tabla)->where('order_id', $sinTarjeta)->value('family_root_id'), 'Sin tarjeta cae al propio order_id.');
            $this->assertSame(0, (int) DB::table($tabla)->whereNull('family_root_id')->count());
            $this->assertSame(
                [AdminContInvoice::TIPO_COMPLETO],
                DB::table($tabla)->whereIn('id', [$conRaiz->id, $huerfana->id])->pluck('tipo')->unique()->values()->all(),
                'Toda factura previa a SCRUM-929 es completo.',
            );
        } finally {
            DB::table($tabla)->where('order_id', $sinTarjeta)->delete();
            DB::statement("ALTER TABLE {$tabla} ALTER COLUMN family_root_id SET NOT NULL");
        }
    }

    public function test_dos_facturas_parciales_activas_con_el_mismo_order_id_las_rechaza_la_bd(): void
    {
        ['children' => $children] = $this->makeFamily();
        $this->makeInvoice($children[0]->id, ['tipo' => AdminContInvoice::TIPO_PARCIAL]);

        $this->assertLaBdRechazaCon('23505', fn () => $this->makeInvoice($children[0]->id, ['tipo' => AdminContInvoice::TIPO_PARCIAL]));
    }

    /** SCRUM-929 — el índice único es `(order_id, tipo)`: una parcial y un completo activos pueden compartir `order_id`. */
    public function test_una_parcial_y_un_completo_activos_pueden_compartir_order_id(): void
    {
        ['root' => $root] = $this->makeFamily();

        $parcial  = $this->makeInvoice($root->id, ['tipo' => AdminContInvoice::TIPO_PARCIAL]);
        $completo = $this->makeInvoice($root->id, ['tipo' => AdminContInvoice::TIPO_COMPLETO]);

        $this->assertSame($root->id, $parcial->order_id);
        $this->assertSame($root->id, $completo->order_id);
        $this->assertSame(2, AdminContInvoice::forOrder($root)->whereNull('anulada_at')->count());
    }

    public function test_dos_completo_activos_con_el_mismo_order_id_los_rechaza_la_bd(): void
    {
        ['root' => $root] = $this->makeFamily();
        $this->makeInvoice($root->id, ['tipo' => AdminContInvoice::TIPO_COMPLETO]);
        $tabla = (new AdminContInvoice())->getTable();

        // INSERT directo: sin pasar por el modelo ni el servicio, es la BD la que rechaza.
        $this->assertLaBdRechazaCon('23505', fn () => DB::table($tabla)->insert([
            'numero' => 'FAC-B1B-'.self::uniqueTestNumber(), 'order_id' => $root->id, 'family_root_id' => $root->id,
            'tipo' => 'completo', 'cliente' => 'X', 'monto' => 1, 'saldo_aplicado' => 0,
        ]));
    }

    public function test_dos_parciales_activas_con_el_mismo_order_id_las_rechaza_la_bd_por_insert_directo(): void
    {
        ['children' => $children] = $this->makeFamily();
        $this->makeInvoice($children[0]->id, ['tipo' => AdminContInvoice::TIPO_PARCIAL]);
        $tabla = (new AdminContInvoice())->getTable();

        $this->assertLaBdRechazaCon('23505', fn () => DB::table($tabla)->insert([
            'numero' => 'FAC-B1B-'.self::uniqueTestNumber(), 'order_id' => $children[0]->id, 'family_root_id' => $children[0]->family_id,
            'tipo' => 'parcial', 'cliente' => 'X', 'monto' => 1, 'saldo_aplicado' => 0,
        ]));
    }

    public function test_una_factura_anulada_convive_con_su_reemplazo_del_mismo_tipo(): void
    {
        ['root' => $root] = $this->makeFamily();
        $original = $this->makeInvoice($root->id, ['tipo' => AdminContInvoice::TIPO_COMPLETO]);
        $original->update(['anulada_at' => now()]);

        $reemplazo = $this->makeInvoice($root->id, ['tipo' => AdminContInvoice::TIPO_COMPLETO]);

        $this->assertNotSame($original->id, $reemplazo->id);
        $this->assertSame(1, AdminContInvoice::forOrder($root)->whereNull('anulada_at')->count());
        $this->assertSame(2, AdminContInvoice::forOrder($root)->count());
    }

    public function test_un_tipo_invalido_lo_rechaza_la_bd(): void
    {
        ['root' => $root] = $this->makeFamily();

        $this->assertLaBdRechazaCon('23514', fn () => $this->makeInvoice($root->id, ['tipo' => 'otro']));
    }
}
